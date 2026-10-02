<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

/**
 * Parses, validates, rewrites, and scopes residual page CSS.
 *
 * This deliberately uses a stateful CSS tokenizer instead of regular-expression
 * rule parsing so strings, functions, nested media rules, and delimiters remain
 * structurally bounded.
 */
final class EnfoldCssCompiler {
	/** @var list<string> */
	private const ALLOWED_PROPERTIES = array(
		'align-content',
		'align-items',
		'align-self',
		'animation',
		'animation-delay',
		'animation-direction',
		'animation-duration',
		'animation-fill-mode',
		'animation-iteration-count',
		'animation-name',
		'animation-play-state',
		'animation-timing-function',
		'aspect-ratio',
		'background',
		'background-attachment',
		'background-blend-mode',
		'background-clip',
		'background-color',
		'background-image',
		'background-origin',
		'background-position',
		'background-repeat',
		'background-size',
		'border',
		'border-bottom',
		'border-bottom-color',
		'border-bottom-left-radius',
		'border-bottom-right-radius',
		'border-bottom-style',
		'border-bottom-width',
		'border-color',
		'border-left',
		'border-left-color',
		'border-left-style',
		'border-left-width',
		'border-radius',
		'border-right',
		'border-right-color',
		'border-right-style',
		'border-right-width',
		'border-style',
		'border-top',
		'border-top-color',
		'border-top-left-radius',
		'border-top-right-radius',
		'border-top-style',
		'border-top-width',
		'border-width',
		'bottom',
		'box-shadow',
		'box-sizing',
		'color',
		'column-gap',
		'cursor',
		'display',
		'filter',
		'flex',
		'flex-basis',
		'flex-direction',
		'flex-flow',
		'flex-grow',
		'flex-shrink',
		'flex-wrap',
		'font-family',
		'font-size',
		'font-style',
		'font-weight',
		'gap',
		'grid-auto-columns',
		'grid-auto-flow',
		'grid-auto-rows',
		'grid-column',
		'grid-column-end',
		'grid-column-start',
		'grid-row',
		'grid-row-end',
		'grid-row-start',
		'grid-template-columns',
		'grid-template-rows',
		'height',
		'inset',
		'justify-content',
		'justify-items',
		'justify-self',
		'left',
		'letter-spacing',
		'line-height',
		'list-style',
		'list-style-image',
		'list-style-position',
		'list-style-type',
		'margin',
		'margin-bottom',
		'margin-left',
		'margin-right',
		'margin-top',
		'max-height',
		'max-width',
		'min-height',
		'min-width',
		'object-fit',
		'object-position',
		'opacity',
		'order',
		'outline',
		'outline-color',
		'outline-offset',
		'outline-style',
		'outline-width',
		'overflow',
		'overflow-wrap',
		'overflow-x',
		'overflow-y',
		'padding',
		'padding-bottom',
		'padding-left',
		'padding-right',
		'padding-top',
		'place-content',
		'place-items',
		'position',
		'right',
		'row-gap',
		'text-align',
		'text-decoration',
		'text-decoration-color',
		'text-decoration-line',
		'text-decoration-style',
		'text-overflow',
		'text-shadow',
		'text-transform',
		'top',
		'transform',
		'transform-origin',
		'transition',
		'transition-delay',
		'transition-duration',
		'transition-property',
		'transition-timing-function',
		'vertical-align',
		'visibility',
		'white-space',
		'width',
		'word-break',
		'word-spacing',
		'z-index',
	);

	/** @var array<string,mixed> */
	private array $media;
	/** @var list<array{selector:string,property:string,reason:string}> */
	private array $rejected = array();
	/** @var list<array{selector:string,reason:string,properties:list<string>}> */
	private array $intentionally_omitted = array();
	/** @var list<string> */
	private array $source_roots = array();
	/** @var list<string> */
	private array $breakpoints = array();
	private int $rules         = 0;
	private string $scope;

