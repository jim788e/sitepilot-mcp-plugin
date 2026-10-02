<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

/**
 * Pure element-tree transforms for Elementor documents.
 *
 * Contains no WordPress or Elementor calls so it is unit-testable in isolation,
 * and so a malformed edit list can be rejected before any mutation runs. Edits
 * are applied to a working copy: any failure returns a WP_Error and the caller's
 * tree is left untouched.
 */
final class ElementorElementEditor {

	/** Element types SitePilot accepts when constructing a new document. */
	public const ELEMENT_TYPES = ElementorElementRegistry::CONSTRUCTIBLE_TYPES;

	/** Supported edit operations. */
	public const EDIT_OPERATIONS = array( 'set_settings', 'insert', 'move', 'remove', 'duplicate', 'set_label' );

	/** Maximum nesting depth Elementor documents may reach. */
	public const MAX_DEPTH = 12;

	/** Maximum edits accepted in one change action. */
	public const MAX_EDITS = 100;

	private ?ElementorWidgetRegistry $widgets;
	private ElementorElementRegistry $elements;

	public function __construct( ?ElementorWidgetRegistry $widgets = null, ?ElementorElementRegistry $elements = null ) {
		$this->widgets  = $widgets;
		$this->elements = $elements ?? new ElementorElementRegistry();
	}

	/**
	 * Apply an ordered edit list to an element tree.
	 *
	 * @param list<array<string,mixed>> $tree  Existing element tree.
	 * @param list<array<string,mixed>> $edits Ordered edits.
	 * @return array{tree:list<array<string,mixed>>,changes:list<array<string,mixed>>}|\WP_Error
	 */
	public function apply( array $tree, array $edits ) {
		if ( array() === $edits ) {
			return new \WP_Error( 'sitepilot_elementor_edits_empty', __( 'At least one element edit is required.', 'sitepilot-mcp' ) );
		}
		if ( count( $edits ) > self::MAX_EDITS ) {
			return new \WP_Error(
				'sitepilot_elementor_edits_excessive',
				sprintf(
					/* translators: %d: maximum number of edits. */
					__( 'A single change action accepts at most %d element edits.', 'sitepilot-mcp' ),
					self::MAX_EDITS
				)
			);
		}
		$readable = $this->validate_readable_tree( $tree );
		if ( is_wp_error( $readable ) ) {
			return $readable;
		}
		$working = $tree;
		$changes = array();
		foreach ( $edits as $index => $edit ) {
			if ( ! is_array( $edit ) ) {
				return $this->edit_error( $index, 'sitepilot_elementor_edit_invalid', __( 'Each element edit must be an object.', 'sitepilot-mcp' ) );
			}
			$operation = (string) ( $edit['op'] ?? '' );
			if ( ! in_array( $operation, self::EDIT_OPERATIONS, true ) ) {
				return $this->edit_error( $index, 'sitepilot_elementor_edit_unknown', __( 'That element edit operation is not supported.', 'sitepilot-mcp' ) );
			}
			$result = match ( $operation ) {
				'set_settings' => $this->set_settings( $working, $edit ),
				'set_label'    => $this->set_label( $working, $edit ),
				'insert'       => $this->insert( $working, $edit ),
				'move'         => $this->move( $working, $edit ),
				'remove'       => $this->remove( $working, $edit ),
				'duplicate'    => $this->duplicate( $working, $edit ),
			};
			if ( is_wp_error( $result ) ) {
				$data          = (array) ( $result->get_error_data() ?? array() );
				$data['index'] = $index;
				return new \WP_Error( $result->get_error_code(), $result->get_error_message(), $data );
			}
			$working   = $result['tree'];
			$changes[] = array_merge( array( 'op' => $operation ), $result['change'] );
		}
		$validated = $this->validate_readable_tree( $working );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		return array(
			'tree'    => $working,
			'changes' => $changes,
		);
	}

	/**
	 * Validate a whole tree the same way ElementorAdapter does before saving.
	 *
	 * @param list<array<string,mixed>> $tree  Element tree.
	 * @param int                       $depth Current recursion depth.
	 * @return true|\WP_Error
	 */
	public function validate_tree( array $tree, int $depth = 0 ) {
		return $this->validate_tree_mode( $tree, $depth, true );
	}

