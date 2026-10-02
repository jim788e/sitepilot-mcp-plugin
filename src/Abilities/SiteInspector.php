<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Abilities;

use SitePilot\Mcp\Adapters\ElementorDocumentStore;
use SitePilot\Mcp\Adapters\ElementorElementEditor;
use SitePilot\Mcp\Adapters\ElementorElementRegistry;
use SitePilot\Mcp\Adapters\ElementorWidgetRegistry;
use SitePilot\Mcp\Adapters\EnfoldAdapter;
use SitePilot\Mcp\Adapters\EnfoldDocument;
use SitePilot\Mcp\Adapters\EnfoldGlobalStyles;
use SitePilot\Mcp\Adapters\EnfoldShortcodeRegistry;
use SitePilot\Mcp\Credentials\CredentialContext;
use SitePilot\Mcp\Infrastructure\SiteVersion;

final class SiteInspector {
	/** @return array<string,mixed> */
	public function inspect(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$theme         = wp_get_theme();
		$template      = $theme->get_template();
		$parent        = $template !== $theme->get_stylesheet() ? wp_get_theme( $template ) : null;
		$enfold_status = EnfoldAdapter::theme_status( $theme );
		$enfold        = array(
			'active'           => $enfold_status['active'],
			'version'          => $enfold_status['active'] ? $enfold_status['version'] : null,
			'alb_available'    => $enfold_status['alb_available'],
			'profile_verified' => $enfold_status['profile_verified'],
		);
		$plugins       = get_plugins();
		$active        = get_option( 'active_plugins', array() );
		$credential    = $this->credential_summary();
		return array(
			'site_version' => ( new SiteVersion() )->current(),
			'environment'  => array(
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'https'     => is_ssl(),
				'multisite' => is_multisite(),
				'rest_url'  => get_rest_url(),
			),
			'theme'        => array(
				'name'           => $theme->get( 'Name' ),
				'stylesheet'     => $theme->get_stylesheet(),
				'version'        => $theme->get( 'Version' ),
				'template'       => $template,
				'parent_name'    => $parent instanceof \WP_Theme ? $parent->get( 'Name' ) : null,
				'parent_version' => $parent instanceof \WP_Theme ? $parent->get( 'Version' ) : null,
				'block_theme'    => wp_is_block_theme(),
			),
			'plugins'      => array_map(
				static fn ( string $file ): array => array(
					'file'    => $file,
					'name'    => $plugins[ $file ]['Name'] ?? $file,
					'version' => $plugins[ $file ]['Version'] ?? '',
				),
				array_values( array_filter( $active, 'is_string' ) )
			),
			'builders'     => array(
				'gutenberg'         => true,
				// Kept as a bare version string: the gateway's builder selection
				// asserts `typeof builders.elementor === "string"`. Richer detail
				// lives alongside it under `elementor_details`.
				'elementor'         => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : false,
				'elementor_details' => $this->elementor_details(),
				'enfold'            => $enfold,
			),
			'woocommerce'  => array(
				'active'  => class_exists( 'WooCommerce' ),
				'version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'hpos'    => class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) ? \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() : false,
			),
			'cloud_opt_in' => '1' === get_option( 'sitepilot_mcp_cloud_opt_in', '0' ),
			'credential'   => $credential,
		);
	}

	/** @return array{type:string,state:string,scopes:list<string>} */
	private function credential_summary(): array {
		$credential = CredentialContext::current();
		if ( ! is_array( $credential ) ) {
			$oauth = $GLOBALS['sitepilot_oauth_context'] ?? null;
			if ( is_array( $oauth ) ) {
				$credential = array(
					'credential_type' => 'oauth',
					'state'           => 'active',
					'scopes'          => is_array( $oauth['scopes'] ?? null ) ? array_values( $oauth['scopes'] ) : array(),
				);
			}
		}
		return array(
			'type'   => is_array( $credential ) ? (string) ( $credential['credential_type'] ?? 'unknown' ) : 'interactive',
			'state'  => is_array( $credential ) ? (string) ( $credential['state'] ?? 'active' ) : 'interactive',
			'scopes' => is_array( $credential ) && is_array( $credential['scopes'] ?? null ) ? array_values( $credential['scopes'] ) : array(),
		);
	}

