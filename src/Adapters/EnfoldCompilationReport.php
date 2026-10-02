<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

/** Builds the honest, actionable result contract for best-effort Enfold imports. */
final class EnfoldCompilationReport {
	/**
	 * @param array<string,mixed> $coverage
	 * @return array{coverage:array<string,mixed>,compiled:array{sections:int,elements:int},unmapped:list<array<string,mixed>>}
	 */
	public static function build( array $coverage, string $alb ): array {
		$expected = 0;
		$mapped   = 0;
		foreach ( (array) ( $coverage['categories'] ?? array() ) as $counts ) {
			$category_expected = (int) ( $counts['source'] ?? 0 );
			$category_actual   = (int) ( $counts['generated'] ?? 0 );
			$expected         += $category_expected;
			$mapped           += min( $category_expected, $category_actual );
		}
		$manifest_expected = count( (array) ( $coverage['manifest'] ?? array() ) );
		$manifest_lost     = count( (array) ( $coverage['lost_nodes'] ?? array() ) );
		$expected         += $manifest_expected;
		$mapped           += max( 0, $manifest_expected - $manifest_lost );

		$coverage['ratio']  = 0 === $expected ? 1.0 : round( $mapped / $expected, 4 );
		$coverage['status'] = array() === ( $coverage['lost_categories'] ?? array() ) ? 'complete' : 'partial';
		$unmapped           = self::unmapped( $coverage );

		preg_match_all( '/\[(?!\/)(av_[A-Za-z0-9_-]+)(?:\s[^\]]*)?\/?\]/u', $alb, $elements );
		$tags = $elements[1] ?? array();
		return array(
			'coverage' => $coverage,
			'compiled' => array(
				'sections' => count( array_filter( $tags, static fn ( string $tag ): bool => in_array( $tag, array( 'av_section', 'av_layout_row' ), true ) ) ),
				'elements' => count( $tags ),
			),
			'unmapped' => $unmapped,
		);
	}

	/** @param array<string,mixed> $coverage @return list<array<string,mixed>> */
	private static function unmapped( array $coverage ): array {
		$unmapped = array();
		foreach ( (array) ( $coverage['unmapped_assets'] ?? array() ) as $asset ) {
			$unmapped[] = array(
				'path'          => (string) ( $asset['path'] ?? '' ),
				'reason'        => 'asset_mapping_missing',
				'source_asset'  => (string) ( $asset['source_asset'] ?? '' ),
				'usage'         => (string) ( $asset['usage'] ?? 'html' ),
				'visible_text'  => '',
				'suggested_ops' => array( 'media.stage', 'design.edit_elements:insert av_image' ),
			);
		}
		foreach ( (array) ( $coverage['lost_nodes'] ?? array() ) as $node ) {
			$type       = (string) ( $node['type'] ?? 'element' );
			$unmapped[] = array(
				'path'          => (string) ( $node['source_path'] ?? '' ),
				'reason'        => 'semantic_' . $type . '_unmapped',
				'visible_text'  => (string) ( $node['visible_text'] ?? '' ),
				'suggested_ops' => self::suggested_ops( $type ),
			);
		}
		foreach ( (array) ( $coverage['unsupported_elements'] ?? array() ) as $item ) {
			$unmapped[] = array(
				'path'          => (string) ( $item['path'] ?? '' ),
				'reason'        => 'unsupported_' . sanitize_key( (string) ( $item['tag'] ?? 'element' ) ),
				'visible_text'  => (string) ( $item['visible_text'] ?? '' ),
				'suggested_ops' => array( 'inspect-design', 'design.edit_elements:insert' ),
			);
		}
		if ( array() !== ( $coverage['missing_visible_text'] ?? array() ) ) {
			$unmapped[] = array(
				'path'          => '',
				'reason'        => 'visible_text_fragments_unmapped',
				'visible_text'  => self::truncate( implode( ' | ', array_map( 'strval', (array) $coverage['missing_visible_text'] ) ) ),
				'suggested_ops' => array( 'inspect-design', 'design.edit_elements:set_content' ),
			);
		}
		return $unmapped;
	}

	/** @return list<string> */
	private static function suggested_ops( string $type ): array {
		$tag = match ( $type ) {
			'heading'   => 'av_heading',
			'text'      => 'av_textblock',
			'image'     => 'av_image',
			'gallery'   => 'av_gallery',
			'accordion' => 'av_toggle_container',
			'tabs'      => 'av_tab_container',
			'button'    => 'av_button',
			'form', 'form_field' => 'av_contact',
			default     => 'av_one_full',
		};
		return array( 'inspect-design', 'design.edit_elements:insert ' . $tag );
	}

	private static function truncate( string $text ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 500 ) : substr( $text, 0, 500 );
	}
}
