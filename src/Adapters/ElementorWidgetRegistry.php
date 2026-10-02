<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

/**
 * Introspects Elementor's registered widget types and their controls.
 *
 * Without this an agent has to guess which widgets a site has and which setting
 * keys they accept, which is how malformed documents reach `Document::save()`.
 * Plays the same role for Elementor that EnfoldShortcodeRegistry plays for ALB.
 */
final class ElementorWidgetRegistry {

	/** Hard ceiling on widgets returned in one call. */
	public const MAX_WIDGETS = 200;

	/** Hard ceiling on controls returned per widget. */
	public const MAX_CONTROLS = 150;

	/** Control types whose values are plain scalars we can validate cheaply. */
	private const SCALAR_CONTROLS = array( 'text', 'textarea', 'number', 'select', 'select2', 'switcher', 'hidden', 'code', 'wysiwyg', 'url', 'color' );

	private ElementorDocumentStore $store;

	public function __construct( ?ElementorDocumentStore $store = null ) {
		$this->store = $store ?? new ElementorDocumentStore();
	}

	/**
	 * List registered widget types.
	 *
	 * @param string $filter Optional case-insensitive substring match on name or title.
	 * @return array{available:bool,count:int,truncated:bool,widgets:list<array<string,mixed>>}
	 */
	public function catalog( string $filter = '' ): array {
		if ( ! $this->store->available() ) {
			return array(
				'available' => false,
				'count'     => 0,
				'truncated' => false,
				'widgets'   => array(),
			);
		}
		$needle  = strtolower( trim( $filter ) );
		$widgets = array();
		foreach ( $this->widget_types() as $name => $widget ) {
			$title = $this->widget_title( $widget );
			if ( '' !== $needle && ! str_contains( strtolower( $name . ' ' . $title ), $needle ) ) {
				continue;
			}
			$widgets[] = array(
				'name'       => (string) $name,
				'title'      => $title,
				'categories' => $this->widget_categories( $widget ),
				'is_pro'     => str_starts_with( (string) $name, 'pro-' ) || str_contains( strtolower( get_class( $widget ) ), 'elementorpro' ),
			);
		}
		$count     = count( $widgets );
		$truncated = $count > self::MAX_WIDGETS;
		return array(
			'available' => true,
			'count'     => $count,
			'truncated' => $truncated,
			'widgets'   => array_slice( $widgets, 0, self::MAX_WIDGETS ),
		);
	}

	/**
	 * Flatten one widget's controls into an agent-authorable schema.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function controls( string $widget_type ) {
		$all = $this->all_controls( $widget_type );
		if ( is_wp_error( $all ) ) {
			return $all;
		}
		// The cap bounds the payload an agent reads; it must never bound what
		// validate_settings() checks against, or a widget with many controls
		// would silently accept any setting key at all.
		return array(
			'widget_type' => $widget_type,
			'title'       => $all['title'],
			'truncated'   => count( $all['controls'] ) > self::MAX_CONTROLS,
			'total'       => count( $all['controls'] ),
			'controls'    => array_slice( $all['controls'], 0, self::MAX_CONTROLS, true ),
		);
	}

	/**
	 * Flatten every control a widget registers, with no display cap.
	 *
	 * @return array{title:string,controls:array<string,mixed>}|\WP_Error
	 */
	private function all_controls( string $widget_type ) {
		$widget = $this->widget( $widget_type );
		if ( is_wp_error( $widget ) ) {
			return $widget;
		}
		try {
			$controls = $widget->get_controls();
		} catch ( \Throwable ) {
			return new \WP_Error( 'sitepilot_elementor_controls_unavailable', __( 'Elementor could not describe that widget.', 'sitepilot-mcp' ) );
		}
		$controls = is_array( $controls ) ? $controls : array();
		$flat     = array();
		foreach ( $controls as $key => $control ) {
			if ( ! is_array( $control ) || in_array( (string) ( $control['type'] ?? '' ), array( 'section', 'tab' ), true ) ) {
				continue;
			}
			$entry = array( 'type' => (string) ( $control['type'] ?? 'text' ) );
			if ( array_key_exists( 'default', $control ) ) {
				$entry['default'] = $control['default'];
			}
			if ( isset( $control['options'] ) && is_array( $control['options'] ) ) {
				$entry['options'] = array_slice( array_map( 'strval', array_keys( $control['options'] ) ), 0, 50 );
			}
			if ( ! empty( $control['responsive'] ) ) {
				$entry['responsive'] = true;
			}
			$flat[ (string) $key ] = $entry;
		}
		return array(
			'title'    => $this->widget_title( $widget ),
			'controls' => $flat,
		);
	}