	/** @return list<array<string,mixed>> */
	public function capabilities( string $query = '' ): array {
		$needle = strtolower( $query );
		$result = array();
		foreach ( wp_get_abilities() as $name => $ability ) {
			$haystack = strtolower( $name . ' ' . $ability->get_label() . ' ' . $ability->get_description() );
			if ( '' !== $needle && ! str_contains( $haystack, $needle ) ) {
				continue;
			}
			$result[] = array(
				'kind'          => 'ability',
				'name'          => $name,
				'label'         => $ability->get_label(),
				'description'   => $ability->get_description(),
				'input_schema'  => $ability->get_input_schema(),
				'output_schema' => $ability->get_output_schema(),
			);
		}
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( ! $this->allowed_route( $route ) || ( '' !== $needle && ! str_contains( strtolower( $route ), $needle ) ) ) {
				continue;
			}
			$methods = array();
			foreach ( $handlers as $handler ) {
				if ( is_array( $handler ) && isset( $handler['methods'] ) ) {
					$methods = array_merge( $methods, array_keys( array_filter( (array) $handler['methods'] ) ) );
				}
			}
			$result[] = array(
				'kind'    => 'rest',
				'name'    => $route,
				'methods' => array_values( array_unique( $methods ) ),
			);
		}
		return array_slice( $result, 0, 250 );
	}

	/** @return array<string,mixed> */
	private function elementor_details(): array {
		$store = new ElementorDocumentStore();
		if ( ! $store->available() ) {
			return array( 'active' => false );
		}
		return array(
			'active'           => true,
			'version'          => ELEMENTOR_VERSION,
			'pro'              => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : false,
			'documents_ready'  => $store->documents_ready(),
			'kit_id'           => $store->active_kit_id(),
			// Atomic elements ship from Elementor 4.0. SitePilot compiles classic
			// containers only; this flag tells an agent what the site could support.
			'atomic_available' => version_compare( ELEMENTOR_VERSION, '4.0', '>=' ),
		);
	}

	/**
	 * Inspect a builder page structure and the site's builder capabilities.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function design( array $input = array() ) {
		$builder = sanitize_key( (string) ( $input['builder'] ?? 'elementor' ) );
		return match ( $builder ) {
			'elementor' => $this->elementor_design( $input ),
			'enfold'    => $this->enfold_design( $input ),
			default     => new \WP_Error(
				'sitepilot_builder_invalid',
				__( 'Design inspection supports the Elementor and Enfold builders.', 'sitepilot-mcp' ),
				array( 'supported' => array( 'elementor', 'enfold' ) )
			),
		};
	}

	/**
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function elementor_design( array $input ) {
		$store = new ElementorDocumentStore();
		if ( ! $store->available() ) {
			return new \WP_Error( 'sitepilot_elementor_unavailable', __( 'Elementor is not active.', 'sitepilot-mcp' ) );
		}
		$registry    = new ElementorWidgetRegistry( $store );
		$elements    = new ElementorElementRegistry();
		$editor      = new ElementorElementEditor( $registry, $elements );
		$target      = absint( $input['target'] ?? 0 );
		$widget_type = sanitize_text_field( (string) ( $input['widget_type'] ?? '' ) );
		$include     = is_array( $input['include'] ?? null ) ? array_map( 'strval', $input['include'] ) : array();
		$wants       = static fn ( string $section ): bool => array() === $include || in_array( $section, $include, true );

		$result = array(
			'builder'   => 'elementor',
			'elementor' => $this->elementor_details(),
		);
		if ( $target > 0 ) {
			$post = get_post( $target );
			if ( ! $post instanceof \WP_Post ) {
				return new \WP_Error( 'sitepilot_target_missing', __( 'The requested design post does not exist.', 'sitepilot-mcp' ) );
			}
			if ( ! current_user_can( 'edit_post', $target ) ) {
				return new \WP_Error( 'sitepilot_target_invalid', __( 'The connected user cannot read that design post.', 'sitepilot-mcp' ) );
			}
			$tree  = $store->get_elements( $target );
			$valid = $editor->validate_readable_tree( $tree );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			if ( $wants( 'tree' ) ) {
				$result['page'] = array(
					'post_id'          => $target,
					'post_type'        => $post->post_type,
					'post_status'      => $post->post_status,
					'title'            => $post->post_title,
					'document_type'    => $store->get_document_type( $target ),
					'element_count'    => count( $editor->collect_ids( $tree ) ),
					'outline'          => $editor->outline( $tree ),
					'unmapped_regions' => $editor->unmapped_regions( $tree ),
					'tree'             => $tree,
				);
			}
			if ( $wants( 'page_settings' ) ) {
				$result['page_settings'] = $store->get_page_settings( $target );
			}
		}
		if ( $wants( 'widgets' ) ) {
			$result['widgets'] = $registry->catalog( $widget_type );
			if ( '' !== $widget_type ) {
				$controls                  = $registry->controls( $widget_type );
				$result['widget_controls'] = is_wp_error( $controls ) ? array( 'error' => $controls->get_error_message() ) : $controls;
			}
		}
		if ( $wants( 'globals' ) ) {
			$kit_id            = $store->active_kit_id();
			$kit               = $kit_id > 0 ? $store->get_page_settings( $kit_id ) : array();
			$result['globals'] = array(
				'kit_id'            => $kit_id,
				'system_colors'     => $kit['system_colors'] ?? array(),
				'custom_colors'     => $kit['custom_colors'] ?? array(),
				'system_typography' => $kit['system_typography'] ?? array(),
				'custom_typography' => $kit['custom_typography'] ?? array(),
				'atomic'            => array(
					'write_supported' => ! defined( 'ELEMENTOR_VERSION' ) || version_compare( ELEMENTOR_VERSION, '4.0', '<' ),
					'classes_store'   => '_elementor_global_classes',
					'variables_store' => '_elementor_global_variables',
				),
			);
		}
		return $result;
	}

	/**
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function enfold_design( array $input ) {
		$status = EnfoldAdapter::theme_status();
		if ( ! $status['active'] || ! $status['alb_available'] ) {
			return new \WP_Error( 'sitepilot_enfold_unavailable', __( 'Enfold and its Advanced Layout Builder are not active.', 'sitepilot-mcp' ) );
		}

		$target        = absint( $input['target'] ?? 0 );
		$include       = is_array( $input['include'] ?? null ) ? array_map( 'strval', $input['include'] ) : array();
		$tree_explicit = in_array( 'tree', $include, true );
		$wants         = static fn ( string $section ): bool => array() === $include || in_array( $section, $include, true );
		$result        = array(
			'builder' => 'enfold',
			'enfold'  => $status,
		);

		if ( $target > 0 ) {
			$post = get_post( $target );
			if ( ! $post instanceof \WP_Post ) {
				return new \WP_Error( 'sitepilot_target_missing', __( 'The requested design post does not exist.', 'sitepilot-mcp' ) );
			}
			if ( ! current_user_can( 'edit_post', $target ) ) {
				return new \WP_Error( 'sitepilot_target_invalid', __( 'The connected user cannot read that design post.', 'sitepilot-mcp' ) );
			}

			$active = 'active' === get_post_meta( $target, '_aviaLayoutBuilder_active', true );
			$clean  = get_post_meta( $target, '_aviaLayoutBuilderCleanData', true );
			$clean  = is_string( $clean ) ? $clean : '';
			if ( $wants( 'tree' ) ) {
				if ( ! $active || '' === trim( $clean ) ) {
					if ( $tree_explicit ) {
						return new \WP_Error( 'sitepilot_enfold_document_empty', __( 'The target page does not contain an active Enfold ALB document.', 'sitepilot-mcp' ) );
					}
				} else {
					$tree = EnfoldDocument::parse( $clean );
					if ( is_wp_error( $tree ) ) {
						return $tree;
					}
					$outline        = EnfoldDocument::outline( $tree );
					$result['page'] = array(
						'post_id'       => $target,
						'post_type'     => $post->post_type,
						'post_status'   => $post->post_status,
						'title'         => $post->post_title,
						'element_count' => self::enfold_element_count( $outline ),
						'outline'       => $outline,
					);
				}
			}
			if ( $wants( 'page_settings' ) ) {
				$result['page_settings'] = array(
					'page_template'        => get_page_template_slug( $target ),
					'alb_active'           => $active,
					'clean_data_available' => '' !== trim( $clean ),
					'profile_verified'     => $status['profile_verified'],
					'av_uid_scheme'        => array(
						'attribute'  => 'av_uid',
						'primary'    => 'uid_when_present',
						'fallback'   => 'path',
						'path_scope' => 'current_document_snapshot',
					),
				);
			}
		}

		if ( $wants( 'elements' ) ) {
			$result['elements'] = EnfoldShortcodeRegistry::catalog( $status['version'] );
		}
		if ( $wants( 'globals' ) ) {
			$result['globals'] = $this->enfold_globals( $status['stylesheet'], $status['template'] );
		}
		return $result;
	}

	/** @param list<array<string,mixed>> $nodes */
	private static function enfold_element_count( array $nodes ): int {
		$count = 0;
		foreach ( $nodes as $node ) {
			++$count;
			$children = is_array( $node['children'] ?? null ) ? $node['children'] : array();
			$count   += self::enfold_element_count( $children );
		}
		return $count;
	}

	/** @return array{option_name:string|null,colors:array<string,mixed>,typography:array<string,mixed>} */
	private function enfold_globals( string $stylesheet, string $template ): array {
		unset( $stylesheet, $template );
		$current = EnfoldGlobalStyles::current();
		if ( is_wp_error( $current ) ) {
			return array(
				'option_name' => null,
				'colors'      => array(),
				'typography'  => array(),
			);
		}
		$groups = EnfoldGlobalStyles::public_groups( $current['options'] );
		return array(
			'option_name' => $current['option_name'],
			'colors'      => $groups['colors'],
			'typography'  => $groups['typography'],
		);
	}

	/** @return array{registered_locations:array<string,string>,assignments:array<string,int>,menus:list<array<string,mixed>>} */
	public function navigation(): array {
		$registered  = get_registered_nav_menus();
		$assignments = get_nav_menu_locations();
		$menus       = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$locations = array_keys( array_filter( $assignments, static fn ( int $menu_id ): bool => $menu_id === (int) $menu->term_id ) );
			$items     = array();
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				$items[] = array(
					'id'        => (int) $item->ID,
					'title'     => (string) $item->title,
					'url'       => (string) $item->url,
					'type'      => (string) $item->type,
					'object'    => (string) $item->object,
					'object_id' => (int) $item->object_id,
					'parent_id' => (int) $item->menu_item_parent,
					'position'  => (int) $item->menu_order,
				);
			}
			$menus[] = array(
				'id'        => (int) $menu->term_id,
				'name'      => (string) $menu->name,
				'slug'      => (string) $menu->slug,
				'locations' => $locations,
				'items'     => $items,
			);
		}
		return array(
			'registered_locations' => $registered,
			'assignments'          => $assignments,
			'menus'                => $menus,
		);
	}

	private function allowed_route( string $route ): bool {
		return 1 === preg_match( '#^/(wp/v2|wp-abilities/v1|wc/v3|elementor)/#', $route );
	}
}
