<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Credentials;

use SitePilot\Mcp\Infrastructure\AuditLog;
use SitePilot\Mcp\OAuth\Scopes;

final class CredentialGrantRepository {
	private const TYPE_APPLICATION_PASSWORD = 'app_password';

	/**
	 * Resolve the least-privilege context for an authenticated Application Password.
	 *
	 * @return array{credential_type:string,credential_uuid:string,user_id:int,label:string,scopes:list<string>,may_approve:int,state:string}
	 */
	public function resolve_application_password( string $uuid, int $user_id, string $label ): array {
		$base = array(
			'credential_type' => self::TYPE_APPLICATION_PASSWORD,
			'credential_uuid' => $uuid,
			'user_id'         => $user_id,
			'label'           => $label,
			'scopes'          => array(),
			'may_approve'     => 0,
			'state'           => 'invalid',
		);
		if ( ! self::valid_uuid( $uuid ) || 0 >= $user_id ) {
			return $base;
		}

		$row = $this->find( $uuid );
		if ( is_wp_error( $row ) ) {
			$base['state'] = 'storage_error';
			return $base;
		}
		if ( null === $row ) {
			$base['scopes'] = array( 'site:read' );
			$base['state']  = 'unclaimed';
			return $base;
		}
		if ( $user_id !== (int) $row['user_id'] || null !== $row['revoked_at'] ) {
			$base['state'] = 'revoked';
			return $base;
		}

		$scopes = $this->decode_scopes( (string) $row['scopes'] );
		if ( is_wp_error( $scopes ) ) {
			$base['state'] = 'invalid';
			return $base;
		}
		$base['label']       = (string) $row['label'];
		$base['scopes']      = $scopes;
		$base['may_approve'] = (int) ( $row['may_approve'] ?? 0 );
		$base['state']       = 'active';
		$this->touch( $uuid, $user_id );
		return $base;
	}

