<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

/** Pure, atomic tree transforms for existing Enfold ALB documents. */
final class EnfoldElementEditor {
	/** Supported edit operations. */
	public const EDIT_OPERATIONS = array( 'set_attributes', 'set_content', 'insert', 'move', 'remove', 'duplicate' );

	/** Maximum edits accepted in one change action. */
	public const MAX_EDITS = 100;

	/** @var list<string> */
	private const CONTENT_TAGS = array( 'av_textblock', 'av_heading', 'av_toggle' );

	/** @var list<string> */
	private const RUNTIME_ATTRIBUTES = array( 'av_uid', 'custom_class', 'sc_version' );

	/**
	 * @param list<array<string,mixed>> $tree  Existing parsed ALB tree.
	 * @param list<array<string,mixed>> $edits Ordered edits.
	 * @return array{tree:list<array<string,mixed>>,changes:list<array<string,mixed>>}|\WP_Error
	 */
	public function apply( array $tree, array $edits ) {
		if ( array() === $edits ) {
			return new \WP_Error( 'sitepilot_enfold_edits_empty', __( 'At least one Enfold element edit is required.', 'sitepilot-mcp' ) );
		}
		if ( count( $edits ) > self::MAX_EDITS ) {
			return new \WP_Error(
				'sitepilot_enfold_edits_excessive',
				sprintf(
					/* translators: %d: maximum number of edits. */
					__( 'A single change action accepts at most %d Enfold element edits.', 'sitepilot-mcp' ),
					self::MAX_EDITS
				)
			);
		}

		$valid = $this->validate_tree( $tree );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$working = $tree;
		$changes = array();
		foreach ( $edits as $index => $edit ) {
			if ( ! is_array( $edit ) ) {
				return $this->indexed_error( $index, new \WP_Error( 'sitepilot_enfold_edit_invalid', __( 'Each Enfold element edit must be an object.', 'sitepilot-mcp' ) ) );
			}
			$operation = (string) ( $edit['op'] ?? '' );
			if ( ! in_array( $operation, self::EDIT_OPERATIONS, true ) ) {
				return $this->indexed_error( $index, new \WP_Error( 'sitepilot_enfold_edit_unknown', __( 'That Enfold element edit operation is not supported.', 'sitepilot-mcp' ) ) );
			}

			$result = match ( $operation ) {
				'set_attributes' => $this->set_attributes( $working, $edit ),
				'set_content'    => $this->set_content( $working, $edit ),
				'insert'         => $this->insert( $working, $edit ),
				'move'           => $this->move( $working, $edit ),
				'remove'         => $this->remove( $working, $edit ),
				'duplicate'      => $this->duplicate( $working, $edit ),
			};
			if ( is_wp_error( $result ) ) {
				return $this->indexed_error( $index, $result );
			}

			$valid = $this->validate_tree( $result['tree'] );
			if ( is_wp_error( $valid ) ) {
				return $this->indexed_error( $index, $valid );
			}
			$normalized = $this->normalize_tree( $result['tree'] );
			if ( is_wp_error( $normalized ) ) {
				return $this->indexed_error( $index, $normalized );
			}

			$working   = $normalized;
			$changes[] = array_merge( array( 'op' => $operation ), $result['change'] );
		}

		return array(
			'tree'    => $working,
			'changes' => $changes,
		);
	}