	/** @param array<string,mixed> $media @param array<string,mixed> $context */
	public function __construct( string $source_hash, array $media, array $context = array() ) {
		$this->scope = 'sitepilot-design-' . substr( $source_hash, 0, 12 );
		$this->media = $media;
		$roots       = is_array( $context['source_root_selectors'] ?? null ) ? $context['source_root_selectors'] : array();
		foreach ( $roots as $root ) {
			if ( is_string( $root ) && preg_match( '/^[.#][a-z_][a-z0-9_-]*$/iu', $root ) ) {
				$this->source_roots[] = $root;
			}
		}
		$this->source_roots = array_values( array_unique( $this->source_roots ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function compile( string $css ) {
		if ( '' === trim( $css ) ) {
			return $this->result( '' );
		}
		if ( preg_match( '#(?:@import\b|expression\s*\(|javascript\s*:|file\s*:|data\s*:|</?style\b)#iu', $css ) ) {
			return new \WP_Error( 'sitepilot_enfold_css_unsafe', __( 'Source CSS contains an unsafe import, expression, protocol, or style boundary.', 'sitepilot-mcp' ) );
		}
		$compiled = $this->compile_rules( $this->strip_comments( $css ) );
		if ( is_wp_error( $compiled ) ) {
			return $compiled;
		}
		return $this->result( $compiled );
	}

	/** @return true|\WP_Error */
	public static function validate_scoped( string $css ) {
		$validator = new self( hash( 'sha256', $css ), array() );
		$offset    = 0;
		return $validator->validate_scoped_rules( $validator->strip_comments( $css ), $offset );
	}

	/** @param array<string,mixed> $styles @return string|\WP_Error */
	public static function compile_element_rule( string $custom_class, array $styles ) {
		if ( ! preg_match( '/^[a-z][a-z0-9_-]{2,80}$/', $custom_class ) ) {
			return new \WP_Error( 'sitepilot_enfold_style_class_invalid', __( 'Element styles require one safe existing custom_class token.', 'sitepilot-mcp' ) );
		}
		if ( count( $styles ) > 64 ) {
			return new \WP_Error( 'sitepilot_enfold_styles_excessive', __( 'An element style update accepts at most 64 CSS properties.', 'sitepilot-mcp' ) );
		}
		$declarations = '';
		foreach ( $styles as $property => $value ) {
			$property = strtolower( (string) $property );
			if ( ! in_array( $property, self::ALLOWED_PROPERTIES, true ) ) {
				return new \WP_Error( 'sitepilot_enfold_style_property_invalid', __( 'That CSS property is not allowed for protected element styles.', 'sitepilot-mcp' ), array( 'property' => $property ) );
			}
			if ( ! is_scalar( $value ) ) {
				return new \WP_Error( 'sitepilot_enfold_style_value_invalid', __( 'Element style values must be scalar.', 'sitepilot-mcp' ), array( 'property' => $property ) );
			}
			$value = trim( (string) $value );
			if ( '' === $value || strlen( $value ) > 2000 || preg_match( '#(?:expression\s*\(|javascript\s*:|file\s*:|data\s*:|url\s*\(|[{};])#iu', $value ) ) {
				return new \WP_Error( 'sitepilot_enfold_style_value_invalid', __( 'An element style value is empty, too large, or unsafe.', 'sitepilot-mcp' ), array( 'property' => $property ) );
			}
			$declarations .= $property . ':' . $value . ';';
		}
		return '' === $declarations ? '' : '.' . $custom_class . '{' . $declarations . '}';
	}

	/** @return array<string,mixed> */
	private function result( string $css ): array {
		return array(
			'content'                     => $css,
			'sha256'                      => hash( 'sha256', $css ),
			'scope_class'                 => $this->scope,
			'scoped'                      => true,
			'residual_rules'              => $this->rules,
			'native_properties'           => array(),
			'source_root_selectors'       => $this->source_roots,
			'rejected_declarations'       => $this->rejected,
			'intentionally_omitted_rules' => $this->intentionally_omitted,
			'responsive_breakpoints'      => array_values( array_unique( $this->breakpoints ) ),
		);
	}

	/** @return true|\WP_Error */
	private function validate_scoped_rules( string $css, int &$offset ) {
		$length = strlen( $css );
		while ( $offset < $length ) {
			$this->skip_space( $css, $offset );
			if ( $offset >= $length ) {
				break;
			}
			$block = $this->read_block( $css, $offset );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
			$prelude = trim( $block['prelude'] );
			if ( str_starts_with( strtolower( $prelude ), '@media' ) ) {
				$inner_offset = 0;
				$inner        = $this->validate_scoped_rules( $block['body'], $inner_offset );
				if ( is_wp_error( $inner ) ) {
					return $inner;
				}
				continue;
			}
			if ( str_starts_with( $prelude, '@' ) ) {
				return new \WP_Error( 'sitepilot_enfold_css_at_rule_unsupported', __( 'Only safe @media rules are supported in page-scoped CSS.', 'sitepilot-mcp' ) );
			}
			foreach ( $this->split_top_level( $prelude, ',' ) as $selector ) {
				if ( ! preg_match( '/^\.sitepilot-design-[a-f0-9]{12}(?:\b|(?=[.#:\[]))/u', trim( $selector ) ) ) {
					return new \WP_Error( 'sitepilot_enfold_css_scope_missing', __( 'Every compiled CSS rule must be scoped to its SitePilot design class.', 'sitepilot-mcp' ), array( 'selector' => trim( $selector ) ) );
				}
			}
			foreach ( $this->split_top_level( $block['body'], ';' ) as $declaration ) {
				$declaration = trim( $declaration );
				if ( '' === $declaration ) {
					continue;
				}
				$colon = $this->top_level_delimiter( $declaration, ':' );
				if ( null === $colon ) {
					return new \WP_Error( 'sitepilot_enfold_css_declaration_invalid', __( 'A compiled CSS declaration is malformed.', 'sitepilot-mcp' ) );
				}
				$property = strtolower( trim( substr( $declaration, 0, $colon ) ) );
				$value    = trim( substr( $declaration, $colon + 1 ) );
				if ( ( ! in_array( $property, self::ALLOWED_PROPERTIES, true ) && ! preg_match( '/^--[a-z0-9_-]+$/u', $property ) ) || preg_match( '#(?:expression\s*\(|javascript\s*:|file\s*:|data\s*:|[{}])#iu', $value ) ) {
					return new \WP_Error( 'sitepilot_enfold_css_value_unsafe', __( 'Compiled CSS contains an unsupported property or unsafe value.', 'sitepilot-mcp' ), array( 'property' => $property ) );
				}
				if ( preg_match_all( '/url\(\s*(["\']?)(.*?)\1\s*\)/iu', $value, $urls ) ) {
					foreach ( $urls[2] as $url ) {
						if ( ! preg_match( '#^https?://#iu', trim( $url ) ) ) {
							return new \WP_Error( 'sitepilot_enfold_css_asset_unmapped', __( 'Compiled CSS URLs must be mapped Media Library HTTP URLs.', 'sitepilot-mcp' ) );
						}
					}
				}
			}
		}
		return true;
	}

	/** @return string|\WP_Error */
	private function compile_rules( string $css ) {
		$output = '';
		$offset = 0;
		$length = strlen( $css );
		while ( $offset < $length ) {
			$this->skip_space( $css, $offset );
			if ( $offset >= $length ) {
				break;
			}
			$block = $this->read_block( $css, $offset );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
			$prelude = trim( $block['prelude'] );
			if ( str_starts_with( strtolower( $prelude ), '@media' ) ) {
				$query = trim( substr( $prelude, 6 ) );
				if ( '' === $query || preg_match( '/[^a-z0-9\s():.\-\/,]/iu', $query ) || preg_match( '/(?:url|var)\s*\(/iu', $query ) ) {
					return new \WP_Error( 'sitepilot_enfold_css_at_rule_invalid', __( 'A CSS media query is malformed or unsafe.', 'sitepilot-mcp' ), array( 'query' => $query ) );
				}
				$this->breakpoints[] = $query;
				$inner               = $this->compile_rules( $block['body'] );
				if ( is_wp_error( $inner ) ) {
					return $inner;
				}
				$output .= '@media ' . $query . '{' . $inner . '}';
				continue;
			}
			if ( str_starts_with( $prelude, '@' ) ) {
				return new \WP_Error( 'sitepilot_enfold_css_at_rule_unsupported', __( 'Only safe @media rules are supported in page-scoped CSS.', 'sitepilot-mcp' ), array( 'at_rule' => $prelude ) );
			}
			$selectors = $this->split_top_level( $prelude, ',' );
			$scoped    = array();
			$retained  = array();
			foreach ( $selectors as $selector ) {
				$selector = trim( $selector );
				if ( '' === $selector || preg_match( '/[{}@\\\\]/u', $selector ) || str_contains( strtolower( $selector ), ':has(' ) ) {
					return new \WP_Error( 'sitepilot_enfold_css_selector_unsafe', __( 'A CSS selector cannot be safely scoped to the compiled page.', 'sitepilot-mcp' ), array( 'selector' => $selector ) );
				}
				if ( $this->targets_intentionally_omitted_node( $selector ) ) {
					$this->intentionally_omitted[] = array(
						'selector'   => $selector,
						'reason'     => 'target_node_intentionally_omitted',
						'properties' => $this->declaration_properties( $block['body'] ),
					);
					continue;
				}
				$retained[] = $selector;
				$selector   = $this->normalize_selector_root( $selector );
				$scoped[]   = '.' . $this->scope . ( '' !== $selector ? ' ' . $selector : '' );
				if ( preg_match( '/^[.#\[]/u', $selector ) ) {
					$scoped[] = '.' . $this->scope . $selector;
				}
			}
			if ( array() === $retained ) {
				continue;
			}
			$declarations = $this->compile_declarations( $block['body'], implode( ', ', $retained ) );
			if ( is_wp_error( $declarations ) ) {
				return $declarations;
			}
			if ( '' !== $declarations ) {
				++$this->rules;
				$output .= implode( ',', array_values( array_unique( $scoped ) ) ) . '{' . $declarations . '}';
			}
		}
		return $output;
	}

	private function normalize_selector_root( string $selector ): string {
		$selector = trim( preg_replace( '/^(?:html|body|:root)(?:\s+|$)/iu', '', $selector ) ?? '' );
		foreach ( $this->source_roots as $root ) {
			$pattern = '/^' . preg_quote( $root, '/' ) . '(?=\s|[.#:\[>+~]|$)/iu';
			if ( 1 !== preg_match( $pattern, $selector ) ) {
				continue;
			}
			$selector = trim( preg_replace( $pattern, '', $selector, 1 ) ?? '' );
			$selector = trim( preg_replace( '/^>\s*/u', '', $selector, 1 ) ?? '' );
			break;
		}
		return $selector;
	}

	private function targets_intentionally_omitted_node( string $selector ): bool {
		$without_pseudo = preg_replace( '/:{1,2}[a-z-]+(?:\([^)]*\))?/iu', '', strtolower( $selector ) );
		if ( ! is_string( $without_pseudo ) ) {
			return false;
		}
		$segments = preg_split( '/\s+|>|\+|~/u', trim( $without_pseudo ), -1, PREG_SPLIT_NO_EMPTY );
		$terminal = is_array( $segments ) && array() !== $segments ? (string) end( $segments ) : '';
		$tags     = array( 'svg', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'g', 'use', 'defs', 'symbol', 'mask', 'clipPath' );
		foreach ( $tags as $tag ) {
			if ( preg_match( '/^' . preg_quote( strtolower( $tag ), '/' ) . '(?=[.#\[]|$)/u', $terminal ) ) {
				return true;
			}
		}
		if ( ! preg_match( '/(?:^|[\s>+~])svg(?=[.#\[\s>+~]|$)/u', $without_pseudo, $match, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}
		$offset = (int) $match[0][1] + strlen( (string) $match[0][0] );
		$suffix = substr( $without_pseudo, $offset );
		return ! str_contains( $suffix, '+' ) && ! str_contains( $suffix, '~' );
	}

	/** @return list<string> */
	private function declaration_properties( string $body ): array {
		$properties = array();
		foreach ( $this->split_top_level( $body, ';' ) as $declaration ) {
			$colon = $this->top_level_delimiter( trim( $declaration ), ':' );
			if ( null !== $colon ) {
				$properties[] = strtolower( trim( substr( trim( $declaration ), 0, $colon ) ) );
			}
		}
		return array_values( array_unique( array_filter( $properties ) ) );
	}

	/** @return string|\WP_Error */
	private function compile_declarations( string $body, string $selector ) {
		$output = '';
		foreach ( $this->split_top_level( $body, ';' ) as $declaration ) {
			$declaration = trim( $declaration );
			if ( '' === $declaration ) {
				continue;
			}
			$colon = $this->top_level_delimiter( $declaration, ':' );
			if ( null === $colon ) {
				return new \WP_Error( 'sitepilot_enfold_css_declaration_invalid', __( 'A CSS declaration is malformed.', 'sitepilot-mcp' ), array( 'selector' => $selector ) );
			}
			$property = strtolower( trim( substr( $declaration, 0, $colon ) ) );
			$value    = trim( substr( $declaration, $colon + 1 ) );
			if ( ! in_array( $property, self::ALLOWED_PROPERTIES, true ) && ! preg_match( '/^--[a-z0-9_-]+$/u', $property ) ) {
				$this->rejected[] = array(
					'selector' => $selector,
					'property' => $property,
					'reason'   => 'property_not_allowlisted',
				);
				continue;
			}
			if ( '' === $value || preg_match( '#(?:expression\s*\(|javascript\s*:|file\s*:|data\s*:|[{}])#iu', $value ) ) {
				return new \WP_Error(
					'sitepilot_enfold_css_value_unsafe',
					__( 'A CSS declaration value is malformed or unsafe.', 'sitepilot-mcp' ),
					array(
						'selector' => $selector,
						'property' => $property,
					)
				);
			}
			$rewritten = $this->rewrite_urls( $value, $selector, $property );
			if ( is_wp_error( $rewritten ) ) {
				return $rewritten;
			}
			$output .= $property . ':' . $rewritten . ';';
		}
		return $output;
	}

	/** @return string|\WP_Error */
	private function rewrite_urls( string $value, string $selector, string $property ) {
		$error  = null;
		$result = preg_replace_callback(
			'/url\(\s*(["\']?)(.*?)\1\s*\)/iu',
			function ( array $url_match ) use ( &$error, $selector, $property ): string {
				$source  = trim( html_entity_decode( $url_match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$mapping = $this->media[ $source ] ?? null;
				$id      = is_array( $mapping ) ? absint( $mapping['attachment_id'] ?? 0 ) : absint( $mapping );
				$url     = $id > 0 && function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $id ) : false;
				if ( $id <= 0 || ! is_string( $url ) || ! preg_match( '#^https?://#iu', $url ) ) {
					$error = new \WP_Error(
						'sitepilot_enfold_css_asset_unmapped',
						__( 'Every CSS image URL must map to a valid Media Library attachment.', 'sitepilot-mcp' ),
						array(
							'selector' => $selector,
							'property' => $property,
							'source'   => $source,
						)
					);
					return '';
				}
				return 'url("' . esc_url_raw( $url ) . '")';
			},
			$value
		);
		if ( $error instanceof \WP_Error ) {
			return $error;
		}
		return is_string( $result ) ? $result : new \WP_Error( 'sitepilot_enfold_css_value_invalid', __( 'A CSS value could not be parsed.', 'sitepilot-mcp' ) );
	}

	/** @return array{prelude:string,body:string}|\WP_Error */
	private function read_block( string $css, int &$offset ) {
		$start   = $offset;
		$length  = strlen( $css );
		$quote   = '';
		$paren   = 0;
		$bracket = 0;
		for ( ; $offset < $length; ++$offset ) {
			$char = $css[ $offset ];
			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					++$offset;
				} elseif ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '(' === $char ) {
				++$paren;
			} elseif ( ')' === $char ) {
				--$paren;
			} elseif ( '[' === $char ) {
				++$bracket;
			} elseif ( ']' === $char ) {
				--$bracket;
			} elseif ( '{' === $char && 0 === $paren && 0 === $bracket ) {
				$prelude = substr( $css, $start, $offset - $start );
				++$offset;
				$body = $this->read_balanced_body( $css, $offset );
				return is_wp_error( $body ) ? $body : array(
					'prelude' => $prelude,
					'body'    => $body,
				);
			}
		}
		return new \WP_Error( 'sitepilot_enfold_css_parse_failed', __( 'CSS contains a rule without a balanced block.', 'sitepilot-mcp' ) );
	}

	/** @return string|\WP_Error */
	private function read_balanced_body( string $css, int &$offset ) {
		$start  = $offset;
		$length = strlen( $css );
		$depth  = 1;
		$quote  = '';
		for ( ; $offset < $length; ++$offset ) {
			$char = $css[ $offset ];
			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					++$offset;
				} elseif ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char && 0 === --$depth ) {
				$body = substr( $css, $start, $offset - $start );
				++$offset;
				return $body;
			}
		}
		return new \WP_Error( 'sitepilot_enfold_css_parse_failed', __( 'CSS contains an unbalanced block.', 'sitepilot-mcp' ) );
	}

	/** @return list<string> */
	private function split_top_level( string $value, string $delimiter ): array {
		$parts  = array();
		$start  = 0;
		$quote  = '';
		$depth  = 0;
		$length = strlen( $value );
		for ( $index = 0; $index < $length; ++$index ) {
			$char = $value[ $index ];
			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					++$index;
				} elseif ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '(' === $char || '[' === $char ) {
				++$depth;
			} elseif ( ')' === $char || ']' === $char ) {
				--$depth;
			} elseif ( $delimiter === $char && 0 === $depth ) {
				$parts[] = substr( $value, $start, $index - $start );
				$start   = $index + 1;
			}
		}
		$parts[] = substr( $value, $start );
		return $parts;
	}

	private function top_level_delimiter( string $value, string $delimiter ): ?int {
		$parts = $this->split_top_level( $value, $delimiter );
		return count( $parts ) > 1 ? strlen( $parts[0] ) : null;
	}

	private function strip_comments( string $css ): string {
		$output = '';
		$length = strlen( $css );
		$quote  = '';
		for ( $index = 0; $index < $length; ++$index ) {
			$char = $css[ $index ];
			if ( '' !== $quote ) {
				$output .= $char;
				if ( '\\' === $char && $index + 1 < $length ) {
					$output .= $css[ ++$index ];
				} elseif ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote   = $char;
				$output .= $char;
			} elseif ( '/' === $char && '*' === ( $css[ $index + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $index + 2 );
				if ( false === $end ) {
					return $output;
				}
				$index = $end + 1;
			} else {
				$output .= $char;
			}
		}
		return $output;
	}

	private function skip_space( string $css, int &$offset ): void {
		$length = strlen( $css );
		while ( $offset < $length && preg_match( '/\s/u', $css[ $offset ] ) ) {
			++$offset;
		}
	}
}
