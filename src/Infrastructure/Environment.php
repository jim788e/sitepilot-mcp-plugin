<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Infrastructure;

use SitePilot\Mcp\OAuth\RefreshReplayCache;

final class Environment {
	/** @return list<string> */
	public function failures(): array {
		$messages = array(
			'php'          => __( 'PHP 8.2 or newer is required.', 'sitepilot-mcp' ),
			'wp_version'   => __( 'WordPress 6.9 or newer is required.', 'sitepilot-mcp' ),
			'multisite'    => __( 'WordPress Multisite is not supported in SitePilot v1.', 'sitepilot-mcp' ),
			'https'        => __( 'HTTPS is required.', 'sitepilot-mcp' ),
			'abilities'    => __( 'The WordPress Abilities API is unavailable.', 'sitepilot-mcp' ),
			'oauth_crypto' => __( 'Authenticated encryption support is required for safe OAuth refresh retries.', 'sitepilot-mcp' ),
		);
		return array_values( array_intersect_key( $messages, array_flip( $this->failure_codes() ) ) );
	}

	/** @return list<string> */
	private function failure_codes(): array {
		$failures = array();
		if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
			$failures[] = 'php';
		}
		if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
			$failures[] = 'wp_version';
		}
		if ( is_multisite() ) {
			$failures[] = 'multisite';
		}
		if ( ! is_ssl() && ! $this->insecure_local_testing_allowed() ) {
			$failures[] = 'https';
		}
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$failures[] = 'abilities';
		}
		if ( ! RefreshReplayCache::is_supported() ) {
			$failures[] = 'oauth_crypto';
		}
		return $failures;
	}

	public function is_supported(): bool {
		return array() === $this->failure_codes();
	}

	/**
	 * Permit HTTP only for an explicitly opted-in local WordPress environment.
	 *
	 * This escape hatch exists solely for isolated development installations
	 * whose locally generated TLS certificate cannot be trusted by a test client.
	 */
	private function insecure_local_testing_allowed(): bool {
		return defined( 'SITEPILOT_MCP_ALLOW_INSECURE_LOCAL' )
			&& true === SITEPILOT_MCP_ALLOW_INSECURE_LOCAL
			&& function_exists( 'wp_get_environment_type' )
			&& 'local' === wp_get_environment_type();
	}

	public function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		foreach ( $this->failures() as $failure ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $failure ) . '</p></div>';
		}
	}
}
