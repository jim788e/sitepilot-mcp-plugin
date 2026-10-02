<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

/** Stores reusable Enfold subtrees and applies fresh-UID copies to ALB drafts. */
final class EnfoldTemplateStore {
	public const POST_TYPE = 'sitepilot_template';

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'SitePilot Templates', 'sitepilot-mcp' ),
					'singular_name' => __( 'SitePilot Template', 'sitepilot-mcp' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'supports'            => array( 'title' ),
			)
		);
	}

	/**
	 * @param list<array<string,mixed>> $tree
	 * @return list<array<string,mixed>>|\WP_Error
	 */
	public function select( array $tree, string $uid = '', string $path = '' ) {
		if ( '' === $uid && '' === $path ) {
			return $tree;
		}
		$location = $this->location( $tree, $uid, $path );
		return is_wp_error( $location ) ? $location : array( $location['node'] );
	}

	/**
	 * @param list<array<string,mixed>> $current
	 * @param list<array<string,mixed>> $template
	 * @return list<array<string,mixed>>|\WP_Error
	 */
	public function apply( array $current, array $template, string $mode, string $parent_uid = '', string $parent_path = '', ?int $position = null ) {
		if ( array() === $template ) {
			return new \WP_Error( 'sitepilot_enfold_template_empty', __( 'The requested Enfold template has no elements.', 'sitepilot-mcp' ) );
		}
		$fresh = $this->fresh_tree( $template );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}
		if ( 'replace' === $mode ) {
			if ( '' !== $parent_uid || '' !== $parent_path || null !== $position ) {
				return new \WP_Error( 'sitepilot_enfold_template_destination_invalid', __( 'A replacing template cannot also specify a parent or position.', 'sitepilot-mcp' ) );
			}
			$result = $fresh;
		} elseif ( 'append' === $mode ) {
			$parent = null;
			if ( '' !== $parent_uid || '' !== $parent_path ) {
				$parent = $this->location( $current, $parent_uid, $parent_path );
				if ( is_wp_error( $parent ) ) {
					return $parent;
				}
			}
			$result = $this->insert_many( $current, is_array( $parent ) ? $parent['path'] : null, $fresh, $position );
		} else {
			return new \WP_Error( 'sitepilot_enfold_template_mode_invalid', __( 'Enfold templates support only append or replace mode.', 'sitepilot-mcp' ) );
		}

		$valid = ( new EnfoldElementEditor() )->validate_tree( $result );
		return is_wp_error( $valid ) ? $valid : $result;
	}

	/** @param list<array<string,mixed>> $tree @return list<array<string,mixed>>|\WP_Error */
	private function fresh_tree( array $tree ) {
		$fresh = array();
		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) || false === ( $node['_attrs_complete'] ?? true ) ) {
				return new \WP_Error( 'sitepilot_enfold_attributes_incomplete', __( 'A template element contains attributes that cannot be regenerated safely.', 'sitepilot-mcp' ) );
			}
			$copy = $this->fresh_node( $node );
			if ( is_wp_error( $copy ) ) {
				return $copy;
			}
			$fresh[] = $copy;
		}
		return $fresh;
	}

	/** @param array<string,mixed> $node @return array<string,mixed>|\WP_Error */
	private function fresh_node( array $node ) {
		if ( false === ( $node['_attrs_complete'] ?? true ) ) {
			return new \WP_Error( 'sitepilot_enfold_attributes_incomplete', __( 'A template subtree contains attributes that cannot be regenerated safely.', 'sitepilot-mcp' ) );
		}
		$attrs                   = is_array( $node['attrs'] ?? null ) ? $node['attrs'] : array();
		$attrs['av_uid']         = '';
		$node['uid']             = '';
		$node['path']            = '';
		$node['attrs']           = $attrs;
		$node['_open']           = '';
		$node['_attrs_original'] = array();
		$children                = array();
		foreach ( is_array( $node['children'] ?? null ) ? $node['children'] : array() as $child ) {
			if ( ! is_array( $child ) ) {
				return new \WP_Error( 'sitepilot_enfold_template_invalid', __( 'An Enfold template subtree is malformed.', 'sitepilot-mcp' ) );
			}
			$fresh_child = $this->fresh_node( $child );
			if ( is_wp_error( $fresh_child ) ) {
				return $fresh_child;
			}
			$children[] = $fresh_child;
		}
		$node['children'] = $children;
		return $node;
	}

	/**
	 * @param list<array<string,mixed>> $tree
	 * @param list<int>|null            $parent_path
	 * @param list<array<string,mixed>> $nodes
	 * @return list<array<string,mixed>>
	 */
	private function insert_many( array $tree, ?array $parent_path, array $nodes, ?int $position ): array {
		if ( null === $parent_path ) {
			$offset = null === $position ? count( $tree ) : max( 0, min( count( $tree ), $position ) );
			array_splice( $tree, $offset, 0, $nodes );
			return array_values( $tree );
		}
		$index = array_shift( $parent_path );
		if ( ! isset( $tree[ $index ] ) || ! is_array( $tree[ $index ] ) ) {
			return $tree;
		}
		if ( array() === $parent_path ) {
			$children = is_array( $tree[ $index ]['children'] ?? null ) ? $tree[ $index ]['children'] : array();
			$offset   = null === $position ? count( $children ) : max( 0, min( count( $children ), $position ) );
			array_splice( $children, $offset, 0, $nodes );
			$tree[ $index ]['children'] = array_values( $children );
			return $tree;
		}
		$tree[ $index ]['children'] = $this->insert_many( (array) $tree[ $index ]['children'], $parent_path, $nodes, $position );
		return $tree;
	}

	/** @param list<array<string,mixed>> $tree @return array{node:array<string,mixed>,path:list<int>}|\WP_Error */
	private function location( array $tree, string $uid, string $path ) {
		if ( '' !== $uid ) {
			$matches = $this->uid_locations( $tree, $uid );
			if ( count( $matches ) > 1 ) {
				return new \WP_Error( 'sitepilot_enfold_uid_ambiguous', __( 'That av_uid appears more than once in the Enfold document.', 'sitepilot-mcp' ) );
			}
			if ( 1 === count( $matches ) ) {
				return $matches[0];
			}
		}
		if ( '' !== $path && preg_match( '/^(?:0|[1-9][0-9]*)(?:\/(?:0|[1-9][0-9]*))*$/', $path ) ) {
			$indexes = array_map( 'intval', explode( '/', $path ) );
			$node    = $this->node_at( $tree, $indexes );
			if ( is_array( $node ) ) {
				return array(
					'node' => $node,
					'path' => $indexes,
				);
			}
		}
		return new \WP_Error( 'sitepilot_enfold_template_element_missing', __( 'The selected Enfold template element does not exist in the current document.', 'sitepilot-mcp' ) );
	}

	/** @param list<array<string,mixed>> $tree @param list<int> $path @return array<string,mixed>|null */
	private function node_at( array $tree, array $path ): ?array {
		$index = array_shift( $path );
		if ( null === $index || ! isset( $tree[ $index ] ) || ! is_array( $tree[ $index ] ) ) {
			return null;
		}
		return array() === $path ? $tree[ $index ] : $this->node_at( (array) $tree[ $index ]['children'], $path );
	}

	/** @param list<array<string,mixed>> $tree @param list<int> $prefix @return list<array{node:array<string,mixed>,path:list<int>}> */
	private function uid_locations( array $tree, string $uid, array $prefix = array() ): array {
		$matches = array();
		foreach ( array_values( $tree ) as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$path = array_merge( $prefix, array( $index ) );
			if ( hash_equals( $uid, (string) ( $node['uid'] ?? '' ) ) ) {
				$matches[] = array(
					'node' => $node,
					'path' => $path,
				);
			}
			$matches = array_merge( $matches, $this->uid_locations( (array) ( $node['children'] ?? array() ), $uid, $path ) );
		}
		return $matches;
	}
}
