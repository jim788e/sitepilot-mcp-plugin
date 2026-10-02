<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Infrastructure;

/** Single source of truth for optional external-service capabilities. */
final class Capabilities {

	public const GATEWAY_ORIGIN_OPTION = 'sitepilot_mcp_gateway_origin';

	public static function gateway_configured(): bool {
		return '1' === get_option( 'sitepilot_mcp_cloud_opt_in', '0' ) && '' !== self::gateway_origin();
	}

	public static function gateway_origin(): string {
		return self::sanitize_gateway_origin( (string) get_option( self::GATEWAY_ORIGIN_OPTION, '' ) );
	}

	public static function artifact_import_available(): bool {
		return self::gateway_configured();
	}

	public static function artifact_origin(): string {
		return self::gateway_origin();
	}

	public static function sanitize_gateway_origin( string $value ): string {
		$value = trim( $value );
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| '' === (string) ( $parts['host'] ?? '' )
			|| ! empty( $parts['user'] )
			|| ! empty( $parts['pass'] )
			|| ! empty( $parts['port'] )
			|| ! empty( $parts['query'] )
			|| ! empty( $parts['fragment'] )
			|| ( isset( $parts['path'] ) && ! in_array( $parts['path'], array( '', '/' ), true ) ) ) {
			return '';
		}
		return 'https://' . strtolower( (string) $parts['host'] );
	}

	/** @return array<string,mixed> */
	public static function visual_verification( bool $requested, float $threshold ): array {
		$status = 'not_requested';
		$reason = null;
		if ( $requested ) {
			$status = self::gateway_configured() ? 'external_verification_required' : 'unavailable';
			$reason = self::gateway_configured() ? 'configured_external_verifier' : 'no_gateway_configured';
		}
		return array(
			'mode'           => 'optional_post_edit',
			'required'       => false,
			'requested'      => $requested,
			'status'         => $status,
			'reason'         => $reason,
			'threshold'      => max( 0.5, min( 1.0, $threshold ) ),
			'blocks_write'   => false,
			'blocks_publish' => false,
		);
	}
}
