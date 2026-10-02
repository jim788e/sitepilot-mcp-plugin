<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM exposes camelCase properties.

/** Parses only semantic HTML or explicitly request-mapped accordion/tab relationships. */
final class EnfoldHtmlCompositeParser {
	public function __construct( private EnfoldHtmlSource $source ) {}

	/**
	 * @return list<array{
	 *   title:string,
	 *   open:bool,
	 *   detail_element?:\DOMElement,
	 *   button_element?:\DOMElement,
	 *   body_element?:\DOMElement,
	 *   p_wrapper?:bool
	 * }>
	 */
	public function accordion_items( \DOMElement $container ): array {
		$xpath   = new \DOMXPath( $container->ownerDocument );
		$details = EnfoldHtmlSource::elements( $xpath->query( 'self::details|.//details', $container ) );
		if ( array() !== $details ) {
			$items = array();
			foreach ( $details as $detail ) {
				$summary = $xpath->query( './summary', $detail )?->item( 0 );
				$title   = $summary instanceof \DOMElement ? EnfoldHtmlDiagnostics::visible_node_text( $summary ) : __( 'Details', 'sitepilot-mcp' );
				$items[] = array(
					'title'          => '' !== trim( $title ) ? $title : __( 'Details', 'sitepilot-mcp' ),
					'open'           => $detail->hasAttribute( 'open' ),
					'detail_element' => $detail,
				);
			}
			return $items;
		}

		$children = EnfoldHtmlSource::direct_elements( $container );
		$items    = array();
		$mapped   = 'accordion' === $this->source->component_mapping( $container );
		for ( $index = 0, $count = count( $children ); $index + 1 < $count; ++$index ) {
			$wrapper = $children[ $index ];
			$button  = $this->accordion_button( $wrapper, $container );
			if ( ! $button instanceof \DOMElement ) {
				continue;
			}
			$panel          = $children[ $index + 1 ];
			$declares_state = $button->hasAttribute( 'aria-expanded' ) || $button->hasAttribute( 'aria-controls' );
			if ( ! $mapped && ! $declares_state ) {
				continue;
			}
			$adjacent_content = in_array( strtolower( $panel->tagName ), array( 'div', 'section', 'article', 'aside' ), true );
			if ( ! $mapped && ! self::semantic_panel( $panel ) && ! ( $declares_state && $adjacent_content ) ) {
				continue;
			}
			$title   = EnfoldHtmlDiagnostics::visible_node_text( $button );
			$items[] = array(
				'title'          => '' !== trim( $title ) ? $title : __( 'Details', 'sitepilot-mcp' ),
				'open'           => 'true' === strtolower( $button->getAttribute( 'aria-expanded' ) ),
				'button_element' => $button,
				'body_element'   => $panel,
				'p_wrapper'      => 'p' === strtolower( $wrapper->tagName ),
			);
			++$index;
		}
		return $items;
	}

	/** @return list<array{title:string,open:bool,control_element?:\DOMElement,panel_element:\DOMElement}> */
	public function tab_items( \DOMElement $container ): array {
		$xpath  = new \DOMXPath( $container->ownerDocument );
		$panels = array();
		foreach ( EnfoldHtmlSource::elements( $xpath->query( './/*[@id]', $container ) ) as $panel ) {
			$id = trim( $panel->getAttribute( 'id' ) );
			if ( '' !== $id && ! isset( $panels[ $id ] ) ) {
				$panels[ $id ] = $panel;
			}
		}

		$mapped = 'tabs' === $this->source->component_mapping( $container );
		$config = $this->source->component_config( $container );
		$attrs  = array( 'aria-controls', 'href' );
		if ( self::valid_target_attribute( $config['target_attribute'] ?? null ) ) {
			array_unshift( $attrs, strtolower( (string) $config['target_attribute'] ) );
		}
		$items = array();
		foreach ( EnfoldHtmlSource::elements( $xpath->query( './/button|.//*[@role="tab"]', $container ) ) as $control ) {
			$control_config = $this->ancestor_config( $control, $container );
			$control_attrs  = $attrs;
			if ( self::valid_target_attribute( $control_config['target_attribute'] ?? null ) ) {
				array_unshift( $control_attrs, strtolower( (string) $control_config['target_attribute'] ) );
			}
			$target = self::target( $control, array_values( array_unique( $control_attrs ) ) );
			if ( '' === $target || ! isset( $panels[ $target ] ) ) {
				continue;
			}
			$semantic = $mapped
				|| 'tabs' === ( $control_config['type'] ?? '' )
				|| 'tab' === strtolower( $control->getAttribute( 'role' ) )
				|| $this->has_tablist_ancestor( $control, $container );
			if ( ! $semantic ) {
				continue;
			}
			$panel   = $panels[ $target ];
			$title   = EnfoldHtmlDiagnostics::visible_node_text( $control );
			$items[] = array(
				'title'           => '' !== trim( $title ) ? $title : __( 'Tab', 'sitepilot-mcp' ),
				'open'            => 'true' === strtolower( $control->getAttribute( 'aria-selected' ) ) || 'true' === strtolower( $control->getAttribute( 'aria-expanded' ) ) || 'false' === strtolower( $panel->getAttribute( 'aria-hidden' ) ),
				'control_element' => $control,
				'panel_element'   => $panel,
			);
		}

		if ( count( $items ) >= 2 ) {
			return $items;
		}
		if ( ! $mapped ) {
			return array();
		}
		$alternating = $this->accordion_items( $container );
		if ( count( $alternating ) < 2 ) {
			return array();
		}
		return array_map(
			static fn ( array $item ): array => array(
				'title'           => $item['title'],
				'open'            => (bool) $item['open'],
				'control_element' => $item['button_element'] ?? null,
				'panel_element'   => $item['body_element'],
			),
			$alternating
		);
	}

