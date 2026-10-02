<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Policy;

use SitePilot\Mcp\Credentials\CredentialContext;

final class ApprovalGuard {
	public const CAPABILITY = 'sitepilot_approve';

	public function register(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 10, 4 );
	}

	/**
	 * Map the approval meta capability to its per-site primitive capability.
	 *
	 * @param list<string> $caps Primitive capabilities selected by WordPress.
	 * @param list<mixed>  $args Capability arguments.
	 * @return list<string>
	 */
	public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		unset( $args );
		if ( self::CAPABILITY !== $cap ) {
			return $caps;
		}
		return 0 < $user_id ? array( self::CAPABILITY ) : array( 'do_not_allow' );
	}

	/**
	 * Authorize an approval and identify the channel for its audit event.
	 *
	 * @return array{channel:string,credential_type:string,credential_uuid:?string}|\WP_Error
	 */
	public function authorize( string $change_set_id, string $nonce = '' ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new \WP_Error(
				'sitepilot_approval_capability_denied',
				__( 'Your account cannot approve SitePilot change sets.', 'sitepilot-mcp' ),
				array( 'status' => 403 )
			);
		}

		$credential_context = CredentialContext::current();
		$oauth_context      = $GLOBALS['sitepilot_oauth_context'] ?? null;
		if ( is_array( $credential_context ) ) {
			$constant_enabled = defined( 'SITEPILOT_ALLOW_DELEGATED_APPROVAL' ) && true === SITEPILOT_ALLOW_DELEGATED_APPROVAL;
			if (
				'app_password' === ( $credential_context['credential_type'] ?? '' )
				&& 'active' === ( $credential_context['state'] ?? '' )
				&& get_current_user_id() === (int) ( $credential_context['user_id'] ?? 0 )
				&& self::delegated_approval_enabled( (int) ( $credential_context['may_approve'] ?? 0 ), $constant_enabled, wp_get_environment_type() )
			) {
				return array(
					'channel'         => 'delegated',
					'credential_type' => 'app_password',
					'credential_uuid' => (string) ( $credential_context['credential_uuid'] ?? '' ),
				);
			}
			return $this->channel_denied();
		}

		if ( is_array( $oauth_context ) ) {
			return $this->channel_denied();
		}

		if ( '' !== $nonce && false !== wp_verify_nonce( $nonce, 'sitepilot_approve_' . $change_set_id ) ) {
			return array(
				'channel'         => 'admin_ui',
				'credential_type' => 'cookie',
				'credential_uuid' => null,
			);
		}

		return $this->channel_denied();
	}

	public static function delegated_approval_enabled( int $may_approve, bool $constant_enabled, string $environment_type ): bool {
		return 1 === $may_approve && $constant_enabled && 'production' !== $environment_type;
	}

	private function channel_denied(): \WP_Error {
		return new \WP_Error(
			'sitepilot_approval_channel_denied',
			__( 'Change-set approval requires the nonce-authenticated SitePilot admin screen or an explicitly delegated non-production credential.', 'sitepilot-mcp' ),
			array( 'status' => 403 )
		);
	}
}
