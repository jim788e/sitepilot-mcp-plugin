<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Infrastructure;

final class Ids {
	public static function uuid(): string {
		return wp_generate_uuid4();
	}

	public static function token( int $bytes = 32 ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of cryptographically random token bytes.
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}

	public static function hash( string $value ): string {
		return hash( 'sha256', $value );
	}

	/** @param mixed $value */
	public static function canonical_hash( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( self::sort_recursive( $value ), JSON_UNESCAPED_SLASHES ) );
	}

	/** @param mixed $value @return mixed */
	private static function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( array( self::class, 'sort_recursive' ), $value );
		}
		ksort( $value );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sort_recursive( $item );
		}
		return $value;
	}
}
