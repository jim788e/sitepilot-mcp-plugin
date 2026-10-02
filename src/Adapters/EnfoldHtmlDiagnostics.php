<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM exposes camelCase properties.

/** Builds compiler coverage, asset, and link diagnostics. */
final class EnfoldHtmlDiagnostics {
	/** @var array<string,int> */
	private array $generated;
	/** @var list<array<string,mixed>> */
	private array $unsupported;
	/** @var list<string> */
	private array $missing_text;

	/**
	 * @param array<string,int>              $generated
	 * @param list<array<string,mixed>>      $unsupported
	 * @param list<string>                   $missing_text
	 * @param array<string,mixed>            $media
	 * @param array<string,string>           $links
	 */
	public function __construct(
		array &$generated,
		array &$unsupported,
		array &$missing_text,
		private EnfoldAssetResolver $asset_resolver,
		private EnfoldHtmlSource $source,
		private array $media,
		private array $links,
		private string $source_css
	) {
		$this->generated    =& $generated;
		$this->unsupported  =& $unsupported;
		$this->missing_text =& $missing_text;
	}

	/** @param array<string,int> $source_counts @return array<string,mixed> */
	public function coverage_report( array $source_counts ): array {
		$categories = array();
		$source     = array();
		$generated  = array();
		foreach ( array_keys( $this->generated ) as $category ) {
			$public_category               = 'buttons_links' === $category ? 'buttons_or_links' : $category;
			$source[ $public_category ]    = (int) ( $source_counts[ $category ] ?? 0 );
			$generated[ $public_category ] = (int) $this->generated[ $category ];
			$categories[ $category ]       = array(
				'source'    => (int) ( $source_counts[ $category ] ?? 0 ),
				'generated' => (int) $this->generated[ $category ],
			);
		}
		$source['source_dom_sections']      = (int) ( $source_counts['source_dom_sections'] ?? $source['sections'] );
		$source['semantic_layout_sections'] = (int) ( $source_counts['semantic_layout_sections'] ?? $source['sections'] );
		$omitted                            = self::intentionally_omitted_elements();
		return array(
			'source'                         => $source,
			'generated'                      => $generated,
			'categories'                     => $categories,
			'unsupported'                    => $this->summarize_unsupported(),
			'unsupported_elements'           => $this->unsupported,
			'intentionally_omitted'          => array_column( $omitted, 'tag' ),
			'intentionally_omitted_elements' => $omitted,
			'external_assets'                => $this->asset_resolver->external_assets(),
			'malformed_assets'               => $this->asset_resolver->malformed_assets(),
			'unmapped_assets'                => $this->asset_resolver->unmapped_assets(),
			'missing_visible_text'           => $this->missing_text,
		);
	}

