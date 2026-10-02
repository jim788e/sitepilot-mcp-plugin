<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

/**
 * Cheap, DOM-free suitability checks for HTML-to-Enfold imports.
 *
 * The compiler is a draft seeding tool, not a browser. Reject source that is
 * clearly dependent on a JavaScript/framework runtime before the expensive
 * normalization and coverage passes begin.
 */
final class EnfoldHtmlPreflight {
	private const MAX_ELEMENTS       = 1500;
	private const MAX_NESTING_DEPTH  = 40;
	private const MAX_MISSING_ASSETS = 12;
	private const VOID_ELEMENTS      = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	/** @param array<string,mixed> $input @return true|\WP_Error */
	public function inspect( array $input ) {
		$started    = hrtime( true );
		$html       = (string) ( $input['source_html'] ?? '' );
		$css        = (string) ( $input['source_css'] ?? '' );
		$reasons    = array();
		$diagnostic = array();

		$node_count = preg_match_all( '#<(?![!/\?])\s*[a-z][^>]*>#iu', $html );
		$node_count = false === $node_count ? 0 : $node_count;
		if ( $node_count > self::MAX_ELEMENTS ) {
			$reasons[]                = 'node_count_exceeded';
			$diagnostic['node_count'] = $node_count;
			$diagnostic['node_limit'] = self::MAX_ELEMENTS;
		}

		$depth = $this->nesting_depth( $html );
		if ( $depth > self::MAX_NESTING_DEPTH ) {
			$reasons[]                   = 'nesting_depth_exceeded';
			$diagnostic['nesting_depth'] = $depth;
			$diagnostic['depth_limit']   = self::MAX_NESTING_DEPTH;
		}

		if ( $this->has_layout_script( $html ) ) {
			$reasons[] = 'script_driven_layout';
		}

		$class_stats = $this->class_statistics( $html );
		if ( $class_stats['framework_marker'] || ( $class_stats['attributes'] >= 30 && $class_stats['average'] >= 10 && $class_stats['unique'] >= 150 ) ) {
			$reasons[]                 = 'framework_class_soup';
			$diagnostic['class_stats'] = $class_stats;
		}

		$custom_property_count = preg_match_all( '/(?:^|[;{]\s*)--[a-z0-9_-]+\s*:/imu', $css );
		$custom_property_count = false === $custom_property_count ? 0 : $custom_property_count;
		$custom_property_uses  = preg_match_all( '/\bvar\(\s*--[a-z0-9_-]+/iu', $css );
		$custom_property_uses  = false === $custom_property_uses ? 0 : $custom_property_uses;
		if ( $custom_property_count > 32 || $custom_property_uses > 64 ) {
			$reasons[]                              = 'css_custom_property_theming';
			$diagnostic['css_custom_properties']    = $custom_property_count;
			$diagnostic['css_custom_property_uses'] = $custom_property_uses;
		}

		$missing_assets = $this->missing_assets( $html, $css, is_array( $input['media_mappings'] ?? null ) ? $input['media_mappings'] : array() );
		if ( count( $missing_assets ) > self::MAX_MISSING_ASSETS ) {
			$reasons[]                          = 'unmapped_asset_count';
			$diagnostic['unmapped_assets']      = array_slice( $missing_assets, 0, 25 );
			$diagnostic['unmapped_asset_count'] = count( $missing_assets );
			$diagnostic['unmapped_asset_limit'] = self::MAX_MISSING_ASSETS;
		}

		if ( array() === $reasons ) {
			return true;
		}

		$elapsed_ms = ( hrtime( true ) - $started ) / 1_000_000;
		return new \WP_Error(
			'sitepilot_enfold_source_unsupported',
			__( 'The source is not a suitable static HTML starting point for the Enfold compiler.', 'sitepilot-mcp' ),
			array(
				'code'               => 'sitepilot_enfold_source_unsupported',
				'reasons'            => array_values( array_unique( $reasons ) ),
				'suggestion'         => __( 'Use inspect-design and design.edit_elements against an existing page instead.', 'sitepilot-mcp' ),
				'diagnostics'        => $diagnostic,
				'preflight_ms'       => round( $elapsed_ms, 3 ),
				'change_set_created' => false,
			)
		);
	}

