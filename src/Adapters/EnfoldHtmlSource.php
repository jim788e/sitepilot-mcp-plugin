<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM exposes camelCase properties.

/** Query model for compiler CSS, request mappings, and normalized DOM paths. */
final class EnfoldHtmlSource {
	/** @var array<string,array<string,string>> */
	private array $css_rules;
	/** @var array<string,array<string,mixed>> */
	private array $components = array();

	/** @param array<string,mixed> $components */
	public function __construct( string $css, array $components ) {
		$this->css_rules = $this->parse_css( $css );
		foreach ( $components as $selector => $component ) {
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

	/** @return array<string,array<string,string>> */
	public function css_rules(): array {
		return $this->css_rules;
	}

	/** @return array<string,string> */
	public function styles( \DOMElement $element ): array {
		$styles = $this->css_rules[ strtolower( $element->tagName ) ] ?? array();
		$id     = strtolower( $element->getAttribute( 'id' ) );
		if ( '' !== $id ) {
			$styles = array_merge( $styles, $this->css_rules[ '#' . $id ] ?? array() );
		}
		$classes = preg_split( '/\s+/u', strtolower( $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( is_array( $classes ) ? $classes : array() as $class ) {
			$styles = array_merge( $styles, $this->css_rules[ '.' . $class ] ?? array() );
		}
		return array_merge( $styles, self::declarations( $element->getAttribute( 'style' ) ) );
	}

	/** @return list<string> */
	public function background_urls( \DOMElement $element ): array {
		$styles = $this->styles( $element );
		$value  = $styles['background-image'] ?? $styles['background'] ?? '';
		if ( ! preg_match_all( '/url\(\s*["\']?([^"\')]+)["\']?\s*\)/iu', $value, $matches ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', $matches[1] ?? array() ), static fn ( string $url ): bool => '' !== $url ) );
	}

	public function component_mapping( \DOMElement $element ): string {
		return sanitize_key( (string) ( $this->component_config( $element )['type'] ?? '' ) );
	}

	/** @return array<string,mixed> */
	public function component_config( \DOMElement $element ): array {
		$best_match = null;
		$best_score = -1;
		foreach ( $this->components as $selector => $config ) {
			$score = $this->selector_score( $element, $selector );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best_match = $config;
			}
		}
		return is_array( $best_match ) ? $best_match : array();
	}

	public function source_path( \DOMElement $element ): string {
		$parts = array();
		$node  = $element;
		while ( $node instanceof \DOMElement && 'html' !== strtolower( $node->tagName ) ) {
			$index   = 1;
			$sibling = $node->previousSibling;
			while ( $sibling instanceof \DOMNode ) {
				if ( $sibling instanceof \DOMElement && strtolower( $sibling->tagName ) === strtolower( $node->tagName ) ) {
					++$index;
				}
				$sibling = $sibling->previousSibling;
			}
			array_unshift( $parts, strtolower( $node->tagName ) . '[' . $index . ']' );
			$node = $node->parentNode;
		}
		return '/' . implode( '/', $parts );
	}

	public function classes( \DOMElement $element ): string {
		$classes = array();
		$source  = preg_split( '/\s+/u', $element->getAttribute( 'class' ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( is_array( $source ) ? $source : array() as $class ) {
			$class = sanitize_html_class( $class );
			if ( '' !== $class ) {
				$classes[] = $class;
			}
		}
		return implode( ' ', array_unique( $classes ) );
	}

	public static function safe_link( string $link ): bool {
		return (bool) preg_match( '#^(?:https?://[^\s]+|/[^\s]*|\#[a-z0-9_-]+|sitepilot://page/[a-z0-9-]+)$#iu', $link );
	}

	public static function color( string $value ): string {
		$value = trim( $value );
		return preg_match( '/^(?:#[0-9a-f]{3,8}|rgba?\([0-9.,%\s]+\)|[a-z]{3,20})$/iu', $value ) ? $value : '';
	}

	public static function css_length( string $value ): string {
		$value = trim( $value );
		return preg_match( '/^-?[0-9.]+(?:px|rem|em|%|vh|vw)$/u', $value ) ? $value : '';
	}

	public static function attribute( string $value ): string {
		return str_replace( array( '&', "'", '[', ']' ), array( '&amp;', '&#039;', '&#091;', '&#093;' ), $value );
	}

	public static function descendant_count( \DOMElement $element, string $query ): int {
		$xpath = new \DOMXPath( $element->ownerDocument );
		return (int) $xpath->evaluate( 'count(' . $query . ')', $element );
	}

	/** @return list<\DOMElement> */
	public static function direct_elements( \DOMElement $container ): array {
		$result = array();
		foreach ( $container->childNodes as $child ) {
			if ( $child instanceof \DOMElement && ! in_array( strtolower( $child->tagName ), array( 'script', 'style', 'noscript', 'template' ), true ) ) {
				$result[] = $child;
			}
		}
		return $result;
	}

	/** @return list<\DOMElement> */
	public static function elements( \DOMNodeList|false|null $nodes ): array {
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

	/** @return array<string,array<string,string>> */
	private function parse_css( string $css ): array {
		$rules = array();
		$css   = preg_replace( '#/\*.*?\*/#s', '', $css );
		if ( ! is_string( $css ) || ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/u', $css, $matches, PREG_SET_ORDER ) ) {
			return $rules;
		}
		foreach ( $matches as $match ) {
			$declarations = self::declarations( $match[2] );
			foreach ( explode( ',', $match[1] ) as $selector ) {
				foreach ( self::selector_tokens( strtolower( trim( $selector ) ) ) as $token ) {
					$rules[ $token ] = array_merge( $rules[ $token ] ?? array(), $declarations );
				}
			}
		}
		return $rules;
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
		if ( preg_match( '/^[a-z][a-z0-9-]*/u', $compound, $tag ) ) {
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
			list( $property, $content ) = array_map( 'trim', explode( ':', $declaration, 2 ) );
			$property                   = strtolower( $property );
			if ( preg_match( '/^[a-z-]+$/u', $property ) ) {
				$result[ $property ] = trim( $content );
			}
		}
		return $result;
	}

	private function selector_score( \DOMElement $element, string $selector ): int {
		$selector = strtolower( trim( $selector ) );
		if ( '' === $selector ) {
			return -1;
		}
		if ( ! preg_match( '/\s+|>|\+/u', $selector ) ) {
			return $this->simple_selector_score( $element, $selector );
		}
		$segments = preg_split( '/\s*(>|\+)\s*|\s+/u', $selector, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $segments ) || array() === $segments || ! self::matches_token( $element, (string) end( $segments ) ) ) {
			return -1;
		}
		$current = $element;
		$index   = count( $segments ) - 2;
		while ( $index >= 0 && $current instanceof \DOMElement ) {
			$combinator = ' ';
			if ( in_array( $segments[ $index ], array( '>', '+' ), true ) ) {
				$combinator = $segments[ $index-- ];
			}
			if ( $index < 0 || '+' === $combinator ) {
				return -1;
			}
			$token = $segments[ $index ];
			if ( '>' === $combinator ) {
				$current = $current->parentNode instanceof \DOMElement ? $current->parentNode : null;
				if ( ! $current instanceof \DOMElement || ! self::matches_token( $current, $token ) ) {
					return -1;
				}
			} else {
				$current = self::matching_ancestor( $current, $token );
				if ( ! $current instanceof \DOMElement ) {
					return -1;
				}
			}
			--$index;
		}
		if ( $index >= 0 ) {
			return -1;
		}
		$score = 0;
		foreach ( $segments as $segment ) {
			if ( ! in_array( $segment, array( '>', '+' ), true ) ) {
				$score += str_starts_with( $segment, '#' ) ? 100 : ( str_starts_with( $segment, '.' ) ? 10 : 1 );
			}
		}
		return $score;
	}

	private function simple_selector_score( \DOMElement $element, string $selector ): int {
		if ( ! self::matches_token( $element, $selector ) ) {
			return -1;
		}
		return str_starts_with( $selector, '#' ) ? 100 : ( str_starts_with( $selector, '.' ) ? 10 : 1 );
	}

	private static function matching_ancestor( \DOMElement $element, string $token ): ?\DOMElement {
		$ancestor = $element->parentNode;
		while ( $ancestor instanceof \DOMElement ) {
			if ( self::matches_token( $ancestor, $token ) ) {
				return $ancestor;
			}
			$ancestor = $ancestor->parentNode;
		}
		return null;
	}

	private static function matches_token( \DOMElement $element, string $token ): bool {
		$token   = trim( $token );
		$id      = strtolower( $element->getAttribute( 'id' ) );
		$tag     = strtolower( $element->tagName );
		$classes = preg_split( '/\s+/u', strtolower( $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
		$classes = is_array( $classes ) ? $classes : array();
		if ( str_starts_with( $token, '#' ) ) {
			return '' !== $id && '#' . $id === $token;
		}
		if ( str_starts_with( $token, '.' ) ) {
			return in_array( substr( $token, 1 ), $classes, true );
		}
		if ( str_contains( $token, '.' ) ) {
			list( $tag_part, $class_part ) = explode( '.', $token, 2 );
			return ( '' === $tag_part || $tag === $tag_part ) && in_array( $class_part, $classes, true );
		}
		return $tag === $token;
	}
}