	/**
	 * Create the immutable, one-shot grant for an Application Password.
	 *
	 * @param list<string> $scopes
	 * @return array{credential_uuid:string,user_id:int,label:string,scopes:list<string>,created:bool}|\WP_Error
	 */
	public function claim_application_password( string $uuid, int $user_id, array $scopes, string $label ) {
		if ( ! self::valid_uuid( $uuid ) || 0 >= $user_id ) {
			return new \WP_Error( 'sitepilot_credential_invalid', __( 'The authenticated credential identity is invalid.', 'sitepilot-mcp' ), array( 'status' => 400 ) );
		}
		$validated = $this->validate_self_claim_scopes( $scopes );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$label = sanitize_text_field( $label );
		$label = '' !== $label ? substr( $label, 0, 191 ) : __( 'SitePilot client', 'sitepilot-mcp' );

		$existing = $this->find( $uuid );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( is_array( $existing ) ) {
			return $this->existing_claim_result( $existing, $user_id, $validated, $label );
		}

		global $wpdb;
		$created = $wpdb->insert(
			$wpdb->prefix . 'sitepilot_credential_grants',
			array(
				'credential_type' => self::TYPE_APPLICATION_PASSWORD,
				'credential_uuid' => $uuid,
				'user_id'         => $user_id,
				'scopes'          => wp_json_encode( $validated ),
				'may_approve'     => 0,
				'label'           => $label,
				'created_at'      => current_time( 'mysql', true ),
				'last_used_at'    => current_time( 'mysql', true ),
				'revoked_at'      => null,
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( false === $created ) {
			$existing = $this->find( $uuid );
			if ( is_array( $existing ) ) {
				return $this->existing_claim_result( $existing, $user_id, $validated, $label );
			}
			return new \WP_Error( 'sitepilot_credential_storage_failed', __( 'The credential grant could not be stored.', 'sitepilot-mcp' ), array( 'status' => 500 ) );
		}

		( new AuditLog() )->record(
			'credential.claimed',
			'success',
			array(
				'client_id' => 'app-password:' . $uuid,
				'inputs'    => array(
					'credential_uuid' => $uuid,
					'scopes'          => $validated,
				),
			)
		);
		return array(
			'credential_uuid' => $uuid,
			'user_id'         => $user_id,
			'label'           => $label,
			'scopes'          => $validated,
			'created'         => true,
		);
	}

	/** @return list<array<string,mixed>> */
	public function application_password_grants(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT g.*,u.user_login FROM {$wpdb->prefix}sitepilot_credential_grants g LEFT JOIN {$wpdb->users} u ON u.ID=g.user_id WHERE g.credential_type='app_password' ORDER BY g.created_at DESC LIMIT 100",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Administratively widen or narrow an existing Application Password grant.
	 *
	 * @param list<string> $scopes
	 * @return array{credential_uuid:string,user_id:int,label:string,scopes:list<string>,may_approve:int}|\WP_Error
	 */
	public function update_application_password_grant( string $uuid, array $scopes, string $label, bool $may_approve = false ) {
		if ( $may_approve && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'sitepilot_approval_delegation_forbidden', __( 'Only an administrator can delegate change-set approval.', 'sitepilot-mcp' ), array( 'status' => 403 ) );
		}
		$validated = $this->validate_known_scopes( $scopes, true );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$row = $this->find( $uuid );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! is_array( $row ) || null !== $row['revoked_at'] ) {
			return new \WP_Error( 'sitepilot_credential_not_found', __( 'The active credential grant was not found.', 'sitepilot-mcp' ), array( 'status' => 404 ) );
		}
		if ( ! Scopes::user_can_grant( $validated, (int) $row['user_id'] ) ) {
			return new \WP_Error( 'sitepilot_credential_scope_forbidden', __( 'The credential owner cannot grant one or more requested scopes through OAuth.', 'sitepilot-mcp' ), array( 'status' => 403 ) );
		}
		$before = $this->decode_scopes( (string) $row['scopes'] );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		$label = substr( sanitize_text_field( $label ), 0, 191 );
		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'sitepilot_credential_grants',
			array(
				'scopes'      => wp_json_encode( $validated ),
				'may_approve' => $may_approve ? 1 : 0,
				'label'       => $label,
			),
			array(
				'credential_type' => self::TYPE_APPLICATION_PASSWORD,
				'credential_uuid' => $uuid,
			),
			array( '%s', '%d', '%s' ),
			array( '%s', '%s' )
		);
		if ( false === $updated ) {
			return new \WP_Error( 'sitepilot_credential_storage_failed', __( 'The credential grant could not be updated.', 'sitepilot-mcp' ), array( 'status' => 500 ) );
		}
		( new AuditLog() )->record(
			'credential.updated',
			'success',
			array(
				'client_id' => 'app-password:' . $uuid,
				'before'    => array(
					'label'       => (string) $row['label'],
					'scopes'      => $before,
					'may_approve' => 1 === (int) ( $row['may_approve'] ?? 0 ),
				),
				'after'     => array(
					'label'       => $label,
					'scopes'      => $validated,
					'may_approve' => $may_approve,
				),
			)
		);
		return array(
			'credential_uuid' => $uuid,
			'user_id'         => (int) $row['user_id'],
			'label'           => $label,
			'scopes'          => $validated,
			'may_approve'     => $may_approve ? 1 : 0,
		);
	}

