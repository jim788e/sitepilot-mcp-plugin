<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM exposes camelCase properties.

/**
 * Deterministically converts bounded, untrusted HTML into a native Elementor element tree.
 *
 * Mirrors the guarantees of EnfoldHtmlCompiler: it never evaluates source scripts,
 * never fetches source assets, never uploads media, and never falls back to a single
 * opaque HTML block. Every image and background needs an explicit Media Library
 * mapping, every internal link needs a `sitepilot://page/<slug>` mapping, and a
 * compilation that would flatten or drop source structure is rejected rather than
 * staged.
 *
 * Output targets classic Elementor containers (`elType: container`). Elementor 4.x
 * atomic elements are deliberately out of scope; see ElementorElementEditor::ELEMENT_TYPES.
 */
final class ElementorHtmlCompiler {

	private const MAX_HTML_BYTES = 2_000_000;
	private const MAX_CSS_BYTES  = 500_000;
	private const MAX_ELEMENTS   = 1500;

	/** Source tags that are never compiled, and why. */
	private const OMITTED_TAGS = array(
		'script'   => 'Executable source is never compiled.',
		'style'    => 'Styles are analyzed but never emitted as page content.',
		'noscript' => 'Fallback script content is intentionally omitted.',
		'template' => 'Inactive template fragments are intentionally omitted.',
		'svg'      => 'Inline decorative SVG markup is omitted; adjacent visible labels remain editable.',
	);

	/** Source tags with no safe native Elementor mapping. */
	private const UNSUPPORTED_TAGS = array( 'video', 'audio', 'canvas', 'iframe', 'object', 'embed' );

	private ElementorWidgetRegistry $widgets;
	private ElementorElementEditor $editor;
	private ElementorCssCompiler $css;

	/** @var array<string,int> */
	private array $generated = array();
	/** @var array<string,mixed> */
	private array $media = array();
	/** @var array<string,string> */
	private array $links = array();
	/** @var array<string,array<string,mixed>> */
	private array $components = array();
	/** @var list<array{tag:string,reason:string}> */
	private array $unsupported = array();
	/** @var list<string> */
	private array $external_assets = array();
	/** @var list<string> */
	private array $unmapped_assets = array();
	/** @var list<string> */
	private array $unmapped_links = array();
	/** @var list<string> */
	private array $missing_text = array();
	/** @var list<string> */
	private array $taken_ids = array();
	private string $seed     = '';

	public function __construct( ?ElementorWidgetRegistry $widgets = null ) {
		$this->widgets = $widgets ?? new ElementorWidgetRegistry();
		$this->editor  = new ElementorElementEditor( $this->widgets );
		$this->css     = new ElementorCssCompiler();
	}

