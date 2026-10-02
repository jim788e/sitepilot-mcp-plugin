<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM exposes camelCase properties.

/** Converts bounded, untrusted HTML into editable native Enfold ALB elements. */
final class EnfoldHtmlCompiler {
	private const MAX_HTML_BYTES = 2_000_000;
	private const MAX_CSS_BYTES  = 500_000;
	private const MAX_ELEMENTS   = 1500;

	/** @var array<string,int> */
	private array $generated = array();
	/** @var array<string,mixed> */
	private array $media = array();
	/** @var array<string,string> */
	private array $links = array();
	/** @var list<array<string,mixed>> */
	private array $unsupported = array();
	/** @var list<string> */
	private array $missing_text = array();
	/** @var array<int,string> */
	private array $original_paths = array();
	private EnfoldAssetResolver $asset_resolver;
	private EnfoldHtmlDiagnostics $diagnostics;
	private EnfoldHtmlCompositeParser $composites;
	private EnfoldHtmlNormalizer $normalizer;
	private EnfoldHtmlSanitizer $sanitizer;
	private EnfoldHtmlSource $source;
	private string $scope_class = '';

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function compile( array $input ) {
		$html = (string) ( $input['source_html'] ?? '' );
		$css  = (string) ( $input['source_css'] ?? '' );
		if ( '' === trim( $html ) || strlen( $html ) > self::MAX_HTML_BYTES ) {
			return new \WP_Error( 'sitepilot_enfold_html_invalid', __( 'Source HTML is required and must not exceed 2 MB.', 'sitepilot-mcp' ) );
		}
		if ( strlen( $css ) > self::MAX_CSS_BYTES ) {
			return new \WP_Error( 'sitepilot_enfold_css_invalid', __( 'Source CSS must not exceed 500 KB.', 'sitepilot-mcp' ) );
		}
		if ( preg_match_all( '#<style\b[^>]*>(.*?)</style>#isu', $html, $embedded_styles ) ) {
			$css .= "\n" . implode( "\n", $embedded_styles[1] ?? array() );
			if ( strlen( $css ) > self::MAX_CSS_BYTES ) {
				return new \WP_Error( 'sitepilot_enfold_css_invalid', __( 'Combined source CSS must not exceed 500 KB.', 'sitepilot-mcp' ) );
			}
		}
		if ( ! class_exists( '\\DOMDocument' ) || ! class_exists( '\\DOMXPath' ) ) {
			return new \WP_Error( 'sitepilot_enfold_dom_unavailable', __( 'The PHP DOM extension is required for guarded HTML compilation.', 'sitepilot-mcp' ) );
		}
		if ( true === ( $input['allow_code_block_fallback'] ?? false ) ) {
			return new \WP_Error(
				'sitepilot_enfold_code_block_fallback_disabled',
				__( 'This compiler does not use a Code Block fallback. Supply component mappings or simplify unsupported source structures.', 'sitepilot-mcp' ),
				array(
					'approval_required' => true,
					'fallback_used'     => false,
				)
			);
		}
		$input['source_css'] = $css;
		$preflight           = ( new EnfoldHtmlPreflight() )->inspect( $input );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}

		$source_hash = hash( 'sha256', $html . "\n" . $css );
		$this->reset( $input, $css );
		// libxml's HTML parser may reparent content following SVG foreignObject or
		// script nodes into the unsafe subtree. Remove those executable/HTML-bearing
		// blocks before parsing, then enforce the complete SVG primitive allowlist on
		// the resulting DOM below.
		$document_html = preg_replace( '#<(?:foreignobject|script)\b[^>]*>.*?</(?:foreignobject|script)>#isu', '', $html ) ?? $html;
		$document      = $this->document( $document_html );
		if ( is_wp_error( $document ) ) {
			return $document;
		}
		$xpath                = new \DOMXPath( $document );
		$this->original_paths = $this->normalizer->remember_original_paths( $xpath );
		$this->normalizer->normalize_roots( $xpath );
		$this->normalizer->normalize_nested_layout_groups(
			$xpath,
			fn ( \DOMElement $element ): bool => $this->is_layout_group( $element ),
			fn ( \DOMElement $element ): bool => $this->is_card( $element ),
			fn ( \DOMElement $element ): bool => $this->is_composite_node( $element ),
			fn ( \DOMElement $element ): bool => $this->preserves_structure( $element )
		);
		$valid_links = $this->normalize_links( $xpath );
		if ( is_wp_error( $valid_links ) ) {
			return $valid_links;
		}
		$valid_backgrounds = $this->validate_background_layers( $xpath );
		if ( is_wp_error( $valid_backgrounds ) ) {
			return $valid_backgrounds;
		}
		foreach ( $this->elements( $xpath->query( '//body//svg' ) ) as $svg ) {
			$this->remove_unsafe_svg_subtrees( $svg );
		}
		$source = $this->source_coverage( $xpath );
		if ( $source['dom_elements'] > self::MAX_ELEMENTS ) {
			return new \WP_Error( 'sitepilot_enfold_html_large', __( 'Source HTML contains too many elements for one guarded compilation.', 'sitepilot-mcp' ) );
		}

		$sections = $this->elements( $xpath->query( "//body//header[not(ancestor::section) and not(.//section)] | //body//section[not(ancestor::section)] | //body//main[not(.//section)] | //body//footer[not(ancestor::section) and not(.//section)] | //body/*[not(.//section) and (contains(concat(' ', normalize-space(@class), ' '), ' hero ') or contains(concat(' ', normalize-space(@class), ' '), ' banner ') or contains(concat(' ', normalize-space(@class), ' '), ' masthead '))]" ) );
		$selected = array_fill_keys( array_map( 'spl_object_id', $sections ), true );
		foreach ( $this->elements( $xpath->query( '//body//*' ) ) as $element ) {
			if ( 'hero' !== $this->component_mapping( $element ) || isset( $selected[ spl_object_id( $element ) ] ) || $this->has_selected_ancestor( $element, $selected ) ) {
				continue;
			}
			$sections[]                            = $element;
			$selected[ spl_object_id( $element ) ] = true;
		}
		if ( array() === $sections ) {
			$body = $xpath->query( '//body' )?->item( 0 );
			if ( $body instanceof \DOMElement ) {
				$sections = array( $body );
			}
		}
		$css_report = ( new EnfoldCssCompiler(
			$source_hash,
			$this->media,
			array( 'source_root_selectors' => $this->source_root_selectors( $sections ) )
		) )->compile( $css );
		if ( is_wp_error( $css_report ) ) {
			return $css_report;
		}
		if ( array() !== $css_report['rejected_declarations'] ) {
			$message = __( 'One or more CSS declarations cannot be safely preserved by the page-scoped compiler.', 'sitepilot-mcp' );
			return new \WP_Error(
				'sitepilot_enfold_css_declaration_unsupported',
				$message,
				array(
					'code'                  => 'sitepilot_enfold_css_declaration_unsupported',
					'message'               => $message,
					'rejected_declarations' => $css_report['rejected_declarations'],
					'change_set_created'    => false,
				)
			);
		}
		$this->scope_class = (string) $css_report['scope_class'];
		$alb               = '';
		// Each top-level section's own compiled output is kept alongside the
		// concatenated $alb. Semantic manifest matching is scoped per section
		// (see semantic_manifest()) so that a mismatch inside one section's own
		// output can never consume a generated tag that rightfully belongs to a
		// sibling section.
		$section_outputs = array();
		foreach ( $sections as $section ) {
			$compiled = $this->compile_section( $section );
			if ( is_wp_error( $compiled ) ) {
				return $compiled;
			}
			$section_outputs[] = $compiled;
			$alb              .= $compiled;
		}
		$this->measure_text_coverage( $xpath, $alb );

		$coverage               = $this->coverage_report( $source );
		$coverage['manifest']   = $this->semantic_manifest( $xpath, $sections, $section_outputs );
		$coverage['lost_nodes'] = array_values( array_filter( $coverage['manifest'], static fn ( array $item ): bool => 'lost' === $item['status'] ) );
		$coverage['counts']     = $coverage['categories'];
		$lost                   = $this->lost_categories( $coverage );
		if ( array() !== $coverage['lost_nodes'] ) {
			$lost[] = array(
				'category'   => 'semantic_nodes',
				'expected'   => count( $coverage['manifest'] ),
				'generated'  => count( $coverage['manifest'] ) - count( $coverage['lost_nodes'] ),
				'message'    => 'one or more semantic source nodes have no compatible generated Enfold element',
				'lost_nodes' => $coverage['lost_nodes'],
			);
		}
		$coverage['lost_categories'] = $lost;
		$report                      = EnfoldCompilationReport::build( $coverage, $alb );
		$coverage                    = $report['coverage'];