	/** Delete the WordPress Application Password and its SitePilot grant. */
	public function delete_application_password_grant( string $uuid ): true|\WP_Error {
		$row = $this->find( $uuid );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! is_array( $row ) ) {
			return new \WP_Error( 'sitepilot_credential_not_found', __( 'The credential grant was not found.', 'sitepilot-mcp' ), array( 'status' => 404 ) );
		}
		$deleted = \WP_Application_Passwords::delete_application_password( (int) $row['user_id'], $uuid );
		if ( is_wp_error( $deleted ) && 'application_password_not_found' !== $deleted->get_error_code() ) {
			return $deleted;
		}
		global $wpdb;
		if ( false === $wpdb->delete(
			$wpdb->prefix . 'sitepilot_credential_grants',
			array(
				'credential_type' => self::TYPE_APPLICATION_PASSWORD,
				'credential_uuid' => $uuid,
			),
			array( '%s', '%s' )
		) ) {
			return new \WP_Error( 'sitepilot_credential_storage_failed', __( 'The credential grant could not be removed.', 'sitepilot-mcp' ), array( 'status' => 500 ) );
		}
		if ( is_wp_error( $deleted ) ) {
			( new AuditLog() )->record( 'credential.revoked', 'removed', array( 'client_id' => 'app-password:' . $uuid ) );
		}
		return true;
	}

	public function revoke_application_password( string $uuid, int $user_id ): void {
		if ( ! self::valid_uuid( $uuid ) || 0 >= $user_id ) {
			return;
		}
		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'sitepilot_credential_grants',
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array(
				'credential_type' => self::TYPE_APPLICATION_PASSWORD,
				'credential_uuid' => $uuid,
				'user_id'         => $user_id,
			),
			array( '%s' ),
			array( '%s', '%s', '%d' )
		);
		if ( is_int( $updated ) && 0 < $updated ) {
			( new AuditLog() )->record( 'credential.revoked', 'success', array( 'client_id' => 'app-password:' . $uuid ) );
		}
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private function find( string $uuid ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}sitepilot_credential_grants WHERE credential_type=%s AND credential_uuid=%s LIMIT 1",
				self::TYPE_APPLICATION_PASSWORD,
				$uuid
			),
			ARRAY_A
		);
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'sitepilot_credential_storage_failed', __( 'The credential grant store is unavailable.', 'sitepilot-mcp' ), array( 'status' => 500 ) );
		}
		return is_array( $row ) ? $row : null;
	}

	private function touch( string $uuid, int $user_id ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'sitepilot_credential_grants',
			array( 'last_used_at' => current_time( 'mysql', true ) ),
			array(
				'credential_type' => self::TYPE_APPLICATION_PASSWORD,
				'credential_uuid' => $uuid,
				'user_id'         => $user_id,
			),
			array( '%s' ),
			array( '%s', '%s', '%d' )
		);
	}

	/** @return list<string>|\WP_Error */
	private function decode_scopes( string $encoded ) {
		$scopes = json_decode( $encoded, true );
		if ( ! is_array( $scopes ) ) {
			return new \WP_Error( 'sitepilot_credential_invalid', __( 'The stored credential scopes are invalid.', 'sitepilot-mcp' ) );
		}
		return $this->validate_known_scopes( $scopes, true );
	}

	/** @param array<mixed> $scopes @return list<string>|\WP_Error */
	private function validate_known_scopes( array $scopes, bool $allow_empty = false ) {
		if ( array_filter( $scopes, static fn ( mixed $scope ): bool => ! is_string( $scope ) ) ) {
			return new \WP_Error( 'sitepilot_credential_invalid', __( 'The credential scopes must be strings.', 'sitepilot-mcp' ), array( 'status' => 400 ) );
		}
		$requested = array_values( array_unique( $scopes ) );
		if ( ( ! $allow_empty && array() === $requested ) || array_diff( $requested, Scopes::ALL ) ) {
			return new \WP_Error( 'invalid_scope', __( 'One or more requested scopes are unsupported.', 'sitepilot-mcp' ), array( 'status' => 400 ) );
		}
		return array_values( array_intersect( Scopes::ALL, $requested ) );
	}

	/** @param list<string> $scopes @return list<string>|\WP_Error */
	private function validate_self_claim_scopes( array $scopes ) {
		$validated = $this->validate_known_scopes( $scopes );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( array_intersect( $validated, Scopes::ADMIN_ONLY ) ) {
			return new \WP_Error( 'sitepilot_admin_scope_claim_denied', __( 'Administrator-only scopes cannot be self-claimed by a credential.', 'sitepilot-mcp' ), array( 'status' => 403 ) );
		}
		return $validated;
	}

	/**
	 * @param array<string,mixed> $existing
	 * @param list<string>        $scopes
	 * @return array{credential_uuid:string,user_id:int,label:string,scopes:list<string>,created:bool}|\WP_Error
	 */
	private function existing_claim_result( array $existing, int $user_id, array $scopes, string $label ) {
		$stored_scopes = $this->decode_scopes( (string) $existing['scopes'] );
		if ( is_wp_error( $stored_scopes ) ) {
			return $stored_scopes;
		}
		if ( $user_id !== (int) $existing['user_id'] || null !== $existing['revoked_at'] ) {
			return new \WP_Error( 'sitepilot_credential_revoked', __( 'This credential grant is revoked or belongs to another user.', 'sitepilot-mcp' ), array( 'status' => 403 ) );
		}
		if ( $stored_scopes !== $scopes || (string) $existing['label'] !== $label ) {
			return new \WP_Error( 'sitepilot_credential_already_claimed', __( 'This credential was already claimed and cannot change its own grant.', 'sitepilot-mcp' ), array( 'status' => 409 ) );
		}
		return array(
			'credential_uuid' => (string) $existing['credential_uuid'],
			'user_id'         => (int) $existing['user_id'],
			'label'           => (string) $existing['label'],
			'scopes'          => $stored_scopes,
			'created'         => false,
		);
	}

	private static function valid_uuid( string $uuid ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid );
	}
}
