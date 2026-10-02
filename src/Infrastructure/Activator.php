<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Infrastructure;

use SitePilot\Mcp\Artifacts\ArtifactService;
use SitePilot\Mcp\Playbooks\PlaybookRegistry;

final class Activator {
	public const CAPABILITIES = array(
		'sitepilot_connect',
		'sitepilot_manage_policy',
		'sitepilot_approve',
		'sitepilot_approve_standard',
		'sitepilot_approve_sensitive',
		'sitepilot_view_audit',
	);

	public static function activate(): void {
		if ( is_multisite() ) {
			wp_die( esc_html__( 'SitePilot MCP v1 supports single-site installations only.', 'sitepilot-mcp' ) );
		}
		if ( version_compare( PHP_VERSION, '8.2', '<' ) || version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
			wp_die( esc_html__( 'SitePilot MCP requires WordPress 6.9+ and PHP 8.2+.', 'sitepilot-mcp' ) );
		}

		$installed_version = (string) get_option( 'sitepilot_mcp_schema_version', '' );
		$schema_installed  = self::install_schema();
		$data_migrated     = $schema_installed && self::migrate_security_state( $installed_version );
		self::install_roles();
		add_option( 'sitepilot_mcp_cloud_opt_in', '0', '', false );
		add_option( Capabilities::GATEWAY_ORIGIN_OPTION, '', '', false );
		add_option( 'sitepilot_mcp_permanent_delete', '0', '', false );
		PlaybookRegistry::seed_defaults();
		ArtifactService::protect_existing_staging_dir();
		if ( $schema_installed && $data_migrated ) {
			update_option( 'sitepilot_mcp_schema_version', SITEPILOT_MCP_VERSION, false );
		}
		if ( ! wp_next_scheduled( 'sitepilot_mcp_retention_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'sitepilot_mcp_retention_cleanup' );
		}
	}

	public static function maybe_upgrade(): bool {
		$installed_version = (string) get_option( 'sitepilot_mcp_schema_version', '' );
		if ( SITEPILOT_MCP_VERSION === $installed_version ) {
			return true;
		}

		// Sites upgraded from a release without the guards keep their existing staging files.
		ArtifactService::protect_existing_staging_dir();

		$schema_installed = self::install_schema();
		$data_migrated    = $schema_installed && self::migrate_security_state( $installed_version );
		if ( $schema_installed && $data_migrated ) {
			self::install_administrator_capabilities();
			PlaybookRegistry::seed_defaults();
			update_option( 'sitepilot_mcp_schema_version', SITEPILOT_MCP_VERSION, false );
		}
		return $schema_installed && $data_migrated;
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'sitepilot_mcp_retention_cleanup' );
	}

