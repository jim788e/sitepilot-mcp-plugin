<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM exposes camelCase properties.

/**
 * Maps source CSS declarations onto native Elementor element settings.
 *
 * Enfold compiles CSS into a scoped stylesheet because ALB shortcodes carry only
 * attributes. Elementor stores presentation inside each element's `settings`, so
 * this compiler resolves the declarations that apply to a node and translates the
 * supported subset into Elementor's own control values. Anything not translatable
 * is reported rather than silently dropped, so the caller can decide whether the
 * design survived.
 */
final class ElementorCssCompiler {

	/** Declarations this compiler knows how to express as Elementor settings. */
	public const SUPPORTED_PROPERTIES = array(
		'color',
		'background-color',
		'font-size',
		'font-weight',
		'font-family',
		'font-style',
		'line-height',
		'letter-spacing',
		'text-align',
		'text-transform',
		'text-decoration',
		'padding',
		'padding-top',
		'padding-right',
		'padding-bottom',
		'padding-left',
		'margin',
		'margin-top',
		'margin-right',
		'margin-bottom',
		'margin-left',
		'border-radius',
		'border-width',
		'border-style',
		'border-color',
		'display',
		'flex-direction',
		'justify-content',
		'align-items',
		'gap',
		'max-width',
		'width',
		'min-height',
		'opacity',
	);

	/** Declarations deliberately ignored: layout Elementor derives itself, or unsafe. */
	private const IGNORED_PROPERTIES = array(
		'box-sizing',
		'position',
		'top',
		'right',
		'bottom',
		'left',
		'z-index',
		'overflow',
		'overflow-x',
		'overflow-y',
		'cursor',
		'transition',
		'transform',
		'list-style',
		'list-style-type',
		'text-rendering',
		'-webkit-font-smoothing',
		'content',
		'visibility',
		'float',
		'clear',
		'white-space',
		'word-break',
		'flex',
		'flex-wrap',
		'flex-grow',
		'flex-shrink',
		'flex-basis',
		'grid-template-columns',
		'grid-gap',
		'box-shadow',
		'background',
		'background-image',
		'background-size',
		'background-position',
		'background-repeat',
	);

	/** @var array<string,array<string,string>> */
	private array $rules;

	/** @var list<array{selector:string,property:string}> */
	private array $unsupported = array();

	/** @param array<string,array<string,string>> $rules Token-keyed declaration map. */
	public function __construct( array $rules = array() ) {
		$this->rules = $rules;
	}

