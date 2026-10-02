<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

/** Resolves approved media mappings and records absent mappings for partial compilation. */
final class EnfoldAssetResolver {
	/** @var list<string> */
	private array $external = array();
	/** @var list<string> */
	private array $malformed = array();
	/** @var list<array<string,mixed>> */
	private array $unmapped = array();
	/** @var array<string,string> */
	private array $used = array();

	/** @param array<string,mixed> $media */
	public function __construct( private readonly array $media ) {}

	/** @return array{attachment_id:int,url:string,alt:string,source:string}|null|\WP_Error */
	public function resolve( string $source, string $alt, string $usage = 'html', string $source_path = '' ) {
		if ( '' === $source || preg_match( '#^(?:data|file|javascript):#iu', $source ) ) {
			$this->malformed[] = $source;
			return $this->error( $source, 0, 'source_invalid' );
		}
		if ( ! array_key_exists( $source, $this->media ) ) {
			if ( preg_match( '#^https?://#iu', $source ) ) {
				$this->external[] = $source;
			}
			$this->record_unmapped( $source, $source_path, $usage );
			return null;
		}
		$mapping = $this->media[ $source ] ?? null;
		$id      = is_array( $mapping ) ? absint( $mapping['attachment_id'] ?? 0 ) : absint( $mapping );
		if ( $id <= 0 ) {
			return $this->error( $source, 0, 'mapping_missing' );
		}
		if ( function_exists( 'wp_attachment_is_image' ) && ! wp_attachment_is_image( $id ) ) {
			$this->malformed[] = $source;
			return $this->error( $source, $id, 'attachment_not_image' );
		}
		$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $id ) : false;
		if ( ! is_string( $url ) || '' === $url || ! $this->same_site_url( $url ) ) {
			$this->malformed[] = $source;
			return $this->error( $source, $id, 'attachment_url_invalid' );
		}
		$mapped_alt = is_array( $mapping ) && is_string( $mapping['alt'] ?? null ) ? $mapping['alt'] : $alt;
		if ( ! isset( $this->used[ $source ] ) || 'html' === $usage ) {
			$this->used[ $source ] = $usage;
		}
		return array(
			'attachment_id' => $id,
			'url'           => esc_url_raw( $url ),
			'alt'           => sanitize_text_field( $mapped_alt ),
			'source'        => $source,
		);
	}

	/** @return list<string> */
	public function external_assets(): array {
		return array_values( array_unique( $this->external ) );
	}

	/** @return list<string> */
	public function malformed_assets(): array {
		return array_values( array_unique( $this->malformed ) );
	}

	/** @return list<array<string,mixed>> */
	public function unmapped_assets(): array {
		return $this->unmapped;
	}

	/** @return array<string,string> */
	public function used_assets(): array {
		return $this->used;
	}

	private function record_unmapped( string $source, string $path, string $usage ): void {
		foreach ( $this->unmapped as $item ) {
			if ( ( $item['source_asset'] ?? '' ) === $source && ( $item['path'] ?? '' ) === $path && ( $item['usage'] ?? '' ) === $usage ) {
				return;
			}
		}
		$this->unmapped[] = array(
			'path'         => $path,
			'source_asset' => $source,
			'usage'        => $usage,
		);
	}

	private function same_site_url( string $url ): bool {
		$asset = wp_parse_url( $url );
		$site  = function_exists( 'home_url' ) ? wp_parse_url( home_url( '/' ) ) : $asset;
		return is_array( $asset )
			&& is_array( $site )
			&& in_array( strtolower( (string) ( $asset['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& hash_equals( strtolower( (string) ( $site['host'] ?? '' ) ), strtolower( (string) ( $asset['host'] ?? '' ) ) );
	}

	private function error( string $source, int $attachment_id, string $reason ): \WP_Error {
		$message = __( 'A mapped Media Library image cannot be emitted as a renderable Enfold asset.', 'sitepilot-mcp' );
		return new \WP_Error(
			'sitepilot_enfold_asset_render_failed',
			$message,
			array(
				'code'               => 'sitepilot_enfold_asset_render_failed',
				'message'            => $message,
				'source_asset'       => $source,
				'attachment_id'      => $attachment_id,
				'emitted_shortcode'  => 'av_image',
				'preview_selector'   => $attachment_id > 0 ? 'img[data-attachment-id="' . $attachment_id . '"],img[src]' : 'img[src]',
				'failure_reason'     => $reason,
				'change_set_created' => false,
			)
		);
	}
}
