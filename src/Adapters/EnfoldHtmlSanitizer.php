<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM exposes camelCase properties.

/** Sanitizes bounded preserved HTML and rewrites its mapped media references. */
final class EnfoldHtmlSanitizer {
	/** @var array<string,int> */
	private array $generated;

	/** @param array<string,int> $generated */
	public function __construct(
		private EnfoldAssetResolver $asset_resolver,
		private EnfoldHtmlSource $source,
		array &$generated
	) {
		$this->generated =& $generated;
	}

	public function safe_html( \DOMElement $node ): string {
		$this->remove_unsafe_svg_subtrees( $node );
		$html  = $node->ownerDocument->saveHTML( $node );
		$html  = is_string( $html ) ? str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $html ) : '';
		$html  = preg_replace( '/\sdata-sitepilot-original-path=(?:"[^"]*"|\'[^\']*\')/iu', '', $html ) ?? '';
		$links = array();
		$html  = preg_replace_callback(
			'#\bhref=(["\'])sitepilot://page/([a-z0-9-]+)\1#iu',
			static function ( array $link_parts ) use ( &$links ): string {
				$placeholder           = 'https://sitepilot.invalid/__sitepilot_link__/' . hash( 'sha256', strtolower( $link_parts[2] ) ) . '/' . strtolower( $link_parts[2] );
				$links[ $placeholder ] = 'sitepilot://page/' . strtolower( $link_parts[2] );
				return 'href=' . $link_parts[1] . $placeholder . $link_parts[1];
			},
			$html
		) ?? '';
		$html  = wp_kses( $html, self::allowlist() );
		foreach ( $links as $placeholder => $target ) {
			$html = str_replace(
				array( 'href="' . $placeholder . '"', "href='" . $placeholder . "'" ),
				array( 'href="' . $target . '"', "href='" . $target . "'" ),
				$html
			);
		}
		return $html;
	}

	/** @return string|\WP_Error */
	public function safe_composite_html( \DOMElement $node ) {
		$xpath = new \DOMXPath( $node->ownerDocument );
		foreach ( EnfoldHtmlSource::elements( $xpath->query( 'self::*[@style]|.//*[@style]', $node ) ) as $element ) {
			$rewritten = $this->rewrite_inline_background_urls( $element );
			if ( is_wp_error( $rewritten ) ) {
				return $rewritten;
			}
		}
		foreach ( EnfoldHtmlSource::elements( $xpath->query( 'self::img[@src]|.//img[@src]', $node ) ) as $image ) {
			$asset = $this->asset_resolver->resolve( trim( $image->getAttribute( 'src' ) ), $image->getAttribute( 'alt' ), 'html', $this->source->source_path( $image ) );
			if ( is_wp_error( $asset ) ) {
				return $asset;
			}
			if ( null === $asset ) {
				$image->parentNode?->removeChild( $image );
				continue;
			}
			$image->setAttribute( 'src', $asset['url'] );
			$image->setAttribute( 'data-attachment-id', (string) $asset['attachment_id'] );
			++$this->generated['images'];
		}
		foreach ( EnfoldHtmlSource::elements( $xpath->query( 'self::*[@data-image]|.//*[@data-image]', $node ) ) as $interactive_image ) {
			$source = trim( $interactive_image->getAttribute( 'data-image' ) );
			if ( '' === $source ) {
				continue;
			}
			$asset = $this->asset_resolver->resolve( $source, '', 'html', $this->source->source_path( $interactive_image ) );
			if ( is_wp_error( $asset ) ) {
				return $asset;
			}
			if ( null === $asset ) {
				$interactive_image->removeAttribute( 'data-image' );
				continue;
			}
			$interactive_image->setAttribute( 'data-image', $asset['url'] );
		}
		return $this->safe_html( $node );
	}

	public function remove_unsafe_svg_subtrees( \DOMElement $node ): void {
		$node_name = strtolower( '' !== $node->localName ? $node->localName : $node->tagName );
		if ( 'svg' !== $node_name ) {
			$stack = EnfoldHtmlSource::direct_elements( $node );
			while ( array() !== $stack ) {
				$candidate = array_pop( $stack );
				if ( ! $candidate instanceof \DOMElement ) {
					continue;
				}
				if ( 'svg' === strtolower( '' !== $candidate->localName ? $candidate->localName : $candidate->tagName ) ) {
					$this->remove_unsafe_svg_subtrees( $candidate );
					continue;
				}
				$stack = array_merge( $stack, EnfoldHtmlSource::direct_elements( $candidate ) );
			}
			return;
		}
		$allowed      = array( 'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon' );
		$flow_content = array( 'span', 'p', 'strong', 'em', 'small', 'b', 'i', 'br' );
		$nodes        = array();
		$stack        = array( $node );
		while ( array() !== $stack ) {
			$candidate = array_pop( $stack );
			if ( ! $candidate instanceof \DOMElement ) {
				continue;
			}
			$nodes[] = $candidate;
			$stack   = array_merge( $stack, EnfoldHtmlSource::direct_elements( $candidate ) );
		}
		foreach ( array_reverse( $nodes ) as $candidate ) {
			$name = strtolower( '' !== $candidate->localName ? $candidate->localName : $candidate->tagName );
			if ( in_array( $name, $allowed, true ) ) {
				continue;
			}
			if ( in_array( $name, $flow_content, true ) && $node->parentNode instanceof \DOMNode ) {
				$node->parentNode->insertBefore( $candidate, $node->nextSibling );
				continue;
			}
			$candidate->parentNode?->removeChild( $candidate );
		}
	}

	/** @return true|\WP_Error */
	private function rewrite_inline_background_urls( \DOMElement $element ) {
		$style = $element->getAttribute( 'style' );
		if ( '' === trim( $style ) || ! preg_match( '/(?:^|;)\s*background(?:-image)?\s*:/iu', $style ) || ! str_contains( strtolower( $style ), 'url(' ) ) {
			return true;
		}
		$error     = null;
		$rewritten = preg_replace_callback(
			'/url\(\s*(["\']?)(.*?)\1\s*\)/iu',
			function ( array $matches ) use ( &$error, $element ): string {
				$source = trim( html_entity_decode( $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$asset  = $this->asset_resolver->resolve( $source, '', 'inline_background', $this->source->source_path( $element ) );
				if ( is_wp_error( $asset ) ) {
					$error = $asset;
					return '';
				}
				return null === $asset ? '' : "url('" . esc_url_raw( $asset['url'] ) . "')";
			},
			$style
		);
		if ( $error instanceof \WP_Error ) {
			return $error;
		}
		if ( ! is_string( $rewritten ) ) {
			return new \WP_Error( 'sitepilot_enfold_inline_background_invalid', __( 'A preserved inline background could not be rewritten safely.', 'sitepilot-mcp' ) );
		}
		$element->setAttribute( 'style', $rewritten );
		return true;
	}

	/** @return array<string,array<string,bool>> */
	private static function allowlist(): array {
		$allowed             = wp_kses_allowed_html( 'post' );
		$common              = array(
			'class'           => true,
			'aria-hidden'     => true,
			'aria-label'      => true,
			'role'            => true,
			'focusable'       => true,
			'width'           => true,
			'height'          => true,
			'viewbox'         => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'opacity'         => true,
			'transform'       => true,
		);
		$allowed['svg']      = $common;
		$allowed['path']     = $common + array( 'd' => true );
		$allowed['circle']   = $common + array(
			'cx' => true,
			'cy' => true,
			'r'  => true,
		);
		$allowed['ellipse']  = $common + array(
			'cx' => true,
			'cy' => true,
			'rx' => true,
			'ry' => true,
		);
		$allowed['rect']     = $common + array(
			'x'  => true,
			'y'  => true,
			'rx' => true,
			'ry' => true,
		);
		$allowed['line']     = $common + array(
			'x1' => true,
			'x2' => true,
			'y1' => true,
			'y2' => true,
		);
		$allowed['polyline'] = $common + array( 'points' => true );
		$allowed['polygon']  = $common + array( 'points' => true );
		$allowed['g']        = $common;
		return $allowed;
	}
}
