<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

/** Parses, outlines, and losslessly serializes bounded Enfold ALB documents. */
final class EnfoldDocument {
	private const MAX_BYTES  = 250000;
	private const MAX_DEPTH  = 20;
	private const MAX_TOKENS = 768;

	/** @var list<string> */
	private const STANDALONE_SHORTCODES = array( 'av_heading', 'av_image', 'av_button', 'av_gallery', 'av_hr' );

	/**
	 * @return list<array<string,mixed>>|\WP_Error
	 */
	public static function parse( string $alb ) {
		if ( '' === trim( $alb ) || strlen( $alb ) > self::MAX_BYTES ) {
			return new \WP_Error( 'sitepilot_enfold_content_invalid', __( 'ALB content is required and must not exceed 250 KB.', 'sitepilot-mcp' ) );
		}
		if ( preg_match( '#<\?(?:php|=)?|<\s*script\b|<\s*iframe\b|javascript\s*:|<!doctype\b|<\s*(?:html|head|body)\b#iu', $alb ) ) {
			return new \WP_Error( 'sitepilot_enfold_content_unsafe', __( 'ALB content contains executable or complete-page markup.', 'sitepilot-mcp' ) );
		}
		if ( preg_match( '#(?:(?:^|[\s"\'(])[A-Za-z]:[\\\\/]|file://|(?:^|[\s"\'(])/(?:home|var|etc|usr|tmp|Users)/)|\.html?(?:[?\#"\'\s\]]|$)#imu', $alb ) ) {
			return new \WP_Error( 'sitepilot_enfold_reference_unsafe', __( 'ALB content must not contain local paths or HTML-file references.', 'sitepilot-mcp' ) );
		}

		$pattern = '/\[(\/)?([A-Za-z][A-Za-z0-9_-]*)(?:\s[^\]]*?)?\s*(\/)?\]/u';
		$count   = preg_match_all( $pattern, $alb, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		if ( false === $count || $count > self::MAX_TOKENS ) {
			return new \WP_Error( 'sitepilot_enfold_structure_large', __( 'ALB content contains too many shortcode elements.', 'sitepilot-mcp' ) );
		}
		if ( 0 === $count ) {
			return new \WP_Error( 'sitepilot_enfold_structure_missing', __( 'Editable ALB shortcode content is required.', 'sitepilot-mcp' ) );
		}
		$unmatched = preg_replace( $pattern, '', $alb );
		if ( ! is_string( $unmatched ) || preg_match( '/\[(?:\/)?[A-Za-z][A-Za-z0-9_-]*/u', $unmatched ) ) {
			return new \WP_Error( 'sitepilot_enfold_structure_invalid', __( 'ALB shortcodes are malformed or unbalanced.', 'sitepilot-mcp' ) );
		}

		$roots  = array();
		$stack  = array();
		$cursor = 0;
		foreach ( $matches as $index => $match ) {
			$token   = (string) ( $match[0][0] ?? '' );
			$offset  = (int) ( $match[0][1] ?? 0 );
			$closing = '' !== ( $match[1][0] ?? '' );
			$tag     = strtolower( (string) ( $match[2][0] ?? '' ) );
			$self    = '' !== ( $match[3][0] ?? '' );
			if ( ! EnfoldShortcodeRegistry::is_registered( $tag ) ) {
				return new \WP_Error(
					'sitepilot_enfold_shortcode_unregistered',
					__( 'ALB content contains a shortcode that is not registered by the active Enfold runtime.', 'sitepilot-mcp' ),
					array( 'tag' => $tag )
				);
			}

			if ( $closing ) {
				$open_index = count( $stack ) - 1;
				if ( $open_index < 0 || ( $stack[ $open_index ]['tag'] ?? '' ) !== $tag ) {
					return new \WP_Error( 'sitepilot_enfold_structure_invalid', __( 'ALB shortcodes are malformed or unbalanced.', 'sitepilot-mcp' ) );
				}
				$stack[ $open_index ]['_tail']          = substr( $alb, $cursor, $offset - $cursor );
				$stack[ $open_index ]['_close']         = $token;
				$stack[ $open_index ]['text']           = 'content' === ( $stack[ $open_index ]['type'] ?? '' ) ? self::direct_text( $stack[ $open_index ] ) : '';
				$stack[ $open_index ]['_text_original'] = $stack[ $open_index ]['text'];
				array_pop( $stack );
				$cursor = $offset + strlen( $token );
				continue;
			}

			$parent = array() !== $stack ? (string) ( $stack[ count( $stack ) - 1 ]['tag'] ?? '' ) : null;
			$path   = array() !== $stack
				? (string) $stack[ count( $stack ) - 1 ]['path'] . '/' . count( $stack[ count( $stack ) - 1 ]['children'] )
				: (string) count( $roots );
			if ( ! EnfoldShortcodeRegistry::parent_is_valid( $tag, $parent ) ) {
				return new \WP_Error(
					'sitepilot_enfold_hierarchy_invalid',
					__( 'Generated Enfold shortcode hierarchy is not valid for the active builder.', 'sitepilot-mcp' ),
					array(
						'path'            => $path,
						'tag'             => $tag,
						'actual_parent'   => $parent,
						'allowed_parents' => EnfoldShortcodeRegistry::allowed_parents( $tag ),
					)
				);
			}

			$attribute_state = self::parse_attribute_state( $token, $tag, $self );
			$attrs           = $attribute_state['attrs'];
			$standalone      = $self || ( in_array( $tag, self::STANDALONE_SHORTCODES, true ) && ! self::has_later_closer( $matches, $index, $tag ) );
			$definition      = EnfoldShortcodeRegistry::definition( $tag );
			$node            = array(
				'uid'             => (string) ( $attrs['av_uid'] ?? '' ),
				'path'            => $path,
				'tag'             => $tag,
				'type'            => $definition['type'],
				'attrs'           => $attrs,
				'text'            => '',
				'children'        => array(),
				'_before'         => substr( $alb, $cursor, $offset - $cursor ),
				'_open'           => $token,
				'_close'          => '',
				'_tail'           => '',
				'_attrs_original' => $attrs,
				'_attrs_complete' => $attribute_state['complete'],
				'_text_original'  => '',
				'_self_closing'   => $self,
				'_standalone'     => $standalone,
			);

			if ( array() === $stack ) {
				$roots[]    = $node;
				$node_index = array_key_last( $roots );
				if ( ! $standalone && null !== $node_index ) {
					$stack[] =& $roots[ $node_index ];
				}
			} else {
				$parent_index                         = count( $stack ) - 1;
				$stack[ $parent_index ]['children'][] = $node;
				$node_index                           = array_key_last( $stack[ $parent_index ]['children'] );
				if ( ! $standalone && null !== $node_index ) {
					$stack[] =& $stack[ $parent_index ]['children'][ $node_index ];
				}
			}
			if ( count( $stack ) > self::MAX_DEPTH ) {
				return new \WP_Error( 'sitepilot_enfold_structure_deep', __( 'ALB shortcode nesting is too deep.', 'sitepilot-mcp' ) );
			}
			$cursor = $offset + strlen( $token );
		}

		if ( array() !== $stack || preg_match( '/\[(?:\/)?av_[^\]]*$/iu', $alb ) ) {
			return new \WP_Error( 'sitepilot_enfold_structure_invalid', __( 'ALB shortcodes are malformed or unbalanced.', 'sitepilot-mcp' ) );
		}
		$document_tail = substr( $alb, $cursor );
		foreach ( $roots as &$root ) {
			$root['_document_tail'] = $document_tail;
		}
		unset( $root );
		return $roots;
	}

	/** @param list<array<string,mixed>> $tree */
	public static function serialize( array $tree ): string {
		$output        = '';
		$document_tail = '';
		foreach ( $tree as $node ) {
			if ( is_array( $node ) && array_key_exists( '_document_tail', $node ) ) {
				$document_tail = (string) $node['_document_tail'];
				break;
			}
		}
		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$output .= (string) ( $node['_before'] ?? '' ) . self::serialize_node( $node );
		}
		return $output . $document_tail;
	}

