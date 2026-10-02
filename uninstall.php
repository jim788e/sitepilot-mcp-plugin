<?php

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove SitePilot's tables, options, roles and staged artifacts.
 */
function sitepilot_mcp_uninstall(): void {
	global $wpdb;
	$uploads = wp_upload_dir();
	$root    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . 'sitepilot-staging/' );
	$paths   = $wpdb->get_col( "SELECT storage_path FROM {$wpdb->prefix}sitepilot_artifacts" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall cleanup.
	foreach ( is_array( $paths ) ? $paths : array() as $artifact_path ) {
		$normalized = wp_normalize_path( (string) $artifact_path );
		if ( str_starts_with( $normalized, $root ) && is_file( $normalized ) ) {
			wp_delete_file( $normalized );
		}
	}
	foreach ( array( 'credential_grants', 'oauth_clients', 'oauth_grants', 'oauth_codes', 'oauth_tokens', 'changesets', 'approvals', 'audit', 'artifacts', 'idempotency' ) as $suffix ) {
		$table = esc_sql( $wpdb->prefix . 'sitepilot_' . $suffix );
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed uninstall table allowlist.
	}
	foreach ( array( 'sitepilot_mcp_cloud_opt_in', 'sitepilot_mcp_enfold_profile', 'sitepilot_mcp_permanent_delete', 'sitepilot_mcp_schema_version', 'sitepilot_mcp_mutation_version' ) as $option ) {
		delete_option( $option );
	}
	wp_clear_scheduled_hook( 'sitepilot_mcp_retention_cleanup' );
	foreach ( array( 'administrator', 'sitepilot_operator' ) as $role_name ) {
		$wp_role = get_role( $role_name );
		if ( $wp_role ) {
			foreach ( array( 'sitepilot_connect', 'sitepilot_manage_policy', 'sitepilot_approve', 'sitepilot_approve_standard', 'sitepilot_approve_sensitive', 'sitepilot_view_audit' ) as $capability ) {
				$wp_role->remove_cap( $capability );
			}
		}
	}
	remove_role( 'sitepilot_operator' );
}

sitepilot_mcp_uninstall();
