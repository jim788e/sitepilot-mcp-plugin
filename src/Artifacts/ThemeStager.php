<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Artifacts;

use SitePilot\Mcp\Infrastructure\AuditLog;

final class ThemeStager {
	private const MAX_ARCHIVE_ENTRIES           = 5000;
	private const MAX_ARCHIVE_EXPANDED_BYTES    = 250 * MB_IN_BYTES;
	private const MAX_ARCHIVE_COMPRESSION_RATIO = 1000;

	/** @param array<string,mixed> $artifact @return array<string,mixed>|\WP_Error */
	public function stage( array $artifact ) {
		if ( 'application/zip' !== $artifact['mime_type'] || ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'sitepilot_theme_archive_invalid', __( 'A ZIP theme package and the PHP ZIP extension are required.', 'sitepilot-mcp' ) );
		}
		$root = $this->archive_root( (string) $artifact['storage_path'] );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return new \WP_Error( 'sitepilot_filesystem_unavailable', __( 'WordPress could not initialize its filesystem API.', 'sitepilot-mcp' ) );
		}
		$themes_root = get_theme_root();
		$destination = trailingslashit( $themes_root ) . $root;
		if ( $wp_filesystem->exists( $destination ) ) {
			return new \WP_Error( 'sitepilot_theme_conflict', __( 'The staged theme directory already exists.', 'sitepilot-mcp' ) );
		}
		$extracted = unzip_file( (string) $artifact['storage_path'], $themes_root );
		if ( is_wp_error( $extracted ) ) {
			return $extracted;
		}
		$theme = wp_get_theme( $root );
		if ( ! $theme->exists() || $theme->errors() ) {
			$wp_filesystem->delete( $destination, true );
			return new \WP_Error( 'sitepilot_theme_invalid', __( 'The extracted package is not a valid WordPress theme.', 'sitepilot-mcp' ) );
		}
		$preview_url = add_query_arg(
			array(
				'theme'  => $root,
				'return' => admin_url( 'themes.php' ),
			),
			admin_url( 'customize.php' )
		);
		( new AuditLog() )->record(
			'artifact.theme_staged',
			'success',
			array(
				'inputs' => array(
					'artifact_id' => $artifact['artifact_id'],
					'stylesheet'  => $root,
				),
			)
		);
		return array(
			'artifact_id'         => $artifact['artifact_id'],
			'status'              => 'theme_staged',
			'stylesheet'          => $root,
			'theme_name'          => $theme->get( 'Name' ),
			'theme_version'       => $theme->get( 'Version' ),
			'preview_url'         => $preview_url,
			'activation_required' => true,
		);
	}

	/** @return string|\WP_Error */
	private function archive_root( string $path ) {
		$archive = new \ZipArchive();
		if ( true !== $archive->open( $path ) ) {
			return new \WP_Error( 'sitepilot_theme_archive_invalid', __( 'The theme archive could not be opened.', 'sitepilot-mcp' ) );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes this native property as numFiles.
		$num_files = $archive->numFiles;
		if ( $num_files < 1 || $num_files > self::MAX_ARCHIVE_ENTRIES ) {
			$archive->close();
			return new \WP_Error( 'sitepilot_theme_archive_invalid', __( 'The theme archive has an invalid number of files.', 'sitepilot-mcp' ) );
		}
		$root             = '';
		$has_style        = false;
		$expanded_bytes   = 0;
		$operating_system = 0;
		$attributes       = 0;
		for ( $index = 0; $index < $num_files; ++$index ) {
			$name = $archive->getNameIndex( $index );
			if ( ! is_string( $name ) || $this->unsafe_path( $name ) ) {
				$archive->close();
				return new \WP_Error( 'sitepilot_theme_archive_unsafe', __( 'The theme archive contains an unsafe path.', 'sitepilot-mcp' ) );
			}
			$stat = $archive->statIndex( $index );
			if ( ! is_array( $stat ) || ! isset( $stat['size'], $stat['comp_size'] ) ) {
				$archive->close();
				return new \WP_Error( 'sitepilot_theme_archive_invalid', __( 'The theme archive has invalid file metadata.', 'sitepilot-mcp' ) );
			}
			$expanded_bytes += (int) $stat['size'];
			$archive_bomb    = $expanded_bytes > self::MAX_ARCHIVE_EXPANDED_BYTES;
			if ( (int) $stat['size'] > 0 ) {
				$archive_bomb = $archive_bomb || 0 === (int) $stat['comp_size'] || (int) $stat['size'] / (int) $stat['comp_size'] > self::MAX_ARCHIVE_COMPRESSION_RATIO;
			}
			if ( $archive_bomb ) {
				$archive->close();
				return new \WP_Error( 'sitepilot_theme_archive_unsafe', __( 'The theme archive exceeds safe size or compression limits.', 'sitepilot-mcp' ) );
			}
			$symbolic_link = method_exists( $archive, 'getExternalAttributesIndex' ) && $archive->getExternalAttributesIndex( $index, $operating_system, $attributes ) && 3 === $operating_system && 0xa000 === ( ( (int) $attributes >> 16 ) & 0xf000 );
			if ( $symbolic_link ) {
				$archive->close();
				return new \WP_Error( 'sitepilot_theme_archive_unsafe', __( 'The theme archive contains a symbolic link.', 'sitepilot-mcp' ) );
			}
			$segments = explode( '/', trim( $name, '/' ) );
			if ( empty( $segments[0] ) || sanitize_key( $segments[0] ) !== $segments[0] ) {
				$archive->close();
				return new \WP_Error( 'sitepilot_theme_archive_invalid', __( 'The theme archive must contain one valid top-level theme directory.', 'sitepilot-mcp' ) );
			}
			if ( '' === $root ) {
				$root = $segments[0];
			}
			if ( $root !== $segments[0] ) {
				$archive->close();
				return new \WP_Error( 'sitepilot_theme_archive_invalid', __( 'The theme archive must contain only one top-level directory.', 'sitepilot-mcp' ) );
			}
			if ( $root . '/style.css' === $name ) {
				$has_style = true;
			}
		}
		$archive->close();
		return $has_style ? $root : new \WP_Error( 'sitepilot_theme_archive_invalid', __( 'The theme archive must contain style.css at its top level.', 'sitepilot-mcp' ) );
	}

	private function unsafe_path( string $path ): bool {
		$normalized = str_replace( '\\', '/', $path );
		if ( '' === $normalized || str_contains( $normalized, "\0" ) || str_starts_with( $normalized, '/' ) || preg_match( '/^[A-Za-z]:/D', $normalized ) ) {
			return true;
		}
		return in_array( '..', explode( '/', $normalized ), true );
	}
}
