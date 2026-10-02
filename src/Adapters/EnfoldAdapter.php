<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

use SitePilot\Mcp\Infrastructure\Capabilities;

final class EnfoldAdapter {
	private const PROFILE_OPTION     = 'sitepilot_mcp_enfold_profile';
	private const ELEMENT_STYLE_META = '_sitepilot_enfold_element_styles';
	private EnfoldElementEditor $editor;
	private EnfoldTemplateStore $templates;

	/** @var list<string> */
	private const GENERATED_META_KEYS = array(
		'_aviaLayoutBuilder_active',
		'_aviaLayoutBuilderCleanData',
		'_avia_builder_shortcode_tree',
		'_av_alb_posts_elements_state',
		'_avia_sc_parser_state',
		'_av_el_mgr_version',
		'_alb_shortcode_status_content',
		'_alb_shortcode_status_clean_data',
		'_alb_shortcode_status_preview',
		'_av_css_styles',
		'_sitepilot_enfold_compiled_css',
		self::ELEMENT_STYLE_META,
	);
	/** @var array<string,string> */
	private const REQUIRED_META_TYPES = array(
		'_aviaLayoutBuilder_active'    => 'string',
		'_aviaLayoutBuilderCleanData'  => 'string',
		'_avia_builder_shortcode_tree' => 'array',
		'_av_alb_posts_elements_state' => 'array',
		'_avia_sc_parser_state'        => 'string',
		'_av_el_mgr_version'           => 'string',
	);
	/**
	 * Enfold 7.1.x renders and indexes toggle state, but its native shortcode-tree
	 * builder omits the child av_toggle records below av_toggle_container. Clean
	 * data and the page-specific element-state index are still verified in full.
	 *
	 * @var list<string>
	 */
	private const TREE_IMPLICIT_SHORTCODES = array( 'av_toggle' );

	public function __construct( ?EnfoldElementEditor $editor = null, ?EnfoldTemplateStore $templates = null ) {
		$this->editor    = $editor ?? new EnfoldElementEditor();
		$this->templates = $templates ?? new EnfoldTemplateStore();
	}

	/**
	 * Make Enfold's native builder API available to REST and MCP requests.
	 *
	 * Enfold can conditionally skip its builder bootstrap outside normal page and
	 * admin requests. SitePilot runs this after the active theme has loaded but
	 * before init, so Enfold can register its normal init callbacks.
	 */
	public static function bootstrap_native_api(): void {
		self::ensure_native_api();
	}