	/**
	 * @param array<string,mixed> $input Compiler input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function compile( array $input ) {
		$html = (string) ( $input['source_html'] ?? '' );
		$css  = (string) ( $input['source_css'] ?? '' );
		if ( '' === trim( $html ) || strlen( $html ) > self::MAX_HTML_BYTES ) {
			return new \WP_Error( 'sitepilot_elementor_html_invalid', __( 'Source HTML is required and must not exceed 2 MB.', 'sitepilot-mcp' ) );
		}
		if ( preg_match_all( '#<style\b[^>]*>(.*?)</style>#isu', $html, $embedded ) ) {
			$css .= "\n" . implode( "\n", $embedded[1] ?? array() );
		}
		if ( strlen( $css ) > self::MAX_CSS_BYTES ) {
			return new \WP_Error( 'sitepilot_elementor_css_invalid', __( 'Combined source CSS must not exceed 500 KB.', 'sitepilot-mcp' ) );
		}
		if ( ! class_exists( '\DOMDocument' ) || ! class_exists( '\DOMXPath' ) ) {
			return new \WP_Error( 'sitepilot_elementor_dom_unavailable', __( 'The PHP DOM extension is required for guarded HTML compilation.', 'sitepilot-mcp' ) );
		}
		if ( true === ( $input['allow_code_block_fallback'] ?? false ) ) {
			return new \WP_Error(
				'sitepilot_elementor_code_block_fallback_disabled',
				__( 'This compiler never emits a single opaque HTML widget. Supply component mappings or simplify unsupported source structures.', 'sitepilot-mcp' ),
				array( 'fallback_used' => false )
			);
		}

		$source_hash = hash( 'sha256', $html . "\n" . $css );
		$this->reset( $input, $css, $source_hash );

		$document = $this->document( $html );
		if ( is_wp_error( $document ) ) {
			return $document;
		}
		$xpath  = new \DOMXPath( $document );
		$source = $this->source_coverage( $xpath );
		if ( $source['dom_elements'] > self::MAX_ELEMENTS ) {
			return new \WP_Error( 'sitepilot_elementor_html_large', __( 'Source HTML contains too many elements for one guarded compilation.', 'sitepilot-mcp' ) );
		}

		$sections = $this->sections( $xpath );
		$tree     = array();
		foreach ( $sections as $index => $section ) {
			$compiled = $this->compile_section( $section, $index );
			if ( is_wp_error( $compiled ) ) {
				return $compiled;
			}
			$tree[] = $compiled;
		}
		if ( array() === $tree ) {
			return new \WP_Error( 'sitepilot_elementor_html_empty', __( 'Source HTML produced no compilable sections.', 'sitepilot-mcp' ) );
		}

		if ( array() !== $this->unmapped_assets ) {
			return new \WP_Error(
				'sitepilot_elementor_media_unmapped',
				__( 'Every source image and background needs a Media Library mapping before compilation.', 'sitepilot-mcp' ),
				array( 'unmapped_assets' => array_values( array_unique( $this->unmapped_assets ) ) )
			);
		}
		if ( array() !== $this->unmapped_links ) {
			return new \WP_Error(
				'sitepilot_elementor_link_unmapped',
				__( 'Every internal source link must map to sitepilot://page/<target-slug>.', 'sitepilot-mcp' ),
				array( 'unmapped_links' => array_values( array_unique( $this->unmapped_links ) ) )
			);
		}

		$this->measure_text_coverage( $xpath, $tree );
		$coverage                    = $this->coverage_report( $source );
		$lost                        = $this->lost_categories( $coverage );
		$coverage['lost_categories'] = $lost;
		if ( array() !== $lost ) {
			$message = __( 'The HTML-to-Elementor compilation would flatten or omit important source design structures.', 'sitepilot-mcp' );
			return new \WP_Error(
				'sitepilot_elementor_design_coverage_failed',
				$message,
				array(
					'code'                 => 'sitepilot_elementor_design_coverage_failed',
					'message'              => $message,
					'lost_categories'      => $lost,
					'coverage'             => $coverage,
					'unsupported_elements' => $this->unsupported,
					'fallback_used'        => false,
					'change_set_created'   => false,
				)
			);
		}

		$valid = $this->editor->validate_tree( $tree );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$document_json = wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return array(
			'document'        => $tree,
			'document_sha256' => hash( 'sha256', is_string( $document_json ) ? $document_json : '' ),
			'source_sha256'   => $source_hash,
			'coverage'        => $coverage,
			'fallback_used'   => false,
			'assets'          => array(
				'external' => array_values( array_unique( $this->external_assets ) ),
				'mapped'   => array_values( array_map( 'intval', array_filter( $this->media, 'is_scalar' ) ) ),
			),
			'css_unsupported' => $this->css->unsupported(),
		);
	}

	/** @param array<string,mixed> $input Compiler input. */
	private function reset( array $input, string $css, string $source_hash ): void {
		$this->generated       = array_fill_keys( array( 'sections', 'layout_groups', 'cards', 'headings', 'text_blocks', 'images', 'buttons_links', 'galleries', 'visible_text_fragments' ), 0 );
		$this->css             = new ElementorCssCompiler( ElementorCssCompiler::parse( $css ) );
		$this->media           = is_array( $input['media_mappings'] ?? null ) ? $input['media_mappings'] : array();
		$this->links           = array();
		$this->components      = array();
		$this->unsupported     = array();
		$this->external_assets = array();
		$this->unmapped_assets = array();
		$this->unmapped_links  = array();
		$this->missing_text    = array();
		$this->taken_ids       = array();
		$this->seed            = substr( $source_hash, 0, 16 );
		foreach ( is_array( $input['link_mappings'] ?? null ) ? $input['link_mappings'] : array() as $from => $to ) {
			if ( is_string( $from ) && is_string( $to ) ) {
				$this->links[ $from ] = $to;
			}
		}
		foreach ( is_array( $input['component_mappings'] ?? null ) ? $input['component_mappings'] : array() as $selector => $component ) {
			if ( ! is_string( $selector ) ) {
				continue;
			}
			$config = is_string( $component ) ? array( 'type' => sanitize_key( $component ) ) : ( is_array( $component ) ? $component : array() );
			if ( is_string( $config['type'] ?? null ) ) {
				$config['type']                                      = sanitize_key( $config['type'] );
				$this->components[ strtolower( trim( $selector ) ) ] = $config;
			}
		}
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
		return $loaded ? $document : new \WP_Error( 'sitepilot_elementor_html_parse_failed', __( 'Source HTML could not be parsed safely.', 'sitepilot-mcp' ) );
	}

