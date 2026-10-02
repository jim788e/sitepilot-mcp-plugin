<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

/**
 * Data-access layer wrapping Elementor internals.
 *
 * Every Elementor read and write in SitePilot funnels through this class so the
 * guarded adapters never touch `\Elementor\Plugin::$instance` directly. Several
 * behaviours here exist because Elementor's own save path is bypassed whenever
 * SitePilot writes from a WP-CLI or REST context; see the individual
 * method notes. Techniques derived from msrbuilds/elementor-mcp (GPL-2.0) are
 * recorded in NOTICE.md.
 */
final class ElementorDocumentStore {

	/** Elementor 4.2 rendered-element cache meta key (Document::CACHE_META_KEY). */
	public const ELEMENT_CACHE_META = '_elementor_element_cache';

	/** Meta keys captured in every Elementor snapshot. */
	public const SNAPSHOT_META = array(
		'_elementor_data',
		'_elementor_edit_mode',
		'_elementor_page_settings',
		'_elementor_version',
		'_elementor_template_type',
		'_elementor_controls_usage',
		self::ELEMENT_CACHE_META,
	);

	/**
	 * Invalidate Elementor's rendered-element cache whenever `_elementor_data` changes.
	 *
	 * Elementor clears this cache inside `Document::save()`, but a rollback or a
	 * recovery path writes the meta directly. On a host with a persistent object
	 * cache the stale entry then survives every later content write, so a page
	 * rendered while its data was still empty keeps serving that empty render.
	 */
	public static function register_cache_invalidation(): void {
		add_action( 'added_post_meta', array( self::class, 'flush_element_cache' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'flush_element_cache' ), 10, 3 );
	}

	/**
	 * @param int    $meta_id  Meta row id, unused.
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key that changed.
	 */
	public static function flush_element_cache( $meta_id, $post_id, $meta_key ): void {
		unset( $meta_id );
		if ( '_elementor_data' === $meta_key ) {
			delete_post_meta( (int) $post_id, self::ELEMENT_CACHE_META );
		}
	}

