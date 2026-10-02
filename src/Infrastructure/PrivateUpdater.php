<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Infrastructure;

final class PrivateUpdater {
	private const CACHE_KEY          = 'sitepilot_mcp_verified_update_manifest_v1';
	private const MANIFEST_SCHEMA    = 'sitepilot-update-manifest-v1';
	private const MAX_MANIFEST_BYTES = 65536;
	private const SLUG               = 'sitepilot-mcp';

	/** @var array<string,string>|null */
	private ?array $manifest = null;

	public function register(): void {
		if ( ! $this->configured() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'offer_update' ) );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_package_download' ), 10, 4 );
	}

	/** @param mixed $transient @return mixed */
	public function offer_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$manifest = $this->load_manifest();
		if ( is_wp_error( $manifest ) || ! version_compare( $manifest['version'], SITEPILOT_MCP_VERSION, '>' ) ) {
			return $transient;
		}

		$plugin = plugin_basename( SITEPILOT_MCP_FILE );
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $plugin ] = (object) array(
			'id'           => self::SLUG,
			'slug'         => self::SLUG,
			'plugin'       => $plugin,
			'new_version'  => $manifest['version'],
			'package'      => $manifest['package_url'],
			'requires'     => $manifest['requires'],
			'requires_php' => $manifest['requires_php'],
			'tested'       => $manifest['tested'],
		);

		return $transient;
	}

	/**
	 * Download and verify the exact SitePilot package before WordPress can install it.
	 *
	 * @param mixed               $reply Existing pre-download result.
	 * @param string              $package Package URL.
	 * @param \WP_Upgrader|null   $upgrader Upgrader instance.
	 * @param array<string,mixed> $hook_extra Upgrade context.
	 * @return mixed
	 */
	public function verify_package_download( $reply, string $package, $upgrader, array $hook_extra ) {
		unset( $upgrader );
		if ( false !== $reply || ! $this->targets_sitepilot( $hook_extra ) ) {
			return $reply;
		}

		$manifest = $this->load_manifest();
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		if ( ! hash_equals( $manifest['package_url'], $package ) ) {
			return new \WP_Error( 'sitepilot_update_package_mismatch', __( 'The update package URL does not match the signed manifest.', 'sitepilot-mcp' ) );
		}

		$file = download_url( $package, 300, false );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$actual_hash = hash_file( 'sha256', $file );
		if ( ! is_string( $actual_hash ) || ! hash_equals( $manifest['package_sha256'], strtolower( $actual_hash ) ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'sitepilot_update_hash_mismatch', __( 'The downloaded SitePilot update failed SHA-256 verification.', 'sitepilot-mcp' ) );
		}

		return $file;
	}

	/** @return array<string,string>|\WP_Error */
	private function load_manifest() {
		if ( is_array( $this->manifest ) ) {
			return $this->manifest;
		}
		$cached      = get_site_transient( self::CACHE_KEY );
		$fingerprint = $this->configuration_fingerprint();
		if ( is_array( $cached )
			&& isset( $cached['configuration'], $cached['manifest'] )
			&& is_string( $cached['configuration'] )
			&& is_array( $cached['manifest'] )
			&& hash_equals( $fingerprint, $cached['configuration'] )
			&& self::MANIFEST_SCHEMA === ( $cached['manifest']['schema'] ?? '' ) ) {
			$this->manifest = $cached['manifest'];
			return $cached['manifest'];
		}

		$url      = (string) constant( 'SITEPILOT_MCP_UPDATE_MANIFEST_URL' );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::MAX_MANIFEST_BYTES,
				'headers'             => array( 'Accept' => 'application/json' ),
				'user-agent'          => 'SitePilot-MCP/' . SITEPILOT_MCP_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'sitepilot_update_manifest_http', __( 'The private update manifest could not be retrieved.', 'sitepilot-mcp' ) );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_MANIFEST_BYTES ) {
			return new \WP_Error( 'sitepilot_update_manifest_size', __( 'The private update manifest is too large.', 'sitepilot-mcp' ) );
		}
		$manifest = self::verify_manifest( $body, (string) constant( 'SITEPILOT_MCP_UPDATE_PUBLIC_KEY' ) );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$this->manifest = $manifest;
		set_site_transient(
			self::CACHE_KEY,
			array(
				'configuration' => $fingerprint,
				'manifest'      => $manifest,
			),
			6 * HOUR_IN_SECONDS
		);
		return $manifest;
	}

	private function configuration_fingerprint(): string {
		return hash(
			'sha256',
			(string) constant( 'SITEPILOT_MCP_UPDATE_MANIFEST_URL' ) . "\n" . (string) constant( 'SITEPILOT_MCP_UPDATE_PUBLIC_KEY' )
		);
	}

	private function configured(): bool {
		if ( ! defined( 'SITEPILOT_MCP_UPDATE_MANIFEST_URL' ) || ! defined( 'SITEPILOT_MCP_UPDATE_PUBLIC_KEY' ) ) {
			return false;
		}
		$url = (string) constant( 'SITEPILOT_MCP_UPDATE_MANIFEST_URL' );
		$key = (string) constant( 'SITEPILOT_MCP_UPDATE_PUBLIC_KEY' );
		return '' !== $key && self::is_https_url( $url );
	}

	/** @param array<string,mixed> $hook_extra */
	private function targets_sitepilot( array $hook_extra ): bool {
		$plugin  = plugin_basename( SITEPILOT_MCP_FILE );
		$targets = array();
		if ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$targets[] = $hook_extra['plugin'];
		}
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$targets = array_merge( $targets, array_filter( $hook_extra['plugins'], 'is_string' ) );
		}
		return in_array( $plugin, $targets, true );
	}

	/** @return array<string,string>|\WP_Error */
	public static function verify_manifest( string $json, string $public_key_base64 ) {
		$manifest = json_decode( $json, true, 8 );
		if ( ! is_array( $manifest ) || JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error( 'sitepilot_update_manifest_json', __( 'The private update manifest is not valid JSON.', 'sitepilot-mcp' ) );
		}

		$expected_keys = array( 'package_sha256', 'package_url', 'published_at', 'requires', 'requires_php', 'schema', 'signature', 'slug', 'tested', 'version' );
		$actual_keys   = array_keys( $manifest );
		sort( $actual_keys, SORT_STRING );
		if ( $actual_keys !== $expected_keys ) {
			return new \WP_Error( 'sitepilot_update_manifest_schema', __( 'The private update manifest has an unexpected schema.', 'sitepilot-mcp' ) );
		}
		foreach ( $expected_keys as $key ) {
			if ( ! is_string( $manifest[ $key ] ) ) {
				return new \WP_Error( 'sitepilot_update_manifest_schema', __( 'The private update manifest contains an invalid value.', 'sitepilot-mcp' ) );
			}
		}
		if ( self::MANIFEST_SCHEMA !== $manifest['schema'] || self::SLUG !== $manifest['slug'] ) {
			return new \WP_Error( 'sitepilot_update_manifest_identity', __( 'The private update manifest is for a different product.', 'sitepilot-mcp' ) );
		}
		if ( 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/D', $manifest['version'] ) ) {
			return new \WP_Error( 'sitepilot_update_manifest_version', __( 'The private update manifest has an invalid version.', 'sitepilot-mcp' ) );
		}
		if ( ! self::is_https_url( $manifest['package_url'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $manifest['package_sha256'] ) ) {
			return new \WP_Error( 'sitepilot_update_manifest_package', __( 'The private update package metadata is invalid.', 'sitepilot-mcp' ) );
		}
		if ( ! self::is_utc_timestamp( $manifest['published_at'] ) ) {
			return new \WP_Error( 'sitepilot_update_manifest_date', __( 'The private update publication date is invalid.', 'sitepilot-mcp' ) );
		}
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return new \WP_Error( 'sitepilot_update_signature_unavailable', __( 'Ed25519 signature verification is unavailable on this WordPress installation.', 'sitepilot-mcp' ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The protocol uses standard Base64 for a fixed-length Ed25519 public key.
		$public_key = base64_decode( $public_key_base64, true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The protocol uses standard Base64 for a fixed-length detached Ed25519 signature.
		$signature = base64_decode( $manifest['signature'], true );
		if ( false === $public_key || false === $signature || 32 !== strlen( $public_key ) || 64 !== strlen( $signature ) ) {
			return new \WP_Error( 'sitepilot_update_signature_encoding', __( 'The private update signature or public key is invalid.', 'sitepilot-mcp' ) );
		}
		$payload = self::signing_payload( $manifest );
		if ( is_wp_error( $payload ) || ! sodium_crypto_sign_verify_detached( $signature, $payload, $public_key ) ) {
			return new \WP_Error( 'sitepilot_update_signature_invalid', __( 'The private update manifest signature is invalid.', 'sitepilot-mcp' ) );
		}

		/** @var array<string,string> $manifest */
		return $manifest;
	}

	/** @param array<string,mixed> $manifest @return string|\WP_Error */
	public static function signing_payload( array $manifest ) {
		unset( $manifest['signature'] );
		ksort( $manifest, SORT_STRING );
		$payload = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $payload ) ? $payload : new \WP_Error( 'sitepilot_update_manifest_encoding', __( 'The private update manifest could not be encoded.', 'sitepilot-mcp' ) );
	}

	private static function is_https_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& '' !== (string) ( $parts['host'] ?? '' )
			&& empty( $parts['user'] )
			&& empty( $parts['pass'] )
			&& empty( $parts['fragment'] );
	}

	private static function is_utc_timestamp( string $timestamp ): bool {
		$formats = array( 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s.v\Z' );
		foreach ( $formats as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $timestamp );
			if ( false !== $date && $date->format( $format ) === $timestamp ) {
				return true;
			}
		}
		return false;
	}
}
