<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Credentials;

final class CredentialController {
	private CredentialGrantRepository $grants;

	public function __construct() {
		$this->grants = new CredentialGrantRepository();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'sitepilot-mcp/v2',
			'/credentials/probe',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'probe' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'sitepilot-mcp/v2',
			'/credentials/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => static fn (): bool => current_user_can( 'sitepilot_connect' ),
			)
		);
		register_rest_route(
			'sitepilot-mcp/v2',
			'/credentials/claim',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'claim' ),
				'permission_callback' => array( $this, 'can_claim' ),
				'args'                => array(
					'scopes' => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
					),
					'label'  => array(
						'type'      => 'string',
						'maxLength' => 191,
					),
				),
			)
		);
	}

	/** Report only whether the web server delivered an Authorization header. */
	public function probe(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'authorization_header_seen' => isset( $_SERVER['HTTP_AUTHORIZATION'] ) && '' !== trim( wp_unslash( (string) $_SERVER['HTTP_AUTHORIZATION'] ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- presence check only.
			),
			200
		);
	}

	/** Return the effective bounded credential state without exposing its UUID or secret. */
	public function status(): \WP_REST_Response {
		$context = CredentialContext::current();
		if ( ! is_array( $context ) ) {
			$oauth = $GLOBALS['sitepilot_oauth_context'] ?? null;
			if ( is_array( $oauth ) ) {
				$context = array(
					'credential_type' => 'oauth',
					'state'           => 'active',
					'label'           => '',
					'scopes'          => is_array( $oauth['scopes'] ?? null ) ? array_values( $oauth['scopes'] ) : array(),
				);
			}
		}
		return new \WP_REST_Response(
			array(
				'credential_type' => is_array( $context ) ? (string) ( $context['credential_type'] ?? 'unknown' ) : 'cookie',
				'state'           => is_array( $context ) ? (string) ( $context['state'] ?? 'active' ) : 'interactive',
				'label'           => is_array( $context ) ? (string) ( $context['label'] ?? '' ) : '',
				'scopes'          => is_array( $context ) && is_array( $context['scopes'] ?? null ) ? array_values( $context['scopes'] ) : array(),
			),
			200
		);
	}

	public function can_claim(): bool|\WP_Error {
		$context = CredentialContext::current();
		if ( ! is_array( $context ) || 'app_password' !== ( $context['credential_type'] ?? '' ) ) {
			return new \WP_Error( 'sitepilot_application_password_required', __( 'Authenticate with the Application Password being claimed.', 'sitepilot-mcp' ), array( 'status' => 401 ) );
		}
		if ( (int) ( $context['user_id'] ?? 0 ) !== get_current_user_id() || ! current_user_can( 'sitepilot_connect' ) ) {
			return new \WP_Error( 'sitepilot_credential_forbidden', __( 'This account cannot claim SitePilot credential scopes.', 'sitepilot-mcp' ), array( 'status' => 403 ) );
		}
		if ( in_array( (string) ( $context['state'] ?? '' ), array( 'invalid', 'revoked', 'storage_error' ), true ) ) {
			return new \WP_Error( 'sitepilot_credential_forbidden', __( 'This credential cannot claim scopes.', 'sitepilot-mcp' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function claim( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$context = CredentialContext::current();
		$scopes  = $request->get_param( 'scopes' );
		if ( ! is_array( $context ) || ! is_array( $scopes ) ) {
			return new \WP_Error( 'sitepilot_credential_invalid', __( 'A credential context and scope list are required.', 'sitepilot-mcp' ), array( 'status' => 400 ) );
		}
		$requested_label = (string) $request->get_param( 'label' );
		$result          = $this->grants->claim_application_password(
			(string) ( $context['credential_uuid'] ?? '' ),
			(int) ( $context['user_id'] ?? 0 ),
			$scopes,
			'' !== $requested_label ? $requested_label : (string) ( $context['label'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		CredentialContext::set(
			array_merge(
				$context,
				array(
					'label'  => $result['label'],
					'scopes' => $result['scopes'],
					'state'  => 'active',
				)
			)
		);
		return new \WP_REST_Response( $result, $result['created'] ? 201 : 200 );
	}
}
