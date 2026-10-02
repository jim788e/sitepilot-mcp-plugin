<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

/**
 * Versioned safety metadata for the Enfold elements emitted by SitePilot.
 *
 * Runtime registration remains authoritative: a known tag is never accepted
 * merely because it appears in this table.
 */
final class EnfoldShortcodeRegistry {
	/** @var array<string,list<string>|null> null means any registered parent is accepted. */
	private const PARENTS = array(
		'av_section'          => array(),
		'av_layout_row'       => array(),
		'av_one_full'         => array( 'av_section' ),
		'av_one_half'         => array( 'av_section' ),
		'av_one_third'        => array( 'av_section' ),
		'av_one_fourth'       => array( 'av_section' ),
		'av_two_third'        => array( 'av_section' ),
		'av_cell_one_full'    => array( 'av_layout_row' ),
		'av_cell_one_half'    => array( 'av_layout_row' ),
		'av_cell_one_third'   => array( 'av_layout_row' ),
		'av_cell_one_fourth'  => array( 'av_layout_row' ),
		'av_cell_three_fifth' => array( 'av_layout_row' ),
		'av_cell_two_fifth'   => array( 'av_layout_row' ),
		'av_iconlist_item'    => array( 'av_iconlist' ),
		'av_toggle'           => array( 'av_toggle_container' ),
		'av_tab'              => array( 'av_tab_container' ),
		'av_contact_field'    => array( 'av_contact' ),
		'av_textblock'        => array( 'av_one_full', 'av_one_half', 'av_one_third', 'av_one_fourth', 'av_two_third', 'av_cell_one_full', 'av_cell_one_half', 'av_cell_one_third', 'av_cell_one_fourth', 'av_cell_three_fifth', 'av_cell_two_fifth' ),
		'av_heading'          => array( 'av_one_full', 'av_one_half', 'av_one_third', 'av_one_fourth', 'av_two_third', 'av_cell_one_full', 'av_cell_one_half', 'av_cell_one_third', 'av_cell_one_fourth', 'av_cell_three_fifth', 'av_cell_two_fifth' ),
		'av_image'            => array( 'av_one_full', 'av_one_half', 'av_one_third', 'av_one_fourth', 'av_two_third', 'av_cell_one_full', 'av_cell_one_half', 'av_cell_one_third', 'av_cell_one_fourth', 'av_cell_three_fifth', 'av_cell_two_fifth' ),
		'av_button'           => array( 'av_one_full', 'av_one_half', 'av_one_third', 'av_one_fourth', 'av_two_third', 'av_cell_one_full', 'av_cell_one_half', 'av_cell_one_third', 'av_cell_one_fourth', 'av_cell_three_fifth', 'av_cell_two_fifth' ),
		'av_gallery'          => array( 'av_one_full', 'av_one_half', 'av_one_third', 'av_one_fourth', 'av_two_third', 'av_cell_one_full', 'av_cell_one_half', 'av_cell_one_third', 'av_cell_one_fourth', 'av_cell_three_fifth', 'av_cell_two_fifth' ),
		'av_iconlist'         => array( 'av_one_full', 'av_one_half', 'av_one_third', 'av_one_fourth', 'av_two_third', 'av_cell_one_full', 'av_cell_one_half', 'av_cell_one_third', 'av_cell_one_fourth', 'av_cell_three_fifth', 'av_cell_two_fifth' ),
		'av_toggle_container' => array( 'av_one_full', 'av_one_half', 'av_one_third', 'av_one_fourth', 'av_two_third', 'av_cell_one_full', 'av_cell_one_half', 'av_cell_one_third', 'av_cell_one_fourth', 'av_cell_three_fifth', 'av_cell_two_fifth' ),
		'av_tab_container'    => array( 'av_one_full', 'av_one_half', 'av_one_third', 'av_one_fourth', 'av_two_third', 'av_cell_one_full', 'av_cell_one_half', 'av_cell_one_third', 'av_cell_one_fourth', 'av_cell_three_fifth', 'av_cell_two_fifth' ),
		'av_hr'               => array( 'av_one_full', 'av_one_half', 'av_one_third', 'av_one_fourth', 'av_two_third', 'av_cell_one_full', 'av_cell_one_half', 'av_cell_one_third', 'av_cell_one_fourth', 'av_cell_three_fifth', 'av_cell_two_fifth' ),
		'av_contact'          => array( 'av_one_full', 'av_one_half', 'av_one_third', 'av_one_fourth', 'av_two_third', 'av_cell_one_full', 'av_cell_one_half', 'av_cell_one_third', 'av_cell_one_fourth', 'av_cell_three_fifth', 'av_cell_two_fifth' ),
	);

	public static function is_registered( string $tag ): bool {
		return (bool) preg_match( '/^av_[a-z0-9_-]+$/', $tag ) && shortcode_exists( $tag );
	}

	/** @return list<string>|null */
	public static function allowed_parents( string $tag ): ?array {
		return self::PARENTS[ $tag ] ?? null;
	}

	public static function parent_is_valid( string $tag, ?string $parent_tag ): bool {
		$allowed = self::allowed_parents( $tag );
		if ( null === $allowed ) {
			return true;
		}
		if ( null === $parent_tag ) {
			return array() === $allowed;
		}
		return in_array( $parent_tag, $allowed, true );
	}