	/**
	 * Reject settings a widget cannot accept, before any mutation runs.
	 *
	 * Unknown widget types and unreadable controls are treated as "cannot judge"
	 * rather than "invalid": Elementor's own save is the final authority, and a
	 * third-party widget that hides its controls must not become unusable. A
	 * widget that registers controls is judged against all of them.
	 *
	 * @param array<string,mixed> $settings Proposed widget settings.
	 * @return true|\WP_Error
	 */
	public function validate_settings( string $widget_type, array $settings ) {
		$described = $this->all_controls( $widget_type );
		if ( is_wp_error( $described ) || array() === $described['controls'] ) {
			return true;
		}
		$known = $described['controls'];
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			// Elementor appends _tablet/_mobile/_laptop to responsive control keys.
			$base = preg_replace( '/_(tablet|mobile|laptop|widescreen|mobile_extra|tablet_extra)$/u', '', $key ) ?? $key;
			if ( str_starts_with( $key, '_' ) || str_starts_with( $key, '__' ) ) {
				// Underscore-prefixed keys are Elementor's own element-level settings.
				continue;
			}
			if ( ! isset( $known[ $key ] ) && ! isset( $known[ $base ] ) ) {
				return new \WP_Error(
					'sitepilot_elementor_setting_unknown',
					sprintf(
						/* translators: 1: setting key, 2: widget type. */
						__( 'Setting "%1$s" is not registered by the "%2$s" widget.', 'sitepilot-mcp' ),
						$key,
						$widget_type
					),
					array(
						'widget_type' => $widget_type,
						'setting'     => $key,
					)
				);
			}
			$control = $known[ $key ] ?? $known[ $base ];
			if ( ! isset( $control['options'] ) || ! in_array( $control['type'], self::SCALAR_CONTROLS, true ) || ! is_scalar( $value ) ) {
				continue;
			}
			if ( '' !== (string) $value && ! in_array( (string) $value, $control['options'], true ) ) {
				return new \WP_Error(
					'sitepilot_elementor_setting_invalid',
					sprintf(
						/* translators: 1: setting key, 2: widget type. */
						__( 'Setting "%1$s" has a value the "%2$s" widget does not offer.', 'sitepilot-mcp' ),
						$key,
						$widget_type
					),
					array(
						'widget_type' => $widget_type,
						'setting'     => $key,
						'allowed'     => $control['options'],
					)
				);
			}
		}
		return true;
	}

	/** Whether a widget type is registered on this site. */
	public function widget_exists( string $widget_type ): bool {
		return ! is_wp_error( $this->widget( $widget_type ) );
	}

	/** @return object|\WP_Error */
	private function widget( string $widget_type ) {
		if ( ! $this->store->available() ) {
			return new \WP_Error( 'sitepilot_elementor_unavailable', __( 'Elementor is not active.', 'sitepilot-mcp' ) );
		}
		try {
			$manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
			$widget  = $manager ? $manager->get_widget_types( $widget_type ) : null;
		} catch ( \Throwable ) {
			$widget = null;
		}
		if ( ! is_object( $widget ) ) {
			return new \WP_Error(
				'sitepilot_elementor_widget_unknown',
				sprintf(
					/* translators: %s: widget type name. */
					__( 'Widget type "%s" is not registered on this site.', 'sitepilot-mcp' ),
					$widget_type
				)
			);
		}
		return $widget;
	}

	/** @return array<string,object> */
	private function widget_types(): array {
		try {
			$manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
			$types   = $manager ? $manager->get_widget_types() : array();
		} catch ( \Throwable ) {
			return array();
		}
		return is_array( $types ) ? $types : array();
	}

	private function widget_title( object $widget ): string {
		try {
			return method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : '';
		} catch ( \Throwable ) {
			return '';
		}
	}

	/** @return list<string> */
	private function widget_categories( object $widget ): array {
		try {
			$categories = method_exists( $widget, 'get_categories' ) ? $widget->get_categories() : array();
		} catch ( \Throwable ) {
			return array();
		}
		return is_array( $categories ) ? array_values( array_map( 'strval', $categories ) ) : array();
	}
}
