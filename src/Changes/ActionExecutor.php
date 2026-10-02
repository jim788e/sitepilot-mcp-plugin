<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Changes;

use SitePilot\Mcp\Adapters\ElementorAdapter;
use SitePilot\Mcp\Adapters\EnfoldAdapter;
use SitePilot\Mcp\Adapters\ExtensionAdapter;
use SitePilot\Mcp\Adapters\NativeAdapter;
use SitePilot\Mcp\Adapters\WooCommerceAdapter;

final class ActionExecutor {
	private NativeAdapter $native;
	private ElementorAdapter $elementor;
	private EnfoldAdapter $enfold;
	private WooCommerceAdapter $woocommerce;
	private ExtensionAdapter $extensions;

	public function __construct() {
		$this->native      = new NativeAdapter();
		$this->elementor   = new ElementorAdapter();
		$this->enfold      = new EnfoldAdapter();
		$this->woocommerce = new WooCommerceAdapter();
		$this->extensions  = new ExtensionAdapter();
	}

	/** @param array<string,mixed> $action @return array<string,mixed>|\WP_Error */
	public function preview( array $action ) {
		$operation = (string) $action['operation'];
		$target    = (string) $action['target'];
		$input     = is_array( $action['input'] ?? null ) ? $action['input'] : array();

		switch ( $operation ) {
			case 'content.create_draft':
				$payload = $this->post_payload( $input, 'draft' );
				if ( is_wp_error( $payload ) ) {
					return $payload;
				}
				return array(
					'operation' => $operation,
					'target'    => 'new:' . $target,
					'before'    => null,
					'after'     => array_merge( $payload, array( 'featured_media_id' => absint( $input['featured_media_id'] ?? 0 ) ) ),
				);
			case 'content.publish':
			case 'content.bulk_update':
				$post = get_post( (int) $target );
				if ( ! $post ) {
					return new \WP_Error( 'sitepilot_target_missing', __( 'The target post does not exist.', 'sitepilot-mcp' ) );
				}
				$payload = $this->post_payload( $input, 'content.publish' === $operation ? 'publish' : $post->post_status, $post );
				if ( is_wp_error( $payload ) ) {
					return $payload;
				}
				return array(
					'operation' => $operation,
					'target'    => $target,
					'before'    => $this->post_snapshot( $post ),
					'after'     => array_merge( $this->post_snapshot( $post ), $payload, array( 'featured_media_id' => absint( $input['featured_media_id'] ?? get_post_thumbnail_id( $post ) ) ) ),
				);
			case 'site.update_setting':
				if ( ! in_array( $target, $this->allowed_settings(), true ) ) {
					return new \WP_Error( 'sitepilot_setting_blocked', __( 'That setting is not in the SitePilot allowlist.', 'sitepilot-mcp' ) );
				}
				return array(
					'operation' => $operation,
					'target'    => $target,
					'before'    => get_option( $target ),
					'after'     => $input['value'] ?? null,
				);
			case 'media.stage':
			case 'media.import_artifact':
			case 'site.create_menu':
			case 'site.assign_menu':
			case 'site.update_menu':
			case 'user.change_role':
			case 'security.update_setting':
				return $this->native->preview( $action );
			case 'design.stage':
			case 'design.compile_enfold_html':
			case 'design.resolve_links':
			case 'design.compile_elementor_html':
			case 'design.edit_elements':
			case 'design.save_template':
			case 'design.apply_template':
			case 'design.set_element_style':
			case 'design.update_global_kit':
			case 'design.stage_theme_document':
			case 'design.calibrate_enfold':
				$adapter = $this->design_adapter( $operation, $input );
				return is_wp_error( $adapter ) ? $adapter : $adapter->preview( $action );
			case 'commerce.update_price':
			case 'commerce.update_stock':
			case 'commerce.refund':
			case 'commerce.update_order':
				return $this->woocommerce->preview( $action );
			case 'extension.install':
			case 'extension.activate':
			case 'theme.activate':
			case 'core.update':
				return $this->extensions->preview( $action );
			case 'content.trash':
			case 'content.permanent_delete':
				if ( ! ctype_digit( $target ) || 0 === (int) $target ) {
					return new \WP_Error( 'sitepilot_target_invalid', __( 'Content deletion requires a numeric post ID.', 'sitepilot-mcp' ) );
				}
				if ( 'content.permanent_delete' === $operation && '1' !== get_option( 'sitepilot_mcp_permanent_delete', '0' ) ) {
					return new \WP_Error( 'sitepilot_feature_disabled', __( 'Permanent deletion is disabled. An administrator must enable it in SitePilot Security.', 'sitepilot-mcp' ) );
				}
				$post = get_post( (int) $target );
				if ( ! $post ) {
					return new \WP_Error( 'sitepilot_target_missing', __( 'The target post does not exist.', 'sitepilot-mcp' ) );
				}
				if ( ! current_user_can( 'delete_post', $post->ID ) ) {
					return new \WP_Error( 'sitepilot_delete_denied', __( 'The connected WordPress user cannot delete this post.', 'sitepilot-mcp' ) );
				}
				if ( 'content.trash' === $operation && 'trash' === $post->post_status ) {
					return new \WP_Error( 'sitepilot_invalid_state', __( 'The target post is already in the trash.', 'sitepilot-mcp' ) );
				}
				return array(
					'operation' => $operation,
					'target'    => $target,
					'before'    => $this->post_snapshot( $post ),
					'after'     => 'content.trash' === $operation ? array_merge( $this->post_snapshot( $post ), array( 'post_status' => 'trash' ) ) : null,
				);
			default:
				// translators: %s: unsupported action operation name.
				return apply_filters( 'sitepilot_mcp_preview_action', new \WP_Error( 'sitepilot_adapter_unavailable', sprintf( __( 'No active adapter can preview %s.', 'sitepilot-mcp' ), $operation ) ), $action );
		}
	}

