<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Abilities;

use SitePilot\Mcp\Adapters\ElementorAdapter;
use SitePilot\Mcp\Adapters\ElementorElementEditor;
use SitePilot\Mcp\Adapters\EnfoldElementEditor;
use SitePilot\Mcp\Artifacts\ArtifactService;
use SitePilot\Mcp\Changes\ChangeSetService;
use SitePilot\Mcp\Infrastructure\SiteVersion;
use SitePilot\Mcp\Policy\RiskEngine;
use SitePilot\Mcp\Policy\ScopeGuard;
final class AbilityRegistry {
	private const OPERATION_ROUTE_ABILITIES = array(
		'inspect-site',
		'search-capabilities',
		'describe-operations',
		'inspect-navigation',
		'inspect-design',
		'plan-change',
		'execute-change',
		'get-change-status',
		'rollback-change',
		'manage-artifact',
		'cancel-change',
		'revoke-approval',
	);

	public const TOOLS = array(
		'sitepilot/inspect-site',
		'sitepilot/search-capabilities',
		'sitepilot/describe-operations',
		'sitepilot/inspect-navigation',
		'sitepilot/inspect-design',
		'sitepilot/plan-change',
		'sitepilot/execute-change',
		'sitepilot/get-change-status',
		'sitepilot/rollback-change',
		'sitepilot/manage-artifact',
	);

	private ChangeSetService $changes;

