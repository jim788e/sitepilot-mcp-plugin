<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Policy;

use SitePilot\Mcp\Credentials\CredentialContext;

final class ScopeGuard {
	public function allows( string $scope ): bool {
		$oauth_context = $GLOBALS['sitepilot_oauth_context'] ?? null;
		if ( is_array( $oauth_context ) ) {
			return in_array( $scope, $oauth_context['scopes'] ?? array(), true );
		}
		$credential_context = CredentialContext::current();
		if ( is_array( $credential_context ) ) {
			return in_array( $scope, $credential_context['scopes'] ?? array(), true );
		}
		return CredentialContext::allows_capability_fallback() && current_user_can( 'sitepilot_connect' );
	}

	public function require_scope( string $scope ): true|\WP_Error {
		if ( $this->allows( $scope ) ) {
			return true;
		}
		return new \WP_Error(
			'sitepilot_scope_denied',
			// translators: %s: required credential scope name.
			sprintf( __( 'The authenticated credential does not include %s.', 'sitepilot-mcp' ), $scope ),
			array(
				'status'         => 403,
				'required_scope' => $scope,
			)
		);
	}
}