	public function has_tabs( \DOMElement $container ): bool {
		$mapping = $this->source->component_mapping( $container );
		return ( '' === $mapping || 'tabs' === $mapping ) && count( $this->tab_items( $container ) ) >= 2;
	}

	private function accordion_button( \DOMElement $element, \DOMElement $boundary ): ?\DOMElement {
		$tag    = strtolower( $element->tagName );
		$button = 'button' === $tag ? $element : null;
		if ( null === $button && in_array( $tag, array( 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			$direct = EnfoldHtmlSource::direct_elements( $element );
			$button = 1 === count( $direct ) && 'button' === strtolower( $direct[0]->tagName ) ? $direct[0] : null;
		}
		if ( ! $button instanceof \DOMElement ) {
			return null;
		}
		$config = $this->ancestor_config( $button, $boundary );
		return $button->hasAttribute( 'aria-expanded' ) || $button->hasAttribute( 'aria-controls' ) || 'accordion' === ( $config['type'] ?? '' ) ? $button : null;
	}

	/** @return array<string,mixed> */
	private function ancestor_config( \DOMElement $element, \DOMElement $boundary ): array {
		$current = $element;
		while ( $current instanceof \DOMElement ) {
			$config = $this->source->component_config( $current );
			if ( array() !== $config ) {
				return $config;
			}
			if ( $current === $boundary ) {
				break;
			}
			$current = $current->parentNode;
		}
		return array();
	}

	private function has_tablist_ancestor( \DOMElement $element, \DOMElement $boundary ): bool {
		$current = $element->parentNode;
		while ( $current instanceof \DOMElement ) {
			if ( 'tablist' === strtolower( $current->getAttribute( 'role' ) ) ) {
				return true;
			}
			if ( $current === $boundary ) {
				break;
			}
			$current = $current->parentNode;
		}
		return false;
	}

	/** @param list<string> $attributes */
	private static function target( \DOMElement $control, array $attributes ): string {
		foreach ( $attributes as $attribute ) {
			$value = trim( $control->getAttribute( $attribute ) );
			if ( '' === $value || ( 'href' === $attribute && ! str_starts_with( $value, '#' ) ) ) {
				continue;
			}
			$value = ltrim( $value, '#' );
			if ( preg_match( '/^[a-z][a-z0-9_.:-]*$/iu', $value ) ) {
				return $value;
			}
		}
		return '';
	}

	private static function valid_target_attribute( mixed $attribute ): bool {
		return is_string( $attribute ) && (bool) preg_match( '/^(?:aria-controls|data-[a-z0-9_-]+|href)$/', $attribute );
	}

	private static function semantic_panel( \DOMElement $element ): bool {
		return 'region' === strtolower( $element->getAttribute( 'role' ) )
			|| $element->hasAttribute( 'aria-labelledby' )
			|| $element->hasAttribute( 'aria-hidden' )
			|| $element->hasAttribute( 'hidden' );
	}
}