	/** Whether Elementor is active at all. */
	public function available(): bool {
		return defined( 'ELEMENTOR_VERSION' ) && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance );
	}

	/**
	 * Whether Elementor's document manager has finished booting.
	 *
	 * False during Elementor's own activation: it inserts its default kit before
	 * `\Elementor\Plugin::$instance->documents` exists, and that insert reaches
	 * our save_post listeners. Dereferencing the manager then is a fatal.
	 */
	public function documents_ready(): bool {
		if ( ! $this->available() ) {
			return false;
		}
		$instance = \Elementor\Plugin::$instance;
		return (bool) ( $instance->documents ?? null );
	}

	/**
	 * @return \Elementor\Core\Base\Document|\WP_Error
	 */
	public function get_document( int $post_id ) {
		if ( ! $this->documents_ready() ) {
			return new \WP_Error( 'sitepilot_elementor_unavailable', __( 'Elementor is not fully initialized.', 'sitepilot-mcp' ) );
		}
		try {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id );
		} catch ( \Throwable ) {
			return new \WP_Error( 'sitepilot_elementor_document_invalid', __( 'Elementor could not initialize the target document.', 'sitepilot-mcp' ) );
		}
		if ( ! $document ) {
			return new \WP_Error( 'sitepilot_elementor_document_invalid', __( 'Elementor could not load the target document.', 'sitepilot-mcp' ) );
		}
		return $document;
	}

	/**
	 * Read a post's element tree.
	 *
	 * `get_elements_data()` returns empty under WP-CLI and REST contexts,
	 * which is exactly how SitePilot's integration scenarios run, so fall back to
	 * decoding the raw meta rather than reporting an empty page.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function get_elements( int $post_id ): array {
		$document = $this->get_document( $post_id );
		if ( ! is_wp_error( $document ) ) {
			try {
				$data = $document->get_elements_data();
			} catch ( \Throwable ) {
				$data = null;
			}
			if ( is_array( $data ) && array() !== $data ) {
				return array_values( $data );
			}
		}
		return $this->get_raw_elements( $post_id );
	}

	/**
	 * Decode `_elementor_data` without going through Elementor.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function get_raw_elements( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_array( $raw ) ) {
			return array_values( $raw );
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? array_values( $decoded ) : array();
	}

	/** @return array<string,mixed> */
	public function get_page_settings( int $post_id ): array {
		$settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		return is_array( $settings ) ? $settings : array();
	}

	public function get_document_type( int $post_id ): string {
		return (string) get_post_meta( $post_id, '_elementor_template_type', true );
	}

	/**
	 * The single Elementor write path.
	 *
	 * @param list<array<string,mixed>> $elements Element tree.
	 * @param array<string,mixed>       $settings Document settings.
	 * @return true|\WP_Error
	 */
	public function save_elements( int $post_id, array $elements, array $settings = array() ) {
		$document = $this->get_document( $post_id );
		if ( is_wp_error( $document ) ) {
			return $document;
		}
		try {
			$payload = array( 'elements' => $elements );
			if ( array() !== $settings ) {
				$payload['settings'] = $settings;
			}
			$result = $document->save( $payload );
		} catch ( \Throwable ) {
			return new \WP_Error( 'sitepilot_elementor_save_failed', __( 'Elementor rejected the staged document.', 'sitepilot-mcp' ) );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result ) {
			return new \WP_Error( 'sitepilot_elementor_save_failed', __( 'Elementor did not persist the staged document.', 'sitepilot-mcp' ) );
		}
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		delete_post_meta( $post_id, self::ELEMENT_CACHE_META );
		$this->regenerate_css( $post_id );
		return true;
	}

	/**
	 * Rebuild the per-post Elementor CSS file.
	 *
	 * Without this a tree written outside the editor persists but renders with
	 * stale styles. Elementor's CSS classes move between majors, so every call is
	 * guarded and a failure is never fatal to the change set.
	 */
	public function regenerate_css( int $post_id ): void {
		if ( ! $this->available() ) {
			return;
		}
		try {
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->update();
			}
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- stale CSS must not fail a change set.
			unset( $post_id );
		}
	}

	/** Clear Elementor's whole generated-file cache after a global style change. */
	public function clear_file_cache(): void {
		if ( ! $this->available() ) {
			return;
		}
		try {
			$files_manager = \Elementor\Plugin::$instance->files_manager ?? null;
			if ( $files_manager && method_exists( $files_manager, 'clear_cache' ) ) {
				$files_manager->clear_cache();
			}
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- cache clearing is best effort.
			return;
		}
	}

	/** The active Elementor kit post id, or 0 when Elementor is inactive. */
	public function active_kit_id(): int {
		if ( ! $this->available() ) {
			return 0;
		}
		try {
			$kits_manager = \Elementor\Plugin::$instance->kits_manager ?? null;
			if ( $kits_manager && method_exists( $kits_manager, 'get_active_id' ) ) {
				return (int) $kits_manager->get_active_id();
			}
		} catch ( \Throwable ) {
			return 0;
		}
		return 0;
	}

	/**
	 * Capture the full Elementor state of a post.
	 *
	 * @return array<string,mixed>
	 */
	public function snapshot( \WP_Post $post ): array {
		$meta = array();
		foreach ( self::SNAPSHOT_META as $key ) {
			$meta[ $key ] = metadata_exists( 'post', $post->ID, $key ) ? get_post_meta( $post->ID, $key, true ) : null;
		}
		return array(
			'post'         => array(
				'ID'            => $post->ID,
				'post_title'    => $post->post_title,
				'post_content'  => $post->post_content,
				'post_excerpt'  => $post->post_excerpt,
				'post_name'     => $post->post_name,
				'post_status'   => $post->post_status,
				'post_type'     => $post->post_type,
				'post_parent'   => $post->post_parent,
				'menu_order'    => $post->menu_order,
				'page_template' => get_page_template_slug( $post ),
			),
			'meta'         => $meta,
			'thumbnail_id' => get_post_thumbnail_id( $post ),
		);
	}

	/**
	 * Restore a snapshot produced by {@see self::snapshot()}.
	 *
	 * @param array<string,mixed> $snapshot Snapshot payload.
	 * @return true|\WP_Error
	 */
	public function restore( array $snapshot ) {
		if ( ! isset( $snapshot['post'] ) || ! is_array( $snapshot['post'] ) ) {
			return new \WP_Error( 'sitepilot_rollback_unknown', __( 'The Elementor snapshot is malformed.', 'sitepilot-mcp' ) );
		}
		$post_id = (int) ( $snapshot['post']['ID'] ?? 0 );
		$result  = wp_update_post( (array) $snapshot['post'], true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		foreach ( (array) ( $snapshot['meta'] ?? array() ) as $key => $value ) {
			if ( null === $value ) {
				delete_post_meta( $post_id, (string) $key );
			} else {
				update_post_meta( $post_id, (string) $key, $value );
			}
		}
		$thumbnail = absint( $snapshot['thumbnail_id'] ?? 0 );
		if ( $thumbnail > 0 ) {
			set_post_thumbnail( $post_id, $thumbnail );
		} else {
			delete_post_thumbnail( $post_id );
		}
		$this->regenerate_css( $post_id );
		return true;
	}
}
