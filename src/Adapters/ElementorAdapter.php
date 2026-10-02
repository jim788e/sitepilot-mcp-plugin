<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

use SitePilot\Mcp\Infrastructure\Capabilities;

/**
 * Guarded Elementor design adapter.
 *
 * Every operation stages into a draft, snapshots the full Elementor state before
 * mutating, and converts any Elementor Throwable into a WP_Error so a malformed
 * document can never fatal a WordPress request. Elementor internals are reached
 * only through ElementorDocumentStore.
 */
final class ElementorAdapter {

	/** Elementor template types SitePilot will stage as theme-builder documents. */
	public const THEME_DOCUMENT_TYPES = array( 'header', 'footer', 'single', 'archive', 'search-results', 'error-404' );

	/** Global-kit setting keys SitePilot is allowed to write. */
	public const KIT_SETTING_KEYS = array( 'system_colors', 'custom_colors', 'system_typography', 'custom_typography' );

	private ElementorDocumentStore $store;
	private ElementorElementEditor $editor;
	private ElementorWidgetRegistry $widgets;

	public function __construct( ?ElementorDocumentStore $store = null, ?ElementorWidgetRegistry $widgets = null, ?ElementorElementEditor $editor = null ) {
		$this->store   = $store ?? new ElementorDocumentStore();
		$this->widgets = $widgets ?? new ElementorWidgetRegistry( $this->store );
		$this->editor  = $editor ?? new ElementorElementEditor( $this->widgets );
	}

	/** @param array<string,mixed> $action @return array<string,mixed>|\WP_Error */
	public function preview( array $action ) {
		$operation = (string) ( $action['operation'] ?? 'design.stage' );
		$input     = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		return match ( $operation ) {
			'design.edit_elements'          => $this->preview_edit_elements( $action, $input ),
			'design.compile_elementor_html' => $this->preview_compile( $action, $input ),
			'design.resolve_links'          => $this->preview_resolve_links( $action, $input ),
			'design.save_template'          => $this->preview_save_template( $action, $input ),
			'design.apply_template'         => $this->preview_apply_template( $action, $input ),
			'design.update_global_kit'      => $this->preview_global_kit( $action, $input ),
			'design.stage_theme_document'   => $this->preview_theme_document( $action, $input ),
			default                         => $this->preview_stage( $action, $input ),
		};
	}

	/** @param array<string,mixed> $action @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	public function execute( array $action ) {
		$operation = (string) ( $action['operation'] ?? 'design.stage' );
		$input     = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		return match ( $operation ) {
			'design.edit_elements'          => $this->execute_edit_elements( $action, $input ),
			'design.compile_elementor_html' => $this->execute_compile( $action, $input ),
			'design.resolve_links'          => $this->execute_resolve_links( $action, $input ),
			'design.save_template'          => $this->execute_save_template( $action, $input ),
			'design.apply_template'         => $this->execute_apply_template( $action, $input ),
			'design.update_global_kit'      => $this->execute_global_kit( $action, $input ),
			'design.stage_theme_document'   => $this->execute_theme_document( $action, $input ),
			default                         => $this->execute_stage( $action, $input ),
		};
	}

	/** @param array<string,mixed> $rollback @return true|\WP_Error */
	public function rollback( array $rollback ) {
		return match ( (string) ( $rollback['operation'] ?? '' ) ) {
			'restore_design'        => $this->restore_design( $rollback ),
			'restore_elementor_kit' => $this->restore_kit( $rollback ),
			default                 => new \WP_Error( 'sitepilot_rollback_unknown', __( 'Unknown design rollback operation.', 'sitepilot-mcp' ) ),
		};
	}

	/**
	 * Restore one snapshot, or every snapshot a multi-page operation recorded.
	 *
	 * @param array<string,mixed> $rollback Rollback payload.
	 * @return true|\WP_Error
	 */
	private function restore_design( array $rollback ) {
		$snapshots = is_array( $rollback['snapshots'] ?? null ) && array() !== $rollback['snapshots']
			? $rollback['snapshots']
			: array( (array) ( $rollback['snapshot'] ?? array() ) );
		foreach ( $snapshots as $snapshot ) {
			$restored = $this->store->restore( (array) $snapshot );
			if ( is_wp_error( $restored ) ) {
				return $restored;
			}
		}
		return true;
	}

