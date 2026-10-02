<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

/** Resolves and validates the active Enfold theme-options colour and typography surface. */
final class EnfoldGlobalStyles {
	/** @return array{option_name:string,options:array<string,mixed>}|\WP_Error */
	public static function current() {
		$theme        = wp_get_theme();
		$stylesheet   = $theme->get_stylesheet();
		$template     = $theme->get_template();
		$slugs        = array_values( array_unique( array( $stylesheet, str_replace( '-', '_', $stylesheet ), $template, str_replace( '-', '_', $template ) ) ) );
		$candidates   = array_map( static fn ( string $slug ): string => 'avia_options_' . sanitize_key( $slug ), $slugs );
		$candidates[] = 'avia_options';
		foreach ( array_unique( $candidates ) as $candidate ) {
			$value = get_option( $candidate, array() );
			if ( is_array( $value ) && array() !== $value ) {
				return array(
					'option_name' => $candidate,
					'options'     => $value,
				);
			}
		}
		return new \WP_Error( 'sitepilot_enfold_options_missing', __( 'The active Enfold theme-options record could not be resolved.', 'sitepilot-mcp' ) );
	}

	/** @param array<string,mixed> $options @return array{colors:array<string,mixed>,typography:array<string,mixed>} */
	public static function public_groups( array $options ): array {
		$colors     = array();
		$typography = array();
		foreach ( $options as $key => $value ) {
			if ( ! is_string( $key ) || ! is_scalar( $value ) || self::sensitive( $key ) ) {
				continue;
			}
			if ( self::is_color( $key ) ) {
				$colors[ $key ] = $value;
			}
			if ( self::is_typography( $key ) ) {
				$typography[ $key ] = $value;
			}
		}
		ksort( $colors );
		ksort( $typography );
		return array(
			'colors'     => $colors,
			'typography' => $typography,
		);
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $settings
	 * @return array{after:array<string,mixed>,updated:list<string>}|\WP_Error
	 */
	public static function merge( array $before, array $settings ) {
		if ( array() === $settings || count( $settings ) > 100 ) {
			return new \WP_Error( 'sitepilot_enfold_global_settings_invalid', __( 'Supply between 1 and 100 Enfold colour or typography settings.', 'sitepilot-mcp' ) );
		}
		$groups  = self::public_groups( $before );
		$allowed = array_fill_keys( array_merge( array_keys( $groups['colors'] ), array_keys( $groups['typography'] ) ), true );
		$after   = $before;
		$updated = array();
		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( $allowed[ $key ] ) || self::sensitive( $key ) ) {
				return new \WP_Error(
					'sitepilot_enfold_global_setting_unknown',
					__( 'Only existing Enfold colour-set and typography keys returned by inspect-design may be updated.', 'sitepilot-mcp' ),
					array( 'setting' => (string) $key )
				);
			}
			if ( ! is_scalar( $value ) || ( is_string( $value ) && ( strlen( $value ) > 500 || preg_match( '#(?:<|>|javascript\s*:|data\s*:)#iu', $value ) ) ) ) {
				return new \WP_Error( 'sitepilot_enfold_global_value_invalid', __( 'An Enfold global style value is malformed or unsafe.', 'sitepilot-mcp' ), array( 'setting' => $key ) );
			}
			$after[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
			$updated[]     = $key;
		}
		return array(
			'after'   => $after,
			'updated' => $updated,
		);
	}

	private static function sensitive( string $key ): bool {
		return (bool) preg_match( '/(?:^|[-_])(?:key|secret|token|password|salt|nonce)(?:$|[-_])/i', $key );
	}

	private static function is_color( string $key ): bool {
		return str_starts_with( $key, 'colorset-' ) || (bool) preg_match( '/(?:^|[-_])colou?r(?:$|[-_])/i', $key );
	}

	private static function is_typography( string $key ): bool {
		return (bool) preg_match( '/(?:^|[-_])(?:font|font_?size|line_?height|typography)(?:$|[-_])/i', $key );
	}
}
