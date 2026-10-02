<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Credentials;

final class ApplicationPasswordAuthenticator {
	private CredentialGrantRepository $grants;

	public function __construct() {
		$this->grants = new CredentialGrantRepository();
	}

	public function register(): void {
		add_action( 'application_password_did_authenticate', array( $this, 'authenticated' ), 10, 2 );
		add_action( 'application_password_failed_authentication', array( CredentialContext::class, 'clear' ) );
		add_action( 'wp_delete_application_password', array( $this, 'deleted' ), 10, 2 );
	}

	/** @param array<string,mixed> $item */
	public function authenticated( \WP_User $user, array $item ): void {
		CredentialContext::set(
			$this->grants->resolve_application_password(
				(string) ( $item['uuid'] ?? '' ),
				(int) $user->ID,
				(string) ( $item['name'] ?? '' )
			)
		);
	}

	/** @param array<string,mixed> $item */
	public function deleted( int $user_id, array $item ): void {
		$this->grants->revoke_application_password( (string) ( $item['uuid'] ?? '' ), $user_id );
	}
}
