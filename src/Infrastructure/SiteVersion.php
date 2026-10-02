<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Infrastructure;

final class SiteVersion {
	public function current(): string {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		$active  = get_option( 'active_plugins', array() );
		sort( $active );
		$theme = wp_get_theme();
		// `wp_cache_get_last_changed( 'posts' )` is generated per request when no
		// persistent object cache is configured. Use stable database metadata so an
		// inspect request can safely be followed by a separate plan request.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is derived only from WordPress's trusted table prefix.
		$post_state = $wpdb->get_row( "SELECT COUNT(*) AS post_count, MAX(ID) AS latest_post_id, MAX(post_modified_gmt) AS latest_post_modified FROM {$wpdb->posts}", ARRAY_A );
		return Ids::canonical_hash(
			array(
				'wp'             => get_bloginfo( 'version' ),
				'theme'          => array( $theme->get_stylesheet(), $theme->get( 'Version' ) ),
				'active_plugins' => array_map(
					static fn ( string $file ): array => array( $file, $plugins[ $file ]['Version'] ?? '' ),
					$active
				),
				'post_state'     => is_array( $post_state ) ? $post_state : array(),
				'mutation'       => (int) get_option( 'sitepilot_mcp_mutation_version', 0 ),
			)
		);
	}

	public function bump(): void {
		update_option( 'sitepilot_mcp_mutation_version', (int) get_option( 'sitepilot_mcp_mutation_version', 0 ) + 1, false );
	}
}