	/** @return list<array<string,mixed>> */
	public function asset_report(): array {
		$assets      = array();
		$used_assets = $this->asset_resolver->used_assets();
		foreach ( $this->media as $source => $mapping ) {
			$id       = is_array( $mapping ) ? absint( $mapping['attachment_id'] ?? 0 ) : absint( $mapping );
			$url      = $id > 0 && function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $id ) : false;
			$usage    = isset( $used_assets[ (string) $source ] ) ? (string) $used_assets[ (string) $source ] : ( str_contains( $this->source_css, (string) $source ) ? 'css' : 'unused' );
			$assets[] = array(
				'source'        => (string) $source,
				'attachment_id' => $id,
				'url'           => is_string( $url ) ? $url : '',
				'alt'           => is_array( $mapping ) ? sanitize_text_field( (string) ( $mapping['alt'] ?? '' ) ) : '',
				'usage'         => $usage,
			);
		}
		return $assets;
	}

	/** @return list<array{source:string,target:string}> */
	public function link_report(): array {
		$links = array();
		foreach ( $this->links as $source => $target ) {
			$links[] = array(
				'source' => $source,
				'target' => $target,
			);
		}
		return $links;
	}

	/** @param array<string,mixed> $coverage @return list<array<string,mixed>> */
	public function lost_categories( array $coverage ): array {
		$lost = array();
		foreach ( (array) ( $coverage['categories'] ?? array() ) as $category => $counts ) {
			$expected = (int) ( $counts['source'] ?? 0 );
			$actual   = (int) ( $counts['generated'] ?? 0 );
			if ( $expected <= $actual ) {
				continue;
			}
			$item = array(
				'category'  => (string) $category,
				'expected'  => $expected,
				'generated' => $actual,
				'message'   => sprintf( 'expected %d %s, generated %d', $expected, str_replace( '_', ' ', (string) $category ), $actual ),
			);
			if ( 'visible_text_fragments' === $category ) {
				$item['missing'] = $this->missing_text;
			}
			$lost[] = $item;
		}
		if ( array() !== ( $coverage['unsupported'] ?? array() ) ) {
			$lost[] = array(
				'category'  => 'unsupported',
				'expected'  => count( (array) $coverage['unsupported'] ),
				'generated' => 0,
				'message'   => 'unsupported interactive or media elements require an explicit component mapping',
			);
		}
		return $lost;
	}

	public function measure_text_coverage( \DOMXPath $xpath, string $alb ): void {
		$source    = self::visible_text_fragments( $xpath );
		$generated = array_count_values( $this->rendered_text_fragments( $alb ) );
		foreach ( array_count_values( $source ) as $fragment => $expected ) {
			$retained                                   = min( $expected, $generated[ $fragment ] ?? 0 );
			$this->generated['visible_text_fragments'] += $retained;
			for ( $missing = $retained; $missing < $expected; ++$missing ) {
				$this->missing_text[] = $fragment;
			}
		}
	}

	/** @return array<string,int> */
	public function source_coverage( \DOMXPath $xpath, callable $is_layout_group, callable $is_composite, callable $is_card, callable $is_gallery ): array {
		$layout      = 0;
		$cards       = 0;
		$galleries   = 0;
		$backgrounds = 0;
		foreach ( EnfoldHtmlSource::elements( $xpath->query( '//*' ) ) as $element ) {
			if ( 'section' !== strtolower( $element->tagName ) && $is_layout_group( $element ) && ! $is_composite( $element ) ) {
				++$layout;
			}
			if ( $is_card( $element ) ) {
				++$cards;
			}
			if ( $is_gallery( $element ) ) {
				++$galleries;
			}
			$backgrounds += count( $this->source->background_urls( $element ) );
			if ( in_array( strtolower( $element->tagName ), array( 'video', 'audio', 'canvas', 'iframe', 'object', 'embed' ), true ) ) {
				$text                = self::visible_node_text( $element );
				$this->unsupported[] = array(
					'tag'          => strtolower( $element->tagName ),
					'reason'       => 'No safe native ALB mapping is enabled.',
					'path'         => $this->source->source_path( $element ),
					'visible_text' => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 500 ) : substr( $text, 0, 500 ),
				);
			}
		}
		return array(
			'dom_elements'             => (int) $xpath->evaluate( 'count(//body//*)' ),
			'sections'                 => (int) $xpath->evaluate( 'count(//body//section[not(ancestor::section)])' ),
			'source_dom_sections'      => (int) $xpath->evaluate( 'count(//body//section)' ),
			'semantic_layout_sections' => (int) $xpath->evaluate( 'count(//body//section[not(ancestor::section)])' ),
			'layout_groups'            => $layout,
			'cards'                    => $cards,
			'headings'                 => (int) $xpath->evaluate( 'count(//body//h1|//body//h2|//body//h3|//body//h4|//body//h5|//body//h6)' ),
			'text_blocks'              => (int) $xpath->evaluate( 'count(//body//p)' ),
			'images'                   => (int) $xpath->evaluate( 'count(//body//img)' ) + $backgrounds,
			'buttons_links'            => (int) $xpath->evaluate( 'count(//body//button|//body//a[@href]|//body//input[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="submit"])' ),
			'form_fields'              => (int) $xpath->evaluate( 'count(//body//input[not(translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="hidden" or translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="submit" or translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="button" or translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="reset" or translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="image")]|//body//select|//body//textarea)' ),
			'galleries'                => $galleries,
			'visible_text_fragments'   => count( self::visible_text_fragments( $xpath ) ),
		);
	}

	/** @return list<string> */
	public static function visible_text_fragments( \DOMXPath $xpath ): array {
		$fragments = array();
		$nodes     = $xpath->query( '//body//text()[normalize-space() and not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::template) and not(ancestor::*[translate(normalize-space(@aria-hidden), "TRUE", "true") = "true"])]' );
		if ( $nodes instanceof \DOMNodeList ) {
			foreach ( $nodes as $node ) {
				$fragment = self::normalize_text( $node->textContent );
				if ( '' !== $fragment ) {
					$fragments[] = $fragment;
				}
			}
		}
		return $fragments;
	}

	public static function visible_node_text( \DOMElement $element ): string {
		$xpath     = new \DOMXPath( $element->ownerDocument );
		$fragments = array();
		$nodes     = $xpath->query( './/text()[normalize-space() and not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::template) and not(ancestor::*[translate(normalize-space(@aria-hidden), "TRUE", "true") = "true"])]', $element );
		if ( $nodes instanceof \DOMNodeList ) {
			foreach ( $nodes as $node ) {
				$fragment = self::normalize_text( $node->textContent );
				if ( '' !== $fragment ) {
					$fragments[] = $fragment;
				}
			}
		}
		return implode( ' ', $fragments );
	}

	public static function normalize_text( string $text ): string {
		return trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
	}

	/** @return list<string> */
	public function custom_css_limitations( \DOMXPath $xpath ): array {
		$limitations = array();
		if ( str_contains( $this->source_css, '@media' ) ) {
			$limitations[] = 'media_queries';
		}
		if ( preg_match( '/:(?:hover|focus|active|before|after)\b/u', $this->source_css ) ) {
			$limitations[] = 'pseudo_selectors';
		}
		$properties = array( 'box-shadow', 'border-radius', 'transform', 'animation', 'transition', 'position', 'font-family', 'font-weight', 'letter-spacing' );
		foreach ( $this->source->css_rules() as $styles ) {
			$limitations = array_merge( $limitations, array_values( array_intersect( $properties, array_keys( $styles ) ) ) );
		}
		foreach ( EnfoldHtmlSource::elements( $xpath->query( '//*[@style]' ) ) as $element ) {
			$limitations = array_merge( $limitations, array_values( array_intersect( $properties, array_keys( $this->source->styles( $element ) ) ) ) );
		}
		return array_values( array_unique( $limitations ) );
	}

	/** @return list<string> */
	private function rendered_text_fragments( string $alb ): array {
		$fragments = array();
		$alb       = preg_replace_callback(
			'/\[(av_(?:button|contact|contact_field|heading|toggle|tab))\b([^\]]*)\]/u',
			static function ( array $shortcode ) use ( &$fragments ): string {
				if ( preg_match_all( '/\b(heading|label|button|title|options)=(["\'])(.*?)\2/u', $shortcode[2], $attributes, PREG_SET_ORDER ) ) {
					foreach ( $attributes as $attribute ) {
						$values = 'options' === $attribute[1] ? explode( ',', $attribute[3] ) : array( $attribute[3] );
						foreach ( $values as $value ) {
							$decoded = html_entity_decode( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
							$lines   = preg_split( '/\R/u', $decoded );
							foreach ( is_array( $lines ) ? $lines : array() as $line ) {
								$fragment = self::normalize_text( $line );
								if ( '' !== $fragment ) {
									$fragments[] = $fragment;
								}
							}
						}
					}
				}
				return '';
			},
			$alb
		) ?? '';
		$alb       = preg_replace( '/\[\/?av_[^\]]*\]/u', ' ', $alb ) ?? '';
		$document  = self::document( $alb );
		if ( $document instanceof \DOMDocument ) {
			$fragments = array_merge( $fragments, self::visible_text_fragments( new \DOMXPath( $document ) ) );
		}
		return $fragments;
	}

	private static function document( string $html ): ?\DOMDocument {
		$document = new \DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		try {
			$loaded = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		return $loaded ? $document : null;
	}

	/** @return array<string,int> */
	private function summarize_unsupported(): array {
		$summary = array();
		foreach ( $this->unsupported as $item ) {
			$tag             = (string) ( $item['tag'] ?? 'unknown' );
			$summary[ $tag ] = ( $summary[ $tag ] ?? 0 ) + 1;
		}
		ksort( $summary );
		return $summary;
	}

	/** @return list<array{tag:string,reason:string}> */
	private static function intentionally_omitted_elements(): array {
		return array(
			array(
				'tag'    => 'script',
				'reason' => 'Executable source is never compiled.',
			),
			array(
				'tag'    => 'style',
				'reason' => 'Styles are analyzed but not emitted as executable page content.',
			),
			array(
				'tag'    => 'noscript',
				'reason' => 'Fallback script content is intentionally omitted.',
			),
			array(
				'tag'    => 'template',
				'reason' => 'Inactive template fragments are intentionally omitted.',
			),
		);
	}
}