	/** @param list<array<string,mixed>> $tree @return true|\WP_Error */
	public function validate_tree( array $tree, ?string $parent_tag = null, int $depth = 0 ) {
		if ( $depth > 20 ) {
			return new \WP_Error( 'sitepilot_enfold_structure_deep', __( 'ALB shortcode nesting is too deep.', 'sitepilot-mcp' ) );
		}
		if ( 0 === $depth && array() === $tree ) {
			return new \WP_Error( 'sitepilot_enfold_document_empty', __( 'The Enfold document cannot be empty.', 'sitepilot-mcp' ) );
		}
		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				return new \WP_Error( 'sitepilot_enfold_document_invalid', __( 'Every Enfold element must be an object.', 'sitepilot-mcp' ) );
			}
			$tag = (string) ( $node['tag'] ?? '' );
			if ( ! EnfoldShortcodeRegistry::is_registered( $tag ) ) {
				return new \WP_Error( 'sitepilot_enfold_shortcode_unregistered', __( 'The Enfold element is not registered by the active runtime.', 'sitepilot-mcp' ), array( 'tag' => $tag ) );
			}
			if ( ! EnfoldShortcodeRegistry::parent_is_valid( $tag, $parent_tag ) ) {
				return new \WP_Error(
					'sitepilot_enfold_hierarchy_invalid',
					__( 'The requested Enfold element hierarchy is not valid for the active builder.', 'sitepilot-mcp' ),
					array(
						'tag'             => $tag,
						'actual_parent'   => $parent_tag,
						'allowed_parents' => EnfoldShortcodeRegistry::allowed_parents( $tag ),
					)
				);
			}
			if ( ! is_array( $node['attrs'] ?? null ) || ! is_array( $node['children'] ?? null ) ) {
				return new \WP_Error( 'sitepilot_enfold_document_invalid', __( 'Enfold elements require attributes and children arrays.', 'sitepilot-mcp' ), array( 'tag' => $tag ) );
			}
			$child = $this->validate_tree( $node['children'], $tag, $depth + 1 );
			if ( is_wp_error( $child ) ) {
				return $child;
			}
		}
		return true;
	}

	/** @param list<array<string,mixed>> $tree @return list<array<string,mixed>> */
	public function outline( array $tree ): array {
		return EnfoldDocument::outline( $tree );
	}

	/**
	 * Describe the externally visible outline changes between two ALB trees.
	 *
	 * Normalized av_uid values are stable identities. Nodes without one (for
	 * example a newly inserted node before Enfold's native save) fall back to
	 * their structural path and tag.
	 *
	 * @param list<array<string,mixed>> $before_tree Tree before editing.
	 * @param list<array<string,mixed>> $after_tree  Tree after editing.
	 * @return array<string,mixed>
	 */
	public function outline_diff( array $before_tree, array $after_tree ): array {
		$before   = $this->flatten_outline( $this->outline( $before_tree ) );
		$after    = $this->flatten_outline( $this->outline( $after_tree ) );
		$added    = array();
		$removed  = array();
		$modified = array();
		$moved    = array();

		foreach ( $before as $key => $node ) {
			if ( ! isset( $after[ $key ] ) ) {
				$removed[] = $node;
				continue;
			}
			$next   = $after[ $key ];
			$fields = array_values(
				array_filter(
					array( 'tag', 'type', 'attrs', 'text' ),
					static fn ( string $field ): bool => $node[ $field ] !== $next[ $field ]
				)
			);
			if ( $fields ) {
				$modified[] = array(
					'uid'    => $next['uid'],
					'path'   => $next['path'],
					'tag'    => $next['tag'],
					'fields' => $fields,
					'before' => array_intersect_key( $node, array_flip( $fields ) ),
					'after'  => array_intersect_key( $next, array_flip( $fields ) ),
				);
			}
			if ( '' !== $next['uid'] && $node['path'] !== $next['path'] ) {
				$moved[] = array(
					'uid'  => $next['uid'],
					'tag'  => $next['tag'],
					'from' => $node['path'],
					'to'   => $next['path'],
				);
			}
		}
		foreach ( $after as $key => $node ) {
			if ( ! isset( $before[ $key ] ) ) {
				$added[] = $node;
			}
		}

		return array(
			'summary'  => array(
				'added'    => count( $added ),
				'removed'  => count( $removed ),
				'modified' => count( $modified ),
				'moved'    => count( $moved ),
			),
			'added'    => $added,
			'removed'  => $removed,
			'modified' => $modified,
			'moved'    => $moved,
		);
	}

	/** @param list<array<string,mixed>> $tree */
	public function count_nodes( array $tree ): int {
		return count( $this->tags( $tree ) );
	}

	/**
	 * @param list<array<string,mixed>> $outline Nested public outline.
	 * @return array<string,array{uid:string,path:string,tag:string,type:string,attrs:array<string,mixed>,text:string}>
	 */
	private function flatten_outline( array $outline ): array {
		$flat = array();
		foreach ( $outline as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$uid          = (string) ( $node['uid'] ?? '' );
			$path         = (string) ( $node['path'] ?? '' );
			$tag          = (string) ( $node['tag'] ?? '' );
			$key          = '' !== $uid ? 'uid:' . $uid : 'path:' . $path . '|tag:' . $tag;
			$flat[ $key ] = array(
				'uid'   => $uid,
				'path'  => $path,
				'tag'   => $tag,
				'type'  => (string) ( $node['type'] ?? 'content' ),
				'attrs' => is_array( $node['attrs'] ?? null ) ? $node['attrs'] : array(),
				'text'  => (string) ( $node['text'] ?? '' ),
			);
			$children     = is_array( $node['children'] ?? null ) ? $node['children'] : array();
			$flat         = array_merge( $flat, $this->flatten_outline( $children ) );
		}
		return $flat;
	}

	/** @param list<array<string,mixed>> $tree @param array<string,mixed> $edit @return array{tree:list<array<string,mixed>>,change:array<string,mixed>}|\WP_Error */
	private function set_attributes( array $tree, array $edit ) {
		$location = $this->target_location( $tree, $edit );
		if ( is_wp_error( $location ) ) {
			return $location;
		}
		$node = $location['node'];
		if ( false === ( $node['_attrs_complete'] ?? true ) ) {
			return new \WP_Error( 'sitepilot_enfold_attributes_incomplete', __( 'This element contains bare, duplicate, or otherwise unparsed attributes and cannot be rewritten safely.', 'sitepilot-mcp' ), $this->identity( $node, $location['path'] ) );
		}
		$updates = is_array( $edit['attrs'] ?? null ) ? $edit['attrs'] : null;
		if ( null === $updates ) {
			return new \WP_Error( 'sitepilot_enfold_edit_invalid', __( 'set_attributes requires an attrs object.', 'sitepilot-mcp' ) );
		}
		$allowed = $this->validate_attribute_updates( (string) $node['tag'], $updates );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$current = is_array( $node['attrs'] ?? null ) ? $node['attrs'] : array();
		$replace = (bool) ( $edit['replace'] ?? false ) && 'curated' === EnfoldShortcodeRegistry::support( (string) $node['tag'] );
		$attrs   = $replace ? array_intersect_key( $current, array( 'av_uid' => true ) ) : $current;
		foreach ( $updates as $key => $value ) {
			$key = (string) $key;
			if ( null === $value ) {
				unset( $attrs[ $key ] );
			} else {
				$attrs[ $key ] = (string) $value;
			}
		}
		if ( isset( $attrs['av_uid'] ) && '' !== $attrs['av_uid'] && $this->uid_exists_elsewhere( $tree, $attrs['av_uid'], $location['path'] ) ) {
			return new \WP_Error( 'sitepilot_enfold_uid_duplicate', __( 'That av_uid already belongs to another element on the page.', 'sitepilot-mcp' ), array( 'av_uid' => $attrs['av_uid'] ) );
		}

		$node['attrs'] = $attrs;
		$node['uid']   = (string) ( $attrs['av_uid'] ?? '' );
		$updated       = $this->replace_at( $tree, $location['path'], $node );
		return array(
			'tree'   => $updated,
			'change' => array_merge( $this->identity( $node, $location['path'] ), array( 'keys' => array_values( array_map( 'strval', array_keys( $updates ) ) ) ) ),
		);
	}

	/** @param list<array<string,mixed>> $tree @param array<string,mixed> $edit @return array{tree:list<array<string,mixed>>,change:array<string,mixed>}|\WP_Error */
	private function set_content( array $tree, array $edit ) {
		$location = $this->target_location( $tree, $edit );
		if ( is_wp_error( $location ) ) {
			return $location;
		}
		$node = $location['node'];
		if ( ! in_array( (string) $node['tag'], self::CONTENT_TAGS, true ) ) {
			return new \WP_Error( 'sitepilot_enfold_content_unsupported', __( 'Only av_textblock, av_heading, and av_toggle elements accept direct content edits.', 'sitepilot-mcp' ), $this->identity( $node, $location['path'] ) );
		}
		if ( array() !== $node['children'] ) {
			return new \WP_Error( 'sitepilot_enfold_content_has_children', __( 'Direct content cannot be replaced on an element that contains child shortcodes.', 'sitepilot-mcp' ), $this->identity( $node, $location['path'] ) );
		}
		if ( true === ( $node['_standalone'] ?? false ) || true === ( $node['_self_closing'] ?? false ) ) {
			return new \WP_Error( 'sitepilot_enfold_content_standalone', __( 'A standalone Enfold element cannot receive direct content.', 'sitepilot-mcp' ), $this->identity( $node, $location['path'] ) );
		}
		if ( ! array_key_exists( 'content', $edit ) || ! is_string( $edit['content'] ) ) {
			return new \WP_Error( 'sitepilot_enfold_edit_invalid', __( 'set_content requires a content string.', 'sitepilot-mcp' ) );
		}
		$node['text'] = $edit['content'];
		return array(
			'tree'   => $this->replace_at( $tree, $location['path'], $node ),
			'change' => array_merge( $this->identity( $node, $location['path'] ), array( 'content_changed' => true ) ),
		);
	}

	/** @param list<array<string,mixed>> $tree @param array<string,mixed> $edit @return array{tree:list<array<string,mixed>>,change:array<string,mixed>}|\WP_Error */
	private function insert( array $tree, array $edit ) {
		if ( ! is_array( $edit['element'] ?? null ) ) {
			return new \WP_Error( 'sitepilot_enfold_edit_invalid', __( 'insert requires an element object.', 'sitepilot-mcp' ) );
		}
		$element = $this->prepare_element( $edit['element'] );
		if ( is_wp_error( $element ) ) {
			return $element;
		}
		$parent = $this->parent_location( $tree, $edit );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		$position = isset( $edit['position'] ) ? max( 0, (int) $edit['position'] ) : PHP_INT_MAX;
		$updated  = $this->insert_at( $tree, $parent['path'], $position, $element );
		$path     = array_merge( $parent['path'] ?? array(), array( min( $position, $this->sibling_count( $tree, $parent['path'] ) ) ) );
		return array(
			'tree'   => $updated,
			'change' => array(
				'uid'         => '',
				'path'        => $this->path_string( $path ),
				'tag'         => (string) $element['tag'],
				'parent_path' => null === $parent['path'] ? null : $this->path_string( $parent['path'] ),
			),
		);
	}

	/** @param list<array<string,mixed>> $tree @param array<string,mixed> $edit @return array{tree:list<array<string,mixed>>,change:array<string,mixed>}|\WP_Error */
	private function move( array $tree, array $edit ) {
		$source = $this->target_location( $tree, $edit );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$parent = $this->parent_location( $tree, $edit );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		if ( null !== $parent['path'] && $this->path_starts_with( $parent['path'], $source['path'] ) ) {
			return new \WP_Error( 'sitepilot_enfold_move_cycle', __( 'An Enfold element cannot be moved inside itself or its own subtree.', 'sitepilot-mcp' ) );
		}
		$detached    = $this->detach_at( $tree, $source['path'] );
		$parent_path = null === $parent['path'] ? null : $this->adjust_path_after_removal( $parent['path'], $source['path'] );
		$position    = isset( $edit['position'] ) ? max( 0, (int) $edit['position'] ) : PHP_INT_MAX;
		$updated     = $this->insert_at( $detached['tree'], $parent_path, $position, $detached['node'] );
		return array(
			'tree'   => $updated,
			'change' => array_merge(
				$this->identity( $source['node'], $source['path'] ),
				array( 'parent_path' => null === $parent_path ? null : $this->path_string( $parent_path ) )
			),
		);
	}

	/** @param list<array<string,mixed>> $tree @param array<string,mixed> $edit @return array{tree:list<array<string,mixed>>,change:array<string,mixed>}|\WP_Error */
	private function remove( array $tree, array $edit ) {
		$location = $this->target_location( $tree, $edit );
		if ( is_wp_error( $location ) ) {
			return $location;
		}
		$detached = $this->detach_at( $tree, $location['path'] );
		if ( array() === $detached['tree'] ) {
			return new \WP_Error( 'sitepilot_enfold_document_empty', __( 'Removing that element would leave the Enfold document empty.', 'sitepilot-mcp' ) );
		}
		return array(
			'tree'   => $detached['tree'],
			'change' => $this->identity( $location['node'], $location['path'] ),
		);
	}

	/** @param list<array<string,mixed>> $tree @param array<string,mixed> $edit @return array{tree:list<array<string,mixed>>,change:array<string,mixed>}|\WP_Error */
	private function duplicate( array $tree, array $edit ) {
		$location = $this->target_location( $tree, $edit );
		if ( is_wp_error( $location ) ) {
			return $location;
		}
		if ( ! $this->subtree_attributes_complete( $location['node'] ) ) {
			return new \WP_Error( 'sitepilot_enfold_attributes_incomplete', __( 'This element subtree contains bare, duplicate, or otherwise unparsed attributes and cannot receive regenerated UIDs safely.', 'sitepilot-mcp' ), $this->identity( $location['node'], $location['path'] ) );
		}
		$clone       = $this->fresh_uid_clone( $location['node'] );
		$parent_path = array_slice( $location['path'], 0, -1 );
		$parent_path = array() === $parent_path ? null : $parent_path;
		$position    = (int) $location['path'][ count( $location['path'] ) - 1 ] + 1;
		$updated     = $this->insert_at( $tree, $parent_path, $position, $clone );
		return array(
			'tree'   => $updated,
			'change' => array_merge( $this->identity( $location['node'], $location['path'] ), array( 'clone_path' => $this->path_string( array_merge( $parent_path ?? array(), array( $position ) ) ) ) ),
		);
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function prepare_element( array $input ) {
		$tag = strtolower( (string) ( $input['tag'] ?? '' ) );
		if ( ! EnfoldShortcodeRegistry::is_registered( $tag ) ) {
			return new \WP_Error( 'sitepilot_enfold_shortcode_unregistered', __( 'The inserted Enfold element is not registered by the active runtime.', 'sitepilot-mcp' ), array( 'tag' => $tag ) );
		}
		if ( 'curated' !== EnfoldShortcodeRegistry::support( $tag ) ) {
			return new \WP_Error( 'sitepilot_enfold_insert_runtime_only', __( 'Runtime-only Enfold elements can be preserved and moved, but SitePilot cannot construct them safely.', 'sitepilot-mcp' ), array( 'tag' => $tag ) );
		}
		$attrs = is_array( $input['attrs'] ?? null ) ? $input['attrs'] : array();
		$valid = $this->validate_attribute_updates( $tag, $attrs );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$normalized_attrs = array();
		foreach ( $attrs as $key => $value ) {
			if ( null !== $value ) {
				$normalized_attrs[ (string) $key ] = (string) $value;
			}
		}
		$normalized_attrs['av_uid'] = '';
		$content                    = $input['content'] ?? ( $input['text'] ?? '' );
		if ( ! is_string( $content ) ) {
			return new \WP_Error( 'sitepilot_enfold_edit_invalid', __( 'Inserted Enfold element content must be a string.', 'sitepilot-mcp' ) );
		}
		if ( '' !== $content && ! in_array( $tag, self::CONTENT_TAGS, true ) ) {
			return new \WP_Error( 'sitepilot_enfold_content_unsupported', __( 'Only av_textblock, av_heading, and av_toggle elements accept inserted direct content.', 'sitepilot-mcp' ), array( 'tag' => $tag ) );
		}
		$children_input = is_array( $input['children'] ?? null ) ? $input['children'] : array();
		if ( '' !== $content && array() !== $children_input ) {
			return new \WP_Error( 'sitepilot_enfold_edit_invalid', __( 'An inserted Enfold element cannot contain both direct content and child elements.', 'sitepilot-mcp' ) );
		}
		$children = array();
		foreach ( $children_input as $child ) {
			if ( ! is_array( $child ) ) {
				return new \WP_Error( 'sitepilot_enfold_edit_invalid', __( 'Every inserted child element must be an object.', 'sitepilot-mcp' ) );
			}
			$prepared = $this->prepare_element( $child );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
			$children[] = $prepared;
		}
		$definition = EnfoldShortcodeRegistry::definition( $tag );
		return array(
			'uid'             => '',
			'path'            => '',
			'tag'             => $tag,
			'type'            => $definition['type'],
			'attrs'           => $normalized_attrs,
			'text'            => $content,
			'children'        => $children,
			'_before'         => '',
			'_open'           => '',
			'_close'          => '',
			'_tail'           => '',
			'_attrs_original' => array(),
			'_attrs_complete' => true,
			'_text_original'  => '',
			'_self_closing'   => false,
			'_standalone'     => false,
		);
	}

	/** @param array<string,mixed> $node @return array<string,mixed> */
	private function fresh_uid_clone( array $node ): array {
		$attrs                   = is_array( $node['attrs'] ?? null ) ? $node['attrs'] : array();
		$attrs['av_uid']         = '';
		$node['uid']             = '';
		$node['attrs']           = $attrs;
		$node['_open']           = '';
		$node['_attrs_original'] = array();
		$children                = array();
		foreach ( is_array( $node['children'] ?? null ) ? $node['children'] : array() as $child ) {
			if ( is_array( $child ) ) {
				$children[] = $this->fresh_uid_clone( $child );
			}
		}
		$node['children'] = $children;
		return $node;
	}

	/** @param array<string,mixed> $updates @return true|\WP_Error */
	private function validate_attribute_updates( string $tag, array $updates ) {
		$allowed = 'runtime_only' === EnfoldShortcodeRegistry::support( $tag ) ? self::RUNTIME_ATTRIBUTES : EnfoldShortcodeRegistry::attributes( $tag );
		foreach ( $updates as $key => $value ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				return new \WP_Error(
					'sitepilot_enfold_attribute_unknown',
					__( 'That attribute is not registered for this Enfold element.', 'sitepilot-mcp' ),
					array(
						'tag'                => $tag,
						'attribute'          => (string) $key,
						'allowed_attributes' => $allowed,
					)
				);
			}
			if ( null !== $value && ! is_scalar( $value ) ) {
				return new \WP_Error(
					'sitepilot_enfold_attribute_invalid',
					__( 'Enfold attribute values must be scalar or null.', 'sitepilot-mcp' ),
					array(
						'tag'       => $tag,
						'attribute' => $key,
					)
				);
			}
		}
		return true;
	}

	/** @param list<array<string,mixed>> $tree @return list<array<string,mixed>>|\WP_Error */
	private function normalize_tree( array $tree ) {
		$expected_tags = $this->tags( $tree );
		$serialized    = EnfoldDocument::serialize( $tree );
		$parsed        = EnfoldDocument::parse( $serialized );
		if ( is_wp_error( $parsed ) ) {
			return new \WP_Error(
				'sitepilot_enfold_edit_structure_changed',
				__( 'The edit would change or invalidate the Enfold shortcode structure.', 'sitepilot-mcp' ),
				array( 'cause' => $parsed->get_error_code() )
			);
		}
		$actual_tags = $this->tags( $parsed );
		if ( $expected_tags !== $actual_tags ) {
			return new \WP_Error(
				'sitepilot_enfold_edit_structure_changed',
				__( 'The edit would change the Enfold shortcode structure through direct content.', 'sitepilot-mcp' ),
				array(
					'expected_tags' => $expected_tags,
					'actual_tags'   => $actual_tags,
				)
			);
		}
		return $parsed;
	}

	/** @param list<array<string,mixed>> $tree @param array<string,mixed> $selector @return array{node:array<string,mixed>,path:list<int>}|\WP_Error */
	private function target_location( array $tree, array $selector ) {
		return $this->location( $tree, (string) ( $selector['uid'] ?? '' ), (string) ( $selector['path'] ?? '' ), false );
	}

	/** @param list<array<string,mixed>> $tree @param array<string,mixed> $edit @return array{node:array<string,mixed>|null,path:list<int>|null}|\WP_Error */
	private function parent_location( array $tree, array $edit ) {
		$uid  = (string) ( $edit['parent_uid'] ?? '' );
		$path = (string) ( $edit['parent_path'] ?? '' );
		if ( '' === $uid && '' === $path ) {
			return array(
				'node' => null,
				'path' => null,
			);
		}
		return $this->location( $tree, $uid, $path, true );
	}

	/** @param list<array<string,mixed>> $tree @return array{node:array<string,mixed>,path:list<int>}|\WP_Error */
	private function location( array $tree, string $uid, string $path, bool $is_parent ) {
		if ( '' !== $uid ) {
			$matches = $this->uid_locations( $tree, $uid );
			if ( count( $matches ) > 1 ) {
				return new \WP_Error( 'sitepilot_enfold_uid_ambiguous', __( 'That av_uid appears more than once in the current Enfold document.', 'sitepilot-mcp' ), array( 'av_uid' => $uid ) );
			}
			if ( 1 === count( $matches ) ) {
				return $matches[0];
			}
		}
		if ( '' !== $path ) {
			$indexes = $this->path_indexes( $path );
			if ( is_wp_error( $indexes ) ) {
				return $indexes;
			}
			$node = $this->node_at( $tree, $indexes );
			if ( null !== $node ) {
				return array(
					'node' => $node,
					'path' => $indexes,
				);
			}
		}
		return new \WP_Error(
			$is_parent ? 'sitepilot_enfold_parent_missing' : 'sitepilot_enfold_element_missing',
			$is_parent ? __( 'The destination Enfold parent does not exist in the current document.', 'sitepilot-mcp' ) : __( 'The target Enfold element does not exist in the current document.', 'sitepilot-mcp' ),
			array(
				'uid'  => $uid,
				'path' => $path,
			)
		);
	}

	/** @param list<array<string,mixed>> $tree @param list<int> $prefix @return list<array{node:array<string,mixed>,path:list<int>}> */
	private function uid_locations( array $tree, string $uid, array $prefix = array() ): array {
		$matches = array();
		foreach ( array_values( $tree ) as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$path = array_merge( $prefix, array( $index ) );
			if ( (string) ( $node['uid'] ?? '' ) === $uid ) {
				$matches[] = array(
					'node' => $node,
					'path' => $path,
				);
			}
			$matches = array_merge( $matches, $this->uid_locations( is_array( $node['children'] ?? null ) ? $node['children'] : array(), $uid, $path ) );
		}
		return $matches;
	}

	/** @return list<int>|\WP_Error */
	private function path_indexes( string $path ) {
		if ( 1 !== preg_match( '/^(?:0|[1-9][0-9]*)(?:\/(?:0|[1-9][0-9]*))*$/', $path ) ) {
			return new \WP_Error( 'sitepilot_enfold_path_invalid', __( 'Enfold element paths must be slash-separated zero-based indexes from the current document snapshot.', 'sitepilot-mcp' ), array( 'path' => $path ) );
		}
		return array_map( 'intval', explode( '/', $path ) );
	}

	/** @param list<array<string,mixed>> $tree @param list<int> $path @return array<string,mixed>|null */
	private function node_at( array $tree, array $path ): ?array {
		$index = array_shift( $path );
		if ( null === $index || ! isset( $tree[ $index ] ) || ! is_array( $tree[ $index ] ) ) {
			return null;
		}
		if ( array() === $path ) {
			return $tree[ $index ];
		}
		return $this->node_at( is_array( $tree[ $index ]['children'] ?? null ) ? $tree[ $index ]['children'] : array(), $path );
	}

	/** @param list<array<string,mixed>> $tree @param list<int> $path @param array<string,mixed> $node @return list<array<string,mixed>> */
	private function replace_at( array $tree, array $path, array $node ): array {
		$index = (int) array_shift( $path );
		if ( array() === $path ) {
			$tree[ $index ] = $node;
			return array_values( $tree );
		}
		$children                   = is_array( $tree[ $index ]['children'] ?? null ) ? $tree[ $index ]['children'] : array();
		$tree[ $index ]['children'] = $this->replace_at( $children, $path, $node );
		return array_values( $tree );
	}

	/** @param list<array<string,mixed>> $tree @param list<int> $path @return array{tree:list<array<string,mixed>>,node:array<string,mixed>} */
	private function detach_at( array $tree, array $path ): array {
		$index = (int) array_shift( $path );
		if ( array() === $path ) {
			$node = $tree[ $index ];
			array_splice( $tree, $index, 1 );
			return array(
				'tree' => array_values( $tree ),
				'node' => $node,
			);
		}
		$children                   = is_array( $tree[ $index ]['children'] ?? null ) ? $tree[ $index ]['children'] : array();
		$result                     = $this->detach_at( $children, $path );
		$tree[ $index ]['children'] = $result['tree'];
		return array(
			'tree' => array_values( $tree ),
			'node' => $result['node'],
		);
	}

	/** @param list<array<string,mixed>> $tree @param list<int>|null $parent_path @param array<string,mixed> $node @return list<array<string,mixed>> */
	private function insert_at( array $tree, ?array $parent_path, int $position, array $node ): array {
		if ( null === $parent_path ) {
			$position = min( $position, count( $tree ) );
			array_splice( $tree, $position, 0, array( $node ) );
			return array_values( $tree );
		}
		$parent   = $this->node_at( $tree, $parent_path );
		$children = is_array( $parent['children'] ?? null ) ? $parent['children'] : array();
		$position = min( $position, count( $children ) );
		array_splice( $children, $position, 0, array( $node ) );
		$parent['children'] = $children;
		return $this->replace_at( $tree, $parent_path, $parent );
	}

	/** @param list<array<string,mixed>> $tree @param list<int>|null $parent_path */
	private function sibling_count( array $tree, ?array $parent_path ): int {
		if ( null === $parent_path ) {
			return count( $tree );
		}
		$parent = $this->node_at( $tree, $parent_path );
		return is_array( $parent['children'] ?? null ) ? count( $parent['children'] ) : 0;
	}

	/** @param list<int> $path @param list<int> $removed @return list<int> */
	private function adjust_path_after_removal( array $path, array $removed ): array {
		$depth = count( $removed ) - 1;
		if ( $depth >= 0
			&& count( $path ) > $depth
			&& array_slice( $path, 0, $depth ) === array_slice( $removed, 0, $depth )
			&& $path[ $depth ] > $removed[ $depth ]
		) {
			--$path[ $depth ];
		}
		return $path;
	}

	/** @param list<int> $path @param list<int> $prefix */
	private function path_starts_with( array $path, array $prefix ): bool {
		return count( $path ) >= count( $prefix ) && array_slice( $path, 0, count( $prefix ) ) === $prefix;
	}

	/** @param list<array<string,mixed>> $tree */
	private function uid_exists_elsewhere( array $tree, string $uid, array $excluded_path ): bool {
		foreach ( $this->uid_locations( $tree, $uid ) as $match ) {
			if ( $match['path'] !== $excluded_path ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $node */
	private function subtree_attributes_complete( array $node ): bool {
		if ( false === ( $node['_attrs_complete'] ?? true ) ) {
			return false;
		}
		foreach ( is_array( $node['children'] ?? null ) ? $node['children'] : array() as $child ) {
			if ( is_array( $child ) && ! $this->subtree_attributes_complete( $child ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param list<array<string,mixed>> $tree @return list<string> */
	private function tags( array $tree ): array {
		$tags = array();
		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$tags[] = (string) ( $node['tag'] ?? '' );
			$tags   = array_merge( $tags, $this->tags( is_array( $node['children'] ?? null ) ? $node['children'] : array() ) );
		}
		return $tags;
	}

	/** @param array<string,mixed> $node @param list<int> $path @return array{uid:string,path:string,tag:string} */
	private function identity( array $node, array $path ): array {
		return array(
			'uid'  => (string) ( $node['uid'] ?? '' ),
			'path' => $this->path_string( $path ),
			'tag'  => (string) ( $node['tag'] ?? '' ),
		);
	}

	/** @param list<int> $path */
	private function path_string( array $path ): string {
		return implode( '/', array_map( 'strval', $path ) );
	}

	private function indexed_error( int $index, \WP_Error $error ): \WP_Error {
		$data          = is_array( $error->get_error_data() ) ? $error->get_error_data() : array();
		$data['index'] = $index;
		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}
}