	/** @param list<array<string,mixed>> $tree @return list<array<string,mixed>> */
	public static function outline( array $tree, int $depth = 0 ): array {
		if ( $depth > self::MAX_DEPTH ) {
			return array();
		}
		$outline = array();
		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$text      = preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) ( $node['text'] ?? '' ) ) );
			$children  = is_array( $node['children'] ?? null ) ? $node['children'] : array();
			$outline[] = array(
				'uid'      => (string) ( $node['uid'] ?? '' ),
				'path'     => (string) ( $node['path'] ?? '' ),
				'tag'      => (string) ( $node['tag'] ?? '' ),
				'type'     => (string) ( $node['type'] ?? 'content' ),
				'attrs'    => is_array( $node['attrs'] ?? null ) ? $node['attrs'] : array(),
				'text'     => self::truncate( trim( is_string( $text ) ? $text : '' ) ),
				'children' => self::outline( $children, $depth + 1 ),
			);
		}
		return $outline;
	}

	/** @param array<string,mixed> $node */
	private static function serialize_node( array $node ): string {
		$tag           = sanitize_key( (string) ( $node['tag'] ?? '' ) );
		$attrs         = is_array( $node['attrs'] ?? null ) ? $node['attrs'] : array();
		$original      = is_array( $node['_attrs_original'] ?? null ) ? $node['_attrs_original'] : null;
		$original_json = wp_json_encode( $original, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$attrs_json    = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$unchanged     = is_string( $original_json ) && is_string( $attrs_json ) && hash_equals( $original_json, $attrs_json );
		$self_closing  = (bool) ( $node['_self_closing'] ?? false );
		$open          = $unchanged && '' !== (string) ( $node['_open'] ?? '' )
			? (string) $node['_open']
			: self::opening_tag( $tag, $attrs, $self_closing );
		if ( true === ( $node['_standalone'] ?? false ) ) {
			return $open;
		}
		$children = is_array( $node['children'] ?? null ) ? $node['children'] : array();
		if ( array() === $children ) {
			$inner = array_key_exists( '_text_original', $node ) && (string) ( $node['text'] ?? '' ) === (string) $node['_text_original']
				? (string) ( $node['_tail'] ?? '' )
				: (string) ( $node['text'] ?? '' );
		} else {
			$inner = '';
			foreach ( $children as $child ) {
				if ( is_array( $child ) ) {
					$inner .= (string) ( $child['_before'] ?? '' ) . self::serialize_node( $child );
				}
			}
			$inner .= (string) ( $node['_tail'] ?? '' );
		}
		$close = '' !== (string) ( $node['_close'] ?? '' ) ? (string) $node['_close'] : '[/' . $tag . ']';
		return $open . $inner . $close;
	}

	/** @param array<string,mixed> $attrs */
	private static function opening_tag( string $tag, array $attrs, bool $self_closing ): string {
		$serialized = '';
		foreach ( $attrs as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/u', $key ) || ! is_scalar( $value ) ) {
				continue;
			}
			$serialized .= ' ' . $key . "='" . htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ) . "'";
		}
		return '[' . $tag . $serialized . ( $self_closing ? ' /' : '' ) . ']';
	}

	/** @return array{attrs:array<string,string>,complete:bool} */
	private static function parse_attribute_state( string $token, string $tag, bool $self_closing ): array {
		$body = trim( substr( $token, 1, -1 ) );
		if ( $self_closing && str_ends_with( $body, '/' ) ) {
			$body = rtrim( substr( $body, 0, -1 ) );
		}
		$raw = ltrim( substr( $body, strlen( $tag ) ) );
		if ( '' === $raw ) {
			return array(
				'attrs'    => array(),
				'complete' => true,
			);
		}
		preg_match_all( '/([A-Za-z_][A-Za-z0-9_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/u', $raw, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		$attrs    = array();
		$complete = true;
		$cursor   = 0;
		foreach ( $matches as $match ) {
			$matched = (string) ( $match[0][0] ?? '' );
			$offset  = (int) ( $match[0][1] ?? 0 );
			if ( '' !== trim( substr( $raw, $cursor, $offset - $cursor ) ) ) {
				$complete = false;
			}
			$key = strtolower( (string) ( $match[1][0] ?? '' ) );
			if ( '' === $key || array_key_exists( $key, $attrs ) ) {
				$complete = false;
				continue;
			}
			$value         = -1 !== (int) ( $match[2][1] ?? -1 ) ? $match[2][0] : ( -1 !== (int) ( $match[3][1] ?? -1 ) ? $match[3][0] : ( $match[4][0] ?? '' ) );
			$attrs[ $key ] = (string) $value;
			$cursor        = $offset + strlen( $matched );
		}
		if ( '' !== trim( substr( $raw, $cursor ) ) ) {
			$complete = false;
		}
		return array(
			'attrs'    => $attrs,
			'complete' => $complete,
		);
	}

	/** @param array<string,mixed> $node */
	private static function direct_text( array $node ): string {
		$text = '';
		foreach ( is_array( $node['children'] ?? null ) ? $node['children'] : array() as $child ) {
			if ( is_array( $child ) ) {
				$text .= (string) ( $child['_before'] ?? '' );
			}
		}
		return $text . (string) ( $node['_tail'] ?? '' );
	}

	/** @param list<array<int,array{0:string,1:int}>> $matches */
	private static function has_later_closer( array $matches, int $index, string $tag ): bool {
		for ( $cursor = $index + 1, $length = count( $matches ); $cursor < $length; ++$cursor ) {
			if ( '' !== ( $matches[ $cursor ][1][0] ?? '' ) && strtolower( (string) ( $matches[ $cursor ][2][0] ?? '' ) ) === $tag ) {
				return true;
			}
		}
		return false;
	}

	private static function truncate( string $text ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 500 ) : substr( $text, 0, 500 );
	}
}