	/**
	 * Pick the top-level layout sections, matching the Enfold compiler's selection.
	 *
	 * @return list<\DOMElement>
	 */
	private function sections( \DOMXPath $xpath ): array {
		$sections = $this->elements(
			$xpath->query(
				'//body//header[not(ancestor::section) and not(.//section)]'
				. ' | //body//section[not(ancestor::section)]'
				. ' | //body//main[not(.//section)]'
				. ' | //body//footer[not(ancestor::section) and not(.//section)]'
			)
		);
		if ( array() !== $sections ) {
			return $sections;
		}
		$body = $xpath->query( '//body' )?->item( 0 );
		return $body instanceof \DOMElement ? array( $body ) : array();
	}

	/** @return array<string,mixed>|\WP_Error */
	private function compile_section( \DOMElement $section, int $index ) {
		++$this->generated['sections'];
		$children = $this->compile_children( $section );
		if ( is_wp_error( $children ) ) {
			return $children;
		}
		$settings = array_merge(
			array(
				'content_width'  => 'boxed',
				'flex_direction' => 'column',
			),
			$this->css->settings_for( $section, 'container' )
		);
		return $this->element( 'container', $settings, $children, 'section:' . $index );
	}

	/**
	 * Compile a node's children into Elementor widgets and nested containers.
	 *
	 * @return list<array<string,mixed>>|\WP_Error
	 */
	private function compile_children( \DOMElement $node ) {
		$elements = array();
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				$text = trim( (string) $child->textContent );
				if ( '' !== $text ) {
					$elements[] = $this->text_widget( $node, $this->escape( $text ) );
					++$this->generated['text_blocks'];
				}
				continue;
			}
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$compiled = $this->compile_node( $child );
			if ( is_wp_error( $compiled ) ) {
				return $compiled;
			}
			foreach ( $compiled as $element ) {
				$elements[] = $element;
			}
		}
		return $elements;
	}

	/**
	 * @return list<array<string,mixed>>|\WP_Error
	 */
	private function compile_node( \DOMElement $node ) {
		$tag = strtolower( $node->tagName );
		if ( isset( self::OMITTED_TAGS[ $tag ] ) ) {
			return array();
		}
		if ( in_array( $tag, self::UNSUPPORTED_TAGS, true ) ) {
			$this->unsupported[] = array(
				'tag'    => $tag,
				'reason' => 'No safe native Elementor mapping is enabled.',
			);
			return array();
		}
		$this->collect_background( $node );

		if ( 1 === preg_match( '/^h[1-6]$/u', $tag ) ) {
			++$this->generated['headings'];
			return array( $this->heading_widget( $node, $tag ) );
		}
		if ( 'p' === $tag ) {
			++$this->generated['text_blocks'];
			return array( $this->text_widget( $node, $this->inner_html( $node ) ) );
		}
		if ( 'img' === $tag ) {
			return $this->image_widget( $node );
		}
		if ( 'picture' === $tag || 'figure' === $tag ) {
			$image = $this->first_descendant( $node, 'img' );
			if ( $image instanceof \DOMElement ) {
				return $this->image_widget( $image );
			}
			return $this->container_from( $node );
		}
		if ( 'a' === $tag || 'button' === $tag ) {
			return $this->button_widget( $node, $tag );
		}
		if ( 'ul' === $tag || 'ol' === $tag ) {
			return $this->list_widget( $node );
		}
		if ( 'br' === $tag || 'hr' === $tag ) {
			return array();
		}
		if ( 'input' === $tag || 'select' === $tag || 'textarea' === $tag || 'form' === $tag || 'label' === $tag ) {
			$this->unsupported[] = array(
				'tag'    => $tag,
				'reason' => 'Native form building requires an explicit form component mapping.',
			);
			return array();
		}
		return $this->container_from( $node );
	}

	/** @return list<array<string,mixed>>|\WP_Error */
	private function container_from( \DOMElement $node ) {
		$children = $this->compile_children( $node );
		if ( is_wp_error( $children ) ) {
			return $children;
		}
		if ( array() === $children ) {
			return array();
		}
		$component = $this->component_mapping( $node );
		if ( 'card' === $component ) {
			++$this->generated['cards'];
		} elseif ( $this->is_layout_group( $node ) ) {
			++$this->generated['layout_groups'];
		} elseif ( 'gallery' === $component ) {
			++$this->generated['galleries'];
		}
		// A wrapper that adds no styling and holds exactly one child is noise:
		// lifting the child keeps the tree editable instead of deeply nested.
		$settings = $this->css->settings_for( $node, 'container' );
		if ( 1 === count( $children ) && array() === $settings && '' === $component ) {
			return $children;
		}
		if ( ! isset( $settings['flex_direction'] ) ) {
			$settings['flex_direction'] = $this->is_layout_group( $node ) ? 'row' : 'column';
		}
		$settings['content_width'] = $settings['content_width'] ?? 'full';
		return array( $this->element( 'container', $settings, $children, $this->source_seed( $node ) ) );
	}

	/** @return array<string,mixed> */
	private function heading_widget( \DOMElement $node, string $tag ): array {
		return $this->widget(
			'heading',
			array_merge(
				array(
					'title'       => $this->escape( trim( (string) $node->textContent ) ),
					'header_size' => $tag,
				),
				$this->css->settings_for( $node, 'heading' )
			),
			$this->source_seed( $node )
		);
	}

	/** @return array<string,mixed> */
	private function text_widget( \DOMElement $node, string $html ): array {
		return $this->widget(
			'text-editor',
			array_merge(
				array( 'editor' => $html ),
				$this->css->settings_for( $node, 'text' )
			),
			$this->source_seed( $node ) . ':text'
		);
	}

	/** @return list<array<string,mixed>> */
	private function image_widget( \DOMElement $node ): array {
		$source = trim( $node->getAttribute( 'src' ) );
		if ( '' === $source ) {
			return array();
		}
		$attachment = $this->attachment( $source );
		if ( null === $attachment ) {
			return array();
		}
		++$this->generated['images'];
		$alt = trim( $node->getAttribute( 'alt' ) );
		if ( '' === $alt && is_array( $this->media[ $source ] ?? null ) ) {
			$alt = (string) ( $this->media[ $source ]['alt'] ?? '' );
		}
		return array(
			$this->widget(
				'image',
				array_merge(
					array(
						'image' => array(
							'id'  => $attachment,
							'url' => wp_get_attachment_url( $attachment ),
							'alt' => $alt,
						),
					),
					$this->css->settings_for( $node, 'image' )
				),
				$this->source_seed( $node )
			),
		);
	}

	/** @return list<array<string,mixed>>|\WP_Error */
	private function button_widget( \DOMElement $node, string $tag ) {
		$text = trim( (string) $node->textContent );
		$href = 'a' === $tag ? trim( $node->getAttribute( 'href' ) ) : '';
		// A link wrapping real structure is a container, not a button.
		if ( 'a' === $tag && ( $node->getElementsByTagName( 'img' )->length > 0 || $this->has_block_child( $node ) ) ) {
			return $this->container_from( $node );
		}
		if ( '' === $text ) {
			return array();
		}
		++$this->generated['buttons_links'];
		$settings = array_merge(
			array( 'text' => $this->escape( $text ) ),
			$this->css->settings_for( $node, 'button' )
		);
		if ( '' !== $href ) {
			$resolved = $this->link( $href );
			if ( null !== $resolved ) {
				$settings['link'] = array(
					'url'         => $resolved,
					'is_external' => $this->is_external( $resolved ) ? 'on' : '',
					'nofollow'    => '',
				);
			}
		}
		return array( $this->widget( 'button', $settings, $this->source_seed( $node ) ) );
	}

	/** @return list<array<string,mixed>> */
	private function list_widget( \DOMElement $node ): array {
		$items = array();
		foreach ( $node->getElementsByTagName( 'li' ) as $item ) {
			$text = trim( (string) $item->textContent );
			if ( '' !== $text ) {
				$items[] = array(
					'text' => $this->escape( $text ),
					'_id'  => $this->identifier( $this->source_seed( $item ) ),
				);
			}
		}
		if ( array() === $items ) {
			return array();
		}
		++$this->generated['text_blocks'];
		return array(
			$this->widget(
				'icon-list',
				array_merge(
					array( 'icon_list' => $items ),
					$this->css->settings_for( $node, 'text' )
				),
				$this->source_seed( $node )
			),
		);
	}

	/**
	 * @param list<array<string,mixed>> $children Child elements.
	 * @param array<string,mixed>       $settings Element settings.
	 * @return array<string,mixed>
	 */
	private function element( string $type, array $settings, array $children, string $seed ): array {
		return array(
			'id'       => $this->identifier( $seed ),
			'elType'   => $type,
			'settings' => $settings,
			'elements' => array_values( $children ),
		);
	}

	/** @param array<string,mixed> $settings Widget settings. @return array<string,mixed> */
	private function widget( string $widget_type, array $settings, string $seed ): array {
		return array(
			'id'         => $this->identifier( $seed ),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	private function identifier( string $seed ): string {
		$id                = $this->editor->generate_id( $this->seed . ':' . $seed, $this->taken_ids );
		$this->taken_ids[] = $id;
		return $id;
	}

	/** Stable, structure-derived seed so recompiling identical HTML yields identical ids. */
	private function source_seed( \DOMElement $node ): string {
		$path    = array();
		$current = $node;
		while ( $current instanceof \DOMElement ) {
			$index   = 0;
			$sibling = $current->previousSibling;
			while ( null !== $sibling ) {
				if ( $sibling instanceof \DOMElement && $sibling->tagName === $current->tagName ) {
					++$index;
				}
				$sibling = $sibling->previousSibling;
			}
			array_unshift( $path, strtolower( $current->tagName ) . '[' . $index . ']' );
			$current = $current->parentNode instanceof \DOMElement ? $current->parentNode : null;
		}
		return implode( '/', $path );
	}

	/** Resolve a source asset path to a mapped attachment id, recording failures. */
	private function attachment( string $source ): ?int {
		if ( $this->is_external( $source ) ) {
			$this->external_assets[] = $source;
			$this->unmapped_assets[] = $source;
			return null;
		}
		$mapping = $this->media[ $source ] ?? null;
		if ( null === $mapping ) {
			// Tolerate a leading ./ or / difference between markup and mapping keys.
			foreach ( array( ltrim( $source, './' ), './' . ltrim( $source, './' ), '/' . ltrim( $source, '/' ) ) as $variant ) {
				if ( isset( $this->media[ $variant ] ) ) {
					$mapping = $this->media[ $variant ];
					break;
				}
			}
		}
		if ( is_array( $mapping ) ) {
			$mapping = $mapping['attachment_id'] ?? null;
		}
		$attachment = absint( $mapping ?? 0 );
		if ( $attachment < 1 || ! wp_attachment_is_image( $attachment ) ) {
			$this->unmapped_assets[] = $source;
			return null;
		}
		return $attachment;
	}

	/** Resolve a source href through the link mappings, recording failures. */
	private function link( string $href ): ?string {
		if ( str_starts_with( $href, '#' ) || str_starts_with( $href, 'mailto:' ) || str_starts_with( $href, 'tel:' ) ) {
			return $href;
		}
		if ( isset( $this->links[ $href ] ) ) {
			return $this->links[ $href ];
		}
		if ( $this->is_external( $href ) ) {
			return $href;
		}
		$this->unmapped_links[] = $href;
		return null;
	}

	private function is_external( string $value ): bool {
		return str_starts_with( $value, 'http://' ) || str_starts_with( $value, 'https://' ) || str_starts_with( $value, '//' );
	}

	/** Record CSS background images so they count toward media coverage. */
	private function collect_background( \DOMElement $node ): void {
		$declarations = $this->css->declarations_for( $node );
		$background   = (string) ( $declarations['background-image'] ?? $declarations['background'] ?? '' );
		if ( '' === $background || ! preg_match_all( '#url\(\s*["\']?([^"\')]+)["\']?\s*\)#iu', $background, $matches ) ) {
			return;
		}
		foreach ( $matches[1] as $source ) {
			$attachment = $this->attachment( trim( $source ) );
			if ( null !== $attachment ) {
				++$this->generated['images'];
			}
		}
	}

	private function has_block_child( \DOMElement $node ): bool {
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && in_array( strtolower( $child->tagName ), array( 'div', 'section', 'article', 'ul', 'ol', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	private function first_descendant( \DOMElement $node, string $tag ): ?\DOMElement {
		$found = $node->getElementsByTagName( $tag )->item( 0 );
		return $found instanceof \DOMElement ? $found : null;
	}

	private function is_layout_group( \DOMElement $node ): bool {
		$declarations = $this->css->declarations_for( $node );
		$display      = strtolower( (string) ( $declarations['display'] ?? '' ) );
		if ( in_array( $display, array( 'flex', 'grid', 'inline-flex' ), true ) ) {
			return true;
		}
		$class = strtolower( $node->getAttribute( 'class' ) );
		return 1 === preg_match( '/\b(row|grid|columns|flex|cards|list)\b/u', $class );
	}

	private function component_mapping( \DOMElement $node ): string {
		$candidates = array( strtolower( $node->tagName ) );
		$id         = trim( $node->getAttribute( 'id' ) );
		if ( '' !== $id ) {
			$candidates[] = '#' . strtolower( $id );
		}
		$classes = preg_split( '/\s+/u', trim( $node->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( is_array( $classes ) ? $classes : array() as $class ) {
			$candidates[] = '.' . strtolower( $class );
		}
		foreach ( $candidates as $candidate ) {
			if ( isset( $this->components[ $candidate ]['type'] ) ) {
				return (string) $this->components[ $candidate ]['type'];
			}
		}
		return '';
	}

	private function inner_html( \DOMElement $node ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$rendered = $node->ownerDocument?->saveHTML( $child );
			if ( is_string( $rendered ) ) {
				$html .= $rendered;
			}
		}
		return wp_kses_post( '' !== trim( $html ) ? $html : $this->escape( (string) $node->textContent ) );
	}

	private function escape( string $value ): string {
		return esc_html( $value );
	}

	/** @return array<string,int> */
	private function source_coverage( \DOMXPath $xpath ): array {
		$layout      = 0;
		$cards       = 0;
		$backgrounds = 0;
		foreach ( $this->elements( $xpath->query( '//body//*' ) ) as $element ) {
			$tag = strtolower( $element->tagName );
			if ( 'section' !== $tag && $this->is_layout_group( $element ) ) {
				++$layout;
			}
			if ( 'card' === $this->component_mapping( $element ) ) {
				++$cards;
			}
			$declarations = $this->css->declarations_for( $element );
			$background   = (string) ( $declarations['background-image'] ?? $declarations['background'] ?? '' );
			if ( '' !== $background && preg_match_all( '#url\(\s*["\']?([^"\')]+)["\']?\s*\)#iu', $background, $matches ) ) {
				$backgrounds += count( $matches[1] );
			}
		}
		return array(
			'dom_elements'           => (int) $xpath->evaluate( 'count(//body//*)' ),
			'sections'               => count( $this->sections( $xpath ) ),
			'layout_groups'          => $layout,
			'cards'                  => $cards,
			'headings'               => (int) $xpath->evaluate( 'count(//body//h1|//body//h2|//body//h3|//body//h4|//body//h5|//body//h6)' ),
			'text_blocks'            => (int) $xpath->evaluate( 'count(//body//p)' ),
			'images'                 => (int) $xpath->evaluate( 'count(//body//img)' ) + $backgrounds,
			'buttons_links'          => (int) $xpath->evaluate( 'count(//body//button|//body//a[@href])' ),
			'galleries'              => 0,
			'visible_text_fragments' => count( $this->visible_text_fragments( $xpath ) ),
		);
	}

	/** @return list<string> */
	private function visible_text_fragments( \DOMXPath $xpath ): array {
		$fragments = array();
		foreach ( $this->elements( $xpath->query( '//body//*' ) ) as $element ) {
			if ( isset( self::OMITTED_TAGS[ strtolower( $element->tagName ) ] ) ) {
				continue;
			}
			foreach ( $element->childNodes as $child ) {
				if ( ! $child instanceof \DOMText ) {
					continue;
				}
				$text = trim( preg_replace( '/\s+/u', ' ', (string) $child->textContent ) ?? '' );
				if ( '' !== $text ) {
					$fragments[] = $text;
				}
			}
		}
		return array_values( array_unique( $fragments ) );
	}

	/** @param list<array<string,mixed>> $tree Compiled element tree. */
	private function measure_text_coverage( \DOMXPath $xpath, array $tree ): void {
		$json      = (string) wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$haystack  = html_entity_decode( wp_strip_all_tags( $json ), ENT_QUOTES, 'UTF-8' );
		$fragments = $this->visible_text_fragments( $xpath );
		foreach ( $fragments as $fragment ) {
			if ( str_contains( $haystack, $fragment ) ) {
				++$this->generated['visible_text_fragments'];
				continue;
			}
			$this->missing_text[] = $fragment;
		}
	}

	/**
	 * @param array<string,int> $source Source-side counts.
	 * @return array<string,mixed>
	 */
	private function coverage_report( array $source ): array {
		$categories = array();
		foreach ( array_keys( $this->generated ) as $category ) {
			$categories[ $category ] = array(
				'source'    => (int) ( $source[ $category ] ?? 0 ),
				'generated' => (int) $this->generated[ $category ],
			);
		}
		return array(
			'source'                => $source,
			'generated'             => $this->generated,
			'categories'            => $categories,
			'unsupported'           => $this->summarize_unsupported(),
			'unsupported_elements'  => $this->unsupported,
			'intentionally_omitted' => array_keys( self::OMITTED_TAGS ),
			'external_assets'       => array_values( array_unique( $this->external_assets ) ),
			'missing_visible_text'  => $this->missing_text,
		);
	}

	/**
	 * @param array<string,mixed> $coverage Coverage report.
	 * @return list<array<string,mixed>>
	 */
	private function lost_categories( array $coverage ): array {
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
		if ( array() !== $this->unsupported ) {
			$lost[] = array(
				'category'  => 'unsupported',
				'expected'  => count( $this->unsupported ),
				'generated' => 0,
				'message'   => 'unsupported interactive or media elements require an explicit component mapping',
			);
		}
		return $lost;
	}

	/** @return array<string,int> */
	private function summarize_unsupported(): array {
		$summary = array();
		foreach ( $this->unsupported as $item ) {
			$summary[ $item['tag'] ] = ( $summary[ $item['tag'] ] ?? 0 ) + 1;
		}
		ksort( $summary );
		return $summary;
	}

	/** @return list<\DOMElement> */
	private function elements( \DOMNodeList|false|null $nodes ): array {
		$result = array();
		if ( $nodes instanceof \DOMNodeList ) {
			foreach ( $nodes as $node ) {
				if ( $node instanceof \DOMElement ) {
					$result[] = $node;
				}
			}
		}
		return $result;
	}
}
