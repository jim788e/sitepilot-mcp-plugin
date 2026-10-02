<?php

declare(strict_types=1);

namespace SitePilot\Mcp\OAuth;

use SitePilot\Mcp\Infrastructure\AuditLog;
use SitePilot\Mcp\Infrastructure\Ids;

final class TokenRepository {
	private RefreshReplayCache $replay_cache;

	public function __construct( ?RefreshReplayCache $replay_cache = null ) {
		$this->replay_cache = $replay_cache ?? new RefreshReplayCache();
	}

	/** @return array<string,mixed>|null */
	public function authenticate_access( string $token ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.*, g.user_id, g.client_id, g.scopes, g.revoked_at AS grant_revoked_at
				FROM {$wpdb->prefix}sitepilot_oauth_tokens t
				JOIN {$wpdb->prefix}sitepilot_oauth_grants g ON g.grant_id = t.grant_id
				WHERE t.token_hash = %s AND t.token_type = 'access' LIMIT 1",
				Ids::hash( $token )
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || $row['revoked_at'] || $row['grant_revoked_at'] || strtotime( (string) $row['expires_at'] . ' UTC' ) <= time() ) {
			return null;
		}
		return $row;
	}

	/** @param list<string> $scopes @return array{access_token:string,token_type:string,expires_in:int,refresh_token:string,scope:string} */
	public function issue_pair( string $grant_id, array $scopes, ?string $family_id = null ): array {
		global $wpdb;
		$access           = Ids::token();
		$refresh          = Ids::token( 48 );
		$family           = $family_id ?? Ids::uuid();
		$now              = current_time( 'mysql', true );
		$access_inserted  = $wpdb->insert(
			$wpdb->prefix . 'sitepilot_oauth_tokens',
			array(
				'grant_id'   => $grant_id,
				'token_hash' => Ids::hash( $access ),
				'token_type' => 'access',
				'family_id'  => $family,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS ),
				'created_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$refresh_inserted = $wpdb->insert(
			$wpdb->prefix . 'sitepilot_oauth_tokens',
			array(
				'grant_id'   => $grant_id,
				'token_hash' => Ids::hash( $refresh ),
				'token_type' => 'refresh',
				'family_id'  => $family,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
				'created_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $access_inserted || false === $refresh_inserted ) {
			throw new \RuntimeException( 'The OAuth token pair could not be persisted.' );
		}
		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => 15 * MINUTE_IN_SECONDS,
			'refresh_token' => $refresh,
			'scope'         => implode( ' ', $scopes ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function rotate( string $refresh_token, string $client_id ) {
		global $wpdb;
		$hash = Ids::hash( $refresh_token );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'server_error', __( 'The refresh token could not be rotated safely. Try again.', 'sitepilot-mcp' ) );
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.*, g.client_id, g.scopes, g.revoked_at AS grant_revoked_at
				FROM {$wpdb->prefix}sitepilot_oauth_tokens t
				JOIN {$wpdb->prefix}sitepilot_oauth_grants g ON g.grant_id = t.grant_id
				WHERE t.token_hash = %s AND t.token_type = 'refresh' FOR UPDATE",
				$hash
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || ! hash_equals( (string) $row['client_id'], $client_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'invalid_grant', __( 'The refresh token is invalid.', 'sitepilot-mcp' ) );
		}
		if ( $row['revoked_at'] || $row['grant_revoked_at'] || strtotime( (string) $row['expires_at'] . ' UTC' ) <= time() ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'invalid_grant', __( 'The refresh token has expired or was revoked.', 'sitepilot-mcp' ) );
		}
		if ( $row['used_at'] ) {
			$used_at            = strtotime( (string) $row['used_at'] . ' UTC' );
			$now                = time();
			$within_retry_grace = false !== $used_at && $used_at <= $now && $used_at >= $now - RefreshReplayCache::TTL_SECONDS;
			if ( $within_retry_grace ) {
				$pair = $this->replay_cache->retrieve( $hash, $client_id, (string) $row['grant_id'], (string) $row['family_id'] );
				if ( null !== $pair ) {
					if ( false === $wpdb->query( 'COMMIT' ) ) {
						$wpdb->query( 'ROLLBACK' );
						return new \WP_Error( 'server_error', __( 'The refresh response could not be replayed safely. Try again.', 'sitepilot-mcp' ) );
					}
					( new AuditLog() )->record( 'oauth.refresh_retry', 'replayed', array( 'client_id' => $client_id ) );
					return $pair;
				}
			}

			$this->replay_cache->forget( $hash );
			$tokens_revoked = $wpdb->update(
				$wpdb->prefix . 'sitepilot_oauth_tokens',
				array( 'revoked_at' => current_time( 'mysql', true ) ),
				array( 'family_id' => $row['family_id'] ),
				array( '%s' ),
				array( '%s' )
			);
			$grant_revoked  = $wpdb->update(
				$wpdb->prefix . 'sitepilot_oauth_grants',
				array(
					'revocation_reason' => 'refresh_token_reuse',
					'revoked_at'        => current_time( 'mysql', true ),
				),
				array( 'grant_id' => $row['grant_id'] ),
				array( '%s', '%s' ),
				array( '%s' )
			);
			if ( false === $tokens_revoked || false === $grant_revoked || false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'server_error', __( 'The reused refresh-token family could not be revoked safely. Try again.', 'sitepilot-mcp' ) );
			}
			( new AuditLog() )->record( 'oauth.refresh_reuse', 'revoked', array( 'client_id' => $client_id ) );
			return new \WP_Error( 'invalid_grant', __( 'Refresh token reuse was detected and the grant was revoked.', 'sitepilot-mcp' ) );
		}
		$marked_used = $wpdb->update(
			$wpdb->prefix . 'sitepilot_oauth_tokens',
			array( 'used_at' => current_time( 'mysql', true ) ),
			array( 'token_id' => $row['token_id'] ),
			array( '%s' ),
			array( '%d' )
		);
		if ( 1 !== $marked_used ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'server_error', __( 'The refresh token could not be rotated safely. Try again.', 'sitepilot-mcp' ) );
		}
		try {
			$pair = $this->issue_pair( (string) $row['grant_id'], explode( ' ', (string) $row['scopes'] ), (string) $row['family_id'] );
		} catch ( \RuntimeException ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'server_error', __( 'The refresh token could not be rotated safely. Try again.', 'sitepilot-mcp' ) );
		}
		if ( ! $this->replay_cache->store( $hash, $client_id, (string) $row['grant_id'], (string) $row['family_id'], $pair ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'server_error', __( 'The refresh token could not be rotated safely. Try again.', 'sitepilot-mcp' ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			$this->replay_cache->forget( $hash );
			return new \WP_Error( 'server_error', __( 'The refresh token could not be rotated safely. Try again.', 'sitepilot-mcp' ) );
		}
		return $pair;
	}

	public function revoke( string $token ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'sitepilot_oauth_tokens',
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array( 'token_hash' => Ids::hash( $token ) ),
			array( '%s' ),
			array( '%s' )
		);
	}
}