		$valid = EnfoldAdapter::validate_alb_content( $alb );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$coverage_json = wp_json_encode( $coverage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return array(
			'alb_content'          => $alb,
			'alb_sha256'           => hash( 'sha256', $alb ),
			'source_sha256'        => $source_hash,
			'ir_sha256'            => hash( 'sha256', is_string( $coverage_json ) ? $coverage_json : '' ),
			'css_sha256'           => $css_report['sha256'],
			'scoped_css'           => $css_report['content'],
			'css'                  => $css_report,
			'coverage'             => $coverage,
			'compiled'             => $report['compiled'],
			'unmapped'             => $report['unmapped'],
			'hierarchy_validation' => array(
				'status' => 'passed',
				'errors' => array(),
			),
			'fallback_used'        => false,
			'assets'               => $this->asset_report(),
			'links'                => $this->link_report(),
			'custom_css_needed'    => array(),
		);
	}

	/** @param list<\DOMElement> $sections @return list<string> */
	private function source_root_selectors( array $sections ): array {
		$roots = array();
		foreach ( $sections as $section ) {
			$ancestor = $section->parentNode;
			while ( $ancestor instanceof \DOMElement ) {
				$id = trim( $ancestor->getAttribute( 'id' ) );
				if ( '' !== $id && preg_match( '/^[a-z_][a-z0-9_-]*$/iu', $id ) ) {
					$roots[] = '#' . $id;
				}
				$classes = preg_split( '/\s+/u', trim( $ancestor->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
				foreach ( is_array( $classes ) ? $classes : array() as $class ) {
					if ( preg_match( '/^[a-z_][a-z0-9_-]*$/iu', $class ) ) {
						$roots[] = '.' . $class;
					}
				}
				if ( 'body' === strtolower( $ancestor->tagName ) ) {
					break;
				}
				$ancestor = $ancestor->parentNode;
			}
		}
		return array_values( array_unique( $roots ) );
	}

	/** @param array<string,mixed> $input */
	private function reset( array $input, string $css ): void {
		$this->generated = array_fill_keys( array( 'sections', 'layout_groups', 'cards', 'headings', 'text_blocks', 'images', 'buttons_links', 'form_fields', 'galleries', 'visible_text_fragments' ), 0 );
		$this->source    = new EnfoldHtmlSource( $css, is_array( $input['component_mappings'] ?? null ) ? $input['component_mappings'] : array() );
		$this->media     = is_array( $input['media_mappings'] ?? null ) ? $input['media_mappings'] : array();
		$this->links     = array();
		foreach ( is_array( $input['link_mappings'] ?? null ) ? $input['link_mappings'] : array() as $from => $to ) {
			if ( is_string( $from ) && is_string( $to ) ) {
				$this->links[ $from ] = $to;
			}
		}
		$this->unsupported    = array();
		$this->missing_text   = array();
		$this->original_paths = array();
		$this->asset_resolver = new EnfoldAssetResolver( $this->media );
		$this->diagnostics    = new EnfoldHtmlDiagnostics( $this->generated, $this->unsupported, $this->missing_text, $this->asset_resolver, $this->source, $this->media, $this->links, $css );
		$this->composites     = new EnfoldHtmlCompositeParser( $this->source );
		$this->normalizer     = new EnfoldHtmlNormalizer( $this->source );
		$this->sanitizer      = new EnfoldHtmlSanitizer( $this->asset_resolver, $this->source, $this->generated );
	}

	/** @return \DOMDocument|\WP_Error */
	private function document( string $html ) {
		$document = new \DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		try {
			$loaded = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		return $loaded ? $document : new \WP_Error( 'sitepilot_enfold_html_parse_failed', __( 'Source HTML could not be parsed safely.', 'sitepilot-mcp' ) );
	}

	/** @return array<string,int> */
	private function source_coverage( \DOMXPath $xpath ): array {
		return $this->diagnostics->source_coverage(
			$xpath,
			fn ( \DOMElement $element ): bool => $this->is_layout_group( $element ),
			fn ( \DOMElement $element ): bool => $this->is_composite_node( $element ),
			fn ( \DOMElement $element ): bool => $this->is_card( $element ),
			fn ( \DOMElement $element ): bool => $this->is_gallery( $element )
		);
	}

	/** @return string|\WP_Error */
	private function compile_section( \DOMElement $section ) {
		if ( 'section' === strtolower( $section->tagName ) ) {
			++$this->generated['sections'];
		}
		$styles      = $this->styles( $section );
		$backgrounds = $this->background_urls( $section );
		if ( count( $backgrounds ) > 1 ) {
			return new \WP_Error(
				'sitepilot_enfold_background_layers_unsupported',
				__( 'Layered background images cannot be represented safely by one native Enfold section.', 'sitepilot-mcp' ),
				array(
					'source_path'     => $this->source_path( $section ),
					'background_urls' => $backgrounds,
				)
			);
		}
		$background = $backgrounds[0] ?? '';
		$attributes = array(
			'minimum_height' => $this->is_hero( $section ) ? '75' : '',
			'color'          => '' !== $background ? 'alternate_color' : 'main_color',
			'custom_class'   => trim( $this->classes( $section ) . ' ' . $this->scope_class ),
			'id'             => sanitize_title( $section->getAttribute( 'id' ) ),
			'av_uid'         => '',
			'sc_version'     => '1.0',
		);
		if ( '' !== $background ) {
			$asset = $this->asset_resolver->resolve( $background, '', 'html', $this->source_path( $section ) );
			if ( is_wp_error( $asset ) ) {
				return $asset;
			}
			if ( null !== $asset ) {
				$attributes['src']             = $asset['url'];
				$attributes['attachment']      = (string) $asset['attachment_id'];
				$attributes['attachment_size'] = 'full';
				$attributes['position']        = 'center center';
				$attributes['repeat']          = 'no-repeat';
				$attributes['attach']          = 'scroll';
				++$this->generated['images'];
			}
		}
		if ( isset( $styles['background-color'] ) ) {
			$attributes['custom_bg'] = $this->color( $styles['background-color'] );
		}
		$attributes = array_merge( $attributes, $this->overlay_attributes( $section ) );
		if ( $this->has_native_tab_interaction( $section ) ) {
			$tabs = $this->compile_tabs( $section );
			if ( is_wp_error( $tabs ) ) {
				return $tabs;
			}
			return $this->shortcode( 'av_section', $attributes, $this->column( 'av_one_full', $tabs, true, $section ) );
		}

		$content = '';
		$output  = '';
		$groups  = $this->direct_elements( $section );
		if ( array() === $groups ) {
			return $this->shortcode( 'av_section', $attributes, '' );
		}
		$handled = array();
		foreach ( $groups as $child ) {
			if ( isset( $handled[ spl_object_id( $child ) ] ) ) {
				continue;
			}
			// A preserved source wrapper cannot retain JavaScript behaviour. Prefer a
			// native Enfold tab container when the markup itself proves that a set of
			// controls maps to a set of panels, even if a legacy mapping requested
			// structure preservation for visual fidelity.
			$native_tabs = $this->has_native_tab_interaction( $child );
			$preserves   = $this->preserves_structure( $child ) && ! $native_tabs;
			$config      = $this->component_config( $child );
			$promoted    = ! $preserves && ( ( $this->is_card( $child ) && 'split' === sanitize_key( (string) ( $config['card_layout'] ?? '' ) ) ) || ( 'cards' === $this->component_mapping( $child ) && $this->is_vertical_stack( $child ) ) );
			if ( $promoted ) {
				if ( '' !== $content ) {
					$output .= $this->shortcode( 'av_section', $attributes, $content );
					$content = '';
				}
				$compiled = $this->is_card( $child ) ? $this->compile_card( $child, true ) : $this->compile_layout_group( $child );
			} elseif ( $preserves ) {
				$compiled = $this->preserved_structure_column( $child );
			} elseif ( $this->is_card( $child ) ) {
				$compiled = $this->column( 'av_one_full', $this->compile_card( $child, false ), true, $child, true );
			} elseif ( $this->is_gallery( $child ) ) {
				$compiled = $this->column( 'av_one_full', $this->compile_gallery( $child ), true, $child );
			} elseif ( 'form' === strtolower( $child->tagName ) || $this->is_form_container( $child ) ) {
				$compiled = $this->column( 'av_one_full', $this->compile_form( $child ), true, $child );
			} elseif ( $native_tabs || $this->is_tabs( $child ) ) {
				$compiled = $this->column( 'av_one_full', $this->compile_tabs( $child ), true, $child );
			} elseif ( $this->is_accordion( $child ) ) {
				$compiled = $this->accordion_requires_structure_preservation( $child )
					? $this->preserved_structure_column( $child )
					: $this->column( 'av_one_full', $this->compile_accordion( $child ), true, $child );
			} elseif ( $this->is_layout_group( $child ) ) {
				$compiled = $this->compile_layout_group( $child );
			} else {
				$compiled = $this->column( 'av_one_full', $this->compile_node( $child ), true, $child );
			}
			if ( is_wp_error( $compiled ) ) {
				return $compiled;
			}
			if ( $promoted ) {
				$output .= $compiled;
			} else {
				$content .= $compiled;
			}
		}
		if ( '' !== $content || '' === $output ) {
			$output .= $this->shortcode( 'av_section', $attributes, $content );
		}
		return $output;
	}

	/** @return string|\WP_Error */
	private function compile_layout_group( \DOMElement $group ) {
		++$this->generated['layout_groups'];
		$children = array_values( array_filter( $this->direct_elements( $group ), fn ( \DOMElement $node ): bool => ! in_array( strtolower( $node->tagName ), array( 'script', 'style' ), true ) ) );
		if ( array() === $children ) {
			return '';
		}
		$cards = array_values( array_filter( $children, fn ( \DOMElement $node ): bool => $this->is_card( $node ) ) );
		if ( 'cards' === $this->component_mapping( $group ) && array() !== $cards && count( $cards ) === count( $children ) && $this->is_vertical_stack( $group ) ) {
			$content = '';
			foreach ( $cards as $card ) {
				$compiled = $this->compile_card( $card, true );
				if ( is_wp_error( $compiled ) ) {
					return $compiled;
				}
				$content .= $compiled;
			}
			return $content;
		}

		$count = $this->desktop_columns( $group, count( $children ) );
		$tag   = match ( $count ) {
			2       => 'av_one_half',
			3       => 'av_one_third',
			4       => 'av_one_fourth',
			default => 'av_one_full',
		};
		$content = '';
		foreach ( array_chunk( $children, $count ) as $row ) {
			foreach ( $row as $index => $child ) {
				// A nested layout group or plain wrapper is intentionally flattened
				// via compile_content() rather than re-entering compile_node(): its own
				// column-group output would otherwise be nested inside this column,
				// which Enfold does not allow (column() below still credits the
				// coverage counter for it via is_layout_group($child)). An <img> or
				// <svg> child has no text/element children of its own for
				// compile_content() to walk, so it needs compile_node()'s type-specific
				// handling — img for a native image, svg for a sanitized decorative icon
				// — or its content silently disappears instead of being flattened.
				$leaf_tag = in_array( strtolower( $child->tagName ), array( 'img', 'svg' ), true );
				$body     = $this->is_card( $child ) ? $this->compile_card( $child, false ) : ( $this->is_composite_node( $child ) || $leaf_tag ? $this->compile_node( $child ) : $this->compile_content( $child ) );
				$compiled = $this->column( $tag, $body, 0 === $index, $child, $this->is_card( $child ) );
				if ( is_wp_error( $compiled ) ) {
					return $compiled;
				}
				$content .= $compiled;
			}
		}
		return $content;
	}

	/** @return string|\WP_Error */
	private function compile_card( \DOMElement $card, bool $standalone ) {
		++$this->generated['cards'];
		if ( $this->is_layout_group( $card ) ) {
			++$this->generated['layout_groups'];
		}

		$children = $this->direct_elements( $card );
		$media    = array();
		$copy     = array();
		foreach ( $children as $child ) {
			if ( array() === $media && $this->contains_media( $child ) ) {
				$media[] = $child;
			} else {
				$copy[] = $child;
			}
		}

		if ( $standalone && array() !== $media && array() !== $copy ) {
			$media_content = $this->compile_elements( $media );
			if ( is_wp_error( $media_content ) ) {
				return $media_content;
			}
			$copy_content = $this->compile_elements( $copy );
			if ( is_wp_error( $copy_content ) ) {
				return $copy_content;
			}

			$classes = trim( $this->classes( $card ) . ' sitepilot-card sitepilot-card-split ' . $this->scope_class );
			return $this->shortcode(
				'av_layout_row',
				array(
					'custom_class'    => $classes,
					'mobile_breaking' => 'av-break-at-tablet',
					'av_uid'          => '',
					'sc_version'      => '1.0',
				),
				$this->grid_cell( 'av_cell_three_fifth', $media_content, $media[0], true )
				. $this->grid_cell( 'av_cell_two_fifth', $copy_content, $copy[0], false )
			);
		}

		$content = $this->compile_content( $card, false );
		return $standalone ? $this->shortcode(
			'av_section',
			array(
				'custom_class' => $this->scope_class,
				'av_uid'       => '',
				'sc_version'   => '1.0',
			),
			$this->column( 'av_one_full', $content, true, $card, true )
		) : $content;
	}

	/** @param list<\DOMElement> $elements @return string|\WP_Error */
	private function compile_elements( array $elements ) {
		$content = '';
		foreach ( $elements as $element ) {
			$compiled = $this->compile_node( $element );
			if ( is_wp_error( $compiled ) ) {
				return $compiled;
			}
			$content .= $compiled;
		}
		return $content;
	}

	private function grid_cell( string $tag, string $content, \DOMElement $source, bool $first ): string {
		$styles = $this->styles( $source );
		return $this->shortcode(
			$tag,
			array(
				'first'              => $first ? 'first' : '',
				'vertical_alignment' => 'middle',
				'padding'            => $styles['padding'] ?? '',
				'custom_bg'          => $this->color( $styles['background-color'] ?? '' ),
				'custom_class'       => $this->classes( $source ),
				'av_uid'             => '',
				'sc_version'         => '1.0',
			),
			$content
		);
	}

	/** @return string|\WP_Error */
	private function compile_content( \DOMElement $container, bool $count_card = true ) {
		if ( $count_card && $this->is_card( $container ) ) {
			++$this->generated['cards'];
		}
		$content = '';
		foreach ( $container->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				if ( in_array( strtolower( $child->tagName ), array( 'script', 'style', 'noscript', 'template' ), true ) ) {
					continue;
				}
				$compiled = $this->compile_node( $child );
				if ( is_wp_error( $compiled ) ) {
					return $compiled;
				}
				$content .= $compiled;
			} elseif ( $child instanceof \DOMText && '' !== trim( $child->textContent ) ) {
				++$this->generated['text_blocks'];
				$content .= $this->shortcode( 'av_textblock', $this->base_attributes( $container ), '<p>' . esc_html( trim( $child->textContent ) ) . '</p>' );
			}
		}
		if ( '' === $content && '' !== trim( $container->textContent ) ) {
			++$this->generated['text_blocks'];
			$content = $this->shortcode( 'av_textblock', $this->base_attributes( $container ), '<p>' . esc_html( trim( $container->textContent ) ) . '</p>' );
		}
		return $content;
	}

	/** @return string|\WP_Error */
	private function compile_node( \DOMElement $node ) {
		$tag     = strtolower( $node->tagName );
		$mapping = $this->component_mapping( $node );
		if ( $this->preserves_structure( $node ) ) {
			return $this->compile_preserved_structure( $node );
		}
		if ( '' !== $mapping ) {
			$mapped = match ( $mapping ) {
				'gallery' => $this->compile_gallery( $node ),
				'accordion' => $this->compile_accordion( $node ),
				'tabs' => $this->compile_tabs( $node ),
				'card' => $this->compile_card( $node, false ),
				'grid', 'flex', 'split', 'cards' => $this->compile_layout_group( $node ),
				default => null,
			};
			if ( null !== $mapped ) {
				return $mapped;
			}
		}
		if ( $this->has_native_tab_interaction( $node ) ) {
			return $this->compile_tabs( $node );
		}
		if ( preg_match( '/^h[1-6]$/', $tag ) ) {
			++$this->generated['headings'];
			$attributes            = $this->base_attributes( $node );
			$attributes['heading'] = trim( $node->textContent );
			$attributes['tag']     = $tag;
			$attributes['style']   = 'blockquote modern-quote';
			return $this->shortcode( 'av_heading', $attributes, '' );
		}
		if ( in_array( $tag, array( 'p', 'ul', 'ol', 'blockquote', 'address' ), true ) ) {
			++$this->generated['text_blocks'];
			$this->generated['buttons_links'] += $this->descendant_count( $node, './/a[@href]|.//button' );
			return $this->shortcode( 'av_textblock', $this->base_attributes( $node ), $this->safe_html( $node ) );
		}
		if ( 'img' === $tag ) {
			return $this->compile_image( $node );
		}
		if ( 'svg' === $tag ) {
			// safe_html() keeps only the restricted decorative SVG subset.
			return $this->shortcode( 'av_textblock', $this->base_attributes( $node ), $this->safe_html( $node ) );
		}
		if ( 'a' === $tag || 'button' === $tag ) {
			return $this->compile_button( $node );
		}
		if ( 'form' === $tag || $this->is_form_container( $node ) ) {
			return $this->compile_form( $node );
		}
		if ( $this->is_gallery( $node ) ) {
			return $this->compile_gallery( $node );
		}
		if ( $this->is_accordion( $node ) ) {
			return $this->compile_accordion( $node );
		}
		if ( $this->is_tabs( $node ) ) {
			return $this->compile_tabs( $node );
		}
		if ( $this->is_card( $node ) ) {
			return $this->compile_card( $node, false );
		}
		if ( $this->is_layout_group( $node ) ) {
			return $this->compile_layout_group( $node );
		}
		if ( in_array( $tag, array( 'script', 'style', 'noscript', 'template' ), true ) ) {
			return '';
		}
		return $this->compile_content( $node );
	}

	/** @return string|\WP_Error */
	private function compile_image( \DOMElement $image ) {
		$source = trim( $image->getAttribute( 'src' ) );
		$asset  = $this->asset_resolver->resolve( $source, $image->getAttribute( 'alt' ), 'html', $this->source_path( $image ) );
		if ( is_wp_error( $asset ) ) {
			return $asset;
		}
		if ( null === $asset ) {
			return '';
		}
		if ( $asset['attachment_id'] > 0 ) {
			++$this->generated['images'];
		}
		$attributes                    = $this->base_attributes( $image );
		$attributes['src']             = $asset['url'];
		$attributes['attachment']      = (string) $asset['attachment_id'];
		$attributes['attachment_size'] = 'full';
		$attributes['alt']             = (string) $asset['alt'];
		$attributes['align']           = 'center';
		$attributes['appearance']      = '';
		return $this->shortcode( 'av_image', $attributes, '' );
	}

	/** @return string|\WP_Error */
	private function compile_button( \DOMElement $node ) {
		$href = 'a' === strtolower( $node->tagName ) ? trim( $node->getAttribute( 'href' ) ) : trim( $node->getAttribute( 'data-href' ) );
		$href = $this->links[ $href ] ?? $href;
		if ( '' === $href || '#' === $href ) {
			$target = $node->getAttribute( 'data-target' );
			$href   = '#' . sanitize_title( '' !== $target ? $target : $node->getAttribute( 'aria-controls' ) );
		}
		if ( ! $this->safe_link( $href ) ) {
			return $this->link_error( $href );
		}
		++$this->generated['buttons_links'];
		$attributes             = $this->base_attributes( $node );
		$label                  = trim( $node->textContent );
		$attributes['label']    = '' !== $label ? $label : __( 'Open', 'sitepilot-mcp' );
		$attributes['link']     = 'manually,' . $href;
		$attributes['target']   = '_blank' === $node->getAttribute( 'target' ) ? '_blank' : '';
		$attributes['size']     = 'medium';
		$attributes['position'] = 'left';
		return $this->shortcode( 'av_button', $attributes, '' );
	}

	/** @return string|\WP_Error */
	private function compile_gallery( \DOMElement $node ) {
		$xpath = new \DOMXPath( $node->ownerDocument );
		$ids   = array();
		foreach ( $this->elements( $xpath->query( './/img', $node ) ) as $image ) {
			$asset = $this->asset_resolver->resolve( trim( $image->getAttribute( 'src' ) ), $image->getAttribute( 'alt' ), 'html', $this->source_path( $image ) );
			if ( is_wp_error( $asset ) ) {
				return $asset;
			}
			if ( null === $asset ) {
				continue;
			}
			$ids[] = (string) $asset['attachment_id'];
			if ( $asset['attachment_id'] > 0 ) {
				++$this->generated['images'];
			}
		}
		if ( array() === $ids ) {
			return '';
		}
		++$this->generated['galleries'];
		return $this->shortcode(
			'av_gallery',
			array_merge(
				$this->base_attributes( $node ),
				array(
					'ids'          => implode( ',', $ids ),
					'style'        => 'thumbnails',
					'preview_size' => 'portfolio',
					'columns'      => min( 4, max( 1, count( $ids ) ) ),
				)
			),
			''
		);
	}

	/** @return string|\WP_Error */
	private function compile_accordion( \DOMElement $node ) {
		$items = $this->parse_accordion_items( $node );
		if ( array() === $items ) {
			return '';
		}
		$content          = '';
		$first_open_index = -1;
		foreach ( $items as $index => $item ) {
			if ( ( $item['open'] ?? false ) && -1 === $first_open_index ) {
				$first_open_index = $index;
			}
			$body = '';
			if ( isset( $item['detail_element'] ) ) {
				$detail = $item['detail_element'];
				foreach ( $this->direct_elements( $detail ) as $child ) {
					if ( 'summary' !== strtolower( $child->tagName ) ) {
						$safe = $this->safe_composite_html( $child );
						if ( is_wp_error( $safe ) ) {
							return $safe;
						}
						$body .= $safe;
					}
				}
				$this->generated['text_blocks']   += $this->descendant_count( $detail, './/p' );
				$this->generated['buttons_links'] += $this->descendant_count( $detail, './/a[@href]|.//button' );
			} elseif ( isset( $item['body_element'] ) ) {
				$panel    = $item['body_element'];
				$children = $this->direct_elements( $panel );
				if ( array() !== $children ) {
					foreach ( $children as $child ) {
						$safe = $this->safe_composite_html( $child );
						if ( is_wp_error( $safe ) ) {
							return $safe;
						}
						$body .= $safe;
					}
				} elseif ( '' !== trim( $panel->textContent ) ) {
					$safe = $this->safe_composite_html( $panel );
					if ( is_wp_error( $safe ) ) {
						return $safe;
					}
					$body .= $safe;
				}

				$p_count                         = $this->descendant_count( $panel, 'self::p|.//p' );
				$this->generated['text_blocks'] += $p_count > 0 ? $p_count : ( '' !== trim( $panel->textContent ) ? 1 : 0 );
				if ( ! empty( $item['p_wrapper'] ) ) {
					++$this->generated['text_blocks'];
				}
				$this->generated['buttons_links'] += 1 + $this->descendant_count( $panel, './/a[@href]|.//button' );
			}
			$content .= $this->shortcode(
				'av_toggle',
				array(
					'title'      => $item['title'],
					'tags'       => '',
					'av_uid'     => '',
					'sc_version' => '1.0',
				),
				$body
			);
		}
		$attributes = $this->base_attributes( $node );
		if ( $first_open_index >= 0 ) {
			$attributes['initial'] = (string) $first_open_index;
		}
		return $this->shortcode( 'av_toggle_container', $attributes, $content );
	}

	/** @return string|\WP_Error */
	private function compile_tabs( \DOMElement $node ) {
		$items  = $this->parse_tab_items( $node );
		$prefix = $this->compile_tab_context( $node, $items );
		if ( is_wp_error( $prefix ) ) {
			return $prefix;
		}
		$content = '';
		if ( array() === $items ) {
			foreach ( $this->direct_elements( $node ) as $index => $panel ) {
				$items[] = array(
					'title'         => $panel->getAttribute( 'data-title' ),
					'panel_element' => $panel,
				);
				if ( '' === $items[ $index ]['title'] ) {
					$items[ $index ]['title'] = $panel->getAttribute( 'aria-label' );
				}
			}
		}
		foreach ( $items as $index => $item ) {
			$title = (string) ( $item['title'] ?? '' );
			if ( '' === $title ) {
				/* translators: %d: one-based tab number. */
				$title = sprintf( __( 'Tab %d', 'sitepilot-mcp' ), $index + 1 );
			}
			$panel = $item['panel_element'];
			$body  = $this->safe_tab_body( $panel );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$content                          .= $this->shortcode(
				'av_tab',
				array(
					'title'       => $title,
					'icon_select' => 'no',
					'av_uid'      => '',
					'sc_version'  => '1.0',
				),
				$body
			);
			$this->generated['text_blocks']   += $this->descendant_count( $panel, './/p' );
			$this->generated['buttons_links'] += 1 + $this->descendant_count( $panel, './/a[@href]|.//button' );
		}
		return $prefix . $this->shortcode( 'av_tab_container', $this->base_attributes( $node ), $content );
	}

	/** @return string|\WP_Error */
	private function safe_tab_body( \DOMElement $panel ) {
		$children = $this->direct_elements( $panel );
		if ( array() === $children ) {
			return '<p>' . esc_html( trim( $panel->textContent ) ) . '</p>';
		}
		$body = '';
		foreach ( $children as $child ) {
			$safe = $this->safe_composite_html( $child );
			if ( is_wp_error( $safe ) ) {
				return $safe;
			}
			$body .= $safe;
		}
		return $body;
	}

	/** @return string|\WP_Error */
	private function compile_form( \DOMElement $form ) {
		$xpath   = new \DOMXPath( $form->ownerDocument );
		$content = '';
		$intro   = '';
		foreach ( $this->elements( $xpath->query( './/*', $form ) ) as $descendant ) {
			if ( $this->is_layout_group( $descendant ) ) {
				++$this->generated['layout_groups'];
			}
		}
		foreach ( $this->elements( $xpath->query( './/h1|.//h2|.//h3|.//h4|.//h5|.//h6|.//legend|.//p[not(.//input or .//select or .//textarea or .//button)]|.//small|.//a[@href][not(ancestor::p or ancestor::label)]|.//*[contains(concat(" ", normalize-space(@class), " "), " help ") or contains(concat(" ", normalize-space(@class), " "), " instructions ") or contains(concat(" ", normalize-space(@class), " "), " description ")][not(.//p or .//small or .//a or .//h1 or .//h2 or .//h3 or .//h4 or .//h5 or .//h6)]', $form ) ) as $context ) {
			if ( 'legend' === strtolower( $context->tagName ) ) {
				++$this->generated['text_blocks'];
				$intro .= $this->shortcode( 'av_textblock', $this->base_attributes( $context ), '<p>' . esc_html( trim( $context->textContent ) ) . '</p>' );
				continue;
			}
			$compiled_context = $this->compile_node( $context );
			if ( is_wp_error( $compiled_context ) ) {
				return $compiled_context;
			}
			$intro .= $compiled_context;
		}
		foreach ( $this->elements( $xpath->query( './/input[not(translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="hidden" or translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="submit" or translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="button" or translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="reset" or translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="image")]|.//select|.//textarea', $form ) ) as $field ) {
			$source_type = $field->getAttribute( 'type' );
			$source_type = strtolower( '' !== $source_type ? $source_type : $field->tagName );
			$type        = match ( $source_type ) {
				'checkbox', 'radio' => 'checkbox',
				'select'   => 'select',
				'textarea' => 'textarea',
				default    => 'text',
			};
			$label = $this->field_label( $field, $xpath );
			if ( '' === $label ) {
				$name  = $field->getAttribute( 'name' );
				$label = ucfirst( str_replace( array( '-', '_' ), ' ', '' !== $name ? $name : __( 'Field', 'sitepilot-mcp' ) ) );
			}
			$options = '';
			if ( 'select' === strtolower( $field->tagName ) ) {
				$options = implode(
					',',
					array_map(
						static fn ( \DOMElement $option ): string => str_replace( ',', '&#44;', trim( $option->textContent ) ),
						$this->elements( $xpath->query( './option', $field ) )
					)
				);
			}
			$content .= $this->shortcode(
				'av_contact_field',
				array(
					'label'      => $label,
					'type'       => $type,
					'check'      => 'email' === $source_type ? 'is_email' : ( $field->hasAttribute( 'required' ) ? 'is_empty' : '' ),
					'width'      => $this->field_width( $field ),
					'options'    => $options,
					'av_uid'     => '',
					'sc_version' => '1.0',
				),
				''
			);
			++$this->generated['form_fields'];
		}
		$this->generated['buttons_links'] += $this->descendant_count( $form, './/button|.//input[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="submit"]' );
		$submit                            = $xpath->query( './/button[not(@type) or translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="submit"]|.//input[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="submit"]', $form )?->item( 0 );
		$button                            = '';
		if ( $submit instanceof \DOMElement ) {
			$value  = $submit->getAttribute( 'value' );
			$button = trim( '' !== $value ? $value : $submit->textContent );
		}
		return $intro . $this->shortcode(
			'av_contact',
			array_merge(
				$this->base_attributes( $form ),
				array(
					'button'  => '' !== $button ? $button : __( 'Submit', 'sitepilot-mcp' ),
					'on_send' => '',
					'sent'    => __( 'Thank you.', 'sitepilot-mcp' ),
				)
			),
			$content
		);
	}

	/** @return string|\WP_Error */
	private function column( string $tag, string|\WP_Error $content, bool $first, \DOMElement $source, bool $card = false ) {
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		if ( ! $card && $this->is_layout_group( $source ) && ! $this->is_composite_node( $source ) ) {
			++$this->generated['layout_groups'];
		}
		$styles = $this->styles( $source );
		$attrs  = array(
			'first'              => $first ? 'first' : '',
			'min_height'         => 'av-equal-height-column',
			'vertical_alignment' => 'av-align-top',
			'padding'            => $styles['padding'] ?? ( $card ? '24px' : '' ),
			'custom_bg'          => $this->color( $styles['background-color'] ?? '' ),
			'border'             => isset( $styles['border'] ) ? 'av-border' : '',
			'custom_class'       => $this->classes( $source ) . ( $card ? ' sitepilot-card' : '' ),
			'av_uid'             => '',
			'sc_version'         => '1.0',
		);
		return $this->shortcode( $tag, $attrs, $content );
	}

	/** @return array<string,string> */
	private function base_attributes( \DOMElement $node ): array {
		$styles = $this->styles( $node );
		return array(
			'custom_class' => $this->classes( $node ),
			'color'        => $this->color( $styles['color'] ?? '' ),
			'font_size'    => $this->css_length( $styles['font-size'] ?? '' ),
			'av_uid'       => '',
			'sc_version'   => '1.0',
		);
	}

	/** @param array<string,string|int> $attributes */
	private function shortcode( string $tag, array $attributes, string $content ): string {
		$parts = array();
		foreach ( $attributes as $name => $value ) {
			$parts[] = sanitize_key( (string) $name ) . "='" . $this->attribute( (string) $value ) . "'";
		}
		return '[' . $tag . ( $parts ? ' ' . implode( ' ', $parts ) : '' ) . ']' . $content . '[/' . $tag . ']';
	}

	private function safe_html( \DOMElement $node ): string {
		return $this->sanitizer->safe_html( $node );
	}

	/**
	 * KSES strips unknown SVG tags but may retain their child text. Remove every
	 * unsupported subtree first, so elements such as foreignObject or script
	 * cannot smuggle HTML or executable-looking source into an editable block.
	 */
	private function remove_unsafe_svg_subtrees( \DOMElement $node ): void {
		$this->sanitizer->remove_unsafe_svg_subtrees( $node );
	}

	/** @return string|\WP_Error */
	private function safe_composite_html( \DOMElement $node ) {
		$xpath                        = new \DOMXPath( $node->ownerDocument );
		$this->generated['headings'] += $this->descendant_count( $node, 'self::h1|self::h2|self::h3|self::h4|self::h5|self::h6|.//h1|.//h2|.//h3|.//h4|.//h5|.//h6' );
		// A layout group or card can legitimately live inside preserved composite
		// content (for example an inclusions list inside a tab panel, where
		// Enfold's own shortcode hierarchy has no valid column representation).
		// The structural coverage count still expects it, so credit it here the
		// same way compile_preserved_structure() already does for its own
		// preserved subtree — otherwise safely preserved content would be
		// misreported as lost purely because of how it was compiled.
		foreach ( $this->elements( $xpath->query( 'self::*|.//*', $node ) ) as $element ) {
			if ( 'section' !== strtolower( $element->tagName ) && $this->is_layout_group( $element ) && ! $this->is_composite_node( $element ) ) {
				++$this->generated['layout_groups'];
			}
			if ( $this->is_card( $element ) ) {
				++$this->generated['cards'];
			}
			if ( $this->is_gallery( $element ) ) {
				++$this->generated['galleries'];
			}
		}
		return $this->sanitizer->safe_composite_html( $node );
	}

	/** @return string|\WP_Error */
	private function compile_preserved_structure( \DOMElement $node ) {
		$xpath = new \DOMXPath( $node->ownerDocument );
		// Layout group, card, and gallery matches are counted inside
		// safe_composite_html() itself (shared with the tab/accordion preserved-body
		// paths), so only this function's own extra metrics — background-image URLs
		// — are counted here to avoid crediting the same element twice.
		foreach ( $this->elements( $xpath->query( 'self::*|.//*', $node ) ) as $element ) {
			foreach ( $this->background_urls( $element ) as $source ) {
				if ( array_key_exists( $source, $this->media ) ) {
					++$this->generated['images'];
				}
			}
		}
		$this->generated['text_blocks']   += $this->descendant_count( $node, 'self::p|.//p' );
		$this->generated['buttons_links'] += $this->descendant_count( $node, 'self::a[@href]|self::button|.//a[@href]|.//button' );
		$html                              = $this->safe_composite_html( $node );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		return $this->shortcode(
			'av_textblock',
			array(
				'custom_class' => 'sitepilot-preserved-structure',
				'av_uid'       => '',
				'sc_version'   => '1.0',
			),
			$html
		);
	}

	/** @return string|\WP_Error */
	private function preserved_structure_column( \DOMElement $node ) {
		$content = $this->compile_preserved_structure( $node );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		return $this->shortcode(
			'av_one_full',
			array(
				'first'              => 'first',
				'min_height'         => 'av-equal-height-column',
				'vertical_alignment' => 'av-align-top',
				'padding'            => '',
				'custom_bg'          => '',
				'border'             => '',
				'custom_class'       => 'sitepilot-preserved-structure-column',
				'av_uid'             => '',
				'sc_version'         => '1.0',
			),
			$content
		);
	}

	/** @return true|\WP_Error */
	private function normalize_links( \DOMXPath $xpath ) {
		foreach ( $this->elements( $xpath->query( '//body//a[@href]' ) ) as $anchor ) {
			$source = trim( $anchor->getAttribute( 'href' ) );
			$target = $this->links[ $source ] ?? $source;
			if ( '' === $target || '#' === $target ) {
				continue;
			}
			if ( ! $this->safe_link( $target ) ) {
				return $this->link_error( $source );
			}
			$anchor->setAttribute( 'href', $target );
		}
		return true;
	}

	private function link_error( string $href ): \WP_Error {
		$message = __( 'A source link is unsafe or lacks an internal link mapping.', 'sitepilot-mcp' );
		return new \WP_Error(
			'sitepilot_enfold_link_invalid',
			$message,
			array(
				'code'             => 'sitepilot_enfold_link_invalid',
				'message'          => $message,
				'href'             => $href,
				'accepted_pattern' => '^sitepilot://page/[a-z0-9-]+$',
				'fallback_used'    => false,
			)
		);
	}

	/** @param array<string,int> $source @return array<string,mixed> */
	private function coverage_report( array $source ): array {
		return $this->diagnostics->coverage_report( $source );
	}

	/**
	 * Build coverage associations independently per top-level section.
	 *
	 * @param list<\DOMElement> $sections        Top-level compiled sections, in document order.
	 * @param list<string>      $section_outputs Each section's own compiled ALB output, same order.
	 * @return list<array<string,mixed>>
	 */
	private function semantic_manifest( \DOMXPath $xpath, array $sections, array $section_outputs ): array {
		$manifest = array();
		$in_scope = array();
		foreach ( $sections as $index => $section ) {
			preg_match_all( '/\[(?!\/)(av_[A-Za-z0-9_-]+)(?:\s[^\]]*)?\/?\]/u', $section_outputs[ $index ] ?? '', $tag_matches );
			$generated_tags = $tag_matches[1] ?? array();
			$cursor         = 0;
			$scoped         = array_merge( array( $section ), $this->elements( $xpath->query( './/*', $section ) ) );
			foreach ( $scoped as $element ) {
				// PHP's DOM extension does not guarantee the same element returns an
				// identical spl_object_id() across separate query() calls, so scope
				// tracking is keyed on the element's structural path (stable and
				// unique for a given position once every mutation pass has already
				// completed) rather than object identity.
				$in_scope[ $this->source_path( $element ) ] = true;
				$entry                                      = $this->manifest_entry( $element, $generated_tags, $cursor );
				if ( null !== $entry ) {
					$manifest[] = $entry;
				}
			}
		}
		// An element outside every top-level section's subtree was never passed
		// to compile_section() and so never contributed anything to $alb. Report
		// it honestly as lost — an empty tag list can never accidentally steal a
		// match that belongs to an actually-compiled element elsewhere.
		foreach ( $this->elements( $xpath->query( '//body//*' ) ) as $element ) {
			if ( isset( $in_scope[ $this->source_path( $element ) ] ) ) {
				continue;
			}
			$unmatched_cursor = 0;
			$entry            = $this->manifest_entry( $element, array(), $unmatched_cursor );
			if ( null !== $entry ) {
				$manifest[] = $entry;
			}
		}
		return $manifest;
	}

	/**
	 * @param list<string> $generated_tags Tags available for matching, already scoped to one section.
	 * @return array<string,mixed>|null Null when the element carries no semantic type or is owned elsewhere.
	 */
	private function manifest_entry( \DOMElement $element, array $generated_tags, int &$cursor ): ?array {
		$composite  = $this->composite_ancestor_type( $element );
		$field_type = strtolower( $element->getAttribute( 'type' ) );
		$form_field = 'form' === $composite
			&& in_array( strtolower( $element->tagName ), array( 'input', 'select', 'textarea' ), true )
			&& ! in_array( $field_type, array( 'hidden', 'submit', 'button', 'reset', 'image' ), true );
		if ( '' !== $composite && ! $form_field ) {
			return null;
		}
		$type = $this->semantic_type( $element );
		if ( '' === $type ) {
			return null;
		}
		$normalized_path = $this->source_path( $element );
		$path            = $element->getAttribute( 'data-sitepilot-original-path' );
		$path            = '' !== $path ? $path : ( $this->original_paths[ spl_object_id( $element ) ] ?? $normalized_path );
		$stable_id       = 'node-' . substr( hash( 'sha256', $type . ':' . $path ), 0, 16 );
		$expected        = $this->compatible_tags( $type );
		$found           = null;
		if ( $this->preserves_structure( $element ) || ( $this->is_accordion( $element ) && $this->accordion_requires_structure_preservation( $element ) ) ) {
			$found = 'native-composite';
		} elseif ( in_array( $type, array( 'content_group', 'layout_group' ), true ) ) {
			$found = 'owned-composite';
		} elseif ( 'button' === $type && $element->parentNode instanceof \DOMElement && 'p' === strtolower( $element->parentNode->tagName ) ) {
			$found = 'native-composite';
		} else {
			for ( $index = $cursor, $count = count( $generated_tags ); $index < $count; ++$index ) {
				if ( in_array( $generated_tags[ $index ], $expected, true ) ) {
					$found  = (string) $index;
					$cursor = $index + 1;
					break;
				}
			}
		}
		return array(
			'id'                     => $stable_id,
			'type'                   => $type,
			'source_path'            => $path,
			'normalized_source_path' => $normalized_path,
			'generated_path'         => null !== $found ? $found : null,
			'output_types'           => $expected,
			'edit_surface'           => in_array( $type, array( 'tabs', 'accordion', 'form', 'content_group', 'layout_group' ), true ) || 'native-composite' === $found ? 'native_composite' : 'separate_alb',
			'status'                 => null !== $found ? 'mapped' : 'lost',
			'visible_text'           => function_exists( 'mb_substr' ) ? mb_substr( $this->visible_node_text( $element ), 0, 500 ) : substr( $this->visible_node_text( $element ), 0, 500 ),
			'promotion_reason'       => 'content_group' === $type ? 'nested_section_owned_by_top_level_section' : $this->parent_component_ownership( $element ),
		);
	}

	private function parent_component_ownership( \DOMElement $element ): ?string {
		$parent = $element->parentNode;
		while ( $parent instanceof \DOMElement ) {
			if ( '' !== $this->component_mapping( $parent ) ) {
				return 'owned_by_parent_component';
			}
			$parent = $parent->parentNode;
		}
		return null;
	}

	private function composite_ancestor_type( \DOMElement $element ): string {
		$parent = $element->parentNode;
		while ( $parent instanceof \DOMElement ) {
			if ( $this->preserves_structure( $parent ) ) {
				return 'preserved_structure';
			}
			if ( $this->is_gallery( $parent ) ) {
				return 'gallery';
			}
			if ( $this->has_native_tab_interaction( $parent ) || $this->is_tabs( $parent ) ) {
				return 'tabs';
			}
			if ( $this->is_accordion( $parent ) ) {
				return 'accordion';
			}
			if ( 'form' === strtolower( $parent->tagName ) || $this->is_form_container( $parent ) ) {
				return 'form';
			}
			$parent = $parent->parentNode;
		}
		return '';
	}

	private function semantic_type( \DOMElement $element ): string {
		$tag = strtolower( $element->tagName );
		if ( 'section' === $tag ) {
			return $this->has_section_ancestor( $element ) ? 'content_group' : 'section';
		}
		if ( 'form' === $tag || $this->is_form_container( $element ) ) {
			return 'form';
		}
		$field_type = strtolower( $element->getAttribute( 'type' ) );
		if ( in_array( $tag, array( 'input', 'select', 'textarea' ), true ) && ! in_array( $field_type, array( 'hidden', 'submit', 'button', 'reset', 'image' ), true ) ) {
			return 'form_field';
		}
		if ( $this->is_gallery( $element ) ) {
			return 'gallery';
		}
		if ( $this->has_native_tab_interaction( $element ) ) {
			return 'tabs';
		}
		if ( $this->is_accordion( $element ) ) {
			return 'accordion';
		}
		if ( $this->is_tabs( $element ) ) {
			return 'tabs';
		}
		if ( $this->is_card( $element ) ) {
			return 'card';
		}
		if ( $this->is_layout_group( $element ) ) {
			return 'layout_group';
		}
		if ( preg_match( '/^h[1-6]$/u', $tag ) ) {
			return 'heading';
		}
		if ( 'p' === $tag ) {
			return 'text';
		}
		if ( 'img' === $tag ) {
			return 'image';
		}
		if ( 'a' === $tag || 'button' === $tag || ( 'input' === $tag && 'submit' === strtolower( $element->getAttribute( 'type' ) ) ) ) {
			return 'button';
		}
		return '';
	}

	private function preserves_structure( \DOMElement $element ): bool {
		return 'preserve' === sanitize_key( (string) ( $this->component_config( $element )['structure_mode'] ?? '' ) );
	}

	/** @return list<string> */
	private function compatible_tags( string $type ): array {
		return match ( $type ) {
			'section'      => array( 'av_section', 'av_layout_row' ),
			'layout_group' => array( 'av_one_full', 'av_one_half', 'av_one_third', 'av_one_fourth', 'av_layout_row' ),
			'card'         => array( 'av_one_full', 'av_one_half', 'av_one_third', 'av_one_fourth', 'av_layout_row' ),
			'heading'      => array( 'av_heading' ),
			'text'         => array( 'av_textblock' ),
			'image'        => array( 'av_image', 'av_gallery' ),
			'button'       => array( 'av_button', 'av_contact' ),
			'form'         => array( 'av_contact' ),
			'form_field'   => array( 'av_contact_field' ),
			'gallery'      => array( 'av_gallery' ),
			'tabs'         => array( 'av_tab_container' ),
			'accordion'    => array( 'av_toggle_container', 'av_toggle' ),
			default        => array(),
		};
	}

	/** @return list<array<string,mixed>> */
	private function asset_report(): array {
		return $this->diagnostics->asset_report();
	}

	/** @return list<array{source:string,target:string}> */
	private function link_report(): array {
		return $this->diagnostics->link_report();
	}

	/** @param array<string,mixed> $coverage @return list<array<string,mixed>> */
	private function lost_categories( array $coverage ): array {
		return $this->diagnostics->lost_categories( $coverage );
	}

	private function measure_text_coverage( \DOMXPath $xpath, string $alb ): void {
		$this->diagnostics->measure_text_coverage( $xpath, $alb );
	}

	private function visible_node_text( \DOMElement $element ): string {
		return EnfoldHtmlDiagnostics::visible_node_text( $element );
	}

	private function has_section_ancestor( \DOMElement $element ): bool {
		$parent = $element->parentNode;
		while ( $parent instanceof \DOMElement ) {
			if ( 'section' === strtolower( $parent->tagName ) ) {
				return true;
			}
			$parent = $parent->parentNode;
		}
		return false;
	}

	/** @param array<int,bool> $selected */
	private function has_selected_ancestor( \DOMElement $element, array $selected ): bool {
		$parent = $element->parentNode;
		while ( $parent instanceof \DOMElement ) {
			if ( isset( $selected[ spl_object_id( $parent ) ] ) ) {
				return true;
			}
			$parent = $parent->parentNode;
		}
		return false;
	}

	private function field_label( \DOMElement $field, \DOMXPath $xpath ): string {
		$label = $field->getAttribute( 'aria-label' );
		$label = trim( '' !== $label ? $label : $field->getAttribute( 'placeholder' ) );
		$id    = $field->getAttribute( 'id' );
		if ( '' === $label && '' !== $id ) {
			foreach ( $this->elements( $xpath->query( '//body//label[@for]' ) ) as $candidate ) {
				if ( hash_equals( $id, $candidate->getAttribute( 'for' ) ) ) {
					$label = trim( $candidate->textContent );
					break;
				}
			}
		}
		if ( '' === $label && $field->parentNode instanceof \DOMElement && 'label' === strtolower( $field->parentNode->tagName ) ) {
			$copy = $field->parentNode->cloneNode( true );
			if ( $copy instanceof \DOMElement ) {
				$copy_xpath = new \DOMXPath( $copy->ownerDocument );
				foreach ( $this->elements( $copy_xpath->query( './/input|.//select|.//textarea|.//button', $copy ) ) as $control ) {
					$control->parentNode?->removeChild( $control );
				}
				$label = trim( $copy->textContent );
			}
		}
		if ( '' === $label ) {
			$sibling = $field->previousSibling;
			while ( $sibling instanceof \DOMText && '' === trim( $sibling->textContent ) ) {
				$sibling = $sibling->previousSibling;
			}
			if ( $sibling instanceof \DOMElement && in_array( strtolower( $sibling->tagName ), array( 'label', 'span', 'strong' ), true ) ) {
				$label = trim( $sibling->textContent );
			}
		}
		return $label;
	}

	private function field_width( \DOMElement $field ): string {
		$classes = strtolower( $field->getAttribute( 'class' ) . ' ' . ( $field->parentNode instanceof \DOMElement ? $field->parentNode->getAttribute( 'class' ) : '' ) );
		$widths  = array(
			'element_three_fourth' => array( 'three-fourth', 'three_fourth', '75' ),
			'element_two_third'    => array( 'two-third', 'two_third', '66' ),
			'element_half'         => array( 'half', '50' ),
			'element_third'        => array( 'third', '33' ),
			'element_fourth'       => array( 'fourth', 'quarter', '25' ),
		);
		foreach ( $widths as $native => $tokens ) {
			foreach ( $tokens as $token ) {
				if ( preg_match( '/(?:^|[\s_-])' . preg_quote( $token, '/' ) . '(?:[\s_% -]|$)/u', $classes ) ) {
					return $native;
				}
			}
		}
		return '';
	}

	/** @return list<string> */
	private function custom_css_limitations( \DOMXPath $xpath ): array {
		return $this->diagnostics->custom_css_limitations( $xpath );
	}

	/** @return array<string,string> */
	private function overlay_attributes( \DOMElement $element ): array {
		$background = $this->styles( $element )['background-image'] ?? $this->styles( $element )['background'] ?? '';
		if ( ! str_contains( strtolower( $background ), 'gradient(' ) ) {
			return array();
		}
		$color   = '#000000';
		$opacity = '50';
		if ( preg_match( '/rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*([01]?(?:\.\d+)?))?\s*\)/iu', $background, $match ) ) {
			$color = sprintf( '#%02x%02x%02x', min( 255, (int) $match[1] ), min( 255, (int) $match[2] ), min( 255, (int) $match[3] ) );
			if ( isset( $match[4] ) && '' !== $match[4] ) {
				$opacity = (string) round( max( 0, min( 1, (float) $match[4] ) ) * 100 );
			}
		} elseif ( preg_match( '/#[0-9a-f]{3,8}\b/iu', $background, $match ) ) {
			$color = $match[0];
		}
		return array(
			'overlay_enable'  => 'aviaTBoverlay_enable',
			'overlay_opacity' => $opacity,
			'overlay_color'   => $color,
		);
	}

	private function is_layout_group( \DOMElement $element ): bool {
		$mapping = $this->component_mapping( $element );
		if ( '' !== $mapping ) {
			return in_array( $mapping, array( 'grid', 'flex', 'split', 'cards' ), true );
		}
		if ( $this->is_card_internal( $element ) ) {
			return false;
		}
		$tag = strtolower( $element->tagName );
		if ( in_array( $tag, array( 'button', 'a', 'summary', 'input', 'select', 'textarea', 'label', 'p', 'span', 'strong', 'em', 'b', 'i', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			return false;
		}
		$styles  = $this->styles( $element );
		$display = strtolower( $styles['display'] ?? '' );
		return in_array( $display, array( 'grid', 'flex', 'inline-flex' ), true ) || $this->has_semantic_class( $element, array( 'grid', 'row', 'column', 'columns', 'cards', 'stack', 'split', 'flex' ) );
	}

	private function is_vertical_stack( \DOMElement $element ): bool {
		$styles = $this->styles( $element );
		return 'column' === strtolower( $styles['flex-direction'] ?? '' ) || $this->has_semantic_class( $element, array( 'stack', 'list', 'vertical' ) );
	}

	private function desktop_columns( \DOMElement $group, int $children ): int {
		$config = $this->component_config( $group );
		if ( isset( $config['desktop_columns'] ) ) {
			return max( 1, min( 4, absint( $config['desktop_columns'] ) ) );
		}
		$template = strtolower( trim( $this->styles( $group )['grid-template-columns'] ?? '' ) );
		if ( preg_match( '/\brepeat\(\s*([2-4])\s*,/u', $template, $repeated ) ) {
			return (int) $repeated[1];
		}
		if ( '' !== $template ) {
			$tracks = preg_split( '/\s+(?![^()]*\))/u', $template, -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $tracks ) && count( $tracks ) >= 2 && count( $tracks ) <= 4 ) {
				return count( $tracks );
			}
		}
		return min( 4, max( 1, $children ) );
	}

	private function contains_media( \DOMElement $element ): bool {
		if ( 'img' === strtolower( $element->tagName ) || '' !== $this->background_url( $element ) ) {
			return true;
		}
		$xpath = new \DOMXPath( $element->ownerDocument );
		return (int) $xpath->evaluate( 'count(.//img)', $element ) > 0;
	}

	private function is_card_internal( \DOMElement $element ): bool {
		$classes = strtolower( $element->getAttribute( 'class' ) );
		if ( ! str_contains( $classes, '__' ) ) {
			return false;
		}
		$parent = $element->parentNode;
		while ( $parent instanceof \DOMElement ) {
			if ( $this->is_card( $parent ) ) {
				return true;
			}
			$parent = $parent->parentNode;
		}
		return false;
	}

	private function is_card( \DOMElement $element ): bool {
		$mapping = $this->component_mapping( $element );
		if ( '' !== $mapping ) {
			return 'card' === $mapping;
		}
		if ( 'article' === strtolower( $element->tagName ) ) {
			return true;
		}
		$classes = preg_split( '/\s+/u', strtolower( $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( is_array( $classes ) ? $classes : array() as $class ) {
			if ( str_contains( $class, '__' ) ) {
				continue;
			}
			$base = preg_split( '/--/u', $class, 2 )[0] ?? '';
			if ( 'card' === $base || (bool) preg_match( '/(?:^|[-_])card$/u', $base ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param list<string> $terms */
	private function has_semantic_class( \DOMElement $element, array $terms ): bool {
		$classes = preg_split( '/\s+/u', strtolower( $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( is_array( $classes ) ? $classes : array() as $class ) {
			$base  = preg_split( '/(?:__|--)/u', $class, 2 )[0] ?? '';
			$parts = preg_split( '/[-_]+/u', $base, -1, PREG_SPLIT_NO_EMPTY );
			if ( array_intersect( $terms, is_array( $parts ) ? $parts : array() ) ) {
				return true;
			}
		}
		return false;
	}

	private function is_hero( \DOMElement $element ): bool {
		$mapping = $this->component_mapping( $element );
		return '' !== $mapping ? 'hero' === $mapping : (bool) preg_match( '/(?:^|[\s_-])(?:hero|banner|masthead)(?:[\s_-]|$)/u', strtolower( $element->getAttribute( 'class' ) ) );
	}

	private function is_gallery( \DOMElement $element ): bool {
		if ( in_array( strtolower( $element->tagName ), array( 'img', 'picture', 'source' ), true ) ) {
			return false;
		}
		$mapping = $this->component_mapping( $element );
		if ( '' !== $mapping ) {
			return 'gallery' === $mapping;
		}
		if ( ! preg_match( '/(?:^|[\s_-])(?:gallery|masonry)(?:[\s_-]|$)/u', strtolower( $element->getAttribute( 'class' ) ) ) ) {
			return false;
		}
		$xpath = new \DOMXPath( $element->ownerDocument );
		return (int) $xpath->evaluate( 'count(.//img)', $element ) > 0;
	}

	private function is_accordion( \DOMElement $element ): bool {
		$mapping = $this->component_mapping( $element );
		if ( '' !== $mapping ) {
			return 'accordion' === $mapping;
		}
		if ( 'details' === strtolower( $element->tagName ) ) {
			return true;
		}
		if ( ! preg_match( '/(?:^|[\s_-])accordion(?:[\s_-]|$)/u', strtolower( $element->getAttribute( 'class' ) ) ) ) {
			return false;
		}
		return count( $this->parse_accordion_items( $element ) ) > 0;
	}

	/**
	 * @return list<array{
	 *   title: string,
	 *   open: bool,
	 *   detail_element?: \DOMElement,
	 *   button_element?: \DOMElement,
	 *   body_element?: \DOMElement
	 * }>
	 */
	private function parse_accordion_items( \DOMElement $container ): array {
		return $this->composites->accordion_items( $container );
	}

	/** @return list<array{title:string,open:bool,control_element?:\DOMElement,panel_element:\DOMElement}> */
	private function parse_tab_items( \DOMElement $container ): array {
		return $this->composites->tab_items( $container );
	}

	private function has_native_tab_interaction( \DOMElement $element ): bool {
		return $this->composites->has_tabs( $element );
	}

	/** @param list<array{title:string,open:bool,control_element?:\DOMElement,panel_element:\DOMElement}> $items @return string|\WP_Error */
	private function compile_tab_context( \DOMElement $container, array $items ) {
		if ( array() === $items ) {
			return '';
		}
		$owned = array();
		foreach ( $items as $item ) {
			foreach ( array( $item['control_element'] ?? null, $item['panel_element'] ) as $element ) {
				if ( $element instanceof \DOMElement ) {
					// Keyed on the element's structural path rather than
					// spl_object_id(): PHP's DOM extension does not guarantee the
					// same node returns an identical object id across separate
					// query() calls, and contains_owned_element() below queries
					// through a freshly created DOMXPath instance.
					$owned[ $this->source_path( $element ) ] = true;
				}
			}
		}
		return $this->compile_composite_context_children( $container, $owned );
	}

	/** @param array<string,bool> $owned @return string|\WP_Error */
	private function compile_composite_context_children( \DOMElement $container, array $owned ) {
		$content = '';
		foreach ( $this->direct_elements( $container ) as $child ) {
			if ( isset( $owned[ $this->source_path( $child ) ] ) ) {
				continue;
			}
			if ( $this->contains_owned_element( $child, $owned ) ) {
				$nested = $this->compile_composite_context_children( $child, $owned );
				if ( is_wp_error( $nested ) ) {
					return $nested;
				}
				$content .= $nested;
				continue;
			}
			$compiled = $this->compile_node( $child );
			if ( is_wp_error( $compiled ) ) {
				return $compiled;
			}
			$content .= $compiled;
		}
		return $content;
	}

	/** @param array<string,bool> $owned */
	private function contains_owned_element( \DOMElement $candidate, array $owned ): bool {
		if ( isset( $owned[ $this->source_path( $candidate ) ] ) ) {
			return true;
		}
		$xpath = new \DOMXPath( $candidate->ownerDocument );
		foreach ( $this->elements( $xpath->query( './/*', $candidate ) ) as $descendant ) {
			if ( isset( $owned[ $this->source_path( $descendant ) ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Enfold's native toggle element cannot retain the source classes that style
	 * complex panels. Preserve the safe HTML structure when a panel owns a grid,
	 * card, gallery, tabs, nested accordion, or form so its interaction and CSS
	 * remain visually faithful and editable in one native text-block surface.
	 */
	private function accordion_requires_structure_preservation( \DOMElement $element ): bool {
		$items = $this->parse_accordion_items( $element );
		foreach ( $items as $item ) {
			if ( isset( $item['detail_element'] ) ) {
				foreach ( $this->direct_elements( $item['detail_element'] ) as $child ) {
					if ( 'summary' === strtolower( $child->tagName ) ) {
						continue;
					}
					if ( $this->is_layout_group( $child ) || $this->is_card( $child ) || $this->is_composite_node( $child ) ) {
						return true;
					}
				}
			} elseif ( isset( $item['body_element'] ) ) {
				$panel = $item['body_element'];
				if ( $this->is_layout_group( $panel ) || $this->is_card( $panel ) || $this->is_composite_node( $panel ) ) {
					return true;
				}
				foreach ( $this->direct_elements( $panel ) as $child ) {
					if ( $this->is_layout_group( $child ) || $this->is_card( $child ) || $this->is_composite_node( $child ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	private function is_tabs( \DOMElement $element ): bool {
		$mapping = $this->component_mapping( $element );
		if ( '' !== $mapping ) {
			return 'tabs' === $mapping;
		}
		return 'tablist' === strtolower( $element->getAttribute( 'role' ) );
	}

	private function is_form_container( \DOMElement $element ): bool {
		$mapping = $this->component_mapping( $element );
		if ( '' !== $mapping ) {
			return 'form' === $mapping;
		}
		if ( 'form' === strtolower( $element->tagName ) ) {
			return true;
		}
		$xpath = new \DOMXPath( $element->ownerDocument );
		return 0 === (int) $xpath->evaluate( 'count(.//form)', $element )
			&& (int) $xpath->evaluate( 'count(./input|./select|./textarea|./label[.//input or .//select or .//textarea]|./fieldset[.//input or .//select or .//textarea])', $element ) > 0;
	}

	private function is_composite_node( \DOMElement $element ): bool {
		return $this->is_gallery( $element )
			|| $this->has_native_tab_interaction( $element )
			|| $this->is_accordion( $element )
			|| $this->is_tabs( $element )
			|| $this->is_form_container( $element );
	}

	private function component_mapping( \DOMElement $element ): string {
		return $this->source->component_mapping( $element );
	}

	/** @return array<string,mixed> */
	private function component_config( \DOMElement $element ): array {
		return $this->source->component_config( $element );
	}

	/** @return array<string,string> */
	private function styles( \DOMElement $element ): array {
		return $this->source->styles( $element );
	}

	private function background_url( \DOMElement $element ): string {
		return $this->background_urls( $element )[0] ?? '';
	}

	/** @return list<string> */
	private function background_urls( \DOMElement $element ): array {
		return $this->source->background_urls( $element );
	}

	/** @return true|\WP_Error */
	private function validate_background_layers( \DOMXPath $xpath ) {
		foreach ( $this->elements( $xpath->query( '//body//*' ) ) as $element ) {
			$urls = $this->background_urls( $element );
			if ( count( $urls ) > 1 ) {
				return new \WP_Error(
					'sitepilot_enfold_background_layers_unsupported',
					__( 'Layered background images cannot be represented safely by one native Enfold element.', 'sitepilot-mcp' ),
					array(
						'source_path'     => $this->source_path( $element ),
						'background_urls' => $urls,
					)
				);
			}
		}
		return true;
	}

	private function source_path( \DOMElement $element ): string {
		return $this->source->source_path( $element );
	}

	private function classes( \DOMElement $element ): string {
		return $this->source->classes( $element );
	}

	private function safe_link( string $link ): bool {
		return EnfoldHtmlSource::safe_link( $link );
	}

	private function color( string $value ): string {
		return EnfoldHtmlSource::color( $value );
	}

	private function css_length( string $value ): string {
		return EnfoldHtmlSource::css_length( $value );
	}

	private function attribute( string $value ): string {
		return EnfoldHtmlSource::attribute( $value );
	}

	private function descendant_count( \DOMElement $element, string $query ): int {
		return EnfoldHtmlSource::descendant_count( $element, $query );
	}

	/** @return list<\DOMElement> */
	private function direct_elements( \DOMElement $container ): array {
		return EnfoldHtmlSource::direct_elements( $container );
	}

	/** @return list<\DOMElement> */
	private function elements( \DOMNodeList|false|null $nodes ): array {
		return EnfoldHtmlSource::elements( $nodes );
	}
}
