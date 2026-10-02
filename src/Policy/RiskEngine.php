<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Policy;

final class RiskEngine {
	/** @var array<string,array{tier:int,scope:string}> */
	private const POLICIES = array(
		'content.create_draft'          => array(
			'tier'  => 1,
			'scope' => 'content:write',
		),
		'media.stage'                   => array(
			'tier'  => 1,
			'scope' => 'media:write',
		),
		'media.import_artifact'         => array(
			'tier'  => 1,
			'scope' => 'media:write',
		),
		'design.stage'                  => array(
			'tier'  => 1,
			'scope' => 'design:write',
		),
		'design.compile_enfold_html'    => array(
			'tier'  => 1,
			'scope' => 'design:write',
		),
		'design.compile_elementor_html' => array(
			'tier'  => 1,
			'scope' => 'design:write',
		),
		'design.edit_elements'          => array(
			'tier'  => 1,
			'scope' => 'design:write',
		),
		'design.save_template'          => array(
			'tier'  => 1,
			'scope' => 'design:write',
		),
		'design.apply_template'         => array(
			'tier'  => 1,
			'scope' => 'design:write',
		),
		'design.set_element_style'      => array(
			'tier'  => 1,
			'scope' => 'design:write',
		),
		'design.resolve_links'          => array(
			'tier'  => 1,
			'scope' => 'design:write',
		),
		'site.create_menu'              => array(
			'tier'  => 1,
			'scope' => 'design:write',
		),
		'content.publish'               => array(
			'tier'  => 2,
			'scope' => 'content:write',
		),
		'content.bulk_update'           => array(
			'tier'  => 2,
			'scope' => 'content:write',
		),
		'content.trash'                 => array(
			'tier'  => 2,
			'scope' => 'content:write',
		),
		'site.update_setting'           => array(
			'tier'  => 2,
			'scope' => 'design:write',
		),
		'design.update_global_kit'      => array(
			'tier'  => 2,
			'scope' => 'design:write',
		),
		'design.stage_theme_document'   => array(
			'tier'  => 2,
			'scope' => 'design:write',
		),
		'site.update_menu'              => array(
			'tier'  => 2,
			'scope' => 'design:write',
		),
		'site.assign_menu'              => array(
			'tier'  => 2,
			'scope' => 'design:write',
		),
		'commerce.update_price'         => array(
			'tier'  => 2,
			'scope' => 'commerce:write',
		),
		'commerce.update_stock'         => array(
			'tier'  => 2,
			'scope' => 'commerce:write',
		),
		'extension.install'             => array(
			'tier'  => 3,
			'scope' => 'extensions:manage',
		),
		'extension.activate'            => array(
			'tier'  => 3,
			'scope' => 'extensions:manage',
		),
		'theme.activate'                => array(
			'tier'  => 3,
			'scope' => 'extensions:manage',
		),
		'core.update'                   => array(
			'tier'  => 3,
			'scope' => 'core:update',
		),
		'user.change_role'              => array(
			'tier'  => 3,
			'scope' => 'users:write',
		),
		'commerce.refund'               => array(
			'tier'  => 3,
			'scope' => 'commerce:write',
		),
		'commerce.update_order'         => array(
			'tier'  => 3,
			'scope' => 'commerce:write',
		),
		'security.update_setting'       => array(
			'tier'  => 3,
			'scope' => 'security:manage',
		),
		'design.calibrate_enfold'       => array(
			'tier'  => 3,
			'scope' => 'design:write',
		),
		'content.permanent_delete'      => array(
			'tier'  => 3,
			'scope' => 'content:write',
		),
	);

	/** @param list<array<string,mixed>> $actions @return array{tier:int,scopes:list<string>}|\WP_Error */
	public function classify( array $actions ) {
		$tier   = 0;
		$scopes = array();
		foreach ( $actions as $action ) {
			$operation = isset( $action['operation'] ) ? (string) $action['operation'] : '';
			if ( ! isset( self::POLICIES[ $operation ] ) ) {
				// translators: %s: blocked operation name.
				return new \WP_Error( 'sitepilot_operation_blocked', sprintf( __( 'Operation %s is not exposed by SitePilot.', 'sitepilot-mcp' ), $operation ) );
			}
			$policy   = self::POLICIES[ $operation ];
			$tier     = max( $tier, $policy['tier'] );
			$scopes[] = $policy['scope'];
		}
		return array(
			'tier'   => $tier,
			'scopes' => array_values( array_unique( $scopes ) ),
		);
	}

	/** @return array<string,array{tier:int,scope:string}> */
	public function policies(): array {
		return self::POLICIES;
	}
}