	public function __construct() {
		$this->changes = new ChangeSetService();
	}

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'rest_api_init', array( $this, 'register_operation_routes' ) );
		add_filter( 'mcp_adapter_tool_call_result', array( $this, 'structure_mcp_tool_error' ), 10, 3 );
	}

	/**
	 * Preserve SitePilot error codes and diagnostics that the MCP adapter otherwise
	 * reduces to a generic text-only tool error.
	 *
	 * @param mixed               $result    Tool result.
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return mixed
	 */
	public function structure_mcp_tool_error( mixed $result, array $arguments, string $tool_name ): mixed {
		unset( $arguments );
		if ( ! is_wp_error( $result ) || ! preg_match( '/^sitepilot[-_]/u', $tool_name ) ) {
			return $result;
		}
		$code = (string) $result->get_error_code();
		if ( ! str_starts_with( $code, 'sitepilot_' ) ) {
			return $result;
		}
		$data     = $result->get_error_data();
		$error    = array(
			'code'    => $code,
			'message' => $result->get_error_message(),
			'data'    => is_array( $data ) ? $data : array(),
		);
		$response = array(
			'ok'    => false,
			'error' => $error,
		);
		if ( in_array( $tool_name, array( 'sitepilot-plan-change', 'sitepilot_plan_change' ), true ) ) {
			$response['status']             = 'rejected';
			$response['change_set_created'] = false;
		}
		return $response;
	}

	public function register_operation_routes(): void {
		$route_args = array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'run_operation' ),
			'permission_callback' => array( $this, 'can_run_operation' ),
		);
		$operations = implode( '|', array_map( static fn ( string $ability ): string => preg_quote( $ability, '/' ), self::OPERATION_ROUTE_ABILITIES ) );

		register_rest_route(
			'sitepilot-mcp/v1',
			'/ops/(?P<ability>' . $operations . ')',
			$route_args
		);
		register_rest_route(
			'sitepilot-mcp/v1',
			'/gateway/(?P<ability>[a-z-]+)',
			$route_args
		);
	}

	public function can_run_operation( \WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'sitepilot_connect' ) ) {
			return false;
		}
		return ! in_array( (string) $request['ability'], array( 'approve-change', 'cancel-change', 'revoke-approval' ), true ) || current_user_can( 'sitepilot_approve' );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function run_operation( \WP_REST_Request $request ) {
		$input = $request->get_json_params();
		$input = is_array( $input ) ? $input : array();
		return match ( (string) $request['ability'] ) {
			'inspect-site'        => $this->inspect_site( $input ),
			'search-capabilities' => $this->search_capabilities( $input ),
			'describe-operations' => $this->describe_operations( $input ),
			'inspect-navigation'  => $this->inspect_navigation( $input ),
			'inspect-design'      => $this->inspect_design( $input ),
			'plan-change'         => $this->plan_change( $input ),
			'execute-change'      => $this->execute_change( $input ),
			'get-change-status'   => $this->get_change_status( $input ),
			'rollback-change'     => $this->rollback_change( $input ),
			'manage-artifact'     => $this->manage_artifact( $input ),
			'approve-change'      => $this->changes->approve( (string) ( $input['change_set_id'] ?? '' ) ),
			'cancel-change'       => $this->changes->cancel( (string) ( $input['change_set_id'] ?? '' ) ),
			'revoke-approval'     => $this->changes->revoke_approval( (string) ( $input['change_set_id'] ?? '' ), (string) ( $input['approval_id'] ?? '' ) ),
			default               => new \WP_Error( 'sitepilot_not_found', __( 'Unknown SitePilot ability.', 'sitepilot-mcp' ), array( 'status' => 404 ) ),
		};
	}

	public function register_category(): void {
		wp_register_ability_category(
			'sitepilot',
			array(
				'label'       => __( 'SitePilot', 'sitepilot-mcp' ),
				'description' => __( 'Guarded site inspection and change-set operations.', 'sitepilot-mcp' ),
			)
		);
	}

	public function register_abilities(): void {
		$this->ability( 'sitepilot/inspect-site', __( 'Inspect site', 'sitepilot-mcp' ), __( 'Returns WordPress, theme, plugin, builder, WooCommerce, HPOS, and site-version information.', 'sitepilot-mcp' ), array( $this, 'inspect_site' ), $this->object_schema(), true );
		$this->ability(
			'sitepilot/search-capabilities',
			__( 'Search capabilities', 'sitepilot-mcp' ),
			__( 'Searches registered WordPress abilities and allowlisted REST API routes.', 'sitepilot-mcp' ),
			array( $this, 'search_capabilities' ),
			$this->object_schema(
				array(
					'query' => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
				)
			),
			true
		);
		$this->ability( 'sitepilot/inspect-navigation', __( 'Inspect navigation', 'sitepilot-mcp' ), __( 'Returns registered theme locations, assigned menus, and existing menu items.', 'sitepilot-mcp' ), array( $this, 'inspect_navigation' ), $this->object_schema(), true );
		$this->ability(
			'sitepilot/inspect-design',
			__( 'Inspect design', 'sitepilot-mcp' ),
			__( 'Returns Elementor or Enfold page structure and runtime builder capabilities, including registered elements, global styles, and page settings. Call this before planning builder-specific element edits so identities and accepted settings are known rather than guessed.', 'sitepilot-mcp' ),
			array( $this, 'inspect_design' ),
			$this->inspect_design_schema(),
			true
		);
		$this->ability(
			'sitepilot/describe-operations',
			__( 'Describe guarded operations', 'sitepilot-mcp' ),
			__( 'Lists every guarded write operation with its OAuth scope, risk tier, target meaning, and accepted input fields. Call this before planning an unfamiliar mutation.', 'sitepilot-mcp' ),
			array( $this, 'describe_operations' ),
			$this->object_schema(
				array(
					'operation' => array(
						'type'        => 'string',
						'enum'        => array_keys( ( new RiskEngine() )->policies() ),
						'description' => __( 'Optional exact operation name. Omit it to list every guarded operation.', 'sitepilot-mcp' ),
					),
				)
			),
			true
		);
		$this->ability( 'sitepilot/plan-change', __( 'Plan change', 'sitepilot-mcp' ), __( 'Creates an immutable dry-run change set. Supported actions include draft pages, guarded HTML-to-Enfold compilation, validated Enfold ALB staging, Media Library uploads, page fields, and menu creation, assignment, or editing. Call describe-operations for exact fields before planning.', 'sitepilot-mcp' ), array( $this, 'plan_change' ), $this->plan_schema(), false );
		$this->ability( 'sitepilot/execute-change', __( 'Execute change', 'sitepilot-mcp' ), __( 'Executes an approved change set with idempotency and optimistic version checks.', 'sitepilot-mcp' ), array( $this, 'execute_change' ), $this->mutation_reference_schema(), false );
		$this->ability(
			'sitepilot/get-change-status',
			__( 'Get change status', 'sitepilot-mcp' ),
			__( 'Returns progress, approval, validation, diff, and rollback availability for a change set.', 'sitepilot-mcp' ),
			array( $this, 'get_change_status' ),
			$this->object_schema(
				array(
					'change_set_id' => array(
						'type'   => 'string',
						'format' => 'uuid',
					),
				),
				array( 'change_set_id' )
			),
			true
		);
		$this->ability( 'sitepilot/rollback-change', __( 'Rollback change', 'sitepilot-mcp' ), __( 'Rolls back a completed change set through its recorded recovery operations.', 'sitepilot-mcp' ), array( $this, 'rollback_change' ), $this->mutation_reference_schema(), false );
		$this->ability( 'sitepilot/manage-artifact', __( 'Manage artifact', 'sitepilot-mcp' ), __( 'Starts, resumes, verifies, or inspects a staged design artifact upload.', 'sitepilot-mcp' ), array( $this, 'manage_artifact' ), $this->artifact_schema(), false );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function inspect_site( array $input = array() ) {
		unset( $input );
		$allowed = ( new ScopeGuard() )->require_scope( 'site:read' );
		return is_wp_error( $allowed ) ? $allowed : ( new SiteInspector() )->inspect();
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function search_capabilities( array $input = array() ) {

		$allowed = ( new ScopeGuard() )->require_scope( 'site:read' );
		return is_wp_error( $allowed ) ? $allowed : array( 'capabilities' => ( new SiteInspector() )->capabilities( sanitize_text_field( (string) ( $input['query'] ?? '' ) ) ) );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function describe_operations( array $input = array() ) {

		$allowed = ( new ScopeGuard() )->require_scope( 'site:read' );
		if ( is_wp_error( $allowed ) ) {
				return $allowed;
		}
		$catalog       = $this->operation_catalog();
			$operation = sanitize_text_field( (string) ( $input['operation'] ?? '' ) );
		if ( '' !== $operation ) {
			if ( ! isset( $catalog[ $operation ] ) ) {
				return new \WP_Error( 'sitepilot_operation_unknown', __( 'That guarded operation is not exposed by SitePilot.', 'sitepilot-mcp' ) );
			}
			return array( 'operations' => array( $operation => $catalog[ $operation ] ) );
		}
		return array( 'operations' => $catalog );
	}
	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function inspect_navigation( array $input = array() ) {
		unset( $input );
		$allowed = ( new ScopeGuard() )->require_scope( 'site:read' );
		return is_wp_error( $allowed ) ? $allowed : ( new SiteInspector() )->navigation();
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function inspect_design( array $input = array() ) {
		$allowed = ( new ScopeGuard() )->require_scope( 'site:read' );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$builder = sanitize_key( (string) ( $input['builder'] ?? 'elementor' ) );
		if ( ! in_array( $builder, array( 'elementor', 'enfold' ), true ) ) {
			return new \WP_Error(
				'sitepilot_builder_invalid',
				__( 'Design inspection supports the Elementor and Enfold builders.', 'sitepilot-mcp' ),
				array( 'supported' => array( 'elementor', 'enfold' ) )
			);
		}
		return ( new SiteInspector() )->design( $input );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function plan_change( array $input ) {
		return $this->changes->plan( $input );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function execute_change( array $input ) {
		return $this->changes->execute( $input );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function get_change_status( array $input ) {
		$allowed = ( new ScopeGuard() )->require_scope( 'site:read' );
		return is_wp_error( $allowed ) ? $allowed : $this->changes->status( (string) $input['change_set_id'] );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function rollback_change( array $input ) {
		return $this->changes->rollback_change( $input );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function manage_artifact( array $input ) {
		$allowed = ( new ScopeGuard() )->require_scope( 'design:write' );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( 'status' !== ( $input['command'] ?? '' ) ) {
			if ( empty( $input['idempotency_key'] ) || strlen( (string) $input['idempotency_key'] ) < 16 || ! hash_equals( ( new SiteVersion() )->current(), (string) ( $input['expected_version'] ?? '' ) ) ) {
				return new \WP_Error( 'sitepilot_invalid_envelope', __( 'Artifact mutations require idempotency_key and the current expected_version.', 'sitepilot-mcp' ) );
			}
		}
		return ( new ArtifactService() )->manage( $input );
	}

	/** @param callable $callback @param array<string,mixed> $input_schema */
	private function ability( string $name, string $label, string $description, callable $callback, array $input_schema, bool $is_readonly ): void {
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'sitepilot',
				'input_schema'        => $input_schema,
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'execute_callback'    => $callback,
				'permission_callback' => static fn (): bool => current_user_can( 'sitepilot_connect' ),
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'_meta'  => array(
							'sitepilot/plugin-version' => SITEPILOT_MCP_VERSION,
							'sitepilot/input-schema-sha256' => hash( 'sha256', (string) wp_json_encode( $input_schema ) ),
						),
					),
					'annotations'  => array(
						'readonly'    => $is_readonly,
						'destructive' => ! $is_readonly,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/** @param array<string,mixed> $properties @param list<string> $required @return array<string,mixed> */
	private function object_schema( array $properties = array(), array $required = array() ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function envelope_properties(): array {
		return array(
			'idempotency_key'  => array(
				'type'      => 'string',
				'minLength' => 16,
				'maxLength' => 128,
			),
			'expected_version' => array(
				'type'      => 'string',
				'minLength' => 16,
				'maxLength' => 128,
			),
		);
	}

	/** @return array<string,mixed> */
	private function plan_schema(): array {

		$operations = array_keys( ( new RiskEngine() )->policies() );
		return $this->object_schema(
			array_merge(
				$this->envelope_properties(),
				array(
					'intent'  => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 4000,
					),
					'actions' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 250,
						'items'    => $this->object_schema(
							array(
								'operation' => array(
									'type'        => 'string',
									'enum'        => $operations,
									'description' => __( 'Exact guarded operation from describe-operations.', 'sitepilot-mcp' ),
								),
								'target'    => array(
									'type'        => 'string',
									'minLength'   => 1,
									'maxLength'   => 512,
									'description' => __( 'Operation-specific target, such as a stable draft label, page ID, menu-item ID, or option name.', 'sitepilot-mcp' ),
								),
								'input'     => array(
									'type'                 => 'object',
									'additionalProperties' => true,
									'description'          => __( 'Operation-specific validated fields. Call describe-operations with the selected operation before constructing this object.', 'sitepilot-mcp' ),
									'x-sitepilot-operation-schemas' => array(
										'design.compile_enfold_html' => $this->enfold_compiler_input_schema(),
										'design.compile_elementor_html' => $this->elementor_compiler_input_schema(),
										'design.edit_elements' => $this->design_editor_input_schema(),
										'design.save_template' => $this->design_save_template_input_schema(),
										'design.apply_template' => $this->design_apply_template_input_schema(),
										'design.set_element_style' => $this->enfold_element_style_input_schema(),
										'design.update_global_kit' => $this->design_global_kit_input_schema(),
										'design.stage_theme_document' => $this->design_theme_document_input_schema(),
									),
								),
							),
							array( 'operation', 'target', 'input' )
						),
					),
				)
			),
			array( 'idempotency_key', 'expected_version', 'intent', 'actions' )
		);
	}

	/** @return array<string,array<string,mixed>> */
	private function operation_catalog(): array {

		$guidance = array(
			'content.create_draft'          => array(
				'target'       => 'Stable label for the new draft.',
				'input_fields' => array( 'post_type', 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_parent', 'parent_slug', 'menu_order', 'page_template', 'featured_media_id' ),
				'notes'        => 'Creates a draft only. parent_slug must resolve to an existing page unless the staged builder workflow explicitly permits a future parent.',
			),
			'content.bulk_update'           => array(
				'target'       => 'Existing numeric post or page ID.',
				'input_fields' => array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_parent', 'parent_slug', 'menu_order', 'page_template', 'featured_media_id' ),
				'notes'        => 'Updates an existing item without publishing a draft.',
			),
			'design.stage'                  => array(
				'target'         => 'Use 0 or a stable label for a new page; use a numeric page ID to update an existing draft.',
				'input_fields'   => array( 'builder', 'post_title', 'post_excerpt', 'post_name', 'alb_content', 'document', 'settings', 'post_content', 'post_parent', 'parent_slug', 'menu_order', 'page_template', 'featured_media_id' ),
				'notes'          => 'For Enfold use builder=enfold and validated editable alb_content. Enfold must have a verified calibration profile. For Elementor use builder=elementor and supply document: an array of elements, each { id, elType, settings, elements }, where elType is container, section, column or widget and widget elements also carry a non-empty widgetType. Optional settings holds document-level Elementor page settings. Call inspect-design first to learn which widget types and setting keys the site accepts. The result remains a draft and includes a preview URL.',
				'document_shape' => array(
					array(
						'id'       => '<8-char id>',
						'elType'   => 'container',
						'settings' => array( 'content_width' => 'boxed' ),
						'elements' => array(
							array(
								'id'         => '<8-char id>',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array(
									'title'       => 'Hello',
									'header_size' => 'h1',
								),
								'elements'   => array(),
							),
						),
					),
				),
			),
			'design.compile_elementor_html' => array(
				'target'              => 'Use 0 or a stable label for a new page; use a numeric page ID to update an existing draft.',
				'input_fields'        => array( 'source_html', 'source_css', 'media_mappings', 'link_mappings', 'component_mappings', 'post_title', 'post_excerpt', 'post_name', 'post_parent', 'parent_slug', 'menu_order', 'page_template', 'featured_media_id', 'allow_code_block_fallback' ),
				'input_schema'        => $this->elementor_compiler_input_schema(),
				'link_mapping_format' => 'sitepilot://page/<target-slug>',
				'notes'               => 'Compiles bounded HTML and CSS into separately editable native Elementor containers and widgets, rejects lost structural coverage, then stages a draft. Every image and CSS background requires a Media Library mapping. Internal HTML links must map to sitepilot://page/<target-slug>. No opaque HTML-widget fallback exists. Output targets classic Elementor containers, not 4.x atomic elements.',
			),
			'design.edit_elements'          => array(
				'target'       => 'Existing numeric page ID carrying an Elementor document or a calibrated Enfold ALB draft.',
				'input_fields' => array( 'builder', 'edits', 'visual_verification', 'visual_similarity_threshold' ),
				'input_schema' => $this->design_editor_input_schema(),
				'notes'        => 'Applies an ordered, atomic edit list without rewriting the document wholesale. Set builder=enfold for ALB pages; omitted builder remains Elementor-compatible. Elementor uses element ids. Enfold uses normalized av_uid as primary identity and current-snapshot path as fallback. Enfold runtime-only elements cannot be inserted and accept only common attribute updates. Dry run returns an explicit outline diff and draft preview URL. visual_verification is an optional Cloud gateway before/after comparison and never blocks the write or later publication.',
			),
			'design.save_template'          => array(
				'target'       => 'Stable label for the new template.',
				'input_fields' => array( 'builder', 'source_post_id', 'title', 'template_type', 'uid', 'path' ),
				'input_schema' => $this->design_save_template_input_schema(),
				'notes'        => 'For Elementor, copies an existing page tree into elementor_library. For Enfold, set builder=enfold and optionally select one subtree by av_uid or current-snapshot path; the serialized ALB subtree is stored in the private sitepilot_template post type. Saving never modifies the source page.',
			),
			'design.apply_template'         => array(
				'target'       => 'Existing numeric page ID to receive the template.',
				'input_fields' => array( 'builder', 'template_id', 'mode', 'parent_uid', 'parent_path', 'position' ),
				'input_schema' => $this->design_apply_template_input_schema(),
				'notes'        => 'Merges the selected builder template into a draft page. Enfold application is draft-page-only and can append at the document root or below a valid parent. Elementor ids and Enfold av_uid values are regenerated on every application so one template can be reused without collisions.',
			),
			'design.set_element_style'      => array(
				'target'       => 'Existing numeric calibrated Enfold ALB draft page ID.',
				'input_fields' => array( 'builder', 'custom_class', 'styles', 'replace' ),
				'input_schema' => $this->enfold_element_style_input_schema(),
				'notes'        => 'Tier 1. Stores protected page-scoped CSS for the one Enfold element carrying the unique custom_class. Values cannot contain URLs, at-rules, selector boundaries, or executable protocols. A null style value removes the protected property; an empty string is invalid. Published and non-page targets are intentionally unsupported.',
			),
			'design.update_global_kit'      => array(
				'target'       => 'Elementor kit post ID, or 0 for the active builder globals. Enfold also accepts its inspect-design option_name.',
				'input_fields' => array( 'builder', 'settings' ),
				'input_schema' => $this->design_global_kit_input_schema(),
				'notes'        => 'Tier 2. Changes site-wide colours and typography and requires fresh approval. Enfold accepts only existing non-sensitive colour/typography keys returned by inspect-design and snapshots the complete previous avia_options record for rollback. Omitted builder remains Elementor-compatible.',
			),
			'design.stage_theme_document'   => array(
				'target'       => 'Existing elementor_library document ID, or 0 to create one.',
				'input_fields' => array( 'builder', 'document_type', 'title', 'document' ),
				'input_schema' => $this->design_theme_document_input_schema(),
				'notes'        => 'Tier 2. Stages an Elementor theme-builder document. Enfold header/footer layout is theme-options driven rather than document-backed, so builder=enfold returns sitepilot_unsupported_for_builder with an explanation instead of fabricating a document model.',
			),
			'design.compile_enfold_html'    => array(
				'target'              => 'Use 0 or a stable label for a new page; use a numeric page ID to update an existing draft.',
				'input_fields'        => array( 'source_html', 'source_css', 'media_mappings', 'link_mappings', 'component_mappings', 'post_title', 'post_excerpt', 'post_name', 'post_parent', 'parent_slug', 'menu_order', 'page_template', 'featured_media_id', 'allow_code_block_fallback' ),
				'input_schema'        => $this->enfold_compiler_input_schema(),
				'link_mapping_format' => 'sitepilot://page/<target-slug>',
				'examples'            => array(
					'media_mappings'     => array( 'assets/trip.jpg' => 811 ),
					'link_mappings'      => array( 'trip.html' => 'sitepilot://page/trip' ),
					'component_mappings' => array(
						'.trip-list' => 'cards',
						'.trip-card' => 'card',
					),
				),
				'notes'               => 'Best-effort first draft from clean, static HTML. Returns compiled elements plus a manifest of unmapped regions. Not intended for JavaScript-driven pages: use inspect-design and design.edit_elements to build or adjust those directly. Every image/background requires a Media Library mapping. Internal HTML links must map to sitepilot://page/<target-slug>. Code Block fallback is disabled.',
			),
			'design.calibrate_enfold'       => array(
				'target'       => 'enfold-profile',
				'input_fields' => array( 'alb_page_id', 'normal_page_id' ),
				'notes'        => 'Administrator-only Tier-3 calibration using one manually saved ALB page and one normal comparison page.',
			),
			'design.resolve_links'          => array(
				'target'       => 'Slug of a staged Enfold page.',
				'input_fields' => array( 'page_slugs' ),
				'notes'        => 'Resolves allowlisted sitepilot://page/slug references after all linked drafts exist.',
			),
			'media.stage'                   => array(
				'target'       => 'Filename or stable media label.',
				'input_fields' => array( 'filename', 'mime_type', 'content_base64', 'title', 'alt', 'caption', 'description' ),
				'notes'        => 'Imports a validated base64 JPEG, PNG, WebP, or PDF payload up to 25 MB into the Media Library.',
			),
			'site.create_menu'              => array(
				'target'       => 'Stable menu label.',
				'input_fields' => array( 'name' ),
				'notes'        => 'Creates the menu only when it does not already exist.',
			),
			'site.update_menu'              => array(
				'target'       => 'Existing menu-item ID, or 0 to create a menu item.',
				'input_fields' => array( 'menu_id', 'menu_name', 'menu_slug', 'location', 'title', 'url', 'object_id', 'object_slug', 'parent_id', 'parent_object_slug', 'position', 'type', 'object' ),
				'notes'        => 'Creates or updates an item only inside an existing selected menu.',
			),
			'site.assign_menu'              => array(
				'target'       => 'Registered theme menu location.',
				'input_fields' => array( 'location', 'menu_id', 'menu_name' ),
				'notes'        => 'Assigns an existing menu to an existing registered theme location.',
			),
		);
		$catalog  = array();
		foreach ( ( new RiskEngine() )->policies() as $operation => $policy ) {
			$catalog[ $operation ] = array_merge(
				array(
					'risk_tier' => $policy['tier'],
					'scope'     => $policy['scope'],
				),
				$guidance[ $operation ] ?? array(
					'target'       => 'Operation-specific target. Use the exact ID or allowlisted setting requested by the adapter.',
					'input_fields' => array(),
					'notes'        => 'Inspect the site and plan a dry run before execution.',
				)
			);
		}
		return $catalog;
	}

	/** @return array<string,mixed> */
	private function inspect_design_schema(): array {
		return $this->object_schema(
			array(
				'builder'     => array(
					'type'        => 'string',
					'enum'        => array( 'elementor', 'enfold' ),
					'default'     => 'elementor',
					'description' => __( 'Page builder to inspect.', 'sitepilot-mcp' ),
				),
				'target'      => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Page ID whose element tree and page settings should be returned. Omit to inspect only site-wide builder capabilities.', 'sitepilot-mcp' ),
				),
				'widget_type' => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => __( 'For Elementor, return the full control list for this widget type and filter the widget catalog to matching names.', 'sitepilot-mcp' ),
				),
				'include'     => array(
					'type'        => 'array',
					'maxItems'    => 5,
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'tree', 'widgets', 'elements', 'globals', 'page_settings' ),
					),
					'description' => __( 'Sections to return. Defaults to every section that applies to the request.', 'sitepilot-mcp' ),
				),
			)
		);
	}

	/**
	 * Elementor element-tree schema, shared by staging and theme-document operations.
	 *
	 * @return array<string,mixed>
	 */
	private function elementor_document_schema(): array {
		return array(
			'type'        => 'array',
			'minItems'    => 1,
			'maxItems'    => 500,
			'description' => __( 'Elementor element tree. Each element is { id, elType, settings, elements }; widgets also carry widgetType.', 'sitepilot-mcp' ),
			'items'       => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'required'             => array( 'id', 'elType', 'settings', 'elements' ),
				'properties'           => array(
					'id'         => array(
						'type'    => 'string',
						'pattern' => '^[A-Za-z0-9_-]{1,64}$',
					),
					'elType'     => array(
						'type' => 'string',
						'enum' => ElementorElementEditor::ELEMENT_TYPES,
					),
					'widgetType' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 100,
					),
					'settings'   => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
					'elements'   => array( 'type' => 'array' ),
				),
			),
		);
	}

	/** @return array<string,mixed> */
	private function design_editor_input_schema(): array {
		return $this->object_schema(
			array(
				'builder'                     => array(
					'type'        => 'string',
					'enum'        => array( 'elementor', 'enfold' ),
					'default'     => 'elementor',
					'description' => __( 'Builder that owns the target document. Omit only for backwards-compatible Elementor editing.', 'sitepilot-mcp' ),
				),
				'edits'                       => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => max( ElementorElementEditor::MAX_EDITS, EnfoldElementEditor::MAX_EDITS ),
					'description' => __( 'Ordered element edits. Applied atomically: any failure leaves the page untouched.', 'sitepilot-mcp' ),
					'items'       => $this->object_schema(
						array(
							'op'          => array(
								'type'        => 'string',
								'enum'        => array_values( array_unique( array_merge( ElementorElementEditor::EDIT_OPERATIONS, EnfoldElementEditor::EDIT_OPERATIONS ) ) ),
								'description' => __( 'Elementor supports set_settings, set_label, insert, move, remove, and duplicate. Enfold supports set_attributes, set_content, insert, move, remove, and duplicate.', 'sitepilot-mcp' ),
							),
							'id'          => array(
								'type'        => 'string',
								'pattern'     => '^[A-Za-z0-9_-]{1,64}$',
								'description' => __( 'Target element id from inspect-design. Required for every operation except insert.', 'sitepilot-mcp' ),
							),
							'parent_id'   => array(
								'type'        => 'string',
								'pattern'     => '^[A-Za-z0-9_-]{1,64}$',
								'description' => __( 'Destination parent for insert and move. Omit to place the element at the document root.', 'sitepilot-mcp' ),
							),
							'uid'         => array(
								'type'        => 'string',
								'maxLength'   => 200,
								'description' => __( 'Enfold target av_uid from inspect-design. Used before path when non-empty.', 'sitepilot-mcp' ),
							),
							'path'        => array(
								'type'        => 'string',
								'pattern'     => '^(?:0|[1-9][0-9]*)(?:/(?:0|[1-9][0-9]*))*$',
								'description' => __( 'Enfold target path from the current inspect-design snapshot, used when uid is blank or unavailable.', 'sitepilot-mcp' ),
							),
							'parent_uid'  => array(
								'type'        => 'string',
								'maxLength'   => 200,
								'description' => __( 'Enfold destination parent av_uid. Omit with parent_path to target the document root.', 'sitepilot-mcp' ),
							),
							'parent_path' => array(
								'type'        => 'string',
								'pattern'     => '^(?:0|[1-9][0-9]*)(?:/(?:0|[1-9][0-9]*))*$',
								'description' => __( 'Enfold destination parent path in the current document snapshot.', 'sitepilot-mcp' ),
							),
							'position'    => array(
								'type'        => 'integer',
								'minimum'     => 0,
								'description' => __( 'Zero-based index among the destination siblings. Omit to append.', 'sitepilot-mcp' ),
							),
							'settings'    => array(
								'type'                 => 'object',
								'additionalProperties' => true,
								'description'          => __( 'Settings for set_settings. Validated against the widget controls reported by inspect-design.', 'sitepilot-mcp' ),
							),
							'attrs'       => array(
								'type'                 => 'object',
								'additionalProperties' => true,
								'description'          => __( 'Enfold attributes for set_attributes. Null removes a key; all keys are checked against the active element definition.', 'sitepilot-mcp' ),
							),
							'content'     => array(
								'type'        => 'string',
								'maxLength'   => 250000,
								'description' => __( 'Direct content for Enfold av_textblock, av_heading, or av_toggle. Child-bearing elements are rejected.', 'sitepilot-mcp' ),
							),
							'replace'     => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'When true, replaces Elementor settings or curated Enfold attributes. Enfold preserves av_uid unless explicitly changed; runtime-only attributes remain merge-only.', 'sitepilot-mcp' ),
							),
							'label'       => array(
								'type'      => 'string',
								'maxLength' => 200,
							),
							'element'     => array(
								'type'                 => 'object',
								'additionalProperties' => true,
								'description'          => __( 'Element to add for insert. Elementor ids and Enfold av_uid values are regenerated so they cannot collide.', 'sitepilot-mcp' ),
							),
							'seed'        => array(
								'type'        => 'string',
								'maxLength'   => 200,
								'description' => __( 'Optional deterministic seed for generated element ids.', 'sitepilot-mcp' ),
							),
						),
						array( 'op' )
					),
				),
				'visual_verification'         => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Ask the optional Cloud gateway to capture the draft before execution and compare it with the rendered result afterward. This evidence never blocks the write or publication.', 'sitepilot-mcp' ),
				),
				'visual_similarity_threshold' => array(
					'type'        => 'number',
					'minimum'     => 0.5,
					'maximum'     => 1,
					'default'     => 0.85,
					'description' => __( 'Optional before/after similarity threshold used only when visual_verification is true.', 'sitepilot-mcp' ),
				),
			),
			array( 'edits' )
		);
	}

	/** @return array<string,mixed> */
	private function design_save_template_input_schema(): array {
		return $this->object_schema(
			array(
				'builder'        => array(
					'type'        => 'string',
					'enum'        => array( 'elementor', 'enfold' ),
					'default'     => 'elementor',
					'description' => __( 'Set enfold to save an ALB document or selected subtree.', 'sitepilot-mcp' ),
				),
				'source_post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'title'          => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'template_type'  => array(
					'type'        => 'string',
					'enum'        => array( 'page', 'section', 'container' ),
					'description' => __( 'Elementor template type. Enfold stores the actual selected subtree shape.', 'sitepilot-mcp' ),
				),
				'uid'            => array(
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => __( 'Optional Enfold av_uid selecting one subtree.', 'sitepilot-mcp' ),
				),
				'path'           => array(
					'type'        => 'string',
					'pattern'     => '^(?:0|[1-9][0-9]*)(?:/(?:0|[1-9][0-9]*))*$',
					'description' => __( 'Optional Enfold current-snapshot path fallback selecting one subtree.', 'sitepilot-mcp' ),
				),
			),
			array( 'source_post_id' )
		);
	}

	/** @return array<string,mixed> */
	private function design_apply_template_input_schema(): array {
		return $this->object_schema(
			array(
				'builder'     => array(
					'type'    => 'string',
					'enum'    => array( 'elementor', 'enfold' ),
					'default' => 'elementor',
				),
				'template_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'mode'        => array(
					'type'    => 'string',
					'enum'    => array( 'append', 'replace' ),
					'default' => 'append',
				),
				'parent_uid'  => array(
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => __( 'Optional Enfold destination parent av_uid for append mode.', 'sitepilot-mcp' ),
				),
				'parent_path' => array(
					'type'        => 'string',
					'pattern'     => '^(?:0|[1-9][0-9]*)(?:/(?:0|[1-9][0-9]*))*$',
					'description' => __( 'Optional Enfold destination parent path fallback.', 'sitepilot-mcp' ),
				),
				'position'    => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			array( 'template_id' )
		);
	}

	/** @return array<string,mixed> */
	private function enfold_element_style_input_schema(): array {
		return $this->object_schema(
			array(
				'builder'      => array(
					'type'        => 'string',
					'enum'        => array( 'enfold' ),
					'default'     => 'enfold',
					'description' => __( 'Protected element styles are currently available for Enfold ALB drafts.', 'sitepilot-mcp' ),
				),
				'custom_class' => array(
					'type'        => 'string',
					'pattern'     => '^[a-z][a-z0-9_-]{2,80}$',
					'description' => __( 'One unique custom_class token already present on the target Enfold element.', 'sitepilot-mcp' ),
				),
				'styles'       => array(
					'type'                 => 'object',
					'maxProperties'        => 64,
					'additionalProperties' => array(
						'oneOf' => array(
							array( 'type' => 'string' ),
							array( 'type' => 'number' ),
							array( 'type' => 'boolean' ),
							array( 'type' => 'null' ),
						),
					),
					'description'          => __( 'Allowlisted CSS property/value pairs. Null removes a property; URLs and selector boundaries are rejected.', 'sitepilot-mcp' ),
				),
				'replace'      => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			array( 'custom_class', 'styles' )
		);
	}

	/** @return array<string,mixed> */
	private function design_global_kit_input_schema(): array {
		$elementor                          = $this->elementor_kit_input_schema();
		$elementor['properties']['builder'] = array(
			'type'    => 'string',
			'enum'    => array( 'elementor' ),
			'default' => 'elementor',
		);
		$enfold                             = $this->object_schema(
			array(
				'builder'  => array(
					'type' => 'string',
					'enum' => array( 'enfold' ),
				),
				'settings' => array(
					'type'                 => 'object',
					'minProperties'        => 1,
					'maxProperties'        => 100,
					'additionalProperties' => array(
						'oneOf' => array(
							array( 'type' => 'string' ),
							array( 'type' => 'number' ),
							array( 'type' => 'boolean' ),
						),
					),
					'description'          => __( 'Exact existing Enfold colour or typography keys returned by inspect-design.globals.', 'sitepilot-mcp' ),
				),
			),
			array( 'builder', 'settings' )
		);
		return array( 'oneOf' => array( $elementor, $enfold ) );
	}

	/** @return array<string,mixed> */
	private function design_theme_document_input_schema(): array {
		$elementor                          = $this->elementor_theme_document_input_schema();
		$elementor['properties']['builder'] = array(
			'type'    => 'string',
			'enum'    => array( 'elementor' ),
			'default' => 'elementor',
		);
		$enfold                             = $this->object_schema(
			array(
				'builder'       => array(
					'type' => 'string',
					'enum' => array( 'enfold' ),
				),
				'document_type' => array(
					'type'        => 'string',
					'enum'        => array( 'header', 'footer' ),
					'description' => __( 'Accepted only so the server can return the explicit Enfold unsupported-for-builder explanation.', 'sitepilot-mcp' ),
				),
			),
			array( 'builder' )
		);
		return array( 'oneOf' => array( $elementor, $enfold ) );
	}

	/** @return array<string,mixed> */
	private function elementor_kit_input_schema(): array {
		$typography = array(
			'type'     => 'array',
			'maxItems' => 100,
			'items'    => array(
				'type'                 => 'object',
				'additionalProperties' => true,
			),
		);
		return $this->object_schema(
			array(
				'settings' => $this->object_schema(
					array(
						'system_colors'     => array(
							'type'        => 'array',
							'maxItems'    => 100,
							'description' => __( 'Elementor system colours, each { _id, title, color }.', 'sitepilot-mcp' ),
							'items'       => array(
								'type'                 => 'object',
								'additionalProperties' => true,
							),
						),
						'custom_colors'     => array(
							'type'     => 'array',
							'maxItems' => 100,
							'items'    => array(
								'type'                 => 'object',
								'additionalProperties' => true,
							),
						),
						'system_typography' => $typography,
						'custom_typography' => $typography,
					)
				),
			),
			array( 'settings' )
		);
	}

	/** @return array<string,mixed> */
	private function elementor_theme_document_input_schema(): array {
		return $this->object_schema(
			array(
				'document_type' => array(
					'type'        => 'string',
					'enum'        => ElementorAdapter::THEME_DOCUMENT_TYPES,
					'description' => __( 'Elementor theme-builder document type.', 'sitepilot-mcp' ),
				),
				'title'         => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'document'      => $this->elementor_document_schema(),
			),
			array( 'document_type', 'document' )
		);
	}

	/** @return array<string,mixed> */
	private function elementor_compiler_input_schema(): array {
		$enfold = $this->enfold_compiler_input_schema();
		// The Elementor compiler accepts the same bounded source contract as the
		// Enfold compiler; only the emitted representation differs.
		unset( $enfold['properties']['component_mappings']['additionalProperties']['oneOf'][1]['properties']['structure_mode'] );
		$enfold['properties']['allow_code_block_fallback']['description'] = __( 'Must remain false. The Elementor compiler never emits a single opaque HTML widget.', 'sitepilot-mcp' );
		return $enfold;
	}

	/** @return array<string,mixed> */
	private function enfold_compiler_input_schema(): array {
		return $this->object_schema(
			array(
				'source_html'               => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 2000000,
				),
				'source_css'                => array(
					'type'      => 'string',
					'maxLength' => 500000,
				),
				'media_mappings'            => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'oneOf' => array(
							array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							$this->object_schema(
								array(
									'attachment_id' => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
									'alt'           => array(
										'type'      => 'string',
										'maxLength' => 1000,
									),
								),
								array( 'attachment_id' )
							),
						),
					),
				),
				'link_mappings'             => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'    => 'string',
						'pattern' => '^sitepilot://page/[a-z0-9-]+$',
					),
				),
				'component_mappings'        => array(
					'type'                 => 'object',
					'description'          => 'Keys are simple tag, .class, or #id selectors. Values may be a legacy type string or deterministic responsive mapping object.',
					'additionalProperties' => array(
						'oneOf' => array(
							array(
								'type' => 'string',
								'enum' => array( 'grid', 'flex', 'split', 'cards', 'card', 'hero', 'gallery', 'accordion', 'tabs', 'form' ),
							),
							$this->object_schema(
								array(
									'type'             => array(
										'type' => 'string',
										'enum' => array( 'grid', 'flex', 'split', 'cards', 'card', 'hero', 'gallery', 'accordion', 'tabs', 'form' ),
									),
									'desktop_columns'  => array(
										'type'    => 'integer',
										'minimum' => 1,
										'maximum' => 4,
									),
									'tablet_columns'   => array(
										'type'    => 'integer',
										'minimum' => 1,
										'maximum' => 4,
									),
									'mobile_columns'   => array(
										'type'    => 'integer',
										'minimum' => 1,
										'maximum' => 2,
									),
									'card_layout'      => array(
										'type' => 'string',
										'enum' => array( 'stacked', 'split' ),
									),
									'structure_mode'   => array(
										'type'        => 'string',
										'enum'        => array( 'native', 'preserve' ),
										'description' => 'Use preserve when nested semantic wrappers and their scoped CSS cannot be represented by native Enfold columns without flattening.',
									),
									'image_ratio'      => array(
										'type'    => 'number',
										'minimum' => 0.1,
										'maximum' => 0.9,
									),
									'target_attribute' => array(
										'type'        => 'string',
										'pattern'     => '^(?:aria-controls|data-[a-z0-9_-]+|href)$',
										'description' => 'For mapped tabs, names the request-specific control attribute whose value identifies a panel id.',
									),
								),
								array( 'type' )
							),
						),
					),
				),
				'allow_code_block_fallback' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Must remain false. The native compiler never silently emits a giant Code Block.',
				),
				'post_title'                => array(
					'type'      => 'string',
					'maxLength' => 4000,
				),
				'post_excerpt'              => array(
					'type'      => 'string',
					'maxLength' => 50000,
				),
				'post_name'                 => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'post_parent'               => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'parent_slug'               => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'menu_order'                => array(
					'type'    => 'integer',
					'minimum' => -10000,
					'maximum' => 10000,
				),
				'page_template'             => array(
					'type'      => 'string',
					'maxLength' => 255,
				),
				'featured_media_id'         => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			array( 'source_html' )
		);
	}
	/** @return array<string,mixed> */
	private function mutation_reference_schema(): array {
		return $this->object_schema(
			array_merge(
				$this->envelope_properties(),
				array(
					'change_set_id' => array(
						'type'   => 'string',
						'format' => 'uuid',
					),
					'approval_id'   => array(
						'type'   => 'string',
						'format' => 'uuid',
					),
				)
			),
			array( 'idempotency_key', 'expected_version', 'change_set_id' )
		);
	}

	/** @return array<string,mixed> */
	private function artifact_schema(): array {
		return $this->object_schema(
			array_merge(
				$this->envelope_properties(),
				array(
					'command'     => array(
						'type' => 'string',
						'enum' => array( 'start', 'append', 'complete', 'stage_theme', 'status' ),
					),
					'artifact_id' => array(
						'type'   => 'string',
						'format' => 'uuid',
					),
					'filename'    => array(
						'type'      => 'string',
						'maxLength' => 255,
					),
					'mime_type'   => array( 'type' => 'string' ),
					'offset'      => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'chunk'       => array( 'type' => 'string' ),
					'sha256'      => array(
						'type'    => 'string',
						'pattern' => '^[a-fA-F0-9]{64}$',
					),
				)
			),
			array( 'command' )
		);
	}
}