	/** @return list<string> */
	public static function known_tags(): array {
		return array_keys( self::PARENTS );
	}

	/** @return 'curated'|'runtime_only' */
	public static function support( string $tag ): string {
		return in_array( $tag, self::known_tags(), true ) ? 'curated' : 'runtime_only';
	}

	/** @return list<string> */
	public static function registered_tags(): array {
		$tags = self::known_tags();
		global $shortcode_tags;
		if ( is_array( $shortcode_tags ) ) {
			$tags = array_merge( $tags, array_keys( $shortcode_tags ) );
		}
		$tags = array_values(
			array_unique(
				array_filter(
					$tags,
					static fn ( mixed $tag ): bool => is_string( $tag ) && self::is_registered( $tag )
				)
			)
		);
		sort( $tags );
		return $tags;
	}

	/**
	 * @return array{
	 *   fingerprint:string,
	 *   runtime_registered:list<string>,
	 *   definitions:array<string,array{tag:string,type:string,drag_level:int,parents:list<string>|null,attributes:list<string>,support:'curated'|'runtime_only'}>
	 * }
	 */
	public static function catalog( string $theme_version = '' ): array {
		$tags        = array_values( array_unique( array_merge( self::known_tags(), self::registered_tags() ) ) );
		$definitions = array();
		sort( $tags );
		foreach ( $tags as $tag ) {
			$definitions[ $tag ]            = self::definition( $tag );
			$definitions[ $tag ]['support'] = self::support( $tag );
		}
		return array(
			'fingerprint'        => self::fingerprint( $theme_version ),
			'runtime_registered' => self::registered_tags(),
			'definitions'        => $definitions,
		);
	}

	/** @return array{tag:string,type:string,drag_level:int,parents:list<string>|null,attributes:list<string>} */
	public static function definition( string $tag ): array {
		$type = match ( $tag ) {
			'av_section', 'av_layout_row' => 'layout',
			'av_one_full', 'av_one_half', 'av_one_third', 'av_one_fourth', 'av_two_third', 'av_cell_one_full', 'av_cell_one_half', 'av_cell_one_third', 'av_cell_one_fourth', 'av_cell_three_fifth', 'av_cell_two_fifth' => 'column',
			default => 'content',
		};
		return array(
			'tag'        => $tag,
			'type'       => $type,
			'drag_level' => 'layout' === $type ? 1 : ( 'column' === $type ? 2 : 3 ),
			'parents'    => self::allowed_parents( $tag ),
			'attributes' => self::attributes( $tag ),
		);
	}

	/** @return list<string> */
	public static function attributes( string $tag ): array {
		$common   = array( 'av_uid', 'custom_class', 'sc_version' );
		$specific = match ( $tag ) {
			'av_section'          => array( 'minimum_height', 'color', 'id', 'src', 'attachment', 'attachment_size', 'position', 'repeat', 'attach', 'custom_bg', 'overlay_enable', 'overlay_opacity', 'overlay_color' ),
			'av_layout_row'       => array( 'mobile_breaking' ),
			'av_one_full', 'av_one_half', 'av_one_third', 'av_one_fourth', 'av_two_third' => array( 'first', 'min_height', 'vertical_alignment', 'padding', 'custom_bg', 'border' ),
			'av_cell_one_full', 'av_cell_one_half', 'av_cell_one_third', 'av_cell_one_fourth', 'av_cell_three_fifth', 'av_cell_two_fifth' => array( 'first', 'vertical_alignment', 'padding', 'custom_bg' ),
			'av_heading'          => array( 'heading', 'tag', 'style', 'color', 'font_size' ),
			'av_textblock'        => array( 'color', 'font_size' ),
			'av_image'            => array( 'src', 'attachment', 'attachment_size', 'alt', 'link', 'target', 'align' ),
			'av_button'           => array( 'label', 'link', 'target', 'size', 'position', 'color' ),
			'av_gallery'          => array( 'ids', 'style', 'preview_size', 'columns', 'imagelink', 'lazy_loading' ),
			'av_toggle', 'av_tab' => array( 'title', 'icon_select' ),
			'av_toggle_container' => array( 'initial', 'mode', 'sort', 'styling' ),
			'av_contact'          => array( 'button', 'on_send', 'sent' ),
			'av_contact_field'    => array( 'label', 'type', 'check', 'width', 'options', 'element_display' ),
			default               => array(),
		};
		return array_values( array_unique( array_merge( $common, $specific ) ) );
	}

	public static function fingerprint( string $theme_version = '' ): string {
		$registered  = array_values( array_filter( self::known_tags(), array( self::class, 'is_registered' ) ) );
		$definitions = array();
		foreach ( self::known_tags() as $tag ) {
			$definitions[ $tag ] = self::definition( $tag );
		}
		$payload = wp_json_encode(
			array(
				'schema'        => 2,
				'theme_version' => $theme_version,
				'definitions'   => $definitions,
				'registered'    => $registered,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		return hash( 'sha256', is_string( $payload ) ? $payload : '' );
	}
}