	/**
	 * Parse a stylesheet into a token-keyed declaration map.
	 *
	 * Tokens are the trailing compound selector's tag, `.class` and `#id` parts,
	 * matching how EnfoldCssCompiler indexes rules so both compilers resolve the
	 * same declarations for the same node.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function parse( string $css ): array {
		$rules = array();
		$css   = preg_replace( '#/\*.*?\*/#s', '', $css );
		if ( ! is_string( $css ) ) {
			return $rules;
		}
		// Drop at-rule blocks wholesale: media/supports/keyframes cannot map onto a single element.
		$css = preg_replace( '/@[a-z-]+[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/iu', '', $css );
		if ( ! is_string( $css ) ) {
			return $rules;
		}
		if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/u', $css, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$declarations = self::declarations( $match[2] );
				foreach ( explode( ',', $match[1] ) as $selector ) {
					foreach ( self::selector_tokens( strtolower( trim( $selector ) ) ) as $token ) {
						$rules[ $token ] = array_merge( $rules[ $token ] ?? array(), $declarations );
					}
				}
			}
		}
		return $rules;
	}

	/**
	 * Resolve the declarations that apply to a node, inline styles winning last.
	 *
	 * @return array<string,string>
	 */
	public function declarations_for( \DOMElement $element ): array {
		$declarations = array();
		$tag          = strtolower( $element->tagName );
		if ( isset( $this->rules[ $tag ] ) ) {
			$declarations = array_merge( $declarations, $this->rules[ $tag ] );
		}
		$id      = trim( $element->getAttribute( 'id' ) );
		$classes = preg_split( '/\s+/u', trim( $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( is_array( $classes ) ? $classes : array() as $class ) {
			$token = '.' . strtolower( $class );
			if ( isset( $this->rules[ $token ] ) ) {
				$declarations = array_merge( $declarations, $this->rules[ $token ] );
			}
		}
		if ( '' !== $id && isset( $this->rules[ '#' . strtolower( $id ) ] ) ) {
			$declarations = array_merge( $declarations, $this->rules[ '#' . strtolower( $id ) ] );
		}
		$inline = trim( $element->getAttribute( 'style' ) );
		if ( '' !== $inline ) {
			$declarations = array_merge( $declarations, self::declarations( $inline ) );
		}
		return $declarations;
	}

	/**
	 * Translate a node's resolved CSS into Elementor settings.
	 *
	 * @param string $context One of `container`, `heading`, `text`, `button`, `image`.
	 * @return array<string,mixed>
	 */
	public function settings_for( \DOMElement $element, string $context ): array {
		$declarations = $this->declarations_for( $element );
		$settings     = array();
		$typography   = array();
		foreach ( $declarations as $property => $value ) {
			$value = trim( $value );
			if ( '' === $value || in_array( $property, self::IGNORED_PROPERTIES, true ) ) {
				continue;
			}
			if ( ! in_array( $property, self::SUPPORTED_PROPERTIES, true ) ) {
				$this->unsupported[] = array(
					'selector' => $this->describe( $element ),
					'property' => $property,
				);
				continue;
			}
			$this->apply( $property, $value, $context, $settings, $typography );
		}
		if ( array() !== $typography ) {
			$settings = array_merge( $settings, $typography );
			$settings[ $this->typography_prefix( $context ) . '_typography' ] = 'custom';
		}
		return $settings;
	}

	/** @return list<array{selector:string,property:string}> */
	public function unsupported(): array {
		return $this->unsupported;
	}

	/**
	 * @param array<string,mixed> $settings   Settings accumulator.
	 * @param array<string,mixed> $typography Typography accumulator.
	 */
	private function apply( string $property, string $value, string $context, array &$settings, array &$typography ): void {
		$prefix = $this->typography_prefix( $context );
		switch ( $property ) {
			case 'color':
				$color = $this->color( $value );
				if ( null !== $color ) {
					$settings[ $this->color_key( $context ) ] = $color;
				}
				break;
			case 'background-color':
				$color = $this->color( $value );
				if ( null !== $color ) {
					$settings['background_background'] = 'classic';
					$settings['background_color']      = $color;
				}
				break;
			case 'font-size':
				$size = $this->size( $value );
				if ( null !== $size ) {
					$typography[ $prefix . '_font_size' ] = $size;
				}
				break;
			case 'font-weight':
				$typography[ $prefix . '_font_weight' ] = $this->font_weight( $value );
				break;
			case 'font-family':
				$family = trim( explode( ',', $value )[0], " \t\n\r\0\x0B\"'" );
				if ( '' !== $family ) {
					$typography[ $prefix . '_font_family' ] = $family;
				}
				break;
			case 'font-style':
				$typography[ $prefix . '_font_style' ] = strtolower( $value );
				break;
			case 'line-height':
				$height = $this->size( $value, is_numeric( $value ) ? 'em' : null );
				if ( null !== $height ) {
					$typography[ $prefix . '_line_height' ] = $height;
				}
				break;
			case 'letter-spacing':
				$spacing = $this->size( $value );
				if ( null !== $spacing ) {
					$typography[ $prefix . '_letter_spacing' ] = $spacing;
				}
				break;
			case 'text-transform':
				$typography[ $prefix . '_text_transform' ] = strtolower( $value );
				break;
			case 'text-decoration':
				$typography[ $prefix . '_text_decoration' ] = strtolower( explode( ' ', $value )[0] );
				break;
			case 'text-align':
				$align = strtolower( $value );
				if ( in_array( $align, array( 'left', 'center', 'right', 'justify' ), true ) ) {
					$align_key              = 'container' === $context ? 'text_align' : 'align';
					$settings[ $align_key ] = $align;
				}
				break;
			case 'padding':
			case 'padding-top':
			case 'padding-right':
			case 'padding-bottom':
			case 'padding-left':
				$this->box( 'padding', $property, $value, $settings );
				break;
			case 'margin':
			case 'margin-top':
			case 'margin-right':
			case 'margin-bottom':
			case 'margin-left':
				$this->box( 'margin', $property, $value, $settings );
				break;
			case 'border-radius':
				$radius = $this->shorthand_box( $value );
				if ( null !== $radius ) {
					$settings['border_radius'] = $radius;
				}
				break;
			case 'border-width':
				$width = $this->shorthand_box( $value );
				if ( null !== $width ) {
					$settings['border_width'] = $width;
				}
				break;
			case 'border-style':
				$settings['border_border'] = strtolower( $value );
				break;
			case 'border-color':
				$color = $this->color( $value );
				if ( null !== $color ) {
					$settings['border_color'] = $color;
				}
				break;
			case 'display':
				if ( 'flex' === strtolower( $value ) && 'container' === $context ) {
					$settings['flex_direction'] = $settings['flex_direction'] ?? 'row';
				}
				break;
			case 'flex-direction':
				if ( 'container' === $context ) {
					$direction                  = strtolower( $value );
					$settings['flex_direction'] = in_array( $direction, array( 'row', 'column', 'row-reverse', 'column-reverse' ), true ) ? $direction : 'row';
				}
				break;
			case 'justify-content':
				if ( 'container' === $context ) {
					$settings['flex_justify_content'] = strtolower( $value );
				}
				break;
			case 'align-items':
				if ( 'container' === $context ) {
					$settings['flex_align_items'] = strtolower( $value );
				}
				break;
			case 'gap':
				$gap = $this->size( $value );
				if ( null !== $gap && 'container' === $context ) {
					$settings['flex_gap'] = array(
						'unit'     => $gap['unit'],
						'size'     => $gap['size'],
						'column'   => (string) $gap['size'],
						'row'      => (string) $gap['size'],
						'isLinked' => true,
					);
				}
				break;
			case 'max-width':
			case 'width':
				$width = $this->size( $value );
				if ( null !== $width && 'container' === $context ) {
					$settings['content_width'] = 'boxed';
					$settings['width']         = $width;
				}
				break;
			case 'min-height':
				$height = $this->size( $value );
				if ( null !== $height && 'container' === $context ) {
					$settings['min_height'] = $height;
				}
				break;
			case 'opacity':
				if ( is_numeric( $value ) ) {
					$settings['_opacity'] = array(
						'unit' => 'px',
						'size' => (float) $value,
					);
				}
				break;
		}
	}

	/** @param array<string,mixed> $settings Settings accumulator. */
	private function box( string $key, string $property, string $value, array &$settings ): void {
		$existing = is_array( $settings[ $key ] ?? null ) ? $settings[ $key ] : array(
			'unit'     => 'px',
			'top'      => '',
			'right'    => '',
			'bottom'   => '',
			'left'     => '',
			'isLinked' => false,
		);
		if ( $key === $property ) {
			$shorthand = $this->shorthand_box( $value );
			if ( null === $shorthand ) {
				return;
			}
			$settings[ $key ] = $shorthand;
			return;
		}
		$side = substr( $property, strlen( $key ) + 1 );
		$size = $this->size( $value );
		if ( null === $size ) {
			return;
		}
		$existing['unit']     = $size['unit'];
		$existing[ $side ]    = (string) $size['size'];
		$existing['isLinked'] = false;
		$settings[ $key ]     = $existing;
	}

	/**
	 * Expand a 1-4 value CSS shorthand into Elementor's linked box object.
	 *
	 * @return array<string,mixed>|null
	 */
	private function shorthand_box( string $value ): ?array {
		$parts = preg_split( '/\s+/u', trim( $value ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) || array() === $parts || count( $parts ) > 4 ) {
			return null;
		}
		$sizes = array();
		$unit  = 'px';
		foreach ( $parts as $part ) {
			$size = $this->size( $part );
			if ( null === $size ) {
				return null;
			}
			$sizes[] = (string) $size['size'];
			$unit    = $size['unit'];
		}
		$box = match ( count( $sizes ) ) {
			1       => array( $sizes[0], $sizes[0], $sizes[0], $sizes[0] ),
			2       => array( $sizes[0], $sizes[1], $sizes[0], $sizes[1] ),
			3       => array( $sizes[0], $sizes[1], $sizes[2], $sizes[1] ),
			default => array( $sizes[0], $sizes[1], $sizes[2], $sizes[3] ),
		};
		return array(
			'unit'     => $unit,
			'top'      => $box[0],
			'right'    => $box[1],
			'bottom'   => $box[2],
			'left'     => $box[3],
			'isLinked' => 1 === count( $sizes ),
		);
	}

	/** @return array{unit:string,size:float}|null */
	private function size( string $value, ?string $default_unit = null ): ?array {
		$value = trim( $value );
		if ( '0' === $value ) {
			return array(
				'unit' => $default_unit ?? 'px',
				'size' => 0.0,
			);
		}
		if ( 1 !== preg_match( '/^(-?\d*\.?\d+)\s*(px|em|rem|%|vw|vh)?$/u', $value, $match ) ) {
			return null;
		}
		$unit = $match[2] ?? '';
		if ( '' === $unit ) {
			$unit = $default_unit ?? 'px';
		}
		return array(
			'unit' => $unit,
			'size' => (float) $match[1],
		);
	}

	private function color( string $value ): ?string {
		$value = trim( strtolower( $value ) );
		if ( 1 === preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/u', $value ) ) {
			return $value;
		}
		if ( 1 === preg_match( '/^rgba?\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+\s*(?:,\s*[\d.]+\s*)?\)$/u', $value ) ) {
			return $value;
		}
		// Named colours are accepted only from a short allowlist; anything else is
		// reported as unsupported so a design change is never silently guessed.
		return in_array( $value, array( 'black', 'white', 'transparent', 'red', 'blue', 'green', 'gray', 'grey' ), true ) ? $value : null;
	}

	private function font_weight( string $value ): string {
		$value = strtolower( trim( $value ) );
		return match ( $value ) {
			'normal' => '400',
			'bold'   => '700',
			default  => 1 === preg_match( '/^[1-9]00$/u', $value ) ? $value : '400',
		};
	}

	private function color_key( string $context ): string {
		return match ( $context ) {
			'heading' => 'title_color',
			'button'  => 'button_text_color',
			default   => 'text_color',
		};
	}

	private function typography_prefix( string $context ): string {
		return match ( $context ) {
			'heading' => 'typography',
			'button'  => 'typography',
			default   => 'typography',
		};
	}

	private function describe( \DOMElement $element ): string {
		$id      = trim( $element->getAttribute( 'id' ) );
		$classes = trim( $element->getAttribute( 'class' ) );
		if ( '' !== $id ) {
			return '#' . $id;
		}
		if ( '' !== $classes ) {
			return '.' . str_replace( ' ', '.', $classes );
		}
		return strtolower( $element->tagName );
	}

	/** @return list<string> */
	private static function selector_tokens( string $selector ): array {
		$selector = preg_replace( '/:{1,2}[a-z-]+(?:\([^)]*\))?/u', '', $selector );
		if ( ! is_string( $selector ) || '' === trim( $selector ) ) {
			return array();
		}
		$segments = preg_split( '/\s+|>|\+|~/u', trim( $selector ), -1, PREG_SPLIT_NO_EMPTY );
		$compound = is_array( $segments ) && array() !== $segments ? (string) end( $segments ) : '';
		$tokens   = array();
		if ( 1 === preg_match( '/^[a-z][a-z0-9-]*/u', $compound, $tag ) ) {
			$tokens[] = $tag[0];
		}
		if ( preg_match_all( '/[.#][a-z_][a-z0-9_-]*/u', $compound, $matches ) ) {
			$tokens = array_merge( $tokens, $matches[0] );
		}
		return array_values( array_unique( $tokens ) );
	}

	/** @return array<string,string> */
	private static function declarations( string $value ): array {
		$result = array();
		foreach ( explode( ';', $value ) as $declaration ) {
			if ( ! str_contains( $declaration, ':' ) ) {
				continue;
			}
			list($property, $content) = array_map( 'trim', explode( ':', $declaration, 2 ) );
			$property                 = strtolower( $property );
			if ( 1 === preg_match( '/^-?[a-z-]+$/u', $property ) ) {
				$result[ $property ] = trim( str_replace( '!important', '', $content ) );
			}
		}
		return $result;
	}
}