	/** @param array<string,mixed> $action @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	public function execute( array $action ) {
		$preview = $this->preview( $action );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$operation = (string) $action['operation'];
		$target    = (string) $action['target'];
		$input     = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		switch ( $operation ) {
			case 'content.create_draft':
				$payload = $this->post_payload( $input, 'draft' );
				if ( is_wp_error( $payload ) ) {
					return $payload;
				}
				$post_id = wp_insert_post( $payload, true );
				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}
				$featured = $this->apply_featured_media( (int) $post_id, $input );
				if ( is_wp_error( $featured ) ) {
					wp_delete_post( (int) $post_id, true );
					return $featured;
				}
				return array(
					'result'   => array( 'post_id' => $post_id ),
					'rollback' => array(
						'operation' => 'delete_created_post',
						'post_id'   => $post_id,
					),
				);
			case 'content.publish':
			case 'content.bulk_update':
				$post = get_post( (int) $target );
				if ( ! $post ) {
					return new \WP_Error( 'sitepilot_target_missing', __( 'The target post does not exist.', 'sitepilot-mcp' ) );
				}
				$before  = $this->post_snapshot( $post );
				$updated = $this->post_payload( $input, 'content.publish' === $operation ? 'publish' : $post->post_status, $post );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				$payload = array_merge( array( 'ID' => (int) $target ), $updated );
				$result  = wp_update_post( $payload, true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$featured = $this->apply_featured_media( (int) $result, $input );
				if ( is_wp_error( $featured ) ) {
					$this->rollback(
						array(
							'operation' => 'restore_post',
							'post'      => $before,
						)
					);
					return $featured;
				}
				return array(
					'result'   => array( 'post_id' => $result ),
					'rollback' => array(
						'operation' => 'restore_post',
						'post'      => $before,
					),
				);
			case 'site.update_setting':
				$before = get_option( $target );
				update_option( $target, $input['value'] ?? null );
				return array(
					'result'   => array( 'option' => $target ),
					'rollback' => array(
						'operation' => 'restore_option',
						'option'    => $target,
						'value'     => $before,
					),
				);
			case 'media.stage':
			case 'media.import_artifact':
			case 'site.create_menu':
			case 'site.assign_menu':
			case 'site.update_menu':
			case 'user.change_role':
			case 'security.update_setting':
				return $this->native->execute( $action );
			case 'design.stage':
			case 'design.compile_enfold_html':
			case 'design.resolve_links':
			case 'design.compile_elementor_html':
			case 'design.edit_elements':
			case 'design.save_template':
			case 'design.apply_template':
			case 'design.set_element_style':
			case 'design.update_global_kit':
			case 'design.stage_theme_document':
			case 'design.calibrate_enfold':
				$adapter = $this->design_adapter( $operation, $input );
				return is_wp_error( $adapter ) ? $adapter : $adapter->execute( $action );
			case 'commerce.update_price':
			case 'commerce.update_stock':
			case 'commerce.refund':
			case 'commerce.update_order':
				return $this->woocommerce->execute( $action );
			case 'extension.install':
			case 'extension.activate':
			case 'theme.activate':
			case 'core.update':
				return $this->extensions->execute( $action );
			case 'content.trash':
				$post = get_post( (int) $target );
				if ( ! $post ) {
					return new \WP_Error( 'sitepilot_target_missing', __( 'The target post does not exist.', 'sitepilot-mcp' ) );
				}
				$before  = $this->post_snapshot( $post );
				$trashed = wp_trash_post( $post->ID );
				if ( ! $trashed ) {
					return new \WP_Error( 'sitepilot_delete_failed', __( 'WordPress could not move the post to the trash.', 'sitepilot-mcp' ) );
				}
				return array(
					'result'   => array(
						'post_id' => $post->ID,
						'status'  => 'trash',
					),
					'rollback' => array(
						'operation' => 'restore_post',
						'post'      => $before,
					),
				);
			case 'content.permanent_delete':
				$post = get_post( (int) $target );
				if ( ! $post ) {
					return new \WP_Error( 'sitepilot_target_missing', __( 'The target post does not exist.', 'sitepilot-mcp' ) );
				}
				$deleted = wp_delete_post( $post->ID, true );
				if ( ! $deleted ) {
					return new \WP_Error( 'sitepilot_delete_failed', __( 'WordPress could not permanently delete the post.', 'sitepilot-mcp' ) );
				}
				return array(
					'result'   => array(
						'post_id'             => $post->ID,
						'permanently_deleted' => true,
					),
					'rollback' => array(),
				);
			default:
				// translators: %s: unsupported action operation name.
				return apply_filters( 'sitepilot_mcp_execute_action', new \WP_Error( 'sitepilot_adapter_unavailable', sprintf( __( 'No active adapter can execute %s.', 'sitepilot-mcp' ), $operation ) ), $action );
		}
	}

	/** @param array<string,mixed> $rollback @return true|\WP_Error */
	public function rollback( array $rollback ) {
		switch ( $rollback['operation'] ?? '' ) {
			case 'delete_created_post':
				return false !== wp_delete_post( (int) $rollback['post_id'], true ) ? true : new \WP_Error( 'sitepilot_rollback_failed', __( 'Could not remove the created draft.', 'sitepilot-mcp' ) );
			case 'restore_post':
				$post     = (array) $rollback['post'];
				$featured = absint( $post['featured_media_id'] ?? 0 );
				unset( $post['featured_media_id'] );
				$result = wp_update_post( $post, true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( $featured > 0 ) {
					set_post_thumbnail( (int) $result, $featured );
				} else {
					delete_post_thumbnail( (int) $result );
				}
				return true;
			case 'restore_option':
				update_option( (string) $rollback['option'], $rollback['value'] ?? null );
				return true;
			case 'delete_menu_item':
			case 'delete_menu':
			case 'restore_menu_locations':
			case 'restore_menu_item':
			case 'delete_attachment':
			case 'restore_user_roles':
			case 'restore_native_design':
				return $this->native->rollback( $rollback );
			case 'restore_design':
			case 'restore_elementor_kit':
				return $this->elementor->rollback( $rollback );
			case 'restore_enfold_design':
			case 'restore_enfold_profile':
			case 'delete_created_enfold_post':
			case 'delete_created_enfold_template':
			case 'restore_enfold_options':
				return $this->enfold->rollback( $rollback );
			case 'restore_product':
			case 'restore_order':
			case 'irreversible_refund':
				return $this->woocommerce->rollback( $rollback );
			case 'noop':
			case 'deactivate_plugin':
			case 'delete_installed_plugin':
			case 'restore_theme':
			case 'manual_core_restore':
				return $this->extensions->rollback( $rollback );
			default:
				return apply_filters( 'sitepilot_mcp_rollback_action', new \WP_Error( 'sitepilot_rollback_unknown', __( 'Unknown rollback operation.', 'sitepilot-mcp' ) ), $rollback );
		}
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function post_payload( array $input, string $status, ?\WP_Post $existing = null ) {
		$post_type     = sanitize_key( (string) ( $input['post_type'] ?? ( $existing ? $existing->post_type : 'page' ) ) );
		$editing_pages = 'page' === $post_type;
		if ( ! current_user_can( $editing_pages ? 'edit_pages' : 'edit_posts' ) ) {
			return new \WP_Error( 'sitepilot_content_denied', __( 'The connected user cannot edit this content type.', 'sitepilot-mcp' ) );
		}
		if ( 'publish' === $status && ! current_user_can( $editing_pages ? 'publish_pages' : 'publish_posts' ) ) {
			return new \WP_Error( 'sitepilot_publish_denied', __( 'The connected user cannot publish this content type.', 'sitepilot-mcp' ) );
		}
		$payload = array(
			'post_type'   => $post_type,
			'post_status' => $status,
		);
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ) as $field ) {
			if ( isset( $input[ $field ] ) && is_string( $input[ $field ] ) ) {
				$payload[ $field ] = match ( $field ) {
					'post_content' => wp_kses_post( $input[ $field ] ),
					'post_excerpt' => sanitize_textarea_field( $input[ $field ] ),
					'post_name'    => sanitize_title( $input[ $field ] ),
					default        => sanitize_text_field( $input[ $field ] ),
				};
			}
		}
		if ( $editing_pages ) {
			if ( isset( $input['post_parent'] ) ) {
				$parent = absint( $input['post_parent'] );
				if ( $parent > 0 && ( 'page' !== get_post_type( $parent ) || ! current_user_can( 'edit_post', $parent ) ) ) {
					return new \WP_Error( 'sitepilot_parent_invalid', __( 'The requested parent must be an editable WordPress page.', 'sitepilot-mcp' ) );
				}
				$payload['post_parent'] = $parent;
			}
			if ( isset( $input['menu_order'] ) ) {
				$payload['menu_order'] = max( -10000, min( 10000, intval( $input['menu_order'] ) ) );
			}
			if ( isset( $input['page_template'] ) ) {
				$template = sanitize_text_field( (string) $input['page_template'] );
				if ( 'default' !== $template && ! in_array( $template, array_keys( wp_get_theme()->get_page_templates( $existing, 'page' ) ), true ) ) {
					return new \WP_Error( 'sitepilot_page_template_invalid', __( 'The requested page template is not registered by the active theme.', 'sitepilot-mcp' ) );
				}
				$payload['page_template'] = $template;
			}
		}
		if ( isset( $input['featured_media_id'] ) ) {
			$featured = absint( $input['featured_media_id'] );
			if ( $featured > 0 && ( ! current_user_can( 'upload_files' ) || ! wp_attachment_is_image( $featured ) ) ) {
				return new \WP_Error( 'sitepilot_featured_media_invalid', __( 'The featured image must be an existing Media Library image and the connected user must be allowed to upload files.', 'sitepilot-mcp' ) );
			}
		}
		return $payload;
	}