	public static function render_schema_error_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'SitePilot MCP could not upgrade its database schema and has been paused. Check the WordPress database error log, then reload this page to retry.', 'sitepilot-mcp' ) . '</p></div>';
	}

	private static function install_roles(): void {
		self::install_administrator_capabilities();

		add_role(
			'sitepilot_operator',
			__( 'SitePilot Operator', 'sitepilot-mcp' ),
			array(
				'read'                       => true,
				'edit_posts'                 => true,
				'upload_files'               => true,
				'sitepilot_connect'          => true,
				'sitepilot_approve_standard' => true,
				'sitepilot_view_audit'       => true,
			)
		);
	}

	private static function install_administrator_capabilities(): void {
		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( self::CAPABILITIES as $capability ) {
				$administrator->add_cap( $capability );
			}
		}
	}

	private static function install_schema(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix . 'sitepilot_';

		$sql = array(
			"CREATE TABLE {$prefix}credential_grants (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				credential_type varchar(20) NOT NULL,
				credential_uuid char(36) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				scopes text NOT NULL,
				may_approve tinyint(1) NOT NULL DEFAULT 0,
				label varchar(191) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				last_used_at datetime NULL,
				revoked_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY credential (credential_type,credential_uuid),
				KEY user_id (user_id)
			) {$collate};",
			"CREATE TABLE {$prefix}oauth_clients (
				client_id varchar(255) NOT NULL,
				client_name varchar(200) NOT NULL,
				redirect_uris longtext NOT NULL,
				scopes varchar(512) NOT NULL DEFAULT 'site:read',
				client_secret_hash char(64) NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (client_id)
			) {$collate};",
			"CREATE TABLE {$prefix}oauth_grants (
				grant_id char(36) NOT NULL,
				client_id varchar(255) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				scopes text NOT NULL,
				revocation_reason varchar(64) NULL,
				revoked_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (grant_id),
				KEY client_user (client_id,user_id),
				KEY revoked_at (revoked_at)
			) {$collate};",
			"CREATE TABLE {$prefix}oauth_codes (
				code_hash char(64) NOT NULL,
				client_id varchar(255) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				redirect_uri text NOT NULL,
				scopes text NOT NULL,
				code_challenge varchar(128) NOT NULL,
				expires_at datetime NOT NULL,
				used_at datetime NULL,
				PRIMARY KEY  (code_hash)
			) {$collate};",
			"CREATE TABLE {$prefix}oauth_tokens (
				token_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				grant_id char(36) NOT NULL,
				token_hash char(64) NOT NULL,
				token_type varchar(16) NOT NULL,
				family_id char(36) NOT NULL,
				expires_at datetime NOT NULL,
				used_at datetime NULL,
				refresh_retry_count tinyint unsigned NOT NULL DEFAULT 0,
				revoked_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (token_id),
				UNIQUE KEY token_hash (token_hash),
				KEY grant_id (grant_id),
				KEY family_id (family_id)
			) {$collate};",
			"CREATE TABLE {$prefix}changesets (
				change_set_id char(36) NOT NULL,
				idempotency_key varchar(128) NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL,
				status varchar(32) NOT NULL,
				risk_tier tinyint unsigned NOT NULL,
				expected_version char(64) NOT NULL,
				input_hash char(64) NOT NULL,
				intent text NOT NULL,
				actions longtext NOT NULL,
				diff longtext NOT NULL,
				rollback_data longtext NULL,
				validation_results longtext NULL,
				cancelled_at datetime NULL,
				archived_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (change_set_id),
				UNIQUE KEY actor_idempotency (actor_user_id,idempotency_key),
				KEY status (status)
			) {$collate};",
			"CREATE TABLE {$prefix}approvals (
				approval_id char(36) NOT NULL,
				change_set_id char(36) NOT NULL,
				approver_user_id bigint(20) unsigned NOT NULL,
				input_hash char(64) NOT NULL,
				expires_at datetime NOT NULL,
				used_at datetime NULL,
				revoked_at datetime NULL,
				revoked_by_user_id bigint(20) unsigned NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (approval_id),
				KEY change_set_id (change_set_id)
			) {$collate};",
			"CREATE TABLE {$prefix}audit (
				audit_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				change_set_id char(36) NULL,
				actor_user_id bigint(20) unsigned NOT NULL,
				client_id varchar(128) NULL,
				scope varchar(64) NULL,
				event varchar(100) NOT NULL,
				inputs_hash char(64) NOT NULL,
				before_state longtext NULL,
				after_state longtext NULL,
				result varchar(32) NOT NULL,
				rollback_ref varchar(255) NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (audit_id),
				KEY change_set_id (change_set_id),
				KEY created_at (created_at)
			) {$collate};",
			"CREATE TABLE {$prefix}artifacts (
				artifact_id char(36) NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL,
				filename varchar(255) NOT NULL,
				mime_type varchar(100) NOT NULL,
				byte_size bigint(20) unsigned NOT NULL DEFAULT 0,
				sha256 char(64) NULL,
				status varchar(32) NOT NULL,
				storage_path text NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (artifact_id),
				KEY actor_status (actor_user_id,status)
			) {$collate};",
			"CREATE TABLE {$prefix}idempotency (
				actor_user_id bigint(20) unsigned NOT NULL,
				operation varchar(64) NOT NULL,
				idempotency_key varchar(128) NOT NULL,
				input_hash char(64) NOT NULL,
				response longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (actor_user_id,operation,idempotency_key)
			) {$collate};",
		);

		$installed = true;
		foreach ( $sql as $statement ) {
			dbDelta( $statement );
			if ( '' !== $wpdb->last_error ) {
				$installed = false;
			}
		}
		return $installed;
	}

	private static function migrate_security_state( string $installed_version ): bool {
		if ( '' !== $installed_version && version_compare( $installed_version, '0.4.2', '>=' ) ) {
			return true;
		}

		return self::revoke_forked_refresh_families();
	}

	/**
	 * Revoke any refresh-token families forked by the 0.4.1 retry implementation.
	 *
	 * @return bool Whether the security migration completed successfully.
	 */
	private static function revoke_forked_refresh_families(): bool {
		global $wpdb;
		$families = $wpdb->get_results(
			"SELECT t.family_id,t.grant_id,g.client_id
			FROM {$wpdb->prefix}sitepilot_oauth_tokens t
			INNER JOIN {$wpdb->prefix}sitepilot_oauth_grants g ON g.grant_id=t.grant_id
			WHERE t.token_type='refresh'
				AND t.used_at IS NULL
				AND t.revoked_at IS NULL
				AND t.expires_at > UTC_TIMESTAMP()
				AND g.revoked_at IS NULL
			GROUP BY t.family_id,t.grant_id,g.client_id
			HAVING COUNT(*) > 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are derived from the trusted WordPress prefix; the query has no external values.
			ARRAY_A
		);
		if ( ! is_array( $families ) || '' !== $wpdb->last_error ) {
			return false;
		}

		foreach ( $families as $family ) {
			$now = current_time( 'mysql', true );
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				return false;
			}
			$tokens_revoked = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}sitepilot_oauth_tokens SET revoked_at=%s WHERE family_id=%s AND grant_id=%s AND revoked_at IS NULL",
					$now,
					(string) $family['family_id'],
					(string) $family['grant_id']
				)
			);
			$grant_revoked  = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}sitepilot_oauth_grants SET revocation_reason='refresh_family_fork_migration',revoked_at=%s WHERE grant_id=%s AND revoked_at IS NULL",
					$now,
					(string) $family['grant_id']
				)
			);
			if ( false === $tokens_revoked || false === $grant_revoked || '' !== $wpdb->last_error ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			( new AuditLog() )->record( 'oauth.refresh_family_fork', 'revoked', array( 'client_id' => (string) $family['client_id'] ) );
		}

		return true;
	}
}
