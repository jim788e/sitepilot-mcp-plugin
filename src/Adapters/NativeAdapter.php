<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

use SitePilot\Mcp\Infrastructure\Capabilities;

final class NativeAdapter {
	/** @param array<string,mixed> $action @return array<string,mixed>|\WP_Error */
	public function preview( array $action ) {
		$operation = (string) $action['operation'];
		$target    = (string) $action['target'];
		$input     = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		if ( 'design.stage' === $operation ) {
			return $this->preview_gutenberg( $action );
		}
		if ( 'site.create_menu' === $operation ) {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return new \WP_Error( 'sitepilot_menu_denied', __( 'The connected user cannot manage navigation menus.', 'sitepilot-mcp' ) );
			}
			return array(
				'operation' => $operation,
				'target'    => 'new:menu',
				'before'    => null,
				'after'     => array( 'name' => sanitize_text_field( (string) ( $input['name'] ?? $target ) ) ),
			);
		}
		if ( 'site.assign_menu' === $operation ) {
			$assignment = $this->menu_assignment( $input, true );
			if ( is_wp_error( $assignment ) ) {
				return $assignment;
			}
			return array(
				'operation' => $operation,
				'target'    => $assignment['location'],
				'before'    => $assignment['before'],
				'after'     => array( 'menu_id' => $assignment['menu_id'] ),
			);
		}
		if ( 'site.update_menu' === $operation ) {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return new \WP_Error( 'sitepilot_menu_denied', __( 'The connected user cannot manage navigation menus.', 'sitepilot-mcp' ) );
			}
			$item = get_post( (int) $target );
			return array(
				'operation' => $operation,
				'target'    => $target,
				'before'    => $item ? $this->menu_snapshot( (int) $target ) : null,
				'after'     => $this->menu_payload( $input ),
			);
		}
		if ( in_array( $operation, array( 'media.stage', 'media.import_artifact' ), true ) ) {
			if ( ! current_user_can( 'upload_files' ) ) {
				return new \WP_Error( 'sitepilot_media_denied', __( 'The connected user cannot upload media.', 'sitepilot-mcp' ) );
			}
			if ( 'media.import_artifact' === $operation && ! Capabilities::artifact_import_available() ) {
				return new \WP_Error(
					'sitepilot_media_import_unavailable',
					__( 'Artifact import is unavailable without an explicitly configured external service. Upload or stage the file with media.stage instead.', 'sitepilot-mcp' ),
					array(
						'alternative' => 'media.stage',
						'reason'      => 'no_external_service_configured',
					)
				);
			}
			return array(
				'operation' => $operation,
				'target'    => 'new:' . $target,
				'before'    => null,
				'after'     => array(
					'filename'  => sanitize_file_name( (string) ( $input['filename'] ?? $target ) ),
					'mime_type' => sanitize_mime_type( (string) ( $input['mime_type'] ?? '' ) ),
					'status'    => 'staged',
				),
			);
		}
		if ( 'user.change_role' === $operation ) {
			$user = get_user_by( 'id', (int) $target );
			if ( ! $user ) {
				return new \WP_Error( 'sitepilot_target_missing', __( 'The target user does not exist.', 'sitepilot-mcp' ) );
			}
			$role = sanitize_key( (string) ( $input['role'] ?? '' ) );
			if ( ! get_role( $role ) ) {
				return new \WP_Error( 'sitepilot_invalid_role', __( 'The requested role does not exist.', 'sitepilot-mcp' ) );
			}
			return array(
				'operation' => $operation,
				'target'    => $target,
				'before'    => array( 'roles' => array_values( $user->roles ) ),
				'after'     => array( 'roles' => array( $role ) ),
			);
		}
		if ( 'security.update_setting' === $operation ) {
			if ( ! in_array( $target, $this->security_settings(), true ) ) {
				return new \WP_Error( 'sitepilot_setting_blocked', __( 'That security setting is not in the SitePilot allowlist.', 'sitepilot-mcp' ) );
			}
			return array(
				'operation' => $operation,
				'target'    => $target,
				'before'    => get_option( $target ),
				'after'     => $input['value'] ?? null,
			);
		}
		return new \WP_Error( 'sitepilot_adapter_unavailable', __( 'The native adapter does not support this action.', 'sitepilot-mcp' ) );
	}

	/** @param array<string,mixed> $action @return array<string,mixed>|\WP_Error */
	public function execute( array $action ) {
		$operation = (string) $action['operation'];
		$target    = (string) $action['target'];
		$input     = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		if ( 'design.stage' === $operation ) {
			return $this->execute_gutenberg( $action );
		}
		if ( 'site.create_menu' === $operation ) {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return new \WP_Error( 'sitepilot_menu_denied', __( 'The connected user cannot manage navigation menus.', 'sitepilot-mcp' ) );
			}
			$name = sanitize_text_field( (string) ( $input['name'] ?? $target ) );
			if ( '' === $name ) {
				return new \WP_Error( 'sitepilot_menu_invalid', __( 'A menu name is required.', 'sitepilot-mcp' ) );
			}
			$existing = wp_get_nav_menu_object( $name );
			if ( $existing ) {
				return array(
					'result'   => array(
						'menu_id' => (int) $existing->term_id,
						'created' => false,
					),
					'rollback' => array( 'operation' => 'noop' ),
				);
			}
			$menu_id = wp_create_nav_menu( $name );
			if ( is_wp_error( $menu_id ) ) {
				return $menu_id;
			}
			return array(
				'result'   => array(
					'menu_id' => (int) $menu_id,
					'created' => true,
				),
				'rollback' => array(
					'operation' => 'delete_menu',
					'menu_id'   => (int) $menu_id,
				),
			);
		}
		if ( 'site.assign_menu' === $operation ) {
			$assignment = $this->menu_assignment( $input );
			if ( is_wp_error( $assignment ) ) {
				return $assignment;
			}
			$locations                            = get_nav_menu_locations();
			$locations[ $assignment['location'] ] = $assignment['menu_id'];
			set_theme_mod( 'nav_menu_locations', $locations );
			return array(
				'result'   => array(
					'location' => $assignment['location'],
					'menu_id'  => $assignment['menu_id'],
				),
				'rollback' => array(
					'operation' => 'restore_menu_locations',
					'locations' => $assignment['locations'],
				),
			);
		}
		if ( 'site.update_menu' === $operation ) {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return new \WP_Error( 'sitepilot_menu_denied', __( 'The connected user cannot manage navigation menus.', 'sitepilot-mcp' ) );
			}
			$before  = get_post( (int) $target ) ? $this->menu_snapshot( (int) $target ) : null;
			$menu_id = $this->menu_id( $input );
			if ( is_wp_error( $menu_id ) || ! $menu_id || ! wp_get_nav_menu_object( $menu_id ) ) {
				return new \WP_Error( 'sitepilot_target_missing', __( 'The target menu does not exist.', 'sitepilot-mcp' ) );
			}
			$payload = $this->menu_payload( $input, $menu_id );
			if ( 'post_type' === $payload['menu-item-type'] && 0 === $payload['menu-item-object-id'] ) {
				return new \WP_Error( 'sitepilot_menu_page_missing', __( 'The referenced page has not been staged.', 'sitepilot-mcp' ) );
			}
			if ( isset( $input['parent_object_slug'] ) && 0 === $payload['menu-item-parent-id'] ) {
				return new \WP_Error( 'sitepilot_menu_parent_missing', __( 'The referenced parent menu item has not been created.', 'sitepilot-mcp' ) );
			}
			$result = wp_update_nav_menu_item( $menu_id, (int) $target, $payload );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array(
				'result'   => array( 'menu_item_id' => $result ),
				'rollback' => $before ? array(
					'operation' => 'restore_menu_item',
					'item'      => $before,
				) : array(
					'operation' => 'delete_menu_item',
					'post_id'   => $result,
				),
			);
		}
		if ( in_array( $operation, array( 'media.stage', 'media.import_artifact' ), true ) ) {
			if ( ! current_user_can( 'upload_files' ) ) {
				return new \WP_Error( 'sitepilot_media_denied', __( 'The connected user cannot upload media.', 'sitepilot-mcp' ) );
			}
			if ( 'media.import_artifact' === $operation ) {
				$bytes = $this->download_artifact( $input );
				if ( is_wp_error( $bytes ) ) {
					return $bytes;
				}
				return $this->stage_media_bytes( $target, $input, $bytes );
			}
			$encoded = (string) ( $input['content_base64'] ?? '' );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes an explicitly size-limited media API payload.
			$bytes = base64_decode( preg_replace( '#^data:[^,]+,#', '', $encoded ), true );
			if ( false === $bytes || '' === $bytes ) {
				return new \WP_Error( 'sitepilot_invalid_media', __( 'A valid base64 media payload is required.', 'sitepilot-mcp' ) );
			}
			if ( strlen( $bytes ) > 25 * MB_IN_BYTES ) {
				return new \WP_Error( 'sitepilot_media_too_large', __( 'The staged media file exceeds 25 MB.', 'sitepilot-mcp' ) );
			}
			return $this->stage_media_bytes( $target, $input, $bytes );
		}
		if ( 'user.change_role' === $operation ) {
			$user = get_user_by( 'id', (int) $target );
			if ( ! $user ) {
				return new \WP_Error( 'sitepilot_target_missing', __( 'The target user does not exist.', 'sitepilot-mcp' ) );
			}
			$before = array_values( $user->roles );
			$role   = sanitize_key( (string) ( $input['role'] ?? '' ) );
			if ( ! get_role( $role ) ) {
				return new \WP_Error( 'sitepilot_invalid_role', __( 'The requested role does not exist.', 'sitepilot-mcp' ) );
			}
			if ( get_current_user_id() === (int) $target && 'administrator' !== $role ) {
				return new \WP_Error( 'sitepilot_self_lockout_blocked', __( 'SitePilot will not remove the current approver administrator role.', 'sitepilot-mcp' ) );
			}
			$user->set_role( $role );
			return array(
				'result'   => array(
					'user_id' => $user->ID,
					'role'    => $role,
				),
				'rollback' => array(
					'operation' => 'restore_user_roles',
					'user_id'   => $user->ID,
					'roles'     => $before,
				),
			);
		}
		if ( 'security.update_setting' === $operation ) {
			if ( ! in_array( $target, $this->security_settings(), true ) ) {
				return new \WP_Error( 'sitepilot_setting_blocked', __( 'That security setting is not in the SitePilot allowlist.', 'sitepilot-mcp' ) );
			}
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
		}
		return new \WP_Error( 'sitepilot_adapter_unavailable', __( 'The native adapter does not support this action.', 'sitepilot-mcp' ) );
	}

	/** @param array<string,mixed> $rollback @return true|\WP_Error */
	public function rollback( array $rollback ) {
		switch ( $rollback['operation'] ?? '' ) {
			case 'delete_menu':
				return true === wp_delete_nav_menu( (int) $rollback['menu_id'] ) ? true : new \WP_Error( 'sitepilot_rollback_failed', __( 'Could not remove the created menu.', 'sitepilot-mcp' ) );
			case 'restore_menu_locations':
				set_theme_mod( 'nav_menu_locations', (array) ( $rollback['locations'] ?? array() ) );
				return true;
			case 'delete_menu_item':
				return false !== wp_delete_post( (int) $rollback['post_id'], true ) ? true : new \WP_Error( 'sitepilot_rollback_failed', __( 'Could not remove the created menu item.', 'sitepilot-mcp' ) );
			case 'restore_menu_item':
				$item   = (array) $rollback['item'];
				$result = wp_update_nav_menu_item( (int) $item['menu_id'], (int) $item['post_id'], (array) $item['payload'] );
				return is_wp_error( $result ) ? $result : true;
			case 'delete_attachment':
				return false !== wp_delete_attachment( (int) $rollback['post_id'], true ) ? true : new \WP_Error( 'sitepilot_rollback_failed', __( 'Could not remove the staged attachment.', 'sitepilot-mcp' ) );
			case 'restore_user_roles':
				$user = get_user_by( 'id', (int) $rollback['user_id'] );
				if ( ! $user ) {
					return new \WP_Error( 'sitepilot_rollback_failed', __( 'The user no longer exists.', 'sitepilot-mcp' ) );
				}
				$user->set_role( '' );
				foreach ( (array) $rollback['roles'] as $role ) {
					$user->add_role( sanitize_key( (string) $role ) );
				}
				return true;
			case 'restore_native_design':
				return $this->restore_gutenberg( (array) ( $rollback['snapshot'] ?? array() ) );
		}
		return new \WP_Error( 'sitepilot_rollback_unknown', __( 'Unknown native rollback operation.', 'sitepilot-mcp' ) );
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	private function menu_payload( array $input, int $menu_id = 0 ): array {
		$object_id = absint( $input['object_id'] ?? 0 );
		if ( ! $object_id && isset( $input['object_slug'] ) ) {
			$page      = $this->page_by_slug( (string) $input['object_slug'] );
			$object_id = $page instanceof \WP_Post ? $page->ID : 0;
		}
		$parent_id = absint( $input['parent_id'] ?? 0 );
		if ( ! $parent_id && $menu_id > 0 && isset( $input['parent_object_slug'] ) ) {
			$parent_page = $this->page_by_slug( (string) $input['parent_object_slug'] );
			if ( $parent_page instanceof \WP_Post ) {
				foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $menu_item ) {
					if ( 'page' === $menu_item->object && (int) $menu_item->object_id === $parent_page->ID ) {
						$parent_id = (int) $menu_item->ID;
						break;
					}
				}
			}
		}
		return array(
			'menu-item-title'     => sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
			'menu-item-url'       => esc_url_raw( (string) ( $input['url'] ?? '' ) ),
			'menu-item-status'    => 'publish',
			'menu-item-type'      => sanitize_key( (string) ( $input['type'] ?? 'custom' ) ),
			'menu-item-object'    => sanitize_key( (string) ( $input['object'] ?? 'custom' ) ),
			'menu-item-object-id' => $object_id,
			'menu-item-parent-id' => $parent_id,
			'menu-item-position'  => absint( $input['position'] ?? 0 ),
		);
	}

	/**
	 * Resolve a page by a full hierarchy path or an unambiguous leaf slug.
	 *
	 * WordPress requires the complete parent/child path in get_page_by_path().
	 * SitePilot site-build plans use unique leaf slugs, so fall back to a leaf
	 * lookup only when exactly one page has that slug.
	 */
	private function page_by_slug( string $reference ): ?\WP_Post {
		$path = implode(
			'/',
			array_filter(
				array_map( 'sanitize_title', explode( '/', trim( $reference, '/' ) ) )
			)
		);
		if ( '' === $path ) {
			return null;
		}
		$page = get_page_by_path( $path, OBJECT, 'page' );
		if ( $page instanceof \WP_Post ) {
			return $page;
		}
		$parts   = explode( '/', $path );
		$matches = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'name'           => end( $parts ),
				'posts_per_page' => 2,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		return 1 === count( $matches ) && $matches[0] instanceof \WP_Post ? $matches[0] : null;
	}

	/** @return array<string,mixed> */
	private function menu_snapshot( int $id ): array {
		$item  = wp_setup_nav_menu_item( get_post( $id ) );
		$menus = wp_get_object_terms( $id, 'nav_menu', array( 'fields' => 'ids' ) );
		return array(
			'post_id' => $id,
			'menu_id' => is_array( $menus ) && isset( $menus[0] ) ? (int) $menus[0] : 0,
			'payload' => array(
				'menu-item-title'     => $item->title,
				'menu-item-url'       => $item->url,
				'menu-item-status'    => $item->post_status,
				'menu-item-type'      => $item->type,
				'menu-item-object'    => $item->object,
				'menu-item-object-id' => $item->object_id,
				'menu-item-parent-id' => $item->menu_item_parent,
				'menu-item-position'  => $item->menu_order,
			),
		);
	}

	/** @param array<string,mixed> $action @return array<string,mixed>|\WP_Error */
	private function preview_gutenberg( array $action ) {
		$validated = $this->gutenberg_payload( $action, true );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$post = $validated['post'];
		return array(
			'operation' => 'design.stage',
			'target'    => $post instanceof \WP_Post ? (string) $post->ID : 'new:page',
			'before'    => $post instanceof \WP_Post ? $this->gutenberg_snapshot( $post ) : null,
			'after'     => array_merge( $validated['payload'], array( 'featured_media_id' => $validated['featured_media_id'] ) ),
		);
	}

	/** @param array<string,mixed> $action @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function execute_gutenberg( array $action ) {
		$validated = $this->gutenberg_payload( $action );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$post    = $validated['post'];
		$before  = $post instanceof \WP_Post ? $this->gutenberg_snapshot( $post ) : null;
		$post_id = $post instanceof \WP_Post ? wp_update_post( $validated['payload'], true ) : wp_insert_post( $validated['payload'], true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( $validated['featured_media_id'] > 0 ) {
			set_post_thumbnail( (int) $post_id, $validated['featured_media_id'] );
		} else {
			delete_post_thumbnail( (int) $post_id );
		}
		return array(
			'result'   => array(
				'post_id'     => (int) $post_id,
				'builder'     => 'gutenberg',
				'slug'        => (string) get_post_field( 'post_name', (int) $post_id ),
				'preview_url' => get_preview_post_link( (int) $post_id ),
			),
			'rollback' => $before ? array(
				'operation' => 'restore_native_design',
				'snapshot'  => $before,
			) : array(
				'operation' => 'delete_created_post',
				'post_id'   => (int) $post_id,
			),
		);
	}

	/** @param array<string,mixed> $action @return array{post:\WP_Post|null,payload:array<string,mixed>,featured_media_id:int}|\WP_Error */
	private function gutenberg_payload( array $action, bool $allow_future_parent = false ) {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return new \WP_Error( 'sitepilot_edit_pages_denied', __( 'The connected user cannot edit pages.', 'sitepilot-mcp' ) );
		}
		$target = absint( $action['target'] ?? 0 );
		$post   = $target ? get_post( $target ) : null;
		if ( $target && ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || ! current_user_can( 'edit_post', $target ) ) ) {
			return new \WP_Error( 'sitepilot_target_invalid', __( 'Gutenberg staging can update only editable WordPress pages.', 'sitepilot-mcp' ) );
		}
		$input  = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		$parent = $this->page_parent( $input, $allow_future_parent );
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
			'post'              => $post instanceof \WP_Post ? $post : null,
			'featured_media_id' => $featured,
			'payload'           => array(
				'ID'            => $target,
				'post_type'     => 'page',
				'post_status'   => 'draft',
				'post_title'    => sanitize_text_field( (string) ( $input['post_title'] ?? '' ) ),
				'post_content'  => wp_kses_post( (string) ( $input['post_content'] ?? '' ) ),
				'post_excerpt'  => sanitize_textarea_field( (string) ( $input['post_excerpt'] ?? '' ) ),
				'post_name'     => sanitize_title( (string) ( $input['post_name'] ?? '' ) ),
				'post_parent'   => $parent,
				'menu_order'    => max( -10000, min( 10000, intval( $input['menu_order'] ?? 0 ) ) ),
				'page_template' => $template,
			),
		);
	}

	/** @return array<string,mixed> */
	private function gutenberg_snapshot( \WP_Post $post ): array {
		return array(
			'post'         => array(
				'ID'            => $post->ID,
				'post_type'     => $post->post_type,
				'post_status'   => $post->post_status,
				'post_title'    => $post->post_title,
				'post_content'  => $post->post_content,
				'post_excerpt'  => $post->post_excerpt,
				'post_name'     => $post->post_name,
				'post_parent'   => $post->post_parent,
				'menu_order'    => $post->menu_order,
				'page_template' => get_page_template_slug( $post ),
			),
			'thumbnail_id' => get_post_thumbnail_id( $post ),
		);
	}

	/** @param array<string,mixed> $snapshot @return true|\WP_Error */
	private function restore_gutenberg( array $snapshot ) {
		$post    = (array) ( $snapshot['post'] ?? array() );
		$post_id = absint( $post['ID'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'sitepilot_rollback_denied', __( 'The connected user cannot restore this Gutenberg page.', 'sitepilot-mcp' ) );
		}
		$result = wp_update_post( $post, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$thumbnail = absint( $snapshot['thumbnail_id'] ?? 0 );
		if ( $thumbnail > 0 ) {
			set_post_thumbnail( $post_id, $thumbnail );
		} else {
			delete_post_thumbnail( $post_id );
		}
		return true;
	}

	/** @param array<string,mixed> $input @return int|\WP_Error */
	private function page_parent( array $input, bool $allow_future = false ) {
		$id   = absint( $input['post_parent'] ?? 0 );
		$slug = sanitize_title( (string) ( $input['parent_slug'] ?? '' ) );
		if ( '' === $slug ) {
			return $id;
		}
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

	/** @param array<string,mixed> $input @return int|\WP_Error */
	private function menu_id( array $input ) {
		$menu_id = absint( $input['menu_id'] ?? 0 );
		if ( $menu_id > 0 ) {
			return $menu_id;
		}
		if ( isset( $input['menu_slug'] ) ) {
			$menu = wp_get_nav_menu_object( sanitize_title( (string) $input['menu_slug'] ) );
			if ( $menu ) {
				return (int) $menu->term_id;
			}
		}
		if ( isset( $input['menu_name'] ) ) {
			$menu = wp_get_nav_menu_object( sanitize_text_field( (string) $input['menu_name'] ) );
			if ( $menu ) {
				return (int) $menu->term_id;
			}
		}
		if ( isset( $input['location'] ) ) {
			$locations = get_nav_menu_locations();
			return absint( $locations[ sanitize_key( (string) $input['location'] ) ] ?? 0 );
		}
		return new \WP_Error( 'sitepilot_menu_missing', __( 'Identify a menu by ID, slug, or registered theme location.', 'sitepilot-mcp' ) );
	}

	/** @param array<string,mixed> $input @return array{location:string,menu_id:int,before:int,locations:array<string,int>}|\WP_Error */
	private function menu_assignment( array $input, bool $allow_future = false ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new \WP_Error( 'sitepilot_menu_denied', __( 'The connected user cannot manage navigation menus.', 'sitepilot-mcp' ) );
		}
		$location = sanitize_key( (string) ( $input['location'] ?? '' ) );
		if ( '' === $location || ! array_key_exists( $location, get_registered_nav_menus() ) ) {
			return new \WP_Error( 'sitepilot_menu_location_invalid', __( 'The requested menu location is not registered by the active theme.', 'sitepilot-mcp' ) );
		}
		$menu_id = $this->menu_id( $input );
		if ( ( is_wp_error( $menu_id ) || ! wp_get_nav_menu_object( $menu_id ) ) && ! ( $allow_future && isset( $input['menu_name'] ) ) ) {
			return new \WP_Error( 'sitepilot_menu_missing', __( 'The requested navigation menu does not exist.', 'sitepilot-mcp' ) );
		}
		if ( is_wp_error( $menu_id ) ) {
			$menu_id = 0;
		}
		$locations = get_nav_menu_locations();
		return array(
			'location'  => $location,
			'menu_id'   => $menu_id,
			'before'    => absint( $locations[ $location ] ?? 0 ),
			'locations' => $locations,
		);
	}

	/** @param array<string,mixed> $input @return string|\WP_Error */
	private function download_artifact( array $input ) {
		if ( ! Capabilities::artifact_import_available() ) {
			return new \WP_Error(
				'sitepilot_media_import_unavailable',
				__( 'Artifact import is unavailable without an explicitly configured external service. Upload or stage the file with media.stage instead.', 'sitepilot-mcp' ),
				array(
					'alternative' => 'media.stage',
					'reason'      => 'no_external_service_configured',
				)
			);
		}
		$url    = esc_url_raw( (string) ( $input['source_url'] ?? '' ) );
		$parts  = wp_parse_url( $url );
		$origin = Capabilities::artifact_origin();
		if ( ! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| strtolower( (string) ( $parts['scheme'] ?? '' ) ) . '://' . strtolower( (string) ( $parts['host'] ?? '' ) ) !== $origin
			|| ! preg_match( '#^/media-artifacts/[a-f0-9]{64}$#', (string) ( $parts['path'] ?? '' ) )
			|| ! empty( $parts['user'] )
			|| ! empty( $parts['pass'] )
			|| ! empty( $parts['port'] )
			|| ! empty( $parts['query'] )
			|| ! empty( $parts['fragment'] ) ) {
			return new \WP_Error( 'sitepilot_artifact_url_invalid', __( 'A public HTTPS artifact URL is required.', 'sitepilot-mcp' ) );
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 30,
				'redirection'         => 0,
				'limit_response_size' => 25 * MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'sitepilot_artifact_download_failed', __( 'The validated media artifact could not be downloaded.', 'sitepilot-mcp' ) );
		}
		$bytes    = wp_remote_retrieve_body( $response );
		$expected = strtolower( sanitize_text_field( (string) ( $input['sha256'] ?? '' ) ) );
		if ( '' === $bytes || ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $expected, hash( 'sha256', $bytes ) ) ) {
			return new \WP_Error( 'sitepilot_artifact_hash_invalid', __( 'The downloaded media artifact failed SHA-256 verification.', 'sitepilot-mcp' ) );
		}
		return $bytes;
	}

	/** @param array<string,mixed> $input @return array{result:array<string,mixed>,rollback:array<string,mixed>}|\WP_Error */
	private function stage_media_bytes( string $target, array $input, string $bytes ) {
		if ( '' === $bytes || strlen( $bytes ) > 25 * MB_IN_BYTES ) {
			return new \WP_Error( 'sitepilot_media_too_large', __( 'The staged media file must be non-empty and no larger than 25 MB.', 'sitepilot-mcp' ) );
		}
		$sha256    = hash( 'sha256', $bytes );
		$duplicate = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_sitepilot_sha256',
				'meta_value'     => $sha256,
			)
		);
		if ( isset( $duplicate[0] ) ) {
			$attachment_id = (int) $duplicate[0];
			return array(
				'result'   => array(
					'attachment_id' => $attachment_id,
					'url'           => wp_get_attachment_url( $attachment_id ),
					'sha256'        => $sha256,
					'reused'        => true,
				),
				'rollback' => array( 'operation' => 'noop' ),
			);
		}
		$filename = sanitize_file_name( (string) ( $input['filename'] ?? $target ) );
		if ( '' === $filename ) {
			return new \WP_Error( 'sitepilot_invalid_media', __( 'A valid media filename is required.', 'sitepilot-mcp' ) );
		}
		$upload = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'sitepilot_upload_failed', (string) $upload['error'] );
		}
		$inspection = wp_check_filetype_and_ext( $upload['file'], $filename, get_allowed_mime_types() );
		$mime       = sanitize_mime_type( (string) ( $inspection['type'] ?? '' ) );
		$requested  = sanitize_mime_type( (string) ( $input['mime_type'] ?? '' ) );
		if ( '' === $mime || ( '' !== $requested && ! hash_equals( $requested, $mime ) ) ) {
			wp_delete_file( $upload['file'] );
			return new \WP_Error( 'sitepilot_media_mime_invalid', __( 'WordPress file inspection rejected the supplied media type.', 'sitepilot-mcp' ) );
		}
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => sanitize_text_field( (string) ( $input['title'] ?? pathinfo( $filename, PATHINFO_FILENAME ) ) ),
				'post_excerpt'   => sanitize_textarea_field( (string) ( $input['caption'] ?? '' ) ),
				'post_content'   => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $upload['file'] );
			return $attachment_id;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		if ( str_starts_with( $mime, 'image/' ) && ( ! is_array( $metadata ) || empty( $metadata['width'] ) || empty( $metadata['height'] ) ) ) {
			wp_delete_attachment( $attachment_id, true );
			return new \WP_Error( 'sitepilot_image_metadata_invalid', __( 'WordPress could not validate the uploaded image dimensions.', 'sitepilot-mcp' ) );
		}
		wp_update_attachment_metadata( $attachment_id, $metadata );
		update_post_meta( $attachment_id, '_sitepilot_sha256', $sha256 );
		if ( isset( $input['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
		}
		return array(
			'result'   => array(
				'attachment_id' => $attachment_id,
				'url'           => wp_get_attachment_url( $attachment_id ),
				'sha256'        => $sha256,
				'reused'        => false,
			),
			'rollback' => array(
				'operation' => 'delete_attachment',
				'post_id'   => $attachment_id,
			),
		);
	}

	/** @return list<string> */
	private function security_settings(): array {
		return array( 'users_can_register', 'default_role', 'comment_registration', 'require_name_email' );
	}
}