	/** @return array<string,mixed> */
	private function post_snapshot( \WP_Post $post ): array {
		return array(
			'ID'                => $post->ID,
			'post_type'         => $post->post_type,
			'post_status'       => $post->post_status,
			'post_title'        => $post->post_title,
			'post_content'      => $post->post_content,
			'post_excerpt'      => $post->post_excerpt,
			'post_name'         => $post->post_name,
			'post_parent'       => $post->post_parent,
			'menu_order'        => $post->menu_order,
			'page_template'     => 'page' === $post->post_type ? get_page_template_slug( $post ) : '',
			'featured_media_id' => get_post_thumbnail_id( $post ),
		);
	}

	/** @param array<string,mixed> $input @return true|\WP_Error */
	private function apply_featured_media( int $post_id, array $input ) {
		if ( ! array_key_exists( 'featured_media_id', $input ) ) {
			return true;
		}
		$featured = absint( $input['featured_media_id'] );
		if ( $featured > 0 ) {
			return set_post_thumbnail( $post_id, $featured ) ? true : new \WP_Error( 'sitepilot_featured_media_failed', __( 'WordPress could not set the featured image.', 'sitepilot-mcp' ) );
		}
		delete_post_thumbnail( $post_id );
		return true;
	}

	/** @param array<string,mixed> $input @return NativeAdapter|ElementorAdapter|EnfoldAdapter|\WP_Error */
	private function design_adapter( string $operation, array $input ) {
		if ( in_array( $operation, array( 'design.compile_enfold_html', 'design.calibrate_enfold' ), true ) ) {
			return $this->enfold;
		}
		if ( 'design.compile_elementor_html' === $operation ) {
			return $this->elementor;
		}
		// design.resolve_links predates Elementor compilation and defaulted to Enfold.
		// Keep that default so existing Enfold change sets route unchanged, and let an
		// explicit builder select the Elementor resolver. Element editing predates
		// Enfold support, so its omitted-builder default remains Elementor.
		$default = match ( $operation ) {
			'design.resolve_links' => 'enfold',
			'design.edit_elements' => 'elementor',
			'design.save_template', 'design.apply_template', 'design.update_global_kit', 'design.stage_theme_document' => 'elementor',
			'design.set_element_style' => 'enfold',
			default                => 'gutenberg',
		};
		return match ( sanitize_key( (string) ( $input['builder'] ?? $default ) ) ) {
			'gutenberg' => $this->native,
			'elementor' => $this->elementor,
			'enfold'    => $this->enfold,
			default     => new \WP_Error( 'sitepilot_builder_invalid', __( 'The requested design builder is not supported.', 'sitepilot-mcp' ) ),
		};
	}

	/** @return list<string> */
	private function allowed_settings(): array {
		return array( 'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'timezone_string', 'date_format', 'time_format', 'posts_per_page' );
	}
}
