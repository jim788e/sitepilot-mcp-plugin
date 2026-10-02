<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Credentials;

final class CredentialContext {
	private const GLOBAL_KEY = 'sitepilot_credential_context';

	/** @param array<string,mixed> $context */
	public static function set( array $context ): void {
		$GLOBALS[ self::GLOBAL_KEY ] = $context; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the key is a constant carrying the plugin prefix.
	}

	/** @return array<string,mixed>|null */
	public static function current(): ?array {
		$context = $GLOBALS[ self::GLOBAL_KEY ] ?? null;
		return is_array( $context ) ? $context : null;
	}

	public static function clear(): void {
		unset( $GLOBALS[ self::GLOBAL_KEY ] );
	}

	/** Return the stable credential identifier used to correlate security audit rows. */
	public static function audit_client_id(): ?string {
		$context = self::current();
		if ( is_array( $context ) && 'app_password' === ( $context['credential_type'] ?? '' ) ) {
			$uuid = trim( (string) ( $context['credential_uuid'] ?? '' ) );
			return '' !== $uuid ? 'app-password:' . $uuid : null;
		}

		$oauth = $GLOBALS['sitepilot_oauth_context'] ?? null;
		if ( is_array( $oauth ) ) {
			$client_id = trim( (string) ( $oauth['client_id'] ?? '' ) );
			return '' !== $client_id ? $client_id : null;
		}

		return null;
	}

	/**
	 * Limit the legacy capability fallback to interactive cookie actors and WP-CLI.
	 * API credentials must establish an explicit OAuth or credential context.
	 */
	public static function allows_capability_fallback(): bool {
		if ( defined( 'WP_CLI' ) && true === WP_CLI ) {
			return true;
		}
		if ( ! self::is_api_request() ) {
			return true;
		}
		if ( ! defined( 'REST_REQUEST' ) || true !== REST_REQUEST ) {
			return false;
		}
		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_WP_NONCE'] ) )
			: sanitize_text_field( wp_unslash( (string) ( $_REQUEST['_wpnonce'] ?? '' ) ) );
		return '' !== $nonce && 0 < get_current_user_id() && false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}

	public static function is_api_request(): bool {
		if ( defined( 'REST_REQUEST' ) && true === REST_REQUEST ) {
			return true;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && true === XMLRPC_REQUEST ) {
			return true;
		}
		// Presence check only: the credential is never stored or output here, and sanitizing it would corrupt it.
		return isset( $_SERVER['HTTP_AUTHORIZATION'] ) && '' !== trim( wp_unslash( (string) $_SERVER['HTTP_AUTHORIZATION'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- presence check only.
	}
}
