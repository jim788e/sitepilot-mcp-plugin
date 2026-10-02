<?php

declare(strict_types=1);

namespace SitePilot\Mcp\OAuth;

final class RefreshReplayCache {
	public const TTL_SECONDS = 30;

	private const CIPHER_SODIUM  = 's1.';
	private const CIPHER_OPENSSL = 'o1.';
	private const KEY_CONTEXT    = 'sitepilot-mcp/oauth-refresh-replay/v1';
	private const TRANSIENT_KEY  = 'sitepilot_oauth_refresh_replay_';

	/**
	 * Store one encrypted token response for idempotent refresh retries.
	 *
	 * @param array{access_token:string,token_type:string,expires_in:int,refresh_token:string,scope:string} $pair Token response.
	 */
	public function store( string $token_hash, string $client_id, string $grant_id, string $family_id, array $pair ): bool {
		$payload = wp_json_encode(
			array(
				'version'    => 1,
				'token_hash' => $token_hash,
				'client_id'  => $client_id,
				'grant_id'   => $grant_id,
				'family_id'  => $family_id,
				'expires_at' => time() + self::TTL_SECONDS,
				'token_pair' => $pair,
			),
			JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $payload ) ) {
			return false;
		}

		$encrypted = $this->encrypt( $payload );
		return null !== $encrypted && set_transient( $this->transient_key( $token_hash ), $encrypted, self::TTL_SECONDS );
	}

	/**
	 * Return the exact original token response when all request bindings still match.
	 *
	 * @return array{access_token:string,token_type:string,expires_in:int,refresh_token:string,scope:string}|null
	 */
	public function retrieve( string $token_hash, string $client_id, string $grant_id, string $family_id ): ?array {
		$cached = get_transient( $this->transient_key( $token_hash ) );
		if ( ! is_string( $cached ) || '' === $cached ) {
			return null;
		}

		$plaintext = $this->decrypt( $cached );
		if ( null === $plaintext ) {
			$this->forget( $token_hash );
			return null;
		}

		$payload = json_decode( $plaintext, true );
		if (
			! is_array( $payload )
			|| 1 !== (int) ( $payload['version'] ?? 0 )
			|| (int) ( $payload['expires_at'] ?? 0 ) <= time()
			|| ! $this->bound_value_matches( $payload, 'token_hash', $token_hash )
			|| ! $this->bound_value_matches( $payload, 'client_id', $client_id )
			|| ! $this->bound_value_matches( $payload, 'grant_id', $grant_id )
			|| ! $this->bound_value_matches( $payload, 'family_id', $family_id )
			|| ! $this->valid_pair( $payload['token_pair'] ?? null )
		) {
			$this->forget( $token_hash );
			return null;
		}

		/** @var array{access_token:string,token_type:string,expires_in:int,refresh_token:string,scope:string} $pair */
		$pair = $payload['token_pair'];
		return $pair;
	}

	public function forget( string $token_hash ): void {
		delete_transient( $this->transient_key( $token_hash ) );
	}

	public static function is_supported(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			|| (
				function_exists( 'openssl_encrypt' )
				&& function_exists( 'openssl_decrypt' )
				&& function_exists( 'openssl_get_cipher_methods' )
				&& in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true )
			);
	}

	private function transient_key( string $token_hash ): string {
		return self::TRANSIENT_KEY . hash_hmac( 'sha256', $token_hash, $this->encryption_key() );
	}

	private function encryption_key(): string {
		return hash_hkdf( 'sha256', wp_salt( 'auth' ), 32, self::KEY_CONTEXT );
	}

	private function encrypt( string $plaintext ): ?string {
		$key = $this->encryption_key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			return self::CIPHER_SODIUM . $this->base64url_encode( $nonce . $ciphertext );
		}

		if ( ! self::is_supported() ) {
			return null;
		}
		$nonce      = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::KEY_CONTEXT, 16 );
		return is_string( $ciphertext ) && 16 === strlen( $tag )
			? self::CIPHER_OPENSSL . $this->base64url_encode( $nonce . $tag . $ciphertext )
			: null;
	}

	private function decrypt( string $encrypted ): ?string {
		$key = $this->encryption_key();
		if ( str_starts_with( $encrypted, self::CIPHER_SODIUM ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$decoded = $this->base64url_decode( substr( $encrypted, strlen( self::CIPHER_SODIUM ) ) );
			if ( null === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return null;
			}
			$plaintext = sodium_crypto_secretbox_open(
				substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				$key
			);
			return is_string( $plaintext ) ? $plaintext : null;
		}

		if ( ! str_starts_with( $encrypted, self::CIPHER_OPENSSL ) || ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}
		$decoded = $this->base64url_decode( substr( $encrypted, strlen( self::CIPHER_OPENSSL ) ) );
		if ( null === $decoded || strlen( $decoded ) <= 28 ) {
			return null;
		}
		$plaintext = openssl_decrypt(
			substr( $decoded, 28 ),
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			substr( $decoded, 0, 12 ),
			substr( $decoded, 12, 16 ),
			self::KEY_CONTEXT
		);
		return is_string( $plaintext ) ? $plaintext : null;
	}

	/** @param array<string,mixed> $payload */
	private function bound_value_matches( array $payload, string $key, string $expected ): bool {
		return isset( $payload[ $key ] ) && is_string( $payload[ $key ] ) && hash_equals( $expected, $payload[ $key ] );
	}

	private function valid_pair( mixed $pair ): bool {
		return is_array( $pair )
			&& isset( $pair['access_token'], $pair['token_type'], $pair['expires_in'], $pair['refresh_token'], $pair['scope'] )
			&& is_string( $pair['access_token'] )
			&& 'Bearer' === $pair['token_type']
			&& is_int( $pair['expires_in'] )
			&& $pair['expires_in'] > 0
			&& is_string( $pair['refresh_token'] )
			&& is_string( $pair['scope'] );
	}

	private function base64url_encode( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe binary encoding, not obfuscation.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private function base64url_decode( string $value ): ?string {
		$padding = strlen( $value ) % 4;
		if ( 0 !== $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the matching URL-safe binary representation.
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true );
		return is_string( $decoded ) ? $decoded : null;
	}
}