	/** Add only the current page's compiler-owned CSS to an existing Enfold stylesheet. */
	public static function enqueue_compiled_css(): void {
		$post_id = get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}
		$compiled = get_post_meta( $post_id, '_sitepilot_enfold_compiled_css', true );
		$compiled = is_string( $compiled ) ? $compiled : '';
		if ( '' !== trim( $compiled ) && is_wp_error( self::validate_compiled_css( $compiled, hash( 'sha256', $compiled ) ) ) ) {
			$compiled = '';
		}
		$element_css = self::element_styles_css( get_post_meta( $post_id, self::ELEMENT_STYLE_META, true ) );
		$css         = trim( $compiled . "\n" . $element_css );
		if ( '' === $css ) {
			return;
		}
		$handles = array( 'avia-layout', 'avia-default', 'avia-dynamic' );
		foreach ( $handles as $handle ) {
			if ( wp_style_is( $handle, 'enqueued' ) ) {
				wp_add_inline_style( $handle, $css );
				return;
			}
		}
		foreach ( $handles as $handle ) {
			if ( wp_style_is( $handle, 'registered' ) ) {
				wp_enqueue_style( $handle );
				wp_add_inline_style( $handle, $css );
				return;
			}
		}
	}

	/** @return array{active:bool,stylesheet:string,template:string,version:string,alb_available:bool,profile_verified:bool} */
	public static function theme_status( ?\WP_Theme $theme = null ): array {
		$theme      = $theme ?? wp_get_theme();
		$stylesheet = $theme->get_stylesheet();
		$template   = $theme->get_template();
		$parent     = $theme->parent();
		$version    = (string) ( $parent instanceof \WP_Theme ? $parent->get( 'Version' ) : $theme->get( 'Version' ) );
		$active     = 'enfold' === strtolower( $stylesheet ) || 'enfold' === strtolower( $template );
		$profile    = get_option( self::PROFILE_OPTION, array() );
		$registry   = EnfoldShortcodeRegistry::fingerprint( $version );
		$verified   = is_array( $profile )
		&& 3 === (int) ( $profile['schema'] ?? 0 )
		&& true === ( $profile['native_pipeline'] ?? false )
			&& hash_equals( $stylesheet, (string) ( $profile['stylesheet'] ?? '' ) )
			&& hash_equals( $template, (string) ( $profile['template'] ?? '' ) )
			&& hash_equals( $version, (string) ( $profile['theme_version'] ?? '' ) )
			&& hash_equals( $registry, (string) ( $profile['shortcode_registry_fingerprint'] ?? '' ) )
			&& self::native_api_available();
		return array(
			'active'           => $active,
			'stylesheet'       => $stylesheet,
			'template'         => $template,
			'version'          => $version,
			'alb_available'    => $active && shortcode_exists( 'av_section' ) && shortcode_exists( 'av_textblock' ),
			'profile_verified' => $active && $verified,
		);
	}

	/** @param array<string,mixed> $action @return array<string,mixed>|\WP_Error */
	public function preview( array $action ) {
		$operation = (string) ( $action['operation'] ?? '' );
		$input     = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		if ( 'design.calibrate_enfold' === $operation ) {
			return $this->preview_calibration( $input );
		}
		if ( 'design.resolve_links' === $operation ) {
			return array(
				'operation' => $operation,
				'target'    => sanitize_title( (string) ( $action['target'] ?? '' ) ),
				'before'    => null,
				'after'     => array( 'page_slugs' => $this->page_slugs( $input ) ),
			);
		}
		if ( 'design.compile_enfold_html' === $operation ) {
			return $this->preview_compilation( $action );
		}
		if ( 'design.edit_elements' === $operation ) {
			return $this->preview_edit_elements( $action, $input );
		}
		if ( 'design.save_template' === $operation ) {
			return $this->preview_save_template( $action, $input );
		}
		if ( 'design.apply_template' === $operation ) {
			return $this->preview_apply_template( $action, $input );
		}
		if ( 'design.update_global_kit' === $operation ) {
			return $this->preview_global_kit( $action, $input );
		}
		if ( 'design.stage_theme_document' === $operation ) {
			return $this->unsupported_theme_document();
		}
		if ( 'design.set_element_style' === $operation ) {
			return $this->preview_element_style( $action, $input );
		}
		return $this->preview_stage( $action );
	}

	/** @param array<string,mixed> $action @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	public function execute( array $action ) {
		$operation = (string) ( $action['operation'] ?? '' );
		$input     = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		if ( 'design.calibrate_enfold' === $operation ) {
			return $this->execute_calibration( $input );
		}
		if ( 'design.resolve_links' === $operation ) {
			return $this->execute_link_resolution( $action );
		}
		if ( 'design.compile_enfold_html' === $operation ) {
			return $this->execute_compilation( $action );
		}
		if ( 'design.edit_elements' === $operation ) {
			return $this->execute_edit_elements( $action, $input );
		}
		if ( 'design.save_template' === $operation ) {
			return $this->execute_save_template( $action, $input );
		}
		if ( 'design.apply_template' === $operation ) {
			return $this->execute_apply_template( $action, $input );
		}
		if ( 'design.update_global_kit' === $operation ) {
			return $this->execute_global_kit( $action, $input );
		}
		if ( 'design.stage_theme_document' === $operation ) {
			return $this->unsupported_theme_document();
		}
		if ( 'design.set_element_style' === $operation ) {
			return $this->execute_element_style( $action, $input );
		}
		return $this->execute_stage( $action );
	}

	/** @param array<string,mixed> $rollback @return true|\WP_Error */
	public function rollback( array $rollback ) {
		if ( 'restore_enfold_profile' === ( $rollback['operation'] ?? '' ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new \WP_Error( 'sitepilot_enfold_calibration_denied', __( 'Only an administrator can restore the Enfold calibration profile.', 'sitepilot-mcp' ) );
			}
			if ( (bool) ( $rollback['existed'] ?? false ) ) {
				update_option( self::PROFILE_OPTION, $rollback['profile'] ?? array(), false );
			} else {
				delete_option( self::PROFILE_OPTION );
			}
			return true;
		}
		if ( 'delete_created_enfold_post' === ( $rollback['operation'] ?? '' ) ) {
			$post_id = absint( $rollback['post_id'] ?? 0 );
			return $this->delete_created_enfold_post( $post_id, true );
		}
		if ( 'delete_created_enfold_template' === ( $rollback['operation'] ?? '' ) ) {
			$post_id = absint( $rollback['post_id'] ?? 0 );
			$post    = $post_id > 0 ? get_post( $post_id ) : null;
			if ( ! $post instanceof \WP_Post || EnfoldTemplateStore::POST_TYPE !== $post->post_type || ! current_user_can( 'delete_post', $post_id ) ) {
				return new \WP_Error( 'sitepilot_rollback_denied', __( 'The connected user cannot remove this Enfold template.', 'sitepilot-mcp' ) );
			}
			return false !== wp_delete_post( $post_id, true ) ? true : new \WP_Error( 'sitepilot_rollback_failed', __( 'Could not remove the created Enfold template.', 'sitepilot-mcp' ) );
		}
		if ( 'restore_enfold_options' === ( $rollback['operation'] ?? '' ) ) {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return new \WP_Error( 'sitepilot_rollback_denied', __( 'The connected user cannot restore Enfold global styles.', 'sitepilot-mcp' ) );
			}
			$option_name = (string) ( $rollback['option_name'] ?? '' );
			$options     = (array) ( $rollback['options'] ?? array() );
			if ( ! preg_match( '/^avia_options(?:_[a-z0-9_]+)?$/', $option_name ) ) {
				return new \WP_Error( 'sitepilot_rollback_failed', __( 'The Enfold global-styles rollback target is invalid.', 'sitepilot-mcp' ) );
			}
			update_option( $option_name, $options, false );
			if ( get_option( $option_name, null ) !== $options ) {
				return new \WP_Error( 'sitepilot_rollback_failed', __( 'Enfold global styles could not be restored exactly.', 'sitepilot-mcp' ) );
			}
			return true;
		}
		if ( 'restore_enfold_design' !== ( $rollback['operation'] ?? '' ) ) {
			return new \WP_Error( 'sitepilot_rollback_unknown', __( 'Unknown Enfold rollback operation.', 'sitepilot-mcp' ) );
		}
		$snapshot = is_array( $rollback['snapshot'] ?? null ) ? $rollback['snapshot'] : array();
		$post     = is_array( $snapshot['post'] ?? null ) ? $snapshot['post'] : array();
		$post_id  = absint( $post['ID'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'sitepilot_rollback_denied', __( 'The connected user cannot restore this Enfold page.', 'sitepilot-mcp' ) );
		}
		$result = wp_update_post( $post, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		foreach ( (array) ( $snapshot['meta'] ?? array() ) as $key => $values ) {
			delete_post_meta( $post_id, (string) $key );
			if ( is_array( $values ) ) {
				foreach ( $values as $value ) {
					add_post_meta( $post_id, (string) $key, $value );
				}
			}
		}
		$restored_post = get_post( $post_id );
		if ( ! $restored_post instanceof \WP_Post ) {
			return new \WP_Error( 'sitepilot_rollback_failed', __( 'The restored Enfold draft could not be reloaded.', 'sitepilot-mcp' ) );
		}
		$active  = get_post_meta( $post_id, '_aviaLayoutBuilder_active', true );
		$clean   = get_post_meta( $post_id, '_aviaLayoutBuilderCleanData', true );
		$content = 'active' === $active && is_string( $clean ) ? $clean : $restored_post->post_content;
		$synced  = $this->sync_native_usage( $restored_post, $content );
		if ( is_wp_error( $synced ) ) {
			return $synced;
		}
		foreach ( array( '_av_alb_posts_elements_state', '_av_el_mgr_version' ) as $key ) {
			$values = $snapshot['meta'][ $key ] ?? null;
			delete_post_meta( $post_id, $key );
			if ( is_array( $values ) ) {
				foreach ( $values as $value ) {
					add_post_meta( $post_id, $key, $value );
				}
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	public static function validate_alb_content( string $content ) {
		$tree = EnfoldDocument::parse( $content );
		return is_wp_error( $tree ) ? $tree : true;
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function preview_calibration( array $input ) {
		$profile = $this->discover_profile( absint( $input['alb_page_id'] ?? 0 ), absint( $input['normal_page_id'] ?? 0 ) );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		return array(
			'operation' => 'design.calibrate_enfold',
			'target'    => 'enfold-profile',
			'before'    => get_option( self::PROFILE_OPTION, null ),
			'after'     => array(
				'source_page_id'                 => $profile['source_page_id'],
				'metadata_keys'                  => $profile['metadata_keys'],
				'metadata_types'                 => $profile['metadata_types'],
				'native_pipeline'                => true,
				'shortcode_registry_fingerprint' => $profile['shortcode_registry_fingerprint'],
				'fingerprint'                    => $profile['fingerprint'],
			),
		);
	}

	/** @param array<string,mixed> $input @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_calibration( array $input ) {
		$profile = $this->discover_profile( absint( $input['alb_page_id'] ?? 0 ), absint( $input['normal_page_id'] ?? 0 ) );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$existed = false !== get_option( self::PROFILE_OPTION, false );
		$before  = get_option( self::PROFILE_OPTION, array() );
		update_option( self::PROFILE_OPTION, $profile, false );
		return array(
			'result'   => array(
				'profile_verified'               => true,
				'metadata_keys'                  => $profile['metadata_keys'],
				'metadata_types'                 => $profile['metadata_types'],
				'native_pipeline'                => true,
				'shortcode_registry_fingerprint' => $profile['shortcode_registry_fingerprint'],
				'fingerprint'                    => $profile['fingerprint'],
			),
			'rollback' => array(
				'operation' => 'restore_enfold_profile',
				'existed'   => $existed,
				'profile'   => $before,
			),
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
				'element_count' => $this->editor->count_nodes( $context['tree'] ),
			),
			'after'               => array(
				'outline'       => $this->editor->outline( $context['result']['tree'] ),
				'element_count' => $this->editor->count_nodes( $context['result']['tree'] ),
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
		$before = $this->snapshot( $post );
		$latest = get_post_meta( $post->ID, '_aviaLayoutBuilderCleanData', true );
		if ( ! is_string( $latest ) || '' === trim( $latest ) ) {
			return new \WP_Error( 'sitepilot_enfold_document_empty', __( 'The target page has no Enfold clean data to edit.', 'sitepilot-mcp' ) );
		}
		$actual_source_sha256 = hash( 'sha256', $latest );
		if ( ! hash_equals( $context['source_sha256'], $actual_source_sha256 ) ) {
			return new \WP_Error(
				'sitepilot_enfold_edit_conflict',
				__( 'The Enfold document changed after it was parsed. Inspect and plan the edit again.', 'sitepilot-mcp' ),
				array(
					'post_id'                => $post->ID,
					'expected_source_sha256' => $context['source_sha256'],
					'actual_source_sha256'   => $actual_source_sha256,
				)
			);
		}
		$refreshed = $this->apply_element_edits( $post->ID, $latest, $input );
		if ( is_wp_error( $refreshed ) ) {
			return $refreshed;
		}
		$saved_tree = $this->persist_tree( $post, $refreshed['result']['tree'], $context['profile'], $before );
		if ( is_wp_error( $saved_tree ) ) {
			return $saved_tree;
		}
		return array(
			'result'   => array(
				'post_id'             => $post->ID,
				'builder'             => 'enfold',
				'changes'             => $refreshed['result']['changes'],
				'outline'             => $this->editor->outline( $saved_tree ),
				'preview_url'         => get_preview_post_link( $post->ID ),
				'visual_verification' => $this->edit_visual_verification( $input ),
			),
			'rollback' => array(
				'operation' => 'restore_enfold_design',
				'snapshot'  => $before,
			),
		);
	}

	/**
	 * @param array<string,mixed> $action Change action.
	 * @param array<string,mixed> $input  Action input.
	 * @return array{post:\WP_Post,profile:array<string,mixed>,tree:list<array<string,mixed>>,result:array{tree:list<array<string,mixed>>,changes:list<array<string,mixed>>},source_sha256:string}|\WP_Error
	 */
	private function element_edit_context( array $action, array $input ) {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return new \WP_Error( 'sitepilot_edit_pages_denied', __( 'The connected user cannot edit pages.', 'sitepilot-mcp' ) );
		}
		$profile = $this->profile();
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$post_id = absint( $action['target'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'sitepilot_target_missing', __( 'Enfold element editing requires an existing page.', 'sitepilot-mcp' ) );
		}
		if ( 'page' !== $post->post_type || 'draft' !== $post->post_status || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'sitepilot_target_invalid', __( 'Enfold element editing can update only editable ALB draft pages.', 'sitepilot-mcp' ) );
		}
		if ( 'active' !== get_post_meta( $post_id, '_aviaLayoutBuilder_active', true ) ) {
			return new \WP_Error( 'sitepilot_enfold_document_inactive', __( 'The target page is not an active Enfold Advanced Layout Builder document.', 'sitepilot-mcp' ) );
		}
		$content = get_post_meta( $post_id, '_aviaLayoutBuilderCleanData', true );
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return new \WP_Error( 'sitepilot_enfold_document_empty', __( 'The target page has no Enfold clean data to edit.', 'sitepilot-mcp' ) );
		}
		$edited = $this->apply_element_edits( $post_id, $content, $input );
		if ( is_wp_error( $edited ) ) {
			return $edited;
		}
		return array(
			'post'          => $post,
			'profile'       => $profile,
			'tree'          => $edited['tree'],
			'result'        => $edited['result'],
			'source_sha256' => hash( 'sha256', $content ),
		);
	}

	/**
	 * Parse the latest clean data, apply the requested edits and validate the
	 * exact serialized document that would enter Enfold's native save path.
	 *
	 * @param array<string,mixed> $input Action input.
	 * @return array{tree:list<array<string,mixed>>,result:array{tree:list<array<string,mixed>>,changes:list<array<string,mixed>>}}|\WP_Error
	 */
	private function apply_element_edits( int $post_id, string $content, array $input ) {
		$tree = EnfoldDocument::parse( $content );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		$edits  = is_array( $input['edits'] ?? null ) ? array_values( $input['edits'] ) : array();
		$result = $this->editor->apply( $tree, $edits );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$edited_content = EnfoldDocument::serialize( $result['tree'] );
		$valid          = self::validate_alb_content( $edited_content );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$css       = get_post_meta( $post_id, '_sitepilot_enfold_compiled_css', true );
		$css_valid = self::validate_compiled_css_scope( $edited_content, is_string( $css ) ? $css : '' );
		if ( is_wp_error( $css_valid ) ) {
			return $css_valid;
		}
		return array(
			'tree'   => $tree,
			'result' => $result,
		);
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	private function edit_visual_verification( array $input ): array {
		$requested = true === ( $input['visual_verification'] ?? false );
		$threshold = isset( $input['visual_similarity_threshold'] ) ? (float) $input['visual_similarity_threshold'] : 0.85;
		return Capabilities::visual_verification( $requested, $threshold );
	}

	// ------------------------------------------------------------- templates

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function preview_save_template( array $action, array $input ) {
		$context = $this->template_source_context( $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		return array(
			'operation' => 'design.save_template',
			'target'    => 'new:' . sanitize_title( (string) ( $action['target'] ?? 'enfold-template' ) ),
			'before'    => null,
			'after'     => array(
				'builder'        => 'enfold',
				'title'          => sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
				'source_post_id' => $context['post']->ID,
				'outline'        => $this->editor->outline( $context['selected'] ),
			),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_save_template( array $action, array $input ) {
		unset( $action );
		$context = $this->template_source_context( $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$title   = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$content = EnfoldDocument::serialize( $context['selected'] );
		$post_id = wp_insert_post(
			array(
				'post_type'    => EnfoldTemplateStore::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '' !== $title ? $title : __( 'SitePilot Enfold template', 'sitepilot-mcp' ),
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( (int) $post_id, '_sitepilot_template_builder', 'enfold' );
		update_post_meta( (int) $post_id, '_sitepilot_template_source_post_id', $context['post']->ID );
		update_post_meta( (int) $post_id, '_sitepilot_template_registry_fingerprint', (string) $context['profile']['shortcode_registry_fingerprint'] );
		$template = get_post( (int) $post_id );
		if (
			! $template instanceof \WP_Post ||
			EnfoldTemplateStore::POST_TYPE !== $template->post_type ||
			'publish' !== $template->post_status ||
			$content !== $template->post_content ||
			'enfold' !== get_post_meta( (int) $post_id, '_sitepilot_template_builder', true ) ||
			(int) get_post_meta( (int) $post_id, '_sitepilot_template_source_post_id', true ) !== $context['post']->ID ||
			(string) get_post_meta( (int) $post_id, '_sitepilot_template_registry_fingerprint', true ) !== (string) $context['profile']['shortcode_registry_fingerprint']
		) {
			wp_delete_post( (int) $post_id, true );
			return new \WP_Error( 'sitepilot_enfold_template_save_failed', __( 'The Enfold template could not be verified after saving.', 'sitepilot-mcp' ) );
		}
		return array(
			'result'   => array(
				'template_id'    => (int) $post_id,
				'builder'        => 'enfold',
				'source_post_id' => $context['post']->ID,
			),
			'rollback' => array(
				'operation' => 'delete_created_enfold_template',
				'post_id'   => (int) $post_id,
			),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function preview_apply_template( array $action, array $input ) {
		$context = $this->template_apply_context( $action, $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		return array(
			'operation' => 'design.apply_template',
			'target'    => (string) $context['post']->ID,
			'before'    => array( 'outline' => $this->editor->outline( $context['tree'] ) ),
			'after'     => array(
				'builder'     => 'enfold',
				'template_id' => $context['template']->ID,
				'mode'        => $context['mode'],
				'outline'     => $this->editor->outline( $context['merged'] ),
			),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_apply_template( array $action, array $input ) {
		$context = $this->template_apply_context( $action, $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$before = $this->snapshot( $context['post'] );
		$saved  = $this->persist_tree( $context['post'], $context['merged'], $context['profile'], $before );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return array(
			'result'   => array(
				'post_id'     => $context['post']->ID,
				'template_id' => $context['template']->ID,
				'builder'     => 'enfold',
				'mode'        => $context['mode'],
				'outline'     => $this->editor->outline( $saved ),
				'preview_url' => get_preview_post_link( $context['post']->ID ),
			),
			'rollback' => array(
				'operation' => 'restore_enfold_design',
				'snapshot'  => $before,
			),
		);
	}

	/** @param array<string,mixed> $input @return array{post:\WP_Post,profile:array<string,mixed>,selected:list<array<string,mixed>>}|\WP_Error */
	private function template_source_context( array $input ) {
		$context = $this->document_context( absint( $input['source_post_id'] ?? 0 ), false );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$selected = $this->templates->select( $context['tree'], (string) ( $input['uid'] ?? '' ), (string) ( $input['path'] ?? '' ) );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		return array(
			'post'     => $context['post'],
			'profile'  => $context['profile'],
			'selected' => $selected,
		);
	}

	/**
	 * @param array<string,mixed> $action
	 * @param array<string,mixed> $input
	 * @return array{post:\WP_Post,profile:array<string,mixed>,tree:list<array<string,mixed>>,template:\WP_Post,merged:list<array<string,mixed>>,mode:string}|\WP_Error
	 */
	private function template_apply_context( array $action, array $input ) {
		$context = $this->document_context( absint( $action['target'] ?? 0 ), true );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$template_id = absint( $input['template_id'] ?? 0 );
		$template    = $template_id > 0 ? get_post( $template_id ) : null;
		if ( ! $template instanceof \WP_Post || EnfoldTemplateStore::POST_TYPE !== $template->post_type || 'publish' !== $template->post_status || 'enfold' !== get_post_meta( $template_id, '_sitepilot_template_builder', true ) ) {
			return new \WP_Error( 'sitepilot_enfold_template_missing', __( 'The requested SitePilot Enfold template does not exist.', 'sitepilot-mcp' ) );
		}
		$fingerprint = (string) get_post_meta( $template_id, '_sitepilot_template_registry_fingerprint', true );
		if ( '' === $fingerprint || ! hash_equals( (string) $context['profile']['shortcode_registry_fingerprint'], $fingerprint ) ) {
			return new \WP_Error( 'sitepilot_enfold_template_outdated', __( 'The template shortcode registry no longer matches the calibrated Enfold runtime.', 'sitepilot-mcp' ) );
		}
		$template_tree = EnfoldDocument::parse( $template->post_content );
		if ( is_wp_error( $template_tree ) ) {
			return $template_tree;
		}
		$mode   = sanitize_key( (string) ( $input['mode'] ?? 'append' ) );
		$merged = $this->templates->apply(
			$context['tree'],
			$template_tree,
			$mode,
			(string) ( $input['parent_uid'] ?? '' ),
			(string) ( $input['parent_path'] ?? '' ),
			isset( $input['position'] ) ? max( 0, (int) $input['position'] ) : null
		);
		if ( is_wp_error( $merged ) ) {
			return $merged;
		}
		$css       = get_post_meta( $context['post']->ID, '_sitepilot_enfold_compiled_css', true );
		$css_valid = self::validate_compiled_css_scope( EnfoldDocument::serialize( $merged ), is_string( $css ) ? $css : '' );
		if ( is_wp_error( $css_valid ) ) {
			return $css_valid;
		}
		return array_merge(
			$context,
			array(
				'template' => $template,
				'merged'   => $merged,
				'mode'     => $mode,
			)
		);
	}

	// ---------------------------------------------------------- global styles

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function preview_global_kit( array $action, array $input ) {
		$context = $this->global_kit_context( $action, $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		return array(
			'operation' => 'design.update_global_kit',
			'target'    => $context['option_name'],
			'before'    => array( 'settings' => array_intersect_key( $context['before'], array_fill_keys( $context['updated'], true ) ) ),
			'after'     => array( 'settings' => array_intersect_key( $context['after'], array_fill_keys( $context['updated'], true ) ) ),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_global_kit( array $action, array $input ) {
		$context = $this->global_kit_context( $action, $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		update_option( $context['option_name'], $context['after'], false );
		if ( get_option( $context['option_name'], null ) !== $context['after'] ) {
			update_option( $context['option_name'], $context['before'], false );
			return new \WP_Error( 'sitepilot_enfold_global_save_failed', __( 'Enfold global styles could not be verified after saving.', 'sitepilot-mcp' ) );
		}
		return array(
			'result'   => array(
				'builder'     => 'enfold',
				'option_name' => $context['option_name'],
				'updated'     => $context['updated'],
			),
			'rollback' => array(
				'operation'   => 'restore_enfold_options',
				'option_name' => $context['option_name'],
				'options'     => $context['before'],
			),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array{option_name:string,before:array<string,mixed>,after:array<string,mixed>,updated:list<string>}|\WP_Error */
	private function global_kit_context( array $action, array $input ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new \WP_Error( 'sitepilot_enfold_global_denied', __( 'The connected user cannot change Enfold global styles.', 'sitepilot-mcp' ) );
		}
		$theme = $this->active_theme( false );
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		$current = EnfoldGlobalStyles::current();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$target = (string) ( $action['target'] ?? '0' );
		if ( ! in_array( $target, array( '0', '', $current['option_name'] ), true ) ) {
			return new \WP_Error( 'sitepilot_enfold_global_target_invalid', __( 'Use target 0 or the option_name returned by inspect-design for Enfold globals.', 'sitepilot-mcp' ) );
		}
		$settings = is_array( $input['settings'] ?? null ) ? $input['settings'] : array();
		$merged   = EnfoldGlobalStyles::merge( $current['options'], $settings );
		if ( is_wp_error( $merged ) ) {
			return $merged;
		}
		return array(
			'option_name' => $current['option_name'],
			'before'      => $current['options'],
			'after'       => $merged['after'],
			'updated'     => $merged['updated'],
		);
	}

	/** @return \WP_Error */
	private function unsupported_theme_document(): \WP_Error {
		return new \WP_Error(
			'sitepilot_unsupported_for_builder',
			__( 'Enfold header and footer layout are theme-options driven, not standalone theme documents. SitePilot does not fabricate an Elementor-style document for this builder.', 'sitepilot-mcp' ),
			array(
				'builder'   => 'enfold',
				'operation' => 'design.stage_theme_document',
			)
		);
	}

	// ---------------------------------------------------------- element styles

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function preview_element_style( array $action, array $input ) {
		$context = $this->element_style_context( $action, $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		return array(
			'operation' => 'design.set_element_style',
			'target'    => (string) $context['post']->ID,
			'before'    => array(
				'custom_class' => $context['custom_class'],
				'styles'       => $context['before_styles'],
			),
			'after'     => array(
				'custom_class' => $context['custom_class'],
				'styles'       => $context['after_styles'],
				'css'          => $context['css'],
			),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_element_style( array $action, array $input ) {
		$context = $this->element_style_context( $action, $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$before = $this->snapshot( $context['post'] );
		if ( array() === $context['after_meta'] ) {
			delete_post_meta( $context['post']->ID, self::ELEMENT_STYLE_META );
		} else {
			update_post_meta( $context['post']->ID, self::ELEMENT_STYLE_META, $context['after_meta'] );
		}
		$verified = array() === $context['after_meta']
			? ! metadata_exists( 'post', $context['post']->ID, self::ELEMENT_STYLE_META )
			: get_post_meta( $context['post']->ID, self::ELEMENT_STYLE_META, true ) === $context['after_meta'];
		if ( ! $verified ) {
			$recovered = $this->rollback(
				array(
					'operation' => 'restore_enfold_design',
					'snapshot'  => $before,
				)
			);
			return new \WP_Error(
				'sitepilot_enfold_style_save_failed',
				__( 'Protected Enfold element styles could not be verified after saving.', 'sitepilot-mcp' ),
				array(
					'automatic_rollback' => is_wp_error( $recovered ) ? 'failed' : 'passed',
					'rollback_error'     => is_wp_error( $recovered ) ? $recovered->get_error_code() : null,
				)
			);
		}
		return array(
			'result'   => array(
				'post_id'      => $context['post']->ID,
				'builder'      => 'enfold',
				'custom_class' => $context['custom_class'],
				'styles'       => $context['after_styles'],
				'css_sha256'   => hash( 'sha256', $context['css'] ),
				'preview_url'  => get_preview_post_link( $context['post']->ID ),
			),
			'rollback' => array(
				'operation' => 'restore_enfold_design',
				'snapshot'  => $before,
			),
		);
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function element_style_context( array $action, array $input ) {
		$context = $this->document_context( absint( $action['target'] ?? 0 ), true );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$requested_class = (string) ( $input['custom_class'] ?? '' );
		$custom_class    = sanitize_html_class( $requested_class );
		if ( $requested_class !== $custom_class ) {
			return new \WP_Error( 'sitepilot_enfold_style_class_invalid', __( 'custom_class must be a single valid CSS class and cannot be normalized during execution.', 'sitepilot-mcp' ) );
		}
		$matches = $this->class_matches( $context['tree'], $custom_class );
		if ( 1 !== $matches ) {
			return new \WP_Error(
				1 < $matches ? 'sitepilot_enfold_style_class_ambiguous' : 'sitepilot_enfold_style_class_missing',
				1 < $matches ? __( 'Element styles require a custom_class used by exactly one element.', 'sitepilot-mcp' ) : __( 'The requested custom_class is not present in the current Enfold document.', 'sitepilot-mcp' ),
				array( 'matches' => $matches )
			);
		}
		$updates = is_array( $input['styles'] ?? null ) ? $input['styles'] : null;
		if ( null === $updates ) {
			return new \WP_Error( 'sitepilot_enfold_styles_invalid', __( 'set_element_style requires a styles object.', 'sitepilot-mcp' ) );
		}
		$before_meta   = get_post_meta( $context['post']->ID, self::ELEMENT_STYLE_META, true );
		$before_meta   = is_array( $before_meta ) ? $before_meta : array();
		$before_styles = is_array( $before_meta[ $custom_class ] ?? null ) ? $before_meta[ $custom_class ] : array();
		$after_styles  = true === ( $input['replace'] ?? false ) ? array() : $before_styles;
		foreach ( $updates as $property => $value ) {
			if ( null === $value ) {
				unset( $after_styles[ (string) $property ] );
			} else {
				$after_styles[ (string) $property ] = $value;
			}
		}
		$css = EnfoldCssCompiler::compile_element_rule( $custom_class, $after_styles );
		if ( is_wp_error( $css ) ) {
			return $css;
		}
		$after_meta = $before_meta;
		if ( array() === $after_styles ) {
			unset( $after_meta[ $custom_class ] );
		} else {
			$after_meta[ $custom_class ] = $after_styles;
		}
		ksort( $after_meta );
		return array_merge(
			$context,
			array(
				'custom_class'  => $custom_class,
				'before_styles' => $before_styles,
				'after_styles'  => $after_styles,
				'after_meta'    => $after_meta,
				'css'           => $css,
			)
		);
	}

	/** @param list<array<string,mixed>> $tree */
	private function class_matches( array $tree, string $custom_class ): int {
		if ( '' === $custom_class ) {
			return 0;
		}
		$count = 0;
		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$attrs   = is_array( $node['attrs'] ?? null ) ? $node['attrs'] : array();
			$classes = preg_split( '/\s+/u', trim( (string) ( $attrs['custom_class'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $classes ) && in_array( $custom_class, $classes, true ) ) {
				++$count;
			}
			$count += $this->class_matches( is_array( $node['children'] ?? null ) ? $node['children'] : array(), $custom_class );
		}
		return $count;
	}

	/** @return string */
	private static function element_styles_css( mixed $styles ): string {
		if ( ! is_array( $styles ) ) {
			return '';
		}
		ksort( $styles );
		$css = '';
		foreach ( $styles as $custom_class => $properties ) {
			if ( ! is_string( $custom_class ) || ! is_array( $properties ) ) {
				continue;
			}
			$rule = EnfoldCssCompiler::compile_element_rule( $custom_class, $properties );
			if ( is_string( $rule ) ) {
				$css .= $rule;
			}
		}
		return $css;
	}

	/** @return array{post:\WP_Post,profile:array<string,mixed>,tree:list<array<string,mixed>>}|\WP_Error */
	private function document_context( int $post_id, bool $require_draft ) {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return new \WP_Error( 'sitepilot_edit_pages_denied', __( 'The connected user cannot edit pages.', 'sitepilot-mcp' ) );
		}
		$profile = $this->profile();
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'sitepilot_target_invalid', __( 'This Enfold operation requires an editable WordPress page.', 'sitepilot-mcp' ) );
		}
		if ( $require_draft && 'draft' !== $post->post_status ) {
			return new \WP_Error( 'sitepilot_target_invalid', __( 'Enfold template application and element styling can update only draft pages.', 'sitepilot-mcp' ) );
		}
		if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) || 'active' !== get_post_meta( $post_id, '_aviaLayoutBuilder_active', true ) ) {
			return new \WP_Error( 'sitepilot_enfold_document_inactive', __( 'The target is not an active Enfold Advanced Layout Builder page.', 'sitepilot-mcp' ) );
		}
		$content = get_post_meta( $post_id, '_aviaLayoutBuilderCleanData', true );
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return new \WP_Error( 'sitepilot_enfold_document_empty', __( 'The target page has no Enfold clean data.', 'sitepilot-mcp' ) );
		}
		$tree = EnfoldDocument::parse( $content );
		return is_wp_error( $tree ) ? $tree : array(
			'post'    => $post,
			'profile' => $profile,
			'tree'    => $tree,
		);
	}

	/**
	 * @param list<array<string,mixed>> $tree
	 * @param array<string,mixed>       $profile
	 * @param array<string,mixed>       $before
	 * @return list<array<string,mixed>>|\WP_Error
	 */
	private function persist_tree( \WP_Post $post, array $tree, array $profile, array $before ) {
		$content = EnfoldDocument::serialize( $tree );
		$updated = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		$saved    = $this->save_native_alb( $post->ID, $content );
		$verified = is_wp_error( $saved ) ? $saved : $this->verify_saved_page( $post->ID, $content, $profile );
		if ( ! is_wp_error( $verified ) ) {
			$clean      = get_post_meta( $post->ID, '_aviaLayoutBuilderCleanData', true );
			$saved_tree = is_string( $clean ) ? EnfoldDocument::parse( $clean ) : new \WP_Error( 'sitepilot_enfold_clean_data_missing', __( 'Enfold clean data could not be reloaded after saving.', 'sitepilot-mcp' ) );
			if ( ! is_wp_error( $saved_tree ) ) {
				return $saved_tree;
			}
			$verified = $saved_tree;
		}
		$recovered = $this->recover_failed_execution( $post->ID, $before );
		return self::with_error_data(
			$verified,
			array(
				'automatic_rollback' => is_wp_error( $recovered ) ? 'failed' : 'passed',
				'rollback_error'     => is_wp_error( $recovered ) ? $recovered->get_error_code() : null,
			)
		);
	}

	/** @param array<string,mixed> $action @return array<string,mixed>|\WP_Error */
	private function preview_stage( array $action ) {
		$validated = $this->validate_stage( $action, true );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$post = $validated['post'];
		return array(
			'operation' => 'design.stage',
			'target'    => $post instanceof \WP_Post ? (string) $post->ID : 'new:page',
			'before'    => $post instanceof \WP_Post ? $this->snapshot( $post ) : null,
			'after'     => array(
				'builder'                               => 'enfold',
				'title'                                 => $validated['payload']['post_title'],
				'slug'                                  => $validated['payload']['post_name'],
				'status'                                => 'draft',
				'parent'                                => $validated['payload']['post_parent'],
				'page_template'                         => $validated['page_template'],
				'featured_media'                        => $validated['featured_media_id'],
				'profile_fingerprint'                   => $validated['profile']['fingerprint'],
				'native_shortcode_registry_fingerprint' => $validated['profile']['shortcode_registry_fingerprint'],
				'css_sha256'                            => $validated['compiled_css_sha256'],
			),
		);
	}

	/** @param array<string,mixed> $action @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_stage( array $action ) {
		$validated = $this->validate_stage( $action );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$post   = $validated['post'];
		$before = $post instanceof \WP_Post ? $this->snapshot( $post ) : null;
		$result = $post instanceof \WP_Post ? wp_update_post( $validated['payload'], true ) : wp_insert_post( $validated['payload'], true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$post_id = (int) $result;
		$saved   = $this->save_native_alb( $post_id, $validated['payload']['post_content'] );
		if ( is_wp_error( $saved ) ) {
			$recovered = $this->recover_failed_execution( $post_id, $before );
			return self::with_error_data(
				$saved,
				array(
					'automatic_rollback' => is_wp_error( $recovered ) ? 'failed' : 'passed',
					'rollback_error'     => is_wp_error( $recovered ) ? $recovered->get_error_code() : null,
				)
			);
		}
		$metadata_saved = $this->persist_stage_post_fields( $post_id, $validated['payload'] );
		if ( is_wp_error( $metadata_saved ) ) {
			$recovered = $this->recover_failed_execution( $post_id, $before );
			return self::with_error_data(
				$metadata_saved,
				array(
					'automatic_rollback' => is_wp_error( $recovered ) ? 'failed' : 'passed',
					'rollback_error'     => is_wp_error( $recovered ) ? $recovered->get_error_code() : null,
				)
			);
		}
		if ( '' !== $validated['compiled_css'] ) {
			update_post_meta( $post_id, '_sitepilot_enfold_compiled_css', $validated['compiled_css'] );
		} elseif ( $validated['compiled_css_supplied'] ) {
			delete_post_meta( $post_id, '_sitepilot_enfold_compiled_css' );
		}
		$stored_css = get_post_meta( $post_id, '_sitepilot_enfold_compiled_css', true );
		if ( ! is_string( $stored_css ) || ! hash_equals( $validated['compiled_css_sha256'], hash( 'sha256', $stored_css ) ) ) {
			$recovered = $this->recover_failed_execution( $post_id, $before );
			return new \WP_Error(
				'sitepilot_enfold_css_persist_failed',
				__( 'Page-scoped compiler CSS could not be persisted with its approved hash.', 'sitepilot-mcp' ),
				array(
					'automatic_rollback' => is_wp_error( $recovered ) ? 'failed' : 'passed',
					'rollback_error'     => is_wp_error( $recovered ) ? $recovered->get_error_code() : null,
				)
			);
		}
		update_post_meta( $post_id, '_wp_page_template', $validated['page_template'] );
		if ( $validated['featured_media_id'] > 0 ) {
			set_post_thumbnail( $post_id, $validated['featured_media_id'] );
		} else {
			delete_post_thumbnail( $post_id );
		}
		$verified = $this->verify_saved_page( $post_id, $validated['payload']['post_content'], $validated['profile'] );
		if ( is_wp_error( $verified ) ) {
			$recovered = $this->recover_failed_execution( $post_id, $before );
			return self::with_error_data(
				$verified,
				array(
					'automatic_rollback' => is_wp_error( $recovered ) ? 'failed' : 'passed',
					'rollback_error'     => is_wp_error( $recovered ) ? $recovered->get_error_code() : null,
				)
			);
		}
		$clean           = get_post_meta( $post_id, '_aviaLayoutBuilderCleanData', true );
		$assets_verified = self::verify_compiled_assets(
			$validated['payload']['post_content'],
			is_string( $clean ) ? $clean : '',
			$validated['compiled_assets'],
			is_string( $stored_css ) ? $stored_css : ''
		);
		if ( is_wp_error( $assets_verified ) ) {
			$recovered = $this->recover_failed_execution( $post_id, $before );
			return self::with_error_data(
				$assets_verified,
				array(
					'automatic_rollback' => is_wp_error( $recovered ) ? 'failed' : 'passed',
					'rollback_error'     => is_wp_error( $recovered ) ? $recovered->get_error_code() : null,
				)
			);
		}
		$scope_verified = self::validate_compiled_css_scope( is_string( $clean ) ? $clean : '', is_string( $stored_css ) ? $stored_css : '' );
		if ( is_wp_error( $scope_verified ) ) {
			$recovered = $this->recover_failed_execution( $post_id, $before );
			return self::with_error_data(
				$scope_verified,
				array(
					'automatic_rollback' => is_wp_error( $recovered ) ? 'failed' : 'passed',
					'rollback_error'     => is_wp_error( $recovered ) ? $recovered->get_error_code() : null,
				)
			);
		}
		clean_post_cache( $post_id );
		do_action( 'sitepilot_mcp_enfold_css_updated', $post_id, $validated['compiled_css_sha256'] );
		return array(
			'result'   => array(
				'post_id'                               => $post_id,
				'builder'                               => 'enfold',
				'slug'                                  => (string) get_post_field( 'post_name', $post_id ),
				'preview_url'                           => get_preview_post_link( $post_id ),
				'native_pipeline'                       => true,
				'canonical_sha256'                      => hash( 'sha256', $validated['payload']['post_content'] ),
				'normalized_clean_sha256'               => is_string( $clean ) ? hash( 'sha256', $clean ) : '',
				'css_sha256'                            => $validated['compiled_css_sha256'],
				'css_installed'                         => '' !== $stored_css,
				'asset_validation'                      => array(
					'status' => 'passed',
					'assets' => count( $validated['compiled_assets'] ),
				),
				'native_shortcode_registry_fingerprint' => $validated['profile']['shortcode_registry_fingerprint'],
			),
			'rollback' => $before ? array(
				'operation' => 'restore_enfold_design',
				'snapshot'  => $before,
			) : array(
				'operation' => 'delete_created_enfold_post',
				'post_id'   => $post_id,
			),
		);
	}

	/** @param array<string,mixed> $action @return array<string,mixed>|\WP_Error */
	private function preview_compilation( array $action ) {
		$target_valid = $this->validate_compilation_target( $action );
		if ( is_wp_error( $target_valid ) ) {
			return $target_valid;
		}
		$compiled = ( new EnfoldHtmlCompiler() )->compile( is_array( $action['input'] ?? null ) ? $action['input'] : array() );
		if ( is_wp_error( $compiled ) ) {
			return $compiled;
		}
		$stage   = $this->compiled_stage_action( $action, $compiled );
		$preview = $this->preview_stage( $stage );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$preview['operation']                     = 'design.compile_enfold_html';
		$preview['after']['compiler']             = 'native-enfold-v2';
		$preview['after']['source_sha256']        = $compiled['source_sha256'];
		$preview['after']['ir_sha256']            = $compiled['ir_sha256'];
		$preview['after']['alb_sha256']           = $compiled['alb_sha256'];
		$preview['after']['css_sha256']           = $compiled['css_sha256'];
		$preview['after']['coverage']             = $compiled['coverage'];
		$preview['after']['compiled']             = $compiled['compiled'];
		$preview['after']['unmapped']             = $compiled['unmapped'];
		$preview['after']['hierarchy_validation'] = $compiled['hierarchy_validation'];
		$preview['after']['css']                  = $compiled['css'];
		$preview['after']['assets']               = $compiled['assets'];
		$preview['after']['links']                = $compiled['links'];
		$preview['after']['custom_css_needed']    = $compiled['custom_css_needed'];
		$preview['after']['fallback_used']        = false;
		return $preview;
	}

	/** @param array<string,mixed> $action @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_compilation( array $action ) {
		$target_valid = $this->validate_compilation_target( $action );
		if ( is_wp_error( $target_valid ) ) {
			return $target_valid;
		}
		$compiled = ( new EnfoldHtmlCompiler() )->compile( is_array( $action['input'] ?? null ) ? $action['input'] : array() );
		if ( is_wp_error( $compiled ) ) {
			return $compiled;
		}
		$result = $this->execute_stage( $this->compiled_stage_action( $action, $compiled ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['result']['compiler']             = 'native-enfold-v2';
		$result['result']['source_sha256']        = $compiled['source_sha256'];
		$result['result']['ir_sha256']            = $compiled['ir_sha256'];
		$result['result']['alb_sha256']           = $compiled['alb_sha256'];
		$result['result']['css_sha256']           = $compiled['css_sha256'];
		$result['result']['coverage']             = $compiled['coverage'];
		$result['result']['compiled']             = $compiled['compiled'];
		$result['result']['unmapped']             = $compiled['unmapped'];
		$result['result']['hierarchy_validation'] = $compiled['hierarchy_validation'];
		$result['result']['css']                  = $compiled['css'];
		$result['result']['assets']               = $compiled['assets'];
		$result['result']['links']                = $compiled['links'];
		$result['result']['fallback_used']        = false;
		$result['result']['custom_css_needed']    = $compiled['custom_css_needed'];
		return $result;
	}

	/** @param array<string,mixed> $action @return true|\WP_Error */
	private function validate_compilation_target( array $action ) {
		$target = absint( $action['target'] ?? 0 );
		if ( 0 === $target ) {
			return true;
		}
		$post = get_post( $target );
		if ( $post instanceof \WP_Post && 'draft' !== $post->post_status ) {
			return new \WP_Error(
				'sitepilot_enfold_compile_target_not_draft',
				__( 'Guarded HTML compilation can update only an existing draft page. Published and other non-draft pages are never demoted.', 'sitepilot-mcp' ),
				array(
					'post_id'     => $target,
					'post_status' => $post->post_status,
				)
			);
		}
		return true;
	}

	/** @param array<string,mixed> $action @param array<string,mixed> $compiled @return array<string,mixed> */
	private function compiled_stage_action( array $action, array $compiled ): array {
		$input  = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		$target = absint( $action['target'] ?? 0 );
		$post   = $target ? get_post( $target ) : null;
		if ( $post instanceof \WP_Post ) {
			$template = get_post_meta( $post->ID, '_wp_page_template', true );
			$defaults = array(
				'post_title'        => $post->post_title,
				'post_excerpt'      => $post->post_excerpt,
				'post_name'         => $post->post_name,
				'post_parent'       => $post->post_parent,
				'menu_order'        => $post->menu_order,
				'page_template'     => is_string( $template ) && '' !== $template ? $template : 'default',
				'featured_media_id' => absint( get_post_meta( $post->ID, '_thumbnail_id', true ) ),
			);
			foreach ( $defaults as $key => $value ) {
				if ( 'post_parent' === $key && array_key_exists( 'parent_slug', $input ) ) {
					continue;
				}
				if ( ! array_key_exists( $key, $input ) ) {
					$input[ $key ] = $value;
				}
			}
		}
		$input['builder']             = 'enfold';
		$input['alb_content']         = (string) $compiled['alb_content'];
		$input['compiled_css']        = (string) ( $compiled['scoped_css'] ?? '' );
		$input['compiled_css_sha256'] = (string) ( $compiled['css_sha256'] ?? hash( 'sha256', $input['compiled_css'] ) );
		$input['compiled_assets']     = is_array( $compiled['assets'] ?? null ) ? $compiled['assets'] : array();
		unset( $input['source_html'], $input['source_css'], $input['media_mappings'], $input['link_mappings'], $input['component_mappings'] );
		return array(
			'operation' => 'design.stage',
			'target'    => (string) ( $action['target'] ?? '0' ),
			'input'     => $input,
		);
	}

	/** @param array<string,mixed> $action @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_link_resolution( array $action ) {
		$profile = $this->profile();
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$slug = sanitize_title( (string) ( $action['target'] ?? '' ) );
		$post = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'sitepilot_target_missing', __( 'The Enfold page link target does not exist or cannot be edited.', 'sitepilot-mcp' ) );
		}
		$valid = self::validate_alb_content( $post->post_content );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$allowed  = $this->page_slugs( is_array( $action['input'] ?? null ) ? $action['input'] : array() );
		$resolved = 0;
		$content  = preg_replace_callback(
			'#sitepilot://page/([a-z0-9]+(?:-[a-z0-9]+)*)#u',
			static function ( array $reference ) use ( $allowed, &$resolved ): string {
				if ( ! in_array( $reference[1], $allowed, true ) ) {
					return $reference[0];
				}
				$linked = get_page_by_path( $reference[1], OBJECT, 'page' );
				if ( ! $linked instanceof \WP_Post ) {
					return $reference[0];
				}
				$url = get_permalink( $linked );
				if ( ! is_string( $url ) || '' === $url ) {
					return $reference[0];
				}
				++$resolved;
				return esc_url_raw( $url );
			},
			$post->post_content
		);
		if ( ! is_string( $content ) || str_contains( $content, 'sitepilot://page/' ) ) {
			return new \WP_Error( 'sitepilot_enfold_link_unresolved', __( 'One or more internal page references could not be resolved.', 'sitepilot-mcp' ) );
		}
		$before = $this->snapshot( $post );
		$result = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$saved    = $this->save_native_alb( $post->ID, $content );
		$verified = is_wp_error( $saved ) ? $saved : $this->verify_saved_page( $post->ID, $content, $profile );
		if ( is_wp_error( $verified ) ) {
			$recovered = $this->rollback(
				array(
					'operation' => 'restore_enfold_design',
					'snapshot'  => $before,
				)
			);
			return self::with_error_data(
				$verified,
				array(
					'automatic_rollback' => is_wp_error( $recovered ) ? 'failed' : 'passed',
					'rollback_error'     => is_wp_error( $recovered ) ? $recovered->get_error_code() : null,
				)
			);
		}
		return array(
			'result'   => array(
				'post_id'        => $post->ID,
				'slug'           => $slug,
				'resolved_links' => $resolved,
			),
			'rollback' => array(
				'operation' => 'restore_enfold_design',
				'snapshot'  => $before,
			),
		);
	}

	/** @param array<string,mixed> $action @return array{post:\WP_Post|null,payload:array<string,mixed>,profile:array<string,mixed>,page_template:string,featured_media_id:int}|\WP_Error */
	private function validate_stage( array $action, bool $allow_future_parent = false ) {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return new \WP_Error( 'sitepilot_edit_pages_denied', __( 'The connected user cannot edit pages.', 'sitepilot-mcp' ) );
		}
		$profile = $this->profile();
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$target = absint( $action['target'] ?? 0 );
		$post   = $target ? get_post( $target ) : null;
		if ( $target && ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || ! current_user_can( 'edit_post', $target ) ) ) {
			return new \WP_Error( 'sitepilot_target_invalid', __( 'Enfold can update only editable WordPress pages.', 'sitepilot-mcp' ) );
		}
		$input   = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		$content = (string) ( $input['alb_content'] ?? '' );
		$valid   = self::validate_alb_content( $content );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
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
		$compiled_css_supplied = array_key_exists( 'compiled_css', $input );
		$compiled_css          = $compiled_css_supplied
			? (string) $input['compiled_css']
			: ( $post instanceof \WP_Post ? (string) get_post_meta( $post->ID, '_sitepilot_enfold_compiled_css', true ) : '' );
		$compiled_css_sha256   = (string) ( $input['compiled_css_sha256'] ?? hash( 'sha256', $compiled_css ) );
		$css_valid             = self::validate_compiled_css( $compiled_css, $compiled_css_sha256 );
		if ( is_wp_error( $css_valid ) ) {
			return $css_valid;
		}
		$scope_valid = self::validate_compiled_css_scope( $content, $compiled_css );
		if ( is_wp_error( $scope_valid ) ) {
			return $scope_valid;
		}
		return array(
			'post'                  => $post instanceof \WP_Post ? $post : null,
			'profile'               => $profile,
			'page_template'         => $template,
			'featured_media_id'     => $featured,
			'compiled_css'          => $compiled_css,
			'compiled_css_sha256'   => $compiled_css_sha256,
			'compiled_css_supplied' => $compiled_css_supplied,
			'compiled_assets'       => is_array( $input['compiled_assets'] ?? null ) ? $input['compiled_assets'] : array(),
			'payload'               => array(
				'ID'           => $target,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => sanitize_text_field( (string) ( $input['post_title'] ?? '' ) ),
				'post_content' => $content,
				'post_excerpt' => sanitize_textarea_field( (string) ( $input['post_excerpt'] ?? '' ) ),
				'post_name'    => sanitize_title( (string) ( $input['post_name'] ?? '' ) ),
				'post_parent'  => $parent,
				'menu_order'   => max( -10000, min( 10000, intval( $input['menu_order'] ?? 0 ) ) ),
			),
		);
	}

	/** @param array<string,mixed> $payload @return true|\WP_Error */
	private function persist_stage_post_fields( int $post_id, array $payload ) {
		$expected = array(
			'post_status'  => 'draft',
			'post_title'   => (string) ( $payload['post_title'] ?? '' ),
			'post_excerpt' => (string) ( $payload['post_excerpt'] ?? '' ),
			'post_name'    => (string) ( $payload['post_name'] ?? '' ),
			'post_parent'  => absint( $payload['post_parent'] ?? 0 ),
			'menu_order'   => intval( $payload['menu_order'] ?? 0 ),
		);
		$post     = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
			return self::diagnostic_error(
				'sitepilot_enfold_page_metadata_missing',
				__( 'The staged Enfold page could not be reloaded after native normalization.', 'sitepilot-mcp' ),
				$post_id,
				'page_metadata'
			);
		}

		$actual = self::stage_post_fields( $post );
		if ( $expected !== $actual ) {
			$result = wp_update_post(
				array_merge(
					array( 'ID' => $post_id ),
					$expected
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return self::with_error_data(
					$result,
					array(
						'post_id'  => $post_id,
						'stage'    => 'page_metadata',
						'expected' => $expected,
						'actual'   => $actual,
					)
				);
			}
			clean_post_cache( $post_id );
			$post   = get_post( $post_id );
			$actual = $post instanceof \WP_Post ? self::stage_post_fields( $post ) : array();
		}

		if ( $expected !== $actual ) {
			return self::diagnostic_error(
				'sitepilot_enfold_page_metadata_mismatch',
				__( 'The staged Enfold page metadata differs from the approved change set.', 'sitepilot-mcp' ),
				$post_id,
				'page_metadata',
				array(
					'expected' => $expected,
					'actual'   => $actual,
				)
			);
		}
		return true;
	}

	/** @return array{post_status:string,post_title:string,post_excerpt:string,post_name:string,post_parent:int,menu_order:int} */
	private static function stage_post_fields( \WP_Post $post ): array {
		return array(
			'post_status'  => $post->post_status,
			'post_title'   => $post->post_title,
			'post_excerpt' => $post->post_excerpt,
			'post_name'    => $post->post_name,
			'post_parent'  => $post->post_parent,
			'menu_order'   => $post->menu_order,
		);
	}

	/** @param array<int,array<string,mixed>> $assets @return true|\WP_Error */
	private static function verify_compiled_assets( string $canonical, string $clean, array $assets, string $css = '' ) {
		foreach ( $assets as $asset ) {
			if ( 'unused' === ( $asset['usage'] ?? '' ) ) {
				continue;
			}
			$id     = absint( $asset['attachment_id'] ?? 0 );
			$source = (string) ( $asset['source'] ?? '' );
			$url    = $id > 0 && function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $id ) : false;
			$needle = is_string( $url ) ? esc_url_raw( $url ) : '';
			if ( 'css' === ( $asset['usage'] ?? '' ) ) {
				if ( $id > 0 && '' !== $needle && str_contains( $css, $needle ) ) {
					continue;
				}
				return self::compiled_asset_error( $source, $id, $needle, 'compiled_background_missing', 'av_section' );
			}
			if ( 'inline_background' === ( $asset['usage'] ?? '' ) ) {
				if ( $id > 0 && '' !== $needle && str_contains( $canonical, $needle ) && str_contains( $clean, $needle ) ) {
					continue;
				}
				return self::compiled_asset_error( $source, $id, $needle, 'preserved_background_missing', 'av_textblock' );
			}
			$has_id   = preg_match( "/\\battachment='" . preg_quote( (string) $id, '/' ) . "'/u", $canonical )
				|| preg_match( "/\\bids='[^']*\\b" . preg_quote( (string) $id, '/' ) . "\\b[^']*'/u", $canonical )
				|| preg_match( '/\\bdata-attachment-id=["\']' . preg_quote( (string) $id, '/' ) . '["\']/u', $canonical );
			$uses_url = str_contains( $canonical, "src='" . $needle . "'" ) || str_contains( $canonical, 'src="' . $needle . '"' );
			if ( $id <= 0 || '' === $needle || ! $has_id || ( ! $uses_url && ! str_contains( $canonical, "ids='" ) ) || ( $uses_url && ! str_contains( $clean, $needle ) ) ) {
				return self::compiled_asset_error( $source, $id, $needle, 'saved_asset_mismatch', $uses_url ? 'av_image' : 'av_gallery' );
			}
		}
		return true;
	}

	private static function compiled_asset_error( string $source, int $id, string $url, string $reason, string $shortcode ): \WP_Error {
		$message = __( 'A compiled Media Library image did not survive the native Enfold save pipeline.', 'sitepilot-mcp' );
		return new \WP_Error(
			'sitepilot_enfold_asset_render_failed',
			$message,
			array(
				'code'              => 'sitepilot_enfold_asset_render_failed',
				'message'           => $message,
				'source_asset'      => $source,
				'attachment_id'     => $id,
				'emitted_shortcode' => $shortcode,
				'preview_selector'  => 'av_section' === $shortcode ? '[style*="' . $url . '"]' : 'img[src="' . $url . '"]',
				'failure_reason'    => $reason,
			)
		);
	}

	/** @return true|\WP_Error */
	private static function validate_compiled_css_scope( string $content, string $css ) {
		if ( '' === trim( $css ) ) {
			return true;
		}
		if ( ! preg_match( '/\.(sitepilot-design-[a-f0-9]{12})\b/u', $css, $scope ) || ! str_contains( $content, $scope[1] ) ) {
			return new \WP_Error(
				'sitepilot_enfold_css_scope_content_mismatch',
				__( 'Page-scoped compiler CSS requires its matching scope class in the staged ALB content.', 'sitepilot-mcp' ),
				array( 'failure_reason' => 'compiled_css_scope_missing_from_content' )
			);
		}
		return true;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function discover_profile( int $alb_page_id, int $normal_page_id ) {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_pages' ) ) {
			return new \WP_Error( 'sitepilot_enfold_calibration_denied', __( 'Only an administrator who can edit pages may calibrate Enfold.', 'sitepilot-mcp' ) );
		}
		$theme = $this->active_theme();
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		$alb    = get_post( $alb_page_id );
		$normal = get_post( $normal_page_id );
		if ( ! $alb instanceof \WP_Post || ! $normal instanceof \WP_Post || $alb->ID === $normal->ID || 'page' !== $alb->post_type || 'page' !== $normal->post_type ) {
			return new \WP_Error( 'sitepilot_enfold_calibration_invalid', __( 'Choose two different WordPress pages: one manually saved ALB page and one normal page.', 'sitepilot-mcp' ) );
		}
		$alb_content = get_post_meta( $alb->ID, '_aviaLayoutBuilderCleanData', true );
		$valid       = is_string( $alb_content ) ? self::validate_alb_content( $alb_content ) : new \WP_Error( 'sitepilot_enfold_calibration_content' );
		if ( is_wp_error( $valid ) ) {
			return new \WP_Error( 'sitepilot_enfold_calibration_content', __( 'The ALB calibration page must contain valid Enfold clean data made from allowed editable ALB shortcodes.', 'sitepilot-mcp' ) );
		}
		if ( ! self::native_api_available() ) {
			return new \WP_Error( 'sitepilot_enfold_native_api_missing', __( 'Enfold does not expose the native ALB save APIs required for safe page staging.', 'sitepilot-mcp' ) );
		}
		$verified = $this->verify_alb_metadata( $alb->ID, $alb_content, false );
		if ( is_wp_error( $verified ) ) {
			return new \WP_Error( 'sitepilot_enfold_calibration_metadata', $verified->get_error_message() );
		}
		$metadata_keys = array_values( array_filter( self::GENERATED_META_KEYS, static fn ( string $key ): bool => metadata_exists( 'post', $alb->ID, $key ) ) );
		if ( 'active' === get_post_meta( $normal->ID, '_aviaLayoutBuilder_active', true ) || count( $metadata_keys ) < 6 ) {
			return new \WP_Error( 'sitepilot_enfold_calibration_invalid', __( 'The ALB and normal comparison pages do not provide a trustworthy native-pipeline calibration pair.', 'sitepilot-mcp' ) );
		}
		$metadata_types = array();
		foreach ( $metadata_keys as $metadata_key ) {
			$metadata_types[ $metadata_key ] = self::value_type( get_post_meta( $alb->ID, $metadata_key, true ) );
		}
		$registry_fingerprint = EnfoldShortcodeRegistry::fingerprint( $theme['version'] );
		$fingerprint_input    = wp_json_encode( array( $theme, $metadata_keys, $metadata_types, $registry_fingerprint, 'native-pipeline-v3' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return array(
			'schema'                         => 3,
			'stylesheet'                     => $theme['stylesheet'],
			'template'                       => $theme['template'],
			'theme_version'                  => $theme['version'],
			'native_pipeline'                => true,
			'source_page_id'                 => $alb->ID,
			'comparison_page_id'             => $normal->ID,
			'sample_content_sha256'          => hash( 'sha256', $alb_content ),
			'metadata_keys'                  => $metadata_keys,
			'metadata_types'                 => $metadata_types,
			'shortcode_registry_fingerprint' => $registry_fingerprint,
			'fingerprint'                    => hash( 'sha256', is_string( $fingerprint_input ) ? $fingerprint_input : '' ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function profile() {
		$theme = $this->active_theme();
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		$profile = get_option( self::PROFILE_OPTION, array() );
		if ( ! is_array( $profile ) || 3 !== (int) ( $profile['schema'] ?? 0 ) || true !== ( $profile['native_pipeline'] ?? false ) || ! is_array( $profile['metadata_keys'] ?? null ) ) {
			return new \WP_Error( 'sitepilot_enfold_profile_required', __( 'Enfold must be calibrated from a manually saved ALB test page before SitePilot can stage Enfold pages.', 'sitepilot-mcp' ) );
		}
		if ( ! self::profile_matches_theme( $profile, $theme ) ) {
			return new \WP_Error( 'sitepilot_enfold_profile_outdated', __( 'The active Enfold theme no longer matches the verified ALB profile. Recalibrate before staging pages.', 'sitepilot-mcp' ) );
		}
		return $profile;
	}

	/** @param array<string,mixed> $profile @param array{stylesheet:string,template:string,version:string} $theme */
	private static function profile_matches_theme( array $profile, array $theme ): bool {
		return hash_equals( $theme['stylesheet'], (string) ( $profile['stylesheet'] ?? '' ) )
			&& hash_equals( $theme['template'], (string) ( $profile['template'] ?? '' ) )
			&& hash_equals( $theme['version'], (string) ( $profile['theme_version'] ?? '' ) )
			&& hash_equals( EnfoldShortcodeRegistry::fingerprint( $theme['version'] ), (string) ( $profile['shortcode_registry_fingerprint'] ?? '' ) )
			&& true === ( $profile['native_pipeline'] ?? false );
	}

	/** @return true|\WP_Error */
	private static function validate_compiled_css( string $css, string $expected_hash ) {
		if ( ! hash_equals( hash( 'sha256', $css ), $expected_hash ) ) {
			return new \WP_Error( 'sitepilot_enfold_css_hash_mismatch', __( 'Compiled page CSS does not match its approved hash.', 'sitepilot-mcp' ) );
		}
		if ( '' === $css ) {
			return true;
		}
		if ( strlen( $css ) > 500000 || preg_match( '#(?:@import\b|expression\s*\(|javascript\s*:|file\s*:|data\s*:|</?style\b)#iu', $css ) ) {
			return new \WP_Error( 'sitepilot_enfold_css_unsafe', __( 'Compiled page CSS is too large or contains unsafe content.', 'sitepilot-mcp' ) );
		}
		return EnfoldCssCompiler::validate_scoped( $css );
	}

	private static function native_api_available(): bool {
		if ( ! self::native_api_ready() && ! self::ensure_native_api() ) {
			return false;
		}
		return self::native_api_ready();
	}

	private static function native_api_ready(): bool {
		if ( ! class_exists( '\\AviaBuilder' ) || ! class_exists( '\\ShortcodeHelper' ) || ! function_exists( 'Avia_Builder' ) ) {
			return false;
		}
		if ( ! is_callable( array( '\\ShortcodeHelper', 'build_shortcode_tree' ) ) ) {
			return false;
		}
		$builder = self::builder_instance();
		if ( ! is_object( $builder )
			|| ! is_callable( array( $builder, 'set_alb_builder_status' ) )
			|| ! is_callable( array( $builder, 'handler_before_save_alb_post_data' ) )
			|| ! is_callable( array( $builder, 'meta_box_save' ) )
			|| ! is_callable( array( $builder, 'element_manager' ) )
			|| ! function_exists( 'Avia_Element_Templates' )
			|| ! function_exists( 'AviaPostCss' )
		) {
			return false;
		}
		$manager = call_user_func( array( $builder, 'element_manager' ) );
		$css     = \AviaPostCss();
		return is_object( $manager )
			&& is_callable( array( $manager, 'updated_post_content' ) )
			&& is_object( $css )
			&& is_callable( array( $css, 'handler_wp_save_post' ) );
	}

	private static function ensure_native_api(): bool {
		if ( self::native_api_ready() ) {
			return true;
		}
		if ( ! did_action( 'after_setup_theme' ) ) {
			return false;
		}
		$theme = wp_get_theme();
		if ( 'enfold' !== strtolower( $theme->get_stylesheet() ) && 'enfold' !== strtolower( $theme->get_template() ) ) {
			return false;
		}

		$template_dir = realpath( get_template_directory() );
		if ( false === $template_dir ) {
			return false;
		}
		$builder_file = realpath( $template_dir . '/config-templatebuilder/avia-template-builder/php/class-template-builder.php' );
		$theme_root   = trailingslashit( wp_normalize_path( $template_dir ) );
		$resolved     = false === $builder_file ? '' : wp_normalize_path( $builder_file );
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$theme_root = strtolower( $theme_root );
			$resolved   = strtolower( $resolved );
		}
		if ( '' === $resolved || ! str_starts_with( $resolved, $theme_root ) ) {
			return false;
		}

		try {
			require_once $builder_file;
			if ( ! function_exists( 'Avia_Builder' ) ) {
				return false;
			}
			$builder = \Avia_Builder();
			if ( ! is_object( $builder ) ) {
				return false;
			}
			if ( did_action( 'init' ) && ! class_exists( '\\ShortcodeHelper', false ) && is_callable( array( $builder, 'handler_wp_init' ) ) ) {
				call_user_func( array( $builder, 'handler_wp_init' ) );
			}
		} catch ( \Throwable $error ) {
			do_action( 'sitepilot_mcp_enfold_bootstrap_error', $error );
			return false;
		}

		return self::native_api_ready();
	}

	private static function builder_instance(): ?object {
		if ( ! function_exists( 'Avia_Builder' ) ) {
			return null;
		}
		try {
			$builder = \Avia_Builder();
			return is_object( $builder ) ? $builder : null;
		} catch ( \Throwable $error ) {
			do_action( 'sitepilot_mcp_enfold_bootstrap_error', $error );
			return null;
		}
	}

	/** @return true|\WP_Error */
	private function save_native_alb( int $post_id, string $content ) {
		if ( ! self::native_api_available() ) {
			return new \WP_Error( 'sitepilot_enfold_native_api_missing', __( 'Enfold does not expose the native ALB save APIs required for safe page staging.', 'sitepilot-mcp' ) );
		}
		$builder = self::builder_instance();
		if ( ! is_object( $builder ) ) {
			return new \WP_Error( 'sitepilot_enfold_native_api_missing', __( 'Enfold does not expose the native ALB save APIs required for safe page staging.', 'sitepilot-mcp' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'draft' !== $post->post_status ) {
			return self::diagnostic_error(
				'sitepilot_enfold_native_post_invalid',
				__( 'Enfold normalization requires an existing WordPress draft page.', 'sitepilot-mcp' ),
				$post_id,
				'native_precondition',
				array( 'actual_status' => $post instanceof \WP_Post ? $post->post_status : null )
			);
		}
		$manager = call_user_func( array( $builder, 'element_manager' ) );
		if ( ! is_object( $manager ) ) {
			return self::diagnostic_error( 'sitepilot_enfold_element_manager_missing', __( 'Enfold could not initialize its ALB element manager.', 'sitepilot-mcp' ), $post_id, 'native_precondition' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.WP.GlobalVariablesOverride.Prohibited -- This approved internal operation must provide and then restore the request context consumed by Enfold's native save API.
		$previous_post_data    = $_POST;
		$previous_request_data = $_REQUEST;
		$had_global_post       = array_key_exists( 'post', $GLOBALS );
		$previous_global_post  = $GLOBALS['post'] ?? null;

		try {
			$slashed_content = wp_slash( $content );
			$_POST           = array(
				'post_ID'                     => $post_id,
				'post_type'                   => 'page',
				'post_status'                 => 'draft',
				'aviaLayoutBuilder_active'    => 'active',
				'_aviaLayoutBuilderCleanData' => $slashed_content,
				'_avia_sc_parser_state'       => 'check_only',
				'content'                     => $slashed_content,
				'post_content'                => $slashed_content,
			);
			$_REQUEST        = array(
				'post_ID'                  => $post_id,
				'aviaLayoutBuilder_active' => 'active',
			);
			$GLOBALS['post'] = $post;

			$post_data = array(
				'ID'           => $post_id,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_content' => $content,
			);
			$prepared  = call_user_func( array( $builder, 'handler_before_save_alb_post_data' ), $post_data, $post_data );
			if ( ! is_array( $prepared ) || ! isset( $_POST['_aviaLayoutBuilderCleanData'] ) || ! is_string( $_POST['_aviaLayoutBuilderCleanData'] ) ) {
				return self::diagnostic_error( 'sitepilot_enfold_normalization_failed', __( 'Enfold did not return normalized ALB clean data.', 'sitepilot-mcp' ), $post_id, 'normalize' );
			}

			// Enfold's handler uses get_the_ID() for this write; REST/MCP has no loop ID.
			call_user_func( array( $builder, 'set_alb_builder_status' ), 'active', $post_id );
			call_user_func( array( $builder, 'meta_box_save' ) );
			// Enfold's own normalized shortcode data; sanitizing it would corrupt the layout.
			$normalized_content = wp_unslash( (string) $_POST['_aviaLayoutBuilderCleanData'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- internal handler call, see above.
			// The native save handler may leave a partial tree behind for composite
			// elements (for example av_toggle inside av_toggle_container). Rebuild it
			// from the normalized, page-specific ALB document before verification.
			$shortcode_tree = call_user_func( array( '\\ShortcodeHelper', 'build_shortcode_tree' ), $normalized_content );
			if ( ! is_array( $shortcode_tree ) ) {
				return self::diagnostic_error(
					'sitepilot_enfold_shortcode_tree_rebuild_failed',
					__( 'Enfold did not generate a valid shortcode tree from normalized ALB content.', 'sitepilot-mcp' ),
					$post_id,
					'shortcode_tree',
					array( 'actual_type' => self::value_type( $shortcode_tree ) )
				);
			}
			update_post_meta( $post_id, '_avia_builder_shortcode_tree', $shortcode_tree );
			$state_refreshed = call_user_func( array( $manager, 'updated_post_content' ), $normalized_content, $post_id );
			if ( false === $state_refreshed ) {
				return self::diagnostic_error(
					'sitepilot_enfold_element_state_refresh_failed',
					__( 'Enfold could not refresh the staged page element index.', 'sitepilot-mcp' ),
					$post_id,
					'element_state_refresh'
				);
			}
			call_user_func( array( \AviaPostCss(), 'handler_wp_save_post' ), $post_id, $post, true );
		} catch ( \Throwable $error ) {
			do_action( 'sitepilot_mcp_enfold_save_error', $error, $post_id );
			return self::diagnostic_error(
				'sitepilot_enfold_native_pipeline_failed',
				__( 'Enfold failed while normalizing or persisting the ALB page.', 'sitepilot-mcp' ),
				$post_id,
				'native_save',
				array( 'exception_type' => get_class( $error ) )
			);
		} finally {
			$_POST    = $previous_post_data;
			$_REQUEST = $previous_request_data;
			if ( $had_global_post ) {
				$GLOBALS['post'] = $previous_global_post;
			} else {
				unset( $GLOBALS['post'] );
			}
		}
		// phpcs:enable

		clean_post_cache( $post_id );
		return true;
	}

	/** @return true|\WP_Error */
	private function verify_alb_metadata( int $post_id, string $content, bool $reject_placeholders = true, array $profile = array() ) {
		$active = get_post_meta( $post_id, '_aviaLayoutBuilder_active', true );
		if ( 'active' !== $active ) {
			return self::diagnostic_error( 'sitepilot_enfold_status_invalid', __( 'Enfold did not mark the page as an active ALB document.', 'sitepilot-mcp' ), $post_id, 'recognition', array( 'actual_status' => $active ) );
		}
		$clean = get_post_meta( $post_id, '_aviaLayoutBuilderCleanData', true );
		if ( ! is_string( $clean ) ) {
			return self::diagnostic_error( 'sitepilot_enfold_clean_data_missing', __( 'Enfold did not persist normalized ALB clean data.', 'sitepilot-mcp' ), $post_id, 'clean_data', array( 'actual_type' => self::value_type( $clean ) ) );
		}
		if ( ! self::alb_content_equivalent( $content, $clean ) ) {
			return self::diagnostic_error(
				'sitepilot_enfold_clean_data_mismatch',
				__( 'Enfold clean data does not represent the supplied ALB content.', 'sitepilot-mcp' ),
				$post_id,
				'clean_data',
				array(
					'canonical_sha256' => hash( 'sha256', $content ),
					'clean_sha256'     => hash( 'sha256', $clean ),
					'canonical_tags'   => self::content_tags( $content ),
					'clean_tags'       => self::content_tags( $clean ),
				)
			);
		}
		$expected_tags = self::content_tags( $content );
		$clean_tags    = self::content_tags( $clean );
		if ( $expected_tags !== $clean_tags ) {
			return self::diagnostic_error(
				'sitepilot_enfold_clean_tree_mismatch',
				__( 'Enfold normalized clean data changed the ALB element tree.', 'sitepilot-mcp' ),
				$post_id,
				'clean_data',
				array(
					'canonical_tags' => $expected_tags,
					'clean_tags'     => $clean_tags,
				)
			);
		}
		$tree               = get_post_meta( $post_id, '_avia_builder_shortcode_tree', true );
		$tree_tags          = self::tree_tags( $tree );
		$expected_tree_tags = self::tree_comparable_tags( $expected_tags );
		$actual_tree_tags   = self::tree_comparable_tags( $tree_tags );
		if ( $expected_tree_tags !== $actual_tree_tags ) {
			return self::diagnostic_error(
				'sitepilot_enfold_tree_mismatch',
				__( 'The Enfold shortcode tree does not represent the staged ALB elements.', 'sitepilot-mcp' ),
				$post_id,
				'shortcode_tree',
				array(
					'expected_tags'            => $expected_tags,
					'actual_tags'              => $tree_tags,
					'expected_comparable_tags' => $expected_tree_tags,
					'actual_comparable_tags'   => $actual_tree_tags,
					'implicit_tree_shortcodes' => self::TREE_IMPLICIT_SHORTCODES,
				)
			);
		}
		$state = get_post_meta( $post_id, '_av_alb_posts_elements_state', true );
		if ( ! is_array( $state ) ) {
			return self::diagnostic_error( 'sitepilot_enfold_state_missing', __( 'Enfold did not generate page-specific ALB element-state metadata.', 'sitepilot-mcp' ), $post_id, 'element_state', array( 'actual_type' => self::value_type( $state ) ) );
		}
		foreach ( array_unique( $expected_tags ) as $tag ) {
			if ( empty( $state[ $tag ] ) ) {
				return self::diagnostic_error(
					'sitepilot_enfold_state_mismatch',
					__( 'Enfold element-state metadata does not list every staged ALB element.', 'sitepilot-mcp' ),
					$post_id,
					'element_state',
					array(
						'missing_element' => $tag,
						'state_elements'  => array_keys( $state ),
					)
				);
			}
		}

		$expected_types = self::REQUIRED_META_TYPES;
		if ( is_array( $profile['metadata_types'] ?? null ) ) {
			foreach ( $profile['metadata_types'] as $key => $type ) {
				if ( is_string( $key ) && is_string( $type ) ) {
					$expected_types[ $key ] = $type;
				}
			}
		}
		$required_keys = array_unique( array_merge( array_keys( self::REQUIRED_META_TYPES ), is_array( $profile['metadata_keys'] ?? null ) ? $profile['metadata_keys'] : array() ) );
		foreach ( $required_keys as $key ) {
			if ( ! metadata_exists( 'post', $post_id, $key ) ) {
				return self::diagnostic_error( 'sitepilot_enfold_metadata_missing', __( 'Enfold did not generate all calibrated ALB metadata.', 'sitepilot-mcp' ), $post_id, 'metadata', array( 'missing_key' => $key ) );
			}
			$value = get_post_meta( $post_id, $key, true );
			if ( isset( $expected_types[ $key ] ) && self::value_type( $value ) !== $expected_types[ $key ] ) {
				return self::diagnostic_error(
					'sitepilot_enfold_metadata_type_mismatch',
					__( 'Enfold generated ALB metadata with an unexpected data type.', 'sitepilot-mcp' ),
					$post_id,
					'metadata',
					array(
						'metadata_key'  => $key,
						'expected_type' => $expected_types[ $key ],
						'actual_type'   => self::value_type( $value ),
					)
				);
			}
		}
		if ( $reject_placeholders && preg_match( '/(?:__SITEPILOT_|\{\{\s*sitepilot[^}]*\}\})/iu', $content . "\n" . $clean ) ) {
			return self::diagnostic_error( 'sitepilot_enfold_placeholder_remaining', __( 'The staged ALB page still contains a SitePilot calibration placeholder.', 'sitepilot-mcp' ), $post_id, 'content_validation' );
		}
		return true;
	}

	/** @param list<string> $tags @return list<string> */
	private static function tree_comparable_tags( array $tags ): array {
		return array_values(
			array_filter(
				$tags,
				static fn ( string $tag ): bool => ! in_array( $tag, self::TREE_IMPLICIT_SHORTCODES, true )
			)
		);
	}

	private static function alb_content_equivalent( string $canonical, string $clean ): bool {
		$normalize = static function ( string $value ): string {
			$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
			$value = preg_replace( '/\s+av_uid\s*=\s*(["\']).*?\1/iu', '', $value );
			return is_string( $value ) ? trim( $value ) : '';
		};
		return hash_equals( $normalize( $canonical ), $normalize( $clean ) );
	}

	private static function value_type( mixed $value ): string {
		return match ( gettype( $value ) ) {
			'boolean' => 'boolean',
			'integer' => 'integer',
			'double'  => 'float',
			default   => gettype( $value ),
		};
	}

	/** @return list<string> */
	private static function content_tags( string $content ): array {
		preg_match_all( '/\[(?!\/)(av_[A-Za-z0-9_-]+)(?:\s[^\]]*)?\/?\]/u', $content, $matches );
		return array_values( array_map( 'strtolower', $matches[1] ?? array() ) );
	}

	/** @return list<string> */
	private static function tree_tags( mixed $tree ): array {
		$tags = array();
		if ( ! is_array( $tree ) ) {
			return $tags;
		}
		foreach ( $tree as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['tag'] ) && is_string( $item['tag'] ) ) {
				$tags[] = strtolower( $item['tag'] );
			}
			foreach ( $item as $value ) {
				if ( is_array( $value ) ) {
					$tags = array_merge( $tags, self::tree_tags( $value ) );
				}
			}
		}
		return $tags;
	}

	/** @return array{stylesheet:string,template:string,version:string}|\WP_Error */
	private function active_theme( bool $require_alb = true ) {
		$status = self::theme_status();
		if ( ! $status['active'] ) {
			return new \WP_Error( 'sitepilot_enfold_unavailable', __( 'Enfold or an Enfold child theme must be active.', 'sitepilot-mcp' ) );
		}
		if ( $require_alb && ! $status['alb_available'] ) {
			return new \WP_Error( 'sitepilot_enfold_alb_unavailable', __( 'The Enfold Advanced Layout Builder is not available.', 'sitepilot-mcp' ) );
		}
		return array(
			'stylesheet' => $status['stylesheet'],
			'template'   => $status['template'],
			'version'    => $status['version'],
		);
	}

	/** @return true|\WP_Error */
	private function verify_saved_page( int $post_id, string $content, array $profile = array() ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return self::diagnostic_error( 'sitepilot_enfold_post_missing', __( 'The staged Enfold page could not be reloaded after saving.', 'sitepilot-mcp' ), $post_id, 'post_reload' );
		}
		if ( 'page' !== $post->post_type || 'draft' !== $post->post_status ) {
			return self::diagnostic_error(
				'sitepilot_enfold_draft_invariant_failed',
				__( 'The staged Enfold document did not remain a draft page.', 'sitepilot-mcp' ),
				$post_id,
				'post_reload',
				array(
					'actual_type'   => $post->post_type,
					'actual_status' => $post->post_status,
				)
			);
		}
		if ( ! hash_equals( $content, $post->post_content ) ) {
			return self::diagnostic_error(
				'sitepilot_enfold_canonical_content_mismatch',
				__( 'The saved canonical page content changed during Enfold normalization.', 'sitepilot-mcp' ),
				$post_id,
				'post_reload',
				array(
					'expected_sha256' => hash( 'sha256', $content ),
					'actual_sha256'   => hash( 'sha256', $post->post_content ),
				)
			);
		}
		return $this->verify_alb_metadata( $post_id, $content, true, $profile );
	}

	/** @param array<string,mixed> $details */
	private static function diagnostic_error( string $code, string $message, int $post_id, string $stage, array $details = array() ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array(
				'component'   => 'enfold',
				'stage'       => $stage,
				'post_id'     => $post_id,
				'diagnostics' => $details,
			)
		);
	}

	/** @param array<string,mixed> $extra */
	private static function with_error_data( \WP_Error $error, array $extra ): \WP_Error {
		$data = $error->get_error_data();
		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), array_merge( is_array( $data ) ? $data : array(), $extra ) );
	}

	/** @return true|\WP_Error */
	private function sync_native_usage( \WP_Post $post, string $content ) {
		if ( ! self::native_api_available() ) {
			return new \WP_Error( 'sitepilot_enfold_rollback_native_api_missing', __( 'Enfold could not refresh its element index during rollback.', 'sitepilot-mcp' ) );
		}
		$builder = self::builder_instance();
		$manager = is_object( $builder ) ? call_user_func( array( $builder, 'element_manager' ) ) : null;
		$css     = function_exists( 'AviaPostCss' ) ? \AviaPostCss() : null;
		if ( ! is_object( $manager ) || ! is_callable( array( $manager, 'updated_post_content' ) ) || ! is_object( $css ) || ! is_callable( array( $css, 'handler_wp_save_post' ) ) ) {
			return new \WP_Error( 'sitepilot_enfold_rollback_index_failed', __( 'Enfold could not refresh its element index during rollback.', 'sitepilot-mcp' ) );
		}

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Enfold reads the current post while rebuilding its element-usage index; the prior value is restored immediately.
		$had_global_post      = array_key_exists( 'post', $GLOBALS );
		$previous_global_post = $GLOBALS['post'] ?? null;
		try {
			$GLOBALS['post'] = $post;
			$result          = call_user_func( array( $manager, 'updated_post_content' ), $content, $post->ID );
			call_user_func( array( $css, 'handler_wp_save_post' ), $post->ID, $post, true );
		} catch ( \Throwable $error ) {
			do_action( 'sitepilot_mcp_enfold_rollback_error', $error, $post->ID );
			return new \WP_Error( 'sitepilot_enfold_rollback_index_failed', __( 'Enfold could not refresh its element index during rollback.', 'sitepilot-mcp' ) );
		} finally {
			if ( $had_global_post ) {
				$GLOBALS['post'] = $previous_global_post;
			} else {
				unset( $GLOBALS['post'] );
			}
		}
		// phpcs:enable
		return false === $result ? new \WP_Error( 'sitepilot_enfold_rollback_index_failed', __( 'Enfold could not refresh its element index during rollback.', 'sitepilot-mcp' ) ) : true;
	}

	/** @return true|\WP_Error */
	private function delete_created_enfold_post( int $post_id, bool $check_permission ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
			return new \WP_Error( 'sitepilot_rollback_failed', __( 'The staged Enfold draft no longer exists.', 'sitepilot-mcp' ) );
		}
		if ( $check_permission && ! current_user_can( 'delete_post', $post_id ) ) {
			return new \WP_Error( 'sitepilot_rollback_denied', __( 'The connected user cannot remove this staged Enfold page.', 'sitepilot-mcp' ) );
		}
		$synced = $this->sync_native_usage( $post, '' );
		if ( is_wp_error( $synced ) ) {
			return $synced;
		}
		return false !== wp_delete_post( $post_id, true ) ? true : new \WP_Error( 'sitepilot_rollback_failed', __( 'Could not remove the created Enfold draft.', 'sitepilot-mcp' ) );
	}

	/** @return array<string,mixed> */
	private function snapshot( \WP_Post $post ): array {
		$keys = array_merge( self::GENERATED_META_KEYS, array( '_wp_page_template', '_thumbnail_id' ) );
		$meta = array();
		foreach ( array_unique( $keys ) as $key ) {
			$meta[ $key ] = metadata_exists( 'post', $post->ID, (string) $key ) ? get_post_meta( $post->ID, (string) $key, false ) : null;
		}
		return array(
			'post' => array(
				'ID'           => $post->ID,
				'post_type'    => $post->post_type,
				'post_status'  => $post->post_status,
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_name'    => $post->post_name,
				'post_parent'  => $post->post_parent,
				'menu_order'   => $post->menu_order,
			),
			'meta' => $meta,
		);
	}

	/** @param array<string,mixed>|null $before @return true|\WP_Error */
	private function recover_failed_execution( int $post_id, ?array $before ) {
		if ( null === $before ) {
			return $this->delete_created_enfold_post( $post_id, false );
		}
		return $this->rollback(
			array(
				'operation' => 'restore_enfold_design',
				'snapshot'  => $before,
			)
		);
	}

	/** @param array<string,mixed> $input @return int|\WP_Error */
	private function parent_id( array $input, bool $allow_future = false ) {
		$id   = absint( $input['post_parent'] ?? 0 );
		$slug = sanitize_title( (string) ( $input['parent_slug'] ?? '' ) );
		if ( '' !== $slug ) {
			$parent = get_page_by_path( $slug, OBJECT, 'page' );
			if ( ! $parent instanceof \WP_Post ) {
				if ( $allow_future ) {
					return 0;
				}
				return new \WP_Error( 'sitepilot_parent_missing', __( 'The requested parent page has not been staged.', 'sitepilot-mcp' ) );
			}
			if ( $id > 0 && $id !== $parent->ID ) {
				return new \WP_Error( 'sitepilot_parent_conflict', __( 'post_parent and parent_slug identify different pages.', 'sitepilot-mcp' ) );
			}
			$id = $parent->ID;
		}
		return $id;
	}

	/** @return string|\WP_Error */
	private function page_template( string $template ) {
		$template = sanitize_text_field( $template );
		if ( '' === $template || 'default' === $template ) {
			return 'default';
		}
		$templates = wp_get_theme()->get_page_templates( null, 'page' );
		if ( ! in_array( $template, array_keys( $templates ), true ) ) {
			return new \WP_Error( 'sitepilot_page_template_invalid', __( 'The requested page template is not registered by the active theme.', 'sitepilot-mcp' ) );
		}
		return $template;
	}

	/** @param array<string,mixed> $input @return list<string> */
	private function page_slugs( array $input ): array {
		$slugs = array();
		foreach ( (array) ( $input['page_slugs'] ?? array() ) as $slug ) {
			$value = sanitize_title( (string) $slug );
			if ( '' !== $value ) {
				$slugs[] = $value;
			}
		}
		return array_values( array_unique( $slugs ) );
	}
}
