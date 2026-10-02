<?php

declare(strict_types=1);

namespace SitePilot\Mcp\OAuth;

final class Scopes {
	public const ALL = array(
		'site:read',
		'content:write',
		'media:write',
		'design:write',
		'commerce:write',
		'users:write',
		'extensions:manage',
		'core:update',
		'security:manage',
	);

	public const ADMIN_ONLY = array(
		'users:write',
		'extensions:manage',
		'core:update',
		'security:manage',
	);

	/** @return list<string>|\WP_Error */
	public static function parse( string $value ) {
		$parts     = preg_split( '/\s+/', trim( $value ) );
		$requested = array_values( array_unique( array_filter( is_array( $parts ) ? $parts : array() ) ) );
		if ( array_diff( $requested, self::ALL ) ) {
			return new \WP_Error( 'invalid_scope', __( 'One or more requested scopes are unsupported.', 'sitepilot-mcp' ) );
		}
		return $requested;
	}

	/** @param list<string> $scopes */
	public static function user_can_grant( array $scopes, int $user_id ): bool {
		if ( array_intersect( $scopes, self::ADMIN_ONLY ) && ! user_can( $user_id, 'manage_options' ) ) {
			return false;
		}
		return user_can( $user_id, 'sitepilot_connect' );
	}
}
