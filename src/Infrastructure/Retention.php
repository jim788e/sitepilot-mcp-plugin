<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Infrastructure;

final class Retention {
	public function register(): void {
		add_action( 'sitepilot_mcp_retention_cleanup', array( $this, 'cleanup' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
	}

	public function cleanup(): void {
		global $wpdb;
		$artifact_cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$audit_cutoff    = gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS );
		$grant_cutoff    = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$rows            = $wpdb->get_results( $wpdb->prepare( "SELECT artifact_id,storage_path FROM {$wpdb->prefix}sitepilot_artifacts WHERE created_at < %s", $artifact_cutoff ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$this->remove_artifact_file( (string) $row['storage_path'] );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sitepilot_artifacts WHERE created_at < %s", $artifact_cutoff ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sitepilot_audit WHERE created_at < %s", $audit_cutoff ) );
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sitepilot_oauth_codes WHERE expires_at < %s", $now ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sitepilot_oauth_tokens WHERE expires_at < %s", $now ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE tokens FROM {$wpdb->prefix}sitepilot_oauth_tokens tokens
				INNER JOIN {$wpdb->prefix}sitepilot_oauth_grants grants ON grants.grant_id = tokens.grant_id
				WHERE grants.revoked_at IS NOT NULL AND grants.revoked_at < %s",
				$grant_cutoff
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}sitepilot_oauth_grants WHERE revoked_at IS NOT NULL AND revoked_at < %s",
				$grant_cutoff
			)
		);
	}

	/** @param array<string,mixed> $erasers @return array<string,mixed> */
	public function erasers( array $erasers ): array {
		$erasers['sitepilot-mcp'] = array(
			'eraser_friendly_name' => __( 'SitePilot MCP grants and artifacts', 'sitepilot-mcp' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
	public function erase( string $email_address, int $page = 1 ): array {
		unset( $page );
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		global $wpdb;
		$artifacts = $wpdb->get_results( $wpdb->prepare( "SELECT artifact_id,storage_path FROM {$wpdb->prefix}sitepilot_artifacts WHERE actor_user_id=%d", $user->ID ), ARRAY_A );
		foreach ( is_array( $artifacts ) ? $artifacts : array() as $artifact ) {
			$this->remove_artifact_file( (string) $artifact['storage_path'] );
		}
		$grant_ids = $wpdb->get_col( $wpdb->prepare( "SELECT grant_id FROM {$wpdb->prefix}sitepilot_oauth_grants WHERE user_id=%d", $user->ID ) );
		if ( $grant_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $grant_ids ), '%s' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sitepilot_oauth_tokens WHERE grant_id IN ({$placeholders})", ...$grant_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated from the trusted value count; values are prepared.
		}
		$wpdb->delete( $wpdb->prefix . 'sitepilot_oauth_grants', array( 'user_id' => $user->ID ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'sitepilot_credential_grants', array( 'user_id' => $user->ID ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'sitepilot_artifacts', array( 'actor_user_id' => $user->ID ), array( '%d' ) );
		return array(
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => array( __( 'Audit metadata is retained for up to 180 days for security accountability.', 'sitepilot-mcp' ) ),
			'done'           => true,
		);
	}

	private function remove_artifact_file( string $path ): void {
		$uploads    = wp_upload_dir();
		$root       = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . 'sitepilot-staging/' );
		$normalized = wp_normalize_path( $path );
		if ( str_starts_with( $normalized, $root ) && is_file( $normalized ) ) {
			wp_delete_file( $normalized );
		}
	}
}