	private function has_layout_script( string $html ): bool {
		if ( ! preg_match_all( '#<script\b([^>]*)>(.*?)</script>#isu', $html, $scripts, PREG_SET_ORDER ) ) {
			return false;
		}
		foreach ( $scripts as $script ) {
			$attributes = strtolower( (string) ( $script[1] ?? '' ) );
			$body       = (string) ( $script[2] ?? '' );
			if ( str_contains( $attributes, 'application/ld+json' ) || str_contains( $attributes, 'application/json' ) ) {
				continue;
			}
			if ( preg_match( '/\b(?:queryselector|createelement|appendchild|replacechildren|innerhtml|outerhtml|classlist|reactdom|hydrate(?:root)?|createapp|swiper|splide|glide|slick|isotope|masonry|carousel|slider|lightbox)\b/iu', $body . ' ' . $attributes ) ) {
				return true;
			}
		}
		return false;
	}

	private function nesting_depth( string $html ): int {
		if ( ! preg_match_all( '#<\s*(/)?\s*([a-z][a-z0-9:-]*)(?:\s[^>]*)?(/)?\s*>#iu', $html, $tokens, PREG_SET_ORDER ) ) {
			return 0;
		}
		$stack = array();
		$max   = 0;
		foreach ( $tokens as $token ) {
			$closing = '' !== ( $token[1] ?? '' );
			$tag     = strtolower( (string) ( $token[2] ?? '' ) );
			$self    = '' !== ( $token[3] ?? '' ) || in_array( $tag, self::VOID_ELEMENTS, true );
			if ( $closing ) {
				$position = array_search( $tag, array_reverse( $stack, true ), true );
				if ( false !== $position ) {
					$stack = array_slice( $stack, 0, (int) $position );
				}
				continue;
			}
			if ( ! $self ) {
				$stack[] = $tag;
				$max     = max( $max, count( $stack ) );
			}
		}
		return $max;
	}

	/** @return array{attributes:int,unique:int,average:float,framework_marker:bool} */
	private function class_statistics( string $html ): array {
		preg_match_all( '/\bclass\s*=\s*(["\'])(.*?)\1/isu', $html, $matches );
		$attributes = count( $matches[2] ?? array() );
		$tokens     = array();
		foreach ( $matches[2] ?? array() as $value ) {
			$parts  = preg_split( '/\s+/u', trim( (string) $value ), -1, PREG_SPLIT_NO_EMPTY );
			$tokens = array_merge( $tokens, is_array( $parts ) ? $parts : array() );
		}
		$marker = (bool) preg_match( '/\b(?:data-reactroot|data-reactid|ng-version|data-v-[a-f0-9]{6,}|id=["\']__next["\'])/iu', $html );
		return array(
			'attributes'       => $attributes,
			'unique'           => count( array_unique( $tokens ) ),
			'average'          => $attributes > 0 ? round( count( $tokens ) / $attributes, 2 ) : 0.0,
			'framework_marker' => $marker,
		);
	}

	/** @param array<string,mixed> $mappings @return list<string> */
	private function missing_assets( string $html, string $css, array $mappings ): array {
		$assets        = array();
		$srcset_assets = array();
		preg_match_all( '/\b(?:src|poster)\s*=\s*(["\'])(.*?)\1/isu', $html, $html_assets );
		preg_match_all( '/\bsrcset\s*=\s*(["\'])(.*?)\1/isu', $html, $srcsets );
		preg_match_all( '/\burl\(\s*(["\']?)(.*?)\1\s*\)/isu', $css, $css_assets );
		foreach ( $srcsets[2] ?? array() as $srcset ) {
			$candidates = preg_split( '/\s*,\s*/u', trim( (string) $srcset ), -1, PREG_SPLIT_NO_EMPTY );
			foreach ( is_array( $candidates ) ? $candidates : array() as $candidate ) {
				$parts = preg_split( '/\s+/u', trim( $candidate ), 2, PREG_SPLIT_NO_EMPTY );
				if ( is_array( $parts ) && isset( $parts[0] ) ) {
					$srcset_assets[] = $parts[0];
				}
			}
		}
		foreach ( array_merge( $html_assets[2] ?? array(), $srcset_assets, $css_assets[2] ?? array() ) as $source ) {
			$source = html_entity_decode( trim( (string) $source ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( '' === $source || str_starts_with( $source, 'data:' ) || str_starts_with( $source, '#' ) || isset( $mappings[ $source ] ) ) {
				continue;
			}
			$assets[] = $source;
		}
		return array_values( array_unique( $assets ) );
	}
}