	/**
	 * Validate a stored mixed-version document without claiming every element is
	 * safe to construct or structurally edit.
	 *
	 * @param list<array<string,mixed>> $tree Element tree.
	 * @return true|\WP_Error
	 */
	public function validate_readable_tree( array $tree, int $depth = 0 ) {
		return $this->validate_tree_mode( $tree, $depth, false );
	}

	/** @param list<array<string,mixed>> $tree @return true|\WP_Error */
	private function validate_tree_mode( array $tree, int $depth, bool $constructible_only ) {
		if ( $depth > self::MAX_DEPTH ) {
			return new \WP_Error( 'sitepilot_elementor_document_invalid', __( 'The Elementor document nests too deeply.', 'sitepilot-mcp' ) );
		}
		if ( 0 === $depth && array() === $tree ) {
			return new \WP_Error( 'sitepilot_elementor_document_invalid', __( 'The Elementor document is empty.', 'sitepilot-mcp' ) );
		}
		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) ) {
				return new \WP_Error( 'sitepilot_elementor_document_invalid', __( 'Every Elementor element must be an object.', 'sitepilot-mcp' ) );
			}
			$id      = $element['id'] ?? null;
			$el_type = $element['elType'] ?? null;
			if ( ! is_string( $id ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ) {
				return new \WP_Error( 'sitepilot_elementor_document_invalid', __( 'Every Elementor element needs a valid id.', 'sitepilot-mcp' ) );
			}
			if ( ! is_string( $el_type ) || '' === sanitize_key( $el_type ) || ( $constructible_only && ! $this->elements->is_constructible( $el_type ) ) ) {
				return new \WP_Error( 'sitepilot_elementor_document_invalid', __( 'That Elementor element type is not supported.', 'sitepilot-mcp' ), array( 'element_id' => $id ) );
			}
			if ( ! is_array( $element['settings'] ?? null ) || ! is_array( $element['elements'] ?? null ) ) {
				return new \WP_Error( 'sitepilot_elementor_document_invalid', __( 'Elementor elements need settings and elements objects.', 'sitepilot-mcp' ), array( 'element_id' => $id ) );
			}
			if ( 'widget' === $el_type && ( ! is_string( $element['widgetType'] ?? null ) || '' === $element['widgetType'] ) ) {
				return new \WP_Error( 'sitepilot_elementor_document_invalid', __( 'Elementor widgets need a widgetType.', 'sitepilot-mcp' ), array( 'element_id' => $id ) );
			}
			$child = $this->validate_tree_mode( $element['elements'], $depth + 1, $constructible_only );
			if ( is_wp_error( $child ) ) {
				return $child;
			}
		}
		return true;
	}

	/**
	 * Flatten a tree into a readable outline for previews and inspection.
	 *
	 * @param list<array<string,mixed>> $tree  Element tree.
	 * @param int                       $depth Current depth.
	 * @return list<array<string,mixed>>
	 */
	public function outline( array $tree, int $depth = 0, string $parent_path = '' ): array {
		$rows = array();
		foreach ( $tree as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$path        = '' === $parent_path ? (string) $index : $parent_path . '/' . $index;
			$editability = $this->elements->editability( (string) ( $element['elType'] ?? '' ) );
			$rows[]      = array_merge(
				array(
					'id'         => (string) ( $element['id'] ?? '' ),
					'elType'     => (string) ( $element['elType'] ?? '' ),
					'widgetType' => isset( $element['widgetType'] ) ? (string) $element['widgetType'] : null,
					'label'      => isset( $element['settings']['_title'] ) ? (string) $element['settings']['_title'] : null,
					'depth'      => $depth,
					'children'   => is_array( $element['elements'] ?? null ) ? count( $element['elements'] ) : 0,
					'path'       => $path,
				),
				$editability
			);
			if ( is_array( $element['elements'] ?? null ) ) {
				$rows = array_merge( $rows, $this->outline( $element['elements'], $depth + 1, $path ) );
			}
		}
		return $rows;
	}

	/** @param list<array<string,mixed>> $tree @return list<array<string,mixed>> */
	public function unmapped_regions( array $tree ): array {
		return array_values(
			array_filter(
				$this->outline( $tree ),
				static fn ( array $row ): bool => 'full' !== $row['editability']
			)
		);
	}

	/**
	 * @param list<array<string,mixed>> $before_tree Tree before editing.
	 * @param list<array<string,mixed>> $after_tree  Tree after editing.
	 * @return array<string,mixed>
	 */
	public function outline_diff( array $before_tree, array $after_tree ): array {
		$before       = array_column( $this->outline( $before_tree ), null, 'id' );
		$after        = array_column( $this->outline( $after_tree ), null, 'id' );
		$before_paths = $this->element_paths( $before_tree );
		$after_paths  = $this->element_paths( $after_tree );
		$added        = array();
		$removed      = array();
		$modified     = array();
		$moved        = array();

		foreach ( $before as $id => $node ) {
			if ( ! isset( $after[ $id ] ) ) {
				$removed[] = $node;
				continue;
			}
			$next   = $after[ $id ];
			$fields = array_values(
				array_filter(
					array( 'elType', 'widgetType', 'label', 'depth', 'children' ),
					static fn ( string $field ): bool => $node[ $field ] !== $next[ $field ]
				)
			);
			if ( $fields ) {
				$modified[] = array(
					'id'     => (string) $id,
					'fields' => $fields,
					'before' => array_intersect_key( $node, array_flip( $fields ) ),
					'after'  => array_intersect_key( $next, array_flip( $fields ) ),
				);
			}
			if ( ( $before_paths[ (string) $id ] ?? '' ) !== ( $after_paths[ (string) $id ] ?? '' ) ) {
				$moved[] = array(
					'id'   => (string) $id,
					'from' => $before_paths[ (string) $id ] ?? '',
					'to'   => $after_paths[ (string) $id ] ?? '',
				);
			}
		}
		foreach ( $after as $id => $node ) {
			if ( ! isset( $before[ $id ] ) ) {
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

	/**
	 * @param list<array<string,mixed>> $tree Elementor tree.
	 * @return array<string,string>
	 */
	private function element_paths( array $tree, string $parent_path = '' ): array {
		$paths = array();
		foreach ( $tree as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$path = '' === $parent_path ? (string) $index : $parent_path . '/' . $index;
			$id   = (string) ( $element['id'] ?? '' );
			if ( '' !== $id ) {
				$paths[ $id ] = $path;
			}
			$children = is_array( $element['elements'] ?? null ) ? $element['elements'] : array();
			$paths    = array_merge( $paths, $this->element_paths( $children, $path ) );
		}
		return $paths;
	}

	/**
	 * Collect every element id in a tree.
	 *
	 * @param list<array<string,mixed>> $tree Element tree.
	 * @return list<string>
	 */
	public function collect_ids( array $tree ): array {
		$ids = array();
		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['id'] ) && is_string( $element['id'] ) ) {
				$ids[] = $element['id'];
			}
			if ( is_array( $element['elements'] ?? null ) ) {
				$ids = array_merge( $ids, $this->collect_ids( $element['elements'] ) );
			}
		}
		return $ids;
	}

	/**
	 * Deterministic element id using FNV-1a 32-bit.
	 *
	 * @param list<string> $taken Ids already present in the tree.
	 */
	public function generate_id( string $seed, array $taken = array() ): string {
		$attempt = 0;
		do {
			$candidate = $this->fnv1a( 0 === $attempt ? $seed : $seed . ':' . $attempt );
			++$attempt;
		} while ( in_array( $candidate, $taken, true ) && $attempt < 64 );
		return $candidate;
	}

	private function fnv1a( string $seed ): string {
		$hash   = 0x811c9dc5;
		$length = strlen( $seed );
		for ( $index = 0; $index < $length; $index++ ) {
			$hash ^= ord( $seed[ $index ] );
			$hash  = ( $hash * 0x01000193 ) & 0xffffffff;
		}
		return str_pad( dechex( $hash ), 8, '0', STR_PAD_LEFT );
	}

	/**
	 * @param list<array<string,mixed>> $tree Element tree.
	 * @param array<string,mixed>       $edit Edit payload.
	 * @return array{tree:list<array<string,mixed>>,change:array<string,mixed>}|\WP_Error
	 */
	private function set_settings( array $tree, array $edit ) {
		$id = (string) ( $edit['id'] ?? '' );
		if ( ! $this->find( $tree, $id ) ) {
			return $this->missing( $id );
		}
		$settings = is_array( $edit['settings'] ?? null ) ? $edit['settings'] : null;
		if ( null === $settings ) {
			return new \WP_Error( 'sitepilot_elementor_edit_invalid', __( 'set_settings requires a settings object.', 'sitepilot-mcp' ) );
		}
		$element = $this->find( $tree, $id );
		$replace = (bool) ( $edit['replace'] ?? false );
		$class   = $this->elements->classification( (string) ( $element['elType'] ?? '' ) );
		if ( 'unknown' === $class ) {
			return new \WP_Error( 'sitepilot_elementor_region_read_only', __( 'That Elementor region is unmapped and can only be inspected.', 'sitepilot-mcp' ), array( 'element_id' => $id ) );
		}
		if ( 'runtime_only' === $class ) {
			$current = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
			if ( $replace || array_diff( array_keys( $settings ), array_keys( $current ) ) ) {
				return new \WP_Error( 'sitepilot_elementor_runtime_setting_unsupported', __( 'Runtime-only Elementor regions allow updates only to existing settings.', 'sitepilot-mcp' ), array( 'element_id' => $id ) );
			}
			foreach ( $settings as $key => $value ) {
				if ( is_array( $current[ $key ] ) && isset( $current[ $key ]['$$type'] ) && ( ! is_array( $value ) || ( $value['$$type'] ?? null ) !== $current[ $key ]['$$type'] ) ) {
					return new \WP_Error(
						'sitepilot_elementor_runtime_setting_unsupported',
						__( 'Typed Elementor settings must retain their existing type.', 'sitepilot-mcp' ),
						array(
							'element_id' => $id,
							'setting'    => $key,
						)
					);
				}
			}
		}
		if ( 'widget' === ( $element['elType'] ?? '' ) && $this->widgets instanceof ElementorWidgetRegistry ) {
			$valid = $this->widgets->validate_settings( (string) $element['widgetType'], $settings );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		$updated = $this->map(
			$tree,
			$id,
			static function ( array $node ) use ( $settings, $replace ): array {
				$node['settings'] = $replace ? $settings : array_merge( is_array( $node['settings'] ?? null ) ? $node['settings'] : array(), $settings );
				return $node;
			}
		);
		return array(
			'tree'   => $updated,
			'change' => array(
				'id'   => $id,
				'keys' => array_keys( $settings ),
			),
		);
	}

	/**
	 * @param list<array<string,mixed>> $tree Element tree.
	 * @param array<string,mixed>       $edit Edit payload.
	 * @return array{tree:list<array<string,mixed>>,change:array<string,mixed>}|\WP_Error
	 */
	private function set_label( array $tree, array $edit ) {
		$id = (string) ( $edit['id'] ?? '' );
		if ( ! $this->find( $tree, $id ) ) {
			return $this->missing( $id );
		}
		$element = $this->find( $tree, $id );
		if ( ! $this->elements->is_constructible( (string) ( $element['elType'] ?? '' ) ) ) {
			return new \WP_Error( 'sitepilot_elementor_region_read_only', __( 'Labels can be changed only on constructible Elementor regions.', 'sitepilot-mcp' ), array( 'element_id' => $id ) );
		}
		$label   = substr( (string) ( $edit['label'] ?? '' ), 0, 200 );
		$updated = $this->map(
			$tree,
			$id,
			static function ( array $node ) use ( $label ): array {
				$settings           = is_array( $node['settings'] ?? null ) ? $node['settings'] : array();
				$settings['_title'] = $label;
				$node['settings']   = $settings;
				return $node;
			}
		);
		return array(
			'tree'   => $updated,
			'change' => array(
				'id'    => $id,
				'label' => $label,
			),
		);
	}

	/**
	 * @param list<array<string,mixed>> $tree Element tree.
	 * @param array<string,mixed>       $edit Edit payload.
	 * @return array{tree:list<array<string,mixed>>,change:array<string,mixed>}|\WP_Error
	 */
	private function insert( array $tree, array $edit ) {
		$element = is_array( $edit['element'] ?? null ) ? $edit['element'] : null;
		if ( null === $element ) {
			return new \WP_Error( 'sitepilot_elementor_edit_invalid', __( 'insert requires an element object.', 'sitepilot-mcp' ) );
		}
		$parent_id = (string) ( $edit['parent_id'] ?? '' );
		if ( '' !== $parent_id && ! $this->find( $tree, $parent_id ) ) {
			return $this->missing( $parent_id );
		}
		if ( '' !== $parent_id ) {
			$parent = $this->find( $tree, $parent_id );
			if ( ! $this->elements->is_constructible( (string) ( $parent['elType'] ?? '' ) ) ) {
				return new \WP_Error( 'sitepilot_elementor_structure_unsupported', __( 'New elements can be inserted only into constructible Elementor regions.', 'sitepilot-mcp' ), array( 'element_id' => $parent_id ) );
			}
		}
		$taken   = $this->collect_ids( $tree );
		$seed    = (string) ( $edit['seed'] ?? ( $parent_id . ':' . ( $element['elType'] ?? 'element' ) . ':' . count( $taken ) ) );
		$element = $this->assign_ids( $element, $taken, $seed );
		$valid   = $this->validate_tree( array( $element ), 1 );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$position = isset( $edit['position'] ) ? max( 0, (int) $edit['position'] ) : PHP_INT_MAX;
		if ( '' === $parent_id ) {
			$updated = $this->splice( $tree, $position, $element );
		} else {
			$updated = $this->map(
				$tree,
				$parent_id,
				function ( array $node ) use ( $element, $position ): array {
					$children         = is_array( $node['elements'] ?? null ) ? $node['elements'] : array();
					$node['elements'] = $this->splice( $children, $position, $element );
					return $node;
				}
			);
		}
		return array(
			'tree'   => $updated,
			'change' => array(
				'id'        => (string) $element['id'],
				'parent_id' => '' === $parent_id ? null : $parent_id,
			),
		);
	}

	/**
	 * @param list<array<string,mixed>> $tree Element tree.
	 * @param array<string,mixed>       $edit Edit payload.
	 * @return array{tree:list<array<string,mixed>>,change:array<string,mixed>}|\WP_Error
	 */
	private function move( array $tree, array $edit ) {
		$id      = (string) ( $edit['id'] ?? '' );
		$element = $this->find( $tree, $id );
		if ( ! $element ) {
			return $this->missing( $id );
		}
		if ( ! $this->elements->is_constructible( (string) ( $element['elType'] ?? '' ) ) ) {
			return new \WP_Error( 'sitepilot_elementor_structure_unsupported', __( 'Runtime-only and unmapped Elementor regions cannot be moved.', 'sitepilot-mcp' ), array( 'element_id' => $id ) );
		}
		$parent_id = (string) ( $edit['parent_id'] ?? '' );
		if ( $parent_id === $id ) {
			return new \WP_Error( 'sitepilot_elementor_edit_invalid', __( 'An element cannot be moved inside itself.', 'sitepilot-mcp' ) );
		}
		if ( '' !== $parent_id && in_array( $parent_id, $this->collect_ids( $element['elements'] ?? array() ), true ) ) {
			return new \WP_Error( 'sitepilot_elementor_edit_invalid', __( 'An element cannot be moved inside its own subtree.', 'sitepilot-mcp' ) );
		}
		if ( '' !== $parent_id && ! $this->find( $tree, $parent_id ) ) {
			return $this->missing( $parent_id );
		}
		if ( '' !== $parent_id ) {
			$parent = $this->find( $tree, $parent_id );
			if ( ! $this->elements->is_constructible( (string) ( $parent['elType'] ?? '' ) ) ) {
				return new \WP_Error( 'sitepilot_elementor_structure_unsupported', __( 'Elements can be moved only into constructible Elementor regions.', 'sitepilot-mcp' ), array( 'element_id' => $parent_id ) );
			}
		}
		$detached = $this->detach( $tree, $id );
		$position = isset( $edit['position'] ) ? max( 0, (int) $edit['position'] ) : PHP_INT_MAX;
		if ( '' === $parent_id ) {
			$updated = $this->splice( $detached, $position, $element );
		} else {
			$updated = $this->map(
				$detached,
				$parent_id,
				function ( array $node ) use ( $element, $position ): array {
					$children         = is_array( $node['elements'] ?? null ) ? $node['elements'] : array();
					$node['elements'] = $this->splice( $children, $position, $element );
					return $node;
				}
			);
		}
		return array(
			'tree'   => $updated,
			'change' => array(
				'id'        => $id,
				'parent_id' => '' === $parent_id ? null : $parent_id,
			),
		);
	}

	/**
	 * @param list<array<string,mixed>> $tree Element tree.
	 * @param array<string,mixed>       $edit Edit payload.
	 * @return array{tree:list<array<string,mixed>>,change:array<string,mixed>}|\WP_Error
	 */
	private function remove( array $tree, array $edit ) {
		$id      = (string) ( $edit['id'] ?? '' );
		$element = $this->find( $tree, $id );
		if ( ! $element ) {
			return $this->missing( $id );
		}
		if ( ! $this->elements->is_constructible( (string) ( $element['elType'] ?? '' ) ) ) {
			return new \WP_Error( 'sitepilot_elementor_structure_unsupported', __( 'Runtime-only and unmapped Elementor regions cannot be removed.', 'sitepilot-mcp' ), array( 'element_id' => $id ) );
		}
		$updated = $this->detach( $tree, $id );
		if ( array() === $updated ) {
			return new \WP_Error( 'sitepilot_elementor_document_invalid', __( 'Removing that element would leave the page empty.', 'sitepilot-mcp' ), array( 'element_id' => $id ) );
		}
		return array(
			'tree'   => $updated,
			'change' => array( 'id' => $id ),
		);
	}

	/**
	 * @param list<array<string,mixed>> $tree Element tree.
	 * @param array<string,mixed>       $edit Edit payload.
	 * @return array{tree:list<array<string,mixed>>,change:array<string,mixed>}|\WP_Error
	 */
	private function duplicate( array $tree, array $edit ) {
		$id      = (string) ( $edit['id'] ?? '' );
		$element = $this->find( $tree, $id );
		if ( ! $element ) {
			return $this->missing( $id );
		}
		if ( ! $this->elements->is_constructible( (string) ( $element['elType'] ?? '' ) ) ) {
			return new \WP_Error( 'sitepilot_elementor_structure_unsupported', __( 'Runtime-only and unmapped Elementor regions cannot be duplicated.', 'sitepilot-mcp' ), array( 'element_id' => $id ) );
		}
		$taken  = $this->collect_ids( $tree );
		$clone  = $this->assign_ids( $element, $taken, (string) ( $edit['seed'] ?? $id . ':copy' ), true );
		$parent = $this->parent_of( $tree, $id );
		if ( null === $parent ) {
			$position = $this->index_of( $tree, $id );
			$updated  = $this->splice( $tree, $position + 1, $clone );
		} else {
			$updated = $this->map(
				$tree,
				$parent,
				function ( array $node ) use ( $clone, $id ): array {
					$children         = is_array( $node['elements'] ?? null ) ? $node['elements'] : array();
					$node['elements'] = $this->splice( $children, $this->index_of( $children, $id ) + 1, $clone );
					return $node;
				}
			);
		}
		return array(
			'tree'   => $updated,
			'change' => array(
				'id'    => $id,
				'clone' => (string) $clone['id'],
			),
		);
	}

	/**
	 * Give an element and its subtree fresh, collision-free ids.
	 *
	 * @param array<string,mixed> $element Element to rewrite.
	 * @param list<string>        $taken   Ids already in use; extended in place.
	 * @return array<string,mixed>
	 */
	private function assign_ids( array $element, array &$taken, string $seed, bool $force = false ): array {
		$existing = isset( $element['id'] ) && is_string( $element['id'] ) ? $element['id'] : '';
		if ( $force || '' === $existing || in_array( $existing, $taken, true ) ) {
			$element['id'] = $this->generate_id( $seed, $taken );
		}
		$taken[]   = (string) $element['id'];
		$children  = is_array( $element['elements'] ?? null ) ? $element['elements'] : array();
		$rewritten = array();
		foreach ( $children as $index => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$rewritten[] = $this->assign_ids( $child, $taken, $seed . ':' . $index, $force );
		}
		$element['elements'] = $rewritten;
		$element['settings'] = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		return $element;
	}

	/**
	 * @param list<array<string,mixed>> $tree Element tree.
	 * @return array<string,mixed>|null
	 */
	private function find( array $tree, string $id ): ?array {
		if ( '' === $id ) {
			return null;
		}
		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( ( $element['id'] ?? null ) === $id ) {
				return $element;
			}
			$found = $this->find( is_array( $element['elements'] ?? null ) ? $element['elements'] : array(), $id );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * Id of the parent holding `$id`, or null when it sits at the root.
	 *
	 * @param list<array<string,mixed>> $tree Element tree.
	 */
	private function parent_of( array $tree, string $id ): ?string {
		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$children = is_array( $element['elements'] ?? null ) ? $element['elements'] : array();
			foreach ( $children as $child ) {
				if ( is_array( $child ) && ( $child['id'] ?? null ) === $id ) {
					return (string) $element['id'];
				}
			}
			$deeper = $this->parent_of( $children, $id );
			if ( null !== $deeper ) {
				return $deeper;
			}
		}
		return null;
	}

	/** @param list<array<string,mixed>> $siblings Sibling list. */
	private function index_of( array $siblings, string $id ): int {
		foreach ( array_values( $siblings ) as $index => $element ) {
			if ( is_array( $element ) && ( $element['id'] ?? null ) === $id ) {
				return $index;
			}
		}
		return count( $siblings ) - 1;
	}

	/**
	 * @param list<array<string,mixed>> $siblings    Sibling list.
	 * @param array<string,mixed>       $element Element to insert.
	 * @return list<array<string,mixed>>
	 */
	private function splice( array $siblings, int $position, array $element ): array {
		$siblings = array_values( $siblings );
		$position = min( max( 0, $position ), count( $siblings ) );
		array_splice( $siblings, $position, 0, array( $element ) );
		return $siblings;
	}

	/**
	 * Remove an element from anywhere in the tree.
	 *
	 * @param list<array<string,mixed>> $tree Element tree.
	 * @return list<array<string,mixed>>
	 */
	private function detach( array $tree, string $id ): array {
		$result = array();
		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) || ( $element['id'] ?? null ) === $id ) {
				continue;
			}
			if ( is_array( $element['elements'] ?? null ) ) {
				$element['elements'] = $this->detach( $element['elements'], $id );
			}
			$result[] = $element;
		}
		return $result;
	}

	/**
	 * Rewrite one element in place.
	 *
	 * @param list<array<string,mixed>> $tree     Element tree.
	 * @param callable                  $callback Receives and returns the element.
	 * @return list<array<string,mixed>>
	 */
	private function map( array $tree, string $id, callable $callback ): array {
		$result = array();
		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( ( $element['id'] ?? null ) === $id ) {
				$element = $callback( $element );
			} elseif ( is_array( $element['elements'] ?? null ) ) {
				$element['elements'] = $this->map( $element['elements'], $id, $callback );
			}
			$result[] = $element;
		}
		return $result;
	}

	private function missing( string $id ): \WP_Error {
		return new \WP_Error(
			'sitepilot_elementor_element_missing',
			sprintf(
				/* translators: %s: element id. */
				__( 'Element "%s" does not exist on the target page.', 'sitepilot-mcp' ),
				$id
			),
			array( 'element_id' => $id )
		);
	}

	private function edit_error( int $index, string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'index' => $index ) );
	}
}
