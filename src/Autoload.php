<?php

declare(strict_types=1);

namespace SitePilot\Mcp;

final class Autoload {
	public static function register(): void {
		spl_autoload_register(
			static function ( string $class_name ): void {
				$prefix = __NAMESPACE__ . '\\';
				if ( ! str_starts_with( $class_name, $prefix ) ) {
					return;
				}
				$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class_name, strlen( $prefix ) ) );
				$file     = __DIR__ . DIRECTORY_SEPARATOR . $relative . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