	// ---------------------------------------------------------------- staging

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function preview_stage( array $action, array $input ) {
		$guard = $this->guard_pages();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$target  = (int) $action['target'];
		$builder = sanitize_key( (string) ( $input['builder'] ?? 'gutenberg' ) );
		if ( ! in_array( $builder, array( 'gutenberg', 'elementor' ), true ) ) {
			return new \WP_Error( 'sitepilot_builder_invalid', __( 'The requested design builder is not supported.', 'sitepilot-mcp' ) );
		}
		$post = $this->editable_page( $target, true );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( 'elementor' === $builder ) {
			$document = $this->require_document( $input['document'] ?? null );
			if ( is_wp_error( $document ) ) {
				return $document;
			}
		}
		$fields = $this->page_fields( $input, 0 === $target );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		return array(
			'operation' => 'design.stage',
			'target'    => $target ? (string) $target : 'new:page',
			'before'    => $post instanceof \WP_Post ? $this->store->snapshot( $post ) : null,
			'after'     => array_merge(
				$fields,
				array(
					'builder'  => $builder,
					'status'   => 'draft',
					'title'    => sanitize_text_field( (string) ( $input['post_title'] ?? '' ) ),
					'document' => $input['document'] ?? null,
				)
			),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_stage( array $action, array $input ) {
		$preview = $this->preview_stage( $action, $input );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$target  = (int) $action['target'];
		$builder = sanitize_key( (string) ( $input['builder'] ?? 'gutenberg' ) );
		$before  = $preview['before'];
		$post_id = $this->write_page( $target, $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( 'elementor' === $builder ) {
			$saved = $this->store->save_elements(
				$post_id,
				(array) $input['document'],
				is_array( $input['settings'] ?? null ) ? $input['settings'] : array()
			);
			if ( is_wp_error( $saved ) ) {
				$this->recover_failed_execution( $post_id, $before );
				return $saved;
			}
		}
		$this->apply_featured( $post_id, $input );
		return array(
			'result'   => array(
				'post_id'     => $post_id,
				'builder'     => $builder,
				'slug'        => (string) get_post_field( 'post_name', $post_id ),
				'preview_url' => get_preview_post_link( $post_id ),
			),
			'rollback' => $this->rollback_for( $post_id, $before ),
		);
	}

	// -------------------------------------------------------- element editing

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function preview_edit_elements( array $action, array $input ) {
		$context = $this->element_edit_context( $action, $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		return array(
			'operation'           => 'design.edit_elements',
			'target'              => (string) $context['post']->ID,
			'preview_url'         => get_preview_post_link( $context['post']->ID ),
			'outline_diff'        => $this->editor->outline_diff( $context['tree'], $context['result']['tree'] ),
			'visual_verification' => $this->edit_visual_verification( $input ),
			'before'              => array(
				'outline'       => $this->editor->outline( $context['tree'] ),
				'element_count' => count( $this->editor->collect_ids( $context['tree'] ) ),
			),
			'after'               => array(
				'outline'       => $this->editor->outline( $context['result']['tree'] ),
				'element_count' => count( $this->editor->collect_ids( $context['result']['tree'] ) ),
				'changes'       => $context['result']['changes'],
			),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_edit_elements( array $action, array $input ) {
		$context = $this->element_edit_context( $action, $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$post   = $context['post'];
		$before = $this->store->snapshot( $post );
		// Granular edits do not modify document settings. Omitting that payload is
		// also required by Elementor 4 atomic documents, whose normalized settings
		// cannot be resubmitted through the classic document-settings validator.
		$saved = $this->store->save_elements( $post->ID, $context['result']['tree'] );
		if ( is_wp_error( $saved ) ) {
			$this->store->restore( $before );
			return $saved;
		}
		return array(
			'result'   => array(
				'post_id'             => $post->ID,
				'builder'             => 'elementor',
				'changes'             => $context['result']['changes'],
				'preview_url'         => get_preview_post_link( $post->ID ),
				'visual_verification' => $this->edit_visual_verification( $input ),
			),
			'rollback' => array(
				'operation' => 'restore_design',
				'snapshot'  => $before,
			),
		);
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	private function edit_visual_verification( array $input ): array {
		$requested = true === ( $input['visual_verification'] ?? false );
		$threshold = isset( $input['visual_similarity_threshold'] ) ? (float) $input['visual_similarity_threshold'] : 0.85;
		return Capabilities::visual_verification( $requested, $threshold );
	}

	/**
	 * Resolve, validate and dry-run an element edit list.
	 *
	 * @param array<string,mixed> $action Change action.
	 * @param array<string,mixed> $input  Action input.
	 * @return array{post:\WP_Post,tree:list<array<string,mixed>>,result:array<string,mixed>}|\WP_Error
	 */
	private function element_edit_context( array $action, array $input ) {
		$guard = $this->guard_pages( true );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$post = $this->editable_page( (int) $action['target'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'sitepilot_target_missing', __( 'Element editing requires an existing page.', 'sitepilot-mcp' ) );
		}
		$tree = $this->store->get_elements( $post->ID );
		if ( array() === $tree ) {
			return new \WP_Error( 'sitepilot_elementor_document_empty', __( 'The target page has no Elementor document to edit.', 'sitepilot-mcp' ) );
		}
		$edits  = is_array( $input['edits'] ?? null ) ? array_values( $input['edits'] ) : array();
		$result = $this->editor->apply( $tree, $edits );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'post'   => $post,
			'tree'   => $tree,
			'result' => $result,
		);
	}

	// ------------------------------------------------------------- compiling

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function preview_compile( array $action, array $input ) {
		$compiled = $this->compile( $input );
		if ( is_wp_error( $compiled ) ) {
			return $compiled;
		}
		$staged             = $input;
		$staged['builder']  = 'elementor';
		$staged['document'] = $compiled['document'];
		$preview            = $this->preview_stage( $action, $staged );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$preview['operation']         = 'design.compile_elementor_html';
		$preview['after']['coverage'] = $compiled['coverage'];
		$preview['after']['outline']  = $this->editor->outline( $compiled['document'] );
		return $preview;
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_compile( array $action, array $input ) {
		$compiled = $this->compile( $input );
		if ( is_wp_error( $compiled ) ) {
			return $compiled;
		}
		$staged             = $input;
		$staged['builder']  = 'elementor';
		$staged['document'] = $compiled['document'];
		$result             = $this->execute_stage( $action, $staged );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['result']['coverage'] = $compiled['coverage'];
		return $result;
	}

	/** @param array<string,mixed> $input @return array{document:list<array<string,mixed>>,coverage:array<string,mixed>}|\WP_Error */
	private function compile( array $input ) {
		if ( ! $this->store->available() ) {
			return new \WP_Error( 'sitepilot_elementor_unavailable', __( 'Elementor is not active.', 'sitepilot-mcp' ) );
		}
		return ( new ElementorHtmlCompiler( $this->widgets ) )->compile( $input );
	}

	// --------------------------------------------------------- link resolving

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function preview_resolve_links( array $action, array $input ) {
		$resolved = $this->resolve_links( $input );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		return array(
			'operation' => 'design.resolve_links',
			'target'    => (string) $action['target'],
			'before'    => array( 'unresolved' => $resolved['unresolved'] ),
			'after'     => array( 'resolved' => $resolved['resolved'] ),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_resolve_links( array $action, array $input ) {
		unset( $action );
		$resolved = $this->resolve_links( $input );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$snapshots = array();
		foreach ( $resolved['pages'] as $post_id => $tree ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$snapshots[] = $this->store->snapshot( $post );
			$saved       = $this->store->save_elements( (int) $post_id, $tree, $this->store->get_page_settings( (int) $post_id ) );
			if ( is_wp_error( $saved ) ) {
				foreach ( $snapshots as $snapshot ) {
					$this->store->restore( $snapshot );
				}
				return $saved;
			}
		}
		return array(
			'result'   => array(
				'resolved' => $resolved['resolved'],
				'pages'    => array_map( 'intval', array_keys( $resolved['pages'] ) ),
			),
			'rollback' => array(
				'operation' => 'restore_design',
				'snapshot'  => $snapshots[0] ?? array(),
				'snapshots' => $snapshots,
			),
		);
	}

	/**
	 * Rewrite `sitepilot://page/<slug>` references once the linked drafts exist.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array{pages:array<int,list<array<string,mixed>>>,resolved:list<string>,unresolved:list<string>}|\WP_Error
	 */
	private function resolve_links( array $input ) {
		$guard = $this->guard_pages( true );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$slugs = is_array( $input['page_slugs'] ?? null ) ? array_values( $input['page_slugs'] ) : array();
		if ( array() === $slugs ) {
			return new \WP_Error( 'sitepilot_elementor_slugs_required', __( 'Link resolution requires at least one staged page slug.', 'sitepilot-mcp' ) );
		}
		$pages      = array();
		$resolved   = array();
		$unresolved = array();
		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( sanitize_title( (string) $slug ), OBJECT, 'page' );
			if ( ! $page instanceof \WP_Post ) {
				$unresolved[] = (string) $slug;
				continue;
			}
			$tree = $this->store->get_elements( $page->ID );
			if ( array() === $tree ) {
				continue;
			}
			$rewritten = $this->rewrite_links( $tree, $resolved, $unresolved );
			if ( wp_json_encode( $rewritten ) !== wp_json_encode( $tree ) ) {
				$pages[ $page->ID ] = $rewritten;
			}
		}
		if ( array() !== $unresolved ) {
			return new \WP_Error(
				'sitepilot_elementor_link_unresolved',
				__( 'Some sitepilot://page references do not resolve to an existing page.', 'sitepilot-mcp' ),
				array( 'unresolved' => array_values( array_unique( $unresolved ) ) )
			);
		}
		return array(
			'pages'      => $pages,
			'resolved'   => array_values( array_unique( $resolved ) ),
			'unresolved' => array(),
		);
	}

	/**
	 * @param list<array<string,mixed>> $tree       Element tree.
	 * @param list<string>              $resolved   Collected resolved references.
	 * @param list<string>              $unresolved Collected unresolved references.
	 * @return list<array<string,mixed>>
	 */
	private function rewrite_links( array $tree, array &$resolved, array &$unresolved ): array {
		foreach ( $tree as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( is_array( $element['settings'] ?? null ) ) {
				$tree[ $index ]['settings'] = $this->rewrite_link_values( $element['settings'], $resolved, $unresolved );
			}
			if ( is_array( $element['elements'] ?? null ) ) {
				$tree[ $index ]['elements'] = $this->rewrite_links( $element['elements'], $resolved, $unresolved );
			}
		}
		return $tree;
	}

	/**
	 * @param array<string,mixed> $settings   Element settings.
	 * @param list<string>        $resolved   Collected resolved references.
	 * @param list<string>        $unresolved Collected unresolved references.
	 * @return array<string,mixed>
	 */
	private function rewrite_link_values( array $settings, array &$resolved, array &$unresolved ): array {
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) ) {
				$settings[ $key ] = $this->rewrite_link_values( $value, $resolved, $unresolved );
				continue;
			}
			if ( ! is_string( $value ) || ! str_contains( $value, 'sitepilot://page/' ) ) {
				continue;
			}
			$settings[ $key ] = preg_replace_callback(
				'#sitepilot://page/([a-z0-9-]+)#u',
				static function ( array $matches ) use ( &$resolved, &$unresolved ): string {
					$page = get_page_by_path( $matches[1], OBJECT, 'page' );
					if ( ! $page instanceof \WP_Post ) {
						$unresolved[] = $matches[0];
						return $matches[0];
					}
					$resolved[] = $matches[0];
					return (string) get_permalink( $page->ID );
				},
				$value
			) ?? $value;
		}
		return $settings;
	}

	// ------------------------------------------------------------- templates

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function preview_save_template( array $action, array $input ) {
		$source = $this->template_source( $input );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		return array(
			'operation' => 'design.save_template',
			'target'    => 'new:' . (string) $action['target'],
			'before'    => null,
			'after'     => array(
				'title'          => sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
				'template_type'  => $this->template_type( $input ),
				'source_post_id' => $source['post_id'],
				'outline'        => $this->editor->outline( $source['tree'] ),
			),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_save_template( array $action, array $input ) {
		unset( $action );
		$source = $this->template_source( $input );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$title   = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'elementor_library',
				'post_status' => 'publish',
				'post_title'  => '' !== $title ? $title : __( 'SitePilot template', 'sitepilot-mcp' ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( (int) $post_id, '_elementor_template_type', $this->template_type( $input ) );
		$saved = $this->store->save_elements( (int) $post_id, $source['tree'] );
		if ( is_wp_error( $saved ) ) {
			wp_delete_post( (int) $post_id, true );
			return $saved;
		}
		return array(
			'result'   => array(
				'template_id'    => (int) $post_id,
				'template_type'  => $this->template_type( $input ),
				'source_post_id' => $source['post_id'],
			),
			'rollback' => array(
				'operation' => 'delete_created_post',
				'post_id'   => (int) $post_id,
			),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function preview_apply_template( array $action, array $input ) {
		$merged = $this->template_merge( $action, $input );
		if ( is_wp_error( $merged ) ) {
			return $merged;
		}
		return array(
			'operation' => 'design.apply_template',
			'target'    => (string) $merged['post']->ID,
			'before'    => array( 'outline' => $this->editor->outline( $merged['before_tree'] ) ),
			'after'     => array(
				'outline'     => $this->editor->outline( $merged['tree'] ),
				'template_id' => $merged['template_id'],
				'mode'        => $merged['mode'],
			),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_apply_template( array $action, array $input ) {
		$merged = $this->template_merge( $action, $input );
		if ( is_wp_error( $merged ) ) {
			return $merged;
		}
		$post   = $merged['post'];
		$before = $this->store->snapshot( $post );
		$saved  = $this->store->save_elements( $post->ID, $merged['tree'], $this->store->get_page_settings( $post->ID ) );
		if ( is_wp_error( $saved ) ) {
			$this->store->restore( $before );
			return $saved;
		}
		return array(
			'result'   => array(
				'post_id'     => $post->ID,
				'template_id' => $merged['template_id'],
				'mode'        => $merged['mode'],
				'preview_url' => get_preview_post_link( $post->ID ),
			),
			'rollback' => array(
				'operation' => 'restore_design',
				'snapshot'  => $before,
			),
		);
	}

	/**
	 * @param array<string,mixed> $input Action input.
	 * @return array{post_id:int,tree:list<array<string,mixed>>}|\WP_Error
	 */
	private function template_source( array $input ) {
		$guard = $this->guard_pages( true );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$post = $this->editable_page( absint( $input['source_post_id'] ?? 0 ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'sitepilot_target_missing', __( 'Saving a template requires an existing source page.', 'sitepilot-mcp' ) );
		}
		$tree = $this->store->get_elements( $post->ID );
		if ( array() === $tree ) {
			return new \WP_Error( 'sitepilot_elementor_document_empty', __( 'The source page has no Elementor document to save.', 'sitepilot-mcp' ) );
		}
		return array(
			'post_id' => $post->ID,
			'tree'    => $tree,
		);
	}

	/**
	 * @param array<string,mixed> $action Change action.
	 * @param array<string,mixed> $input  Action input.
	 * @return array{post:\WP_Post,before_tree:list<array<string,mixed>>,tree:list<array<string,mixed>>,template_id:int,mode:string}|\WP_Error
	 */
	private function template_merge( array $action, array $input ) {
		$guard = $this->guard_pages( true );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$post = $this->editable_page( (int) $action['target'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'sitepilot_target_missing', __( 'Applying a template requires an existing page.', 'sitepilot-mcp' ) );
		}
		$template_id = absint( $input['template_id'] ?? 0 );
		$template    = $template_id > 0 ? get_post( $template_id ) : null;
		if ( ! $template instanceof \WP_Post || 'elementor_library' !== $template->post_type ) {
			return new \WP_Error( 'sitepilot_elementor_template_missing', __( 'The requested Elementor template does not exist.', 'sitepilot-mcp' ) );
		}
		$template_tree = $this->store->get_elements( $template_id );
		if ( array() === $template_tree ) {
			return new \WP_Error( 'sitepilot_elementor_template_empty', __( 'The requested Elementor template has no content.', 'sitepilot-mcp' ) );
		}
		$mode        = in_array( (string) ( $input['mode'] ?? 'append' ), array( 'append', 'replace' ), true ) ? (string) $input['mode'] : 'append';
		$before_tree = $this->store->get_elements( $post->ID );
		if ( 'replace' === $mode ) {
			// Regenerate ids so a template applied to several pages never collides.
			$edits = array();
			foreach ( $template_tree as $index => $element ) {
				$edits[] = array(
					'op'       => 'insert',
					'element'  => $element,
					'position' => $index,
					'seed'     => 'template:' . $template_id . ':' . $post->ID . ':' . $index,
				);
			}
			$applied = $this->editor->apply( array_merge( $before_tree, array() ), $edits );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			$tree = array_slice( $applied['tree'], 0, count( $template_tree ) );
		} else {
			$edits = array();
			foreach ( $template_tree as $index => $element ) {
				$edits[] = array(
					'op'      => 'insert',
					'element' => $element,
					'seed'    => 'template:' . $template_id . ':' . $post->ID . ':' . $index,
				);
			}
			$applied = $this->editor->apply( $before_tree, $edits );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			$tree = $applied['tree'];
		}
		return array(
			'post'        => $post,
			'before_tree' => $before_tree,
			'tree'        => $tree,
			'template_id' => $template_id,
			'mode'        => $mode,
		);
	}

	private function template_type( array $input ): string {
		$type = sanitize_key( (string) ( $input['template_type'] ?? 'page' ) );
		return in_array( $type, array_merge( array( 'page', 'section', 'container' ), self::THEME_DOCUMENT_TYPES ), true ) ? $type : 'page';
	}

	// ------------------------------------------------------------ global kit

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function preview_global_kit( array $action, array $input ) {
		$context = $this->kit_context( $action, $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		return array(
			'operation' => 'design.update_global_kit',
			'target'    => (string) $context['kit_id'],
			'before'    => array( 'settings' => $context['before'] ),
			'after'     => array( 'settings' => $context['after'] ),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_global_kit( array $action, array $input ) {
		$context = $this->kit_context( $action, $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$kit_id = $context['kit_id'];
		update_post_meta( $kit_id, '_elementor_page_settings', $context['after'] );
		delete_post_meta( $kit_id, ElementorDocumentStore::ELEMENT_CACHE_META );
		$this->store->clear_file_cache();
		return array(
			'result'   => array(
				'kit_id'  => $kit_id,
				'updated' => array_keys( array_diff_key( $context['after'], $context['before'] ) + array_intersect_key( $context['after'], $context['before'] ) ),
			),
			'rollback' => array(
				'operation' => 'restore_elementor_kit',
				'kit_id'    => $kit_id,
				'settings'  => $context['before'],
			),
		);
	}

	/**
	 * @param array<string,mixed> $action Change action.
	 * @param array<string,mixed> $input  Action input.
	 * @return array{kit_id:int,before:array<string,mixed>,after:array<string,mixed>}|\WP_Error
	 */
	private function kit_context( array $action, array $input ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new \WP_Error( 'sitepilot_global_kit_denied', __( 'The connected user cannot change global site styles.', 'sitepilot-mcp' ) );
		}
		if ( ! $this->store->available() ) {
			return new \WP_Error( 'sitepilot_elementor_unavailable', __( 'Elementor is not active.', 'sitepilot-mcp' ) );
		}
		// Reject malformed input before probing site state so the error names the
		// caller's mistake rather than whatever the site happens to be missing.
		$settings = is_array( $input['settings'] ?? null ) ? $input['settings'] : array();
		$unknown  = array_diff( array_keys( $settings ), self::KIT_SETTING_KEYS );
		if ( array() === $settings || array() !== $unknown ) {
			return new \WP_Error(
				'sitepilot_elementor_kit_settings_invalid',
				__( 'Global kit updates accept only system_colors, custom_colors, system_typography, and custom_typography.', 'sitepilot-mcp' ),
				array( 'unsupported' => array_values( $unknown ) )
			);
		}
		if ( defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, '4.0', '>=' ) ) {
			return new \WP_Error(
				'atomic_global_styles_unsupported',
				__( 'Elementor 4 atomic Global Classes and Variables use separate stores that SitePilot does not write. No global style was changed.', 'sitepilot-mcp' )
			);
		}
		$kit_id = absint( $action['target'] ?? 0 );
		if ( 0 === $kit_id ) {
			$kit_id = $this->store->active_kit_id();
		}
		if ( 0 === $kit_id || ! get_post( $kit_id ) instanceof \WP_Post ) {
			return new \WP_Error( 'sitepilot_elementor_kit_missing', __( 'The active Elementor kit could not be resolved.', 'sitepilot-mcp' ) );
		}
		$before = $this->store->get_page_settings( $kit_id );
		return array(
			'kit_id' => $kit_id,
			'before' => $before,
			'after'  => array_merge( $before, $settings ),
		);
	}

	/** @param array<string,mixed> $rollback @return true|\WP_Error */
	private function restore_kit( array $rollback ) {
		$kit_id = absint( $rollback['kit_id'] ?? 0 );
		if ( 0 === $kit_id ) {
			return new \WP_Error( 'sitepilot_rollback_unknown', __( 'The Elementor kit rollback is malformed.', 'sitepilot-mcp' ) );
		}
		update_post_meta( $kit_id, '_elementor_page_settings', (array) ( $rollback['settings'] ?? array() ) );
		delete_post_meta( $kit_id, ElementorDocumentStore::ELEMENT_CACHE_META );
		$this->store->clear_file_cache();
		return true;
	}

	// -------------------------------------------------------- theme builder

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function preview_theme_document( array $action, array $input ) {
		$context = $this->theme_document_context( $action, $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		return array(
			'operation' => 'design.stage_theme_document',
			'target'    => $context['post'] instanceof \WP_Post ? (string) $context['post']->ID : 'new:' . $context['document_type'],
			'before'    => $context['post'] instanceof \WP_Post ? $this->store->snapshot( $context['post'] ) : null,
			'after'     => array(
				'document_type' => $context['document_type'],
				'title'         => $context['title'],
				'status'        => 'draft',
				'outline'       => $this->editor->outline( $context['tree'] ),
			),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_theme_document( array $action, array $input ) {
		$context = $this->theme_document_context( $action, $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$existing = $context['post'];
		$before   = $existing instanceof \WP_Post ? $this->store->snapshot( $existing ) : null;
		$payload  = array(
			'post_type'   => 'elementor_library',
			'post_status' => 'draft',
			'post_title'  => $context['title'],
		);
		if ( $existing instanceof \WP_Post ) {
			$payload['ID'] = $existing->ID;
			$post_id       = wp_update_post( $payload, true );
		} else {
			$post_id = wp_insert_post( $payload, true );
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( (int) $post_id, '_elementor_template_type', $context['document_type'] );
		$saved = $this->store->save_elements( (int) $post_id, $context['tree'] );
		if ( is_wp_error( $saved ) ) {
			$this->recover_failed_execution( (int) $post_id, $before );
			return $saved;
		}
		return array(
			'result'   => array(
				'post_id'       => (int) $post_id,
				'document_type' => $context['document_type'],
				'status'        => 'draft',
			),
			'rollback' => $this->rollback_for( (int) $post_id, $before ),
		);
	}

	/**
	 * @param array<string,mixed> $action Change action.
	 * @param array<string,mixed> $input  Action input.
	 * @return array{post:?\WP_Post,document_type:string,title:string,tree:list<array<string,mixed>>}|\WP_Error
	 */
	private function theme_document_context( array $action, array $input ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new \WP_Error( 'sitepilot_theme_document_denied', __( 'The connected user cannot change theme-wide templates.', 'sitepilot-mcp' ) );
		}
		if ( ! $this->store->available() ) {
			return new \WP_Error( 'sitepilot_elementor_unavailable', __( 'Elementor is not active.', 'sitepilot-mcp' ) );
		}
		$document_type = sanitize_key( (string) ( $input['document_type'] ?? '' ) );
		if ( ! in_array( $document_type, self::THEME_DOCUMENT_TYPES, true ) ) {
			return new \WP_Error(
				'sitepilot_elementor_document_type_invalid',
				__( 'That Elementor theme-document type is not supported.', 'sitepilot-mcp' ),
				array( 'supported' => self::THEME_DOCUMENT_TYPES )
			);
		}
		$document = $this->require_document( $input['document'] ?? null );
		if ( is_wp_error( $document ) ) {
			return $document;
		}
		$target = absint( $action['target'] ?? 0 );
		$post   = $target > 0 ? get_post( $target ) : null;
		if ( $target > 0 && ( ! $post instanceof \WP_Post || 'elementor_library' !== $post->post_type ) ) {
			return new \WP_Error( 'sitepilot_target_invalid', __( 'The target must be an existing Elementor library document.', 'sitepilot-mcp' ) );
		}
		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		return array(
			'post'          => $post instanceof \WP_Post ? $post : null,
			'document_type' => $document_type,
			'title'         => '' !== $title ? $title : sprintf( 'SitePilot %s', $document_type ),
			'tree'          => $document,
		);
	}

	// ---------------------------------------------------------------- helpers

	/** @return true|\WP_Error */
	private function guard_pages( bool $require_elementor = false ) {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return new \WP_Error( 'sitepilot_edit_pages_denied', __( 'The connected user cannot edit pages.', 'sitepilot-mcp' ) );
		}
		if ( $require_elementor && ! $this->store->available() ) {
			return new \WP_Error( 'sitepilot_elementor_unavailable', __( 'Elementor is not active.', 'sitepilot-mcp' ) );
		}
		return true;
	}

	/**
	 * Resolve an editable page target.
	 *
	 * @return \WP_Post|null|\WP_Error Null when staging a brand-new page.
	 */
	private function editable_page( int $target, bool $allow_new = false ) {
		if ( 0 === $target ) {
			return $allow_new ? null : new \WP_Error( 'sitepilot_target_missing', __( 'This operation requires an existing page ID.', 'sitepilot-mcp' ) );
		}
		$post = get_post( $target );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'sitepilot_target_missing', __( 'The target design post does not exist.', 'sitepilot-mcp' ) );
		}
		if ( 'page' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'sitepilot_target_invalid', __( 'Elementor staging can update only editable WordPress pages.', 'sitepilot-mcp' ) );
		}
		return $post;
	}

	/**
	 * Validate a supplied Elementor document.
	 *
	 * @return list<array<string,mixed>>|\WP_Error
	 */
	private function require_document( mixed $document ) {
		if ( ! $this->store->available() ) {
			return new \WP_Error( 'sitepilot_elementor_unavailable', __( 'Elementor is not active.', 'sitepilot-mcp' ) );
		}
		if ( ! is_array( $document ) ) {
			return new \WP_Error( 'sitepilot_elementor_document_invalid', __( 'An Elementor document is required.', 'sitepilot-mcp' ) );
		}
		$valid = $this->editor->validate_tree( $document );
		if ( is_wp_error( $valid ) ) {
			return new \WP_Error( 'sitepilot_elementor_document_invalid', $valid->get_error_message(), $valid->get_error_data() );
		}
		return array_values( $document );
	}

	/**
	 * Shared page-field validation for staging operations.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function page_fields( array $input, bool $allow_future_parent ) {
		$parent = $this->parent_id( $input, $allow_future_parent );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		$template = $this->page_template( (string) ( $input['page_template'] ?? 'default' ) );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		$featured = absint( $input['featured_media_id'] ?? 0 );
		if ( $featured > 0 && ( ! current_user_can( 'upload_files' ) || ! wp_attachment_is_image( $featured ) ) ) {
			return new \WP_Error( 'sitepilot_featured_media_invalid', __( 'The featured image must be an existing Media Library image and the connected user must be allowed to upload files.', 'sitepilot-mcp' ) );
		}
		return array(
			'parent'            => $parent,
			'menu_order'        => max( -10000, min( 10000, intval( $input['menu_order'] ?? 0 ) ) ),
			'page_template'     => $template,
			'featured_media_id' => $featured,
		);
	}

	/** @param array<string,mixed> $input @return int|\WP_Error */
	private function write_page( int $target, array $input ) {
		$fields = $this->page_fields( $input, 0 === $target );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$payload = array(
			'ID'            => $target,
			'post_type'     => 'page',
			'post_status'   => 'draft',
			'post_title'    => sanitize_text_field( (string) ( $input['post_title'] ?? '' ) ),
			'post_content'  => wp_kses_post( (string) ( $input['post_content'] ?? '' ) ),
			'post_excerpt'  => sanitize_textarea_field( (string) ( $input['post_excerpt'] ?? '' ) ),
			'post_name'     => sanitize_title( (string) ( $input['post_name'] ?? '' ) ),
			'post_parent'   => $fields['parent'],
			'menu_order'    => $fields['menu_order'],
			'page_template' => $fields['page_template'],
		);
		$post_id = $target ? wp_update_post( $payload, true ) : wp_insert_post( $payload, true );
		return is_wp_error( $post_id ) ? $post_id : (int) $post_id;
	}

	/** @param array<string,mixed> $input */
	private function apply_featured( int $post_id, array $input ): void {
		$featured = absint( $input['featured_media_id'] ?? 0 );
		if ( $featured > 0 ) {
			set_post_thumbnail( $post_id, $featured );
		} else {
			delete_post_thumbnail( $post_id );
		}
	}

	/** @param array<string,mixed>|null $before @return array<string,mixed> */
	private function rollback_for( int $post_id, ?array $before ): array {
		return $before ? array(
			'operation' => 'restore_design',
			'snapshot'  => $before,
		) : array(
			'operation' => 'delete_created_post',
			'post_id'   => $post_id,
		);
	}

	/** @param array<string,mixed>|null $before */
	private function recover_failed_execution( int $post_id, ?array $before ): void {
		if ( null === $before ) {
			wp_delete_post( $post_id, true );
			return;
		}
		$this->store->restore( $before );
	}

	/** @param array<string,mixed> $input @return int|\WP_Error */
	private function parent_id( array $input, bool $allow_future = false ) {
		$id   = absint( $input['post_parent'] ?? 0 );
		$slug = sanitize_title( (string) ( $input['parent_slug'] ?? '' ) );
		if ( '' === $slug ) {
			return $id;
		}
		$parent = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $parent instanceof \WP_Post ) {
			return $allow_future ? 0 : new \WP_Error( 'sitepilot_parent_missing', __( 'The requested parent page has not been staged.', 'sitepilot-mcp' ) );
		}
		if ( $id > 0 && $id !== $parent->ID ) {
			return new \WP_Error( 'sitepilot_parent_conflict', __( 'post_parent and parent_slug identify different pages.', 'sitepilot-mcp' ) );
		}
		return $parent->ID;
	}

	/** @return string|\WP_Error */
	private function page_template( string $template ) {
		$template = sanitize_text_field( $template );
		if ( '' === $template || 'default' === $template ) {
			return 'default';
		}
		if ( ! in_array( $template, array_keys( wp_get_theme()->get_page_templates( null, 'page' ) ), true ) ) {
			return new \WP_Error( 'sitepilot_page_template_invalid', __( 'The requested page template is not registered by the active theme.', 'sitepilot-mcp' ) );
		}
		return $template;
	}
}
