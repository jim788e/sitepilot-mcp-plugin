<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Artifacts;

use SitePilot\Mcp\Infrastructure\AuditLog;
use SitePilot\Mcp\Infrastructure\Ids;

final class ArtifactService {
	private const ALLOWED_MIMES      = array( 'application/zip', 'application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/html', 'text/css', 'application/javascript' );
	private const MAX_ARTIFACT_BYTES = 100 * MB_IN_BYTES;

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function manage( array $input ) {
		$command = (string) ( $input['command'] ?? '' );
		return match ( $command ) {
			'start'    => $this->start( $input ),
			'append'   => $this->append( $input ),
			'complete' => $this->complete( $input ),
			'stage_theme' => $this->stage_theme( $input ),
			'status'   => $this->status( (string) ( $input['artifact_id'] ?? '' ) ),
			default    => new \WP_Error( 'sitepilot_artifact_command', __( 'Unknown artifact command.', 'sitepilot-mcp' ) ),
		};
	}

	/**
	 * Staged uploads live under the public uploads directory. Keep them from being listed or
	 * fetched directly: index.php stops directory listing and .htaccess denies access on Apache.
	 * Servers that ignore .htaccess still rely on the unguessable UUID file names.
	 */
	public static function protect_staging_dir( string $dir ): void {
		$guards = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
		);
		foreach ( $guards as $name => $contents ) {
			$file = trailingslashit( $dir ) . $name;
			if ( ! file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a fixed access guard into the private staging directory.
				file_put_contents( $file, $contents );
			}
		}
	}

	/** Protect a staging directory that already exists, for example after an upgrade. */
	public static function protect_existing_staging_dir(): void {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'sitepilot-staging';
		if ( is_dir( $dir ) ) {
			self::protect_staging_dir( $dir );
		}
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function start( array $input ) {
		global $wpdb;
		$mime = sanitize_mime_type( (string) ( $input['mime_type'] ?? '' ) );
		if ( ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
			return new \WP_Error( 'sitepilot_mime_blocked', __( 'This artifact MIME type is not allowed.', 'sitepilot-mcp' ) );
		}
		$filename = sanitize_file_name( (string) ( $input['filename'] ?? 'artifact' ) );
		$uploads  = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'sitepilot_upload_error', (string) $uploads['error'] );
		}
		$id  = Ids::uuid();
		$dir = trailingslashit( $uploads['basedir'] ) . 'sitepilot-staging';
		wp_mkdir_p( $dir );
		self::protect_staging_dir( $dir );
		$path = trailingslashit( $dir ) . $id . '.part';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creates a private resumable upload staging file.
		if ( false === file_put_contents( $path, '' ) ) {
			return new \WP_Error( 'sitepilot_upload_error', __( 'Could not initialize artifact upload.', 'sitepilot-mcp' ) );
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$wpdb->prefix . 'sitepilot_artifacts',
			array(
				'artifact_id'   => $id,
				'actor_user_id' => get_current_user_id(),
				'filename'      => $filename,
				'mime_type'     => $mime,
				'byte_size'     => 0,
				'status'        => 'uploading',
				'storage_path'  => $path,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		return array(
			'artifact_id' => $id,
			'status'      => 'uploading',
			'offset'      => 0,
		);
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function append( array $input ) {
		global $wpdb;
		$row = $this->find( (string) ( $input['artifact_id'] ?? '' ) );
		if ( ! $row || 'uploading' !== $row['status'] || get_current_user_id() !== (int) $row['actor_user_id'] ) {
			return new \WP_Error( 'sitepilot_artifact_state', __( 'Artifact upload is unavailable.', 'sitepilot-mcp' ) );
		}
		$offset = (int) ( $input['offset'] ?? -1 );
		if ( (int) $row['byte_size'] !== $offset ) {
			return new \WP_Error( 'sitepilot_offset_conflict', __( 'Upload offset does not match.', 'sitepilot-mcp' ), array( 'expected_offset' => (int) $row['byte_size'] ) );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a size-limited multipart upload chunk.
		$chunk = base64_decode( (string) ( $input['chunk'] ?? '' ), true );
		if ( false === $chunk || strlen( $chunk ) > 5 * MB_IN_BYTES || (int) $row['byte_size'] + strlen( $chunk ) > self::MAX_ARTIFACT_BYTES ) {
			return new \WP_Error( 'sitepilot_chunk_invalid', __( 'Chunk is invalid or exceeds 5 MiB.', 'sitepilot-mcp' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Appends to the plugin-owned staging file with an exclusive lock.
		if ( false === file_put_contents( (string) $row['storage_path'], $chunk, FILE_APPEND | LOCK_EX ) ) {
			return new \WP_Error( 'sitepilot_upload_error', __( 'Could not append artifact chunk.', 'sitepilot-mcp' ) );
		}
		$size = $offset + strlen( $chunk );
		$wpdb->update(
			$wpdb->prefix . 'sitepilot_artifacts',
			array(
				'byte_size'  => $size,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'artifact_id' => $row['artifact_id'] )
		);
		return array(
			'artifact_id' => $row['artifact_id'],
			'status'      => 'uploading',
			'offset'      => $size,
		);
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function stage_theme( array $input ) {
		global $wpdb;
		$row = $this->find( (string) ( $input['artifact_id'] ?? '' ) );
		if ( ! $row || 'staged' !== $row['status'] || get_current_user_id() !== (int) $row['actor_user_id'] ) {
			return new \WP_Error( 'sitepilot_artifact_state', __( 'Only a completed artifact upload can be staged as a theme.', 'sitepilot-mcp' ) );
		}
		$result = ( new ThemeStager() )->stage( $row );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$wpdb->update(
			$wpdb->prefix . 'sitepilot_artifacts',
			array(
				'status'     => 'theme_staged',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'artifact_id' => $row['artifact_id'] )
		);
		return $result;
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private function complete( array $input ) {
		global $wpdb;
		$row = $this->find( (string) ( $input['artifact_id'] ?? '' ) );
		if ( ! $row || 'uploading' !== $row['status'] || get_current_user_id() !== (int) $row['actor_user_id'] ) {
			return new \WP_Error( 'sitepilot_artifact_state', __( 'Artifact upload is unavailable.', 'sitepilot-mcp' ) );
		}
		$hash     = hash_file( 'sha256', (string) $row['storage_path'] );
		$expected = strtolower( (string) ( $input['sha256'] ?? '' ) );
		if ( ! $hash || ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $expected, $hash ) ) {
			return new \WP_Error( 'sitepilot_hash_mismatch', __( 'Artifact checksum validation failed.', 'sitepilot-mcp' ) );
		}
		$wpdb->update(
			$wpdb->prefix . 'sitepilot_artifacts',
			array(
				'sha256'     => $hash,
				'status'     => 'staged',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'artifact_id' => $row['artifact_id'] )
		);
		( new AuditLog() )->record(
			'artifact.staged',
			'success',
			array(
				'inputs' => array(
					'artifact_id' => $row['artifact_id'],
					'sha256'      => $hash,
				),
			)
		);
		return array(
			'artifact_id' => $row['artifact_id'],
			'status'      => 'staged',
			'byte_size'   => (int) $row['byte_size'],
			'sha256'      => $hash,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function status( string $id ) {
		$row = $this->find( $id );
		if ( ! $row || get_current_user_id() !== (int) $row['actor_user_id'] ) {
			return new \WP_Error( 'sitepilot_artifact_missing', __( 'Artifact not found.', 'sitepilot-mcp' ) );
		}
		return array(
			'artifact_id' => $row['artifact_id'],
			'filename'    => $row['filename'],
			'mime_type'   => $row['mime_type'],
			'status'      => $row['status'],
			'byte_size'   => (int) $row['byte_size'],
			'sha256'      => $row['sha256'],
		);
	}

	/** @return array<string,mixed>|null */
	private function find( string $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sitepilot_artifacts WHERE artifact_id = %s", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}
