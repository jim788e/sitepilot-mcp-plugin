<?php

declare(strict_types=1);

namespace SitePilot\Mcp\OAuth;

use SitePilot\Mcp\Infrastructure\AuditLog;
use SitePilot\Mcp\Infrastructure\Ids;

final class AuthorizationServer {
	private const LATEST_MCP_RESOURCE_VERSION = 'v2';
	private const MCP_RESOURCE_VERSIONS       = array( 'v1', 'v2' );

	// Dynamic Client Registration is public by design (RFC 7591), so it is rate limited and bounded.
	private const REGISTRATION_LIMIT_PER_HOUR    = 30;
	private const REGISTRATION_MAX_REDIRECT_URIS = 5;
	private const REGISTRATION_MAX_URI_LENGTH    = 512;
	private const REGISTRATION_MAX_NAME_LENGTH   = 100;
	private const REGISTRATION_LOCK_WAIT         = 2;

	private TokenRepository $tokens;

	public function __construct() {
		$this->tokens = new TokenRepository();
	}

	public function register(): void {
		add_action( 'parse_request', array( $this, 'serve_well_known_metadata' ), 0 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'determine_current_user', array( $this, 'authenticate_bearer' ), 20 );
		add_action( 'admin_post_sitepilot_oauth_authorize', array( $this, 'authorize' ) );
		add_action( 'admin_post_nopriv_sitepilot_oauth_authorize', array( $this, 'authorize' ) );
	}

	/** Serve RFC 8414/9728 well-known documents outside the REST namespace. */
	public function serve_well_known_metadata(): void {
		// The path is only compared with the fixed well-known routes and is never stored or output, so it is unslashed but not sanitized.
		$path     = (string) wp_parse_url( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with fixed routes only.
		$response = $this->well_known_metadata_for_path( rawurldecode( $path ) );
		if ( ! $response ) {
			return;
		}
		wp_send_json( $response->get_data(), $response->get_status() );
	}

	public function well_known_metadata_for_path( string $path ): ?\WP_REST_Response {
		$issuer_path = (string) wp_parse_url( rest_url( 'sitepilot-mcp/v1' ), PHP_URL_PATH );
		if ( '/.well-known/oauth-protected-resource' === $path ) {
			return $this->protected_resource_metadata_for_version( self::LATEST_MCP_RESOURCE_VERSION );
		}
		foreach ( self::MCP_RESOURCE_VERSIONS as $version ) {
			$resource_path = self::mcp_resource_path( $version );
			if ( '/.well-known/oauth-protected-resource' . $resource_path === $path ) {
				return $this->protected_resource_metadata_for_version( $version );
			}
			if ( '/.well-known/oauth-authorization-server' . $resource_path === $path ) {
				return $this->authorization_server_metadata();
			}
		}
		if ( in_array( $path, array( '/.well-known/oauth-authorization-server', '/.well-known/oauth-authorization-server' . $issuer_path ), true ) ) {
			return $this->authorization_server_metadata();
		}
		return null;
	}

	/** Return the RFC 9728 metadata URL for the direct MCP resource. */
	public static function protected_resource_metadata_url( string $version = self::LATEST_MCP_RESOURCE_VERSION ): string {
		return home_url( '/.well-known/oauth-protected-resource' . self::mcp_resource_path( $version ) );
	}

	public function register_routes(): void {
		$public = static fn (): bool => true;
		register_rest_route(
			'sitepilot-mcp/v1',
			'/.well-known/oauth-protected-resource',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'protected_resource_metadata' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			'sitepilot-mcp/v2',
			'/.well-known/oauth-protected-resource',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'protected_resource_metadata' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			'sitepilot-mcp/v1',
			'/.well-known/oauth-authorization-server',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'authorization_server_metadata' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			'sitepilot-mcp/v1',
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'register_client' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			'sitepilot-mcp/v1',
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'token' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			'sitepilot-mcp/v1',
			'/oauth/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'revoke' ),
				'permission_callback' => $public,
			)
		);
	}

	public function protected_resource_metadata( ?\WP_REST_Request $request = null ): \WP_REST_Response {
		$version = $request instanceof \WP_REST_Request && str_contains( $request->get_route(), '/v1/' ) ? 'v1' : self::LATEST_MCP_RESOURCE_VERSION;
		return $this->protected_resource_metadata_for_version( $version );
	}

	private function protected_resource_metadata_for_version( string $version ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'resource'                 => self::mcp_resource_url( $version ),
				'authorization_servers'    => array( rest_url( 'sitepilot-mcp/v1' ) ),
				'scopes_supported'         => Scopes::ALL,
				'bearer_methods_supported' => array( 'header' ),
			),
			200
		);
	}

	public function authorization_server_metadata(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'issuer'                                => rest_url( 'sitepilot-mcp/v1' ),
				'authorization_endpoint'                => admin_url( 'admin-post.php?action=sitepilot_oauth_authorize' ),
				'token_endpoint'                        => rest_url( 'sitepilot-mcp/v1/oauth/token' ),
				'registration_endpoint'                 => rest_url( 'sitepilot-mcp/v1/oauth/register' ),
				'revocation_endpoint'                   => rest_url( 'sitepilot-mcp/v1/oauth/revoke' ),
				'response_types_supported'              => array( 'code' ),
				'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
				'code_challenge_methods_supported'      => array( 'S256' ),
				'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post' ),
				'client_id_metadata_document_supported' => true,
				'scopes_supported'                      => Scopes::ALL,
			),
			200
		);
	}

	public function register_client( \WP_REST_Request $request ): \WP_REST_Response {
		$redirects = $request->get_param( 'redirect_uris' );
		if ( ! is_array( $redirects ) || array() === $redirects ) {
			return $this->oauth_error( 'invalid_client_metadata', 'redirect_uris is required.' );
		}
		// Registration is public, so every stored field is bounded.
		if ( count( $redirects ) > self::REGISTRATION_MAX_REDIRECT_URIS ) {
			return $this->oauth_error( 'invalid_client_metadata', 'At most ' . self::REGISTRATION_MAX_REDIRECT_URIS . ' redirect URIs can be registered.' );
		}
		foreach ( $redirects as $uri ) {
			if ( ! is_string( $uri ) || strlen( $uri ) > self::REGISTRATION_MAX_URI_LENGTH ) {
				return $this->oauth_error( 'invalid_redirect_uri', 'Each redirect URI must be a string of at most ' . self::REGISTRATION_MAX_URI_LENGTH . ' characters.' );
			}
		}
		$redirects = array_values( array_unique( array_map( 'esc_url_raw', $redirects ) ) );
		foreach ( $redirects as $uri ) {
			if ( ! $this->valid_redirect_uri( $uri ) ) {
				return $this->oauth_error( 'invalid_redirect_uri', 'Redirect URIs must be exact HTTPS or loopback URLs without fragments.' );
			}
		}
		$method = $request->get_param( 'token_endpoint_auth_method' );
		$method = ( null === $method || '' === $method ) ? 'none' : $method;
		if ( ! is_string( $method ) || ! in_array( $method, array( 'none', 'client_secret_post' ), true ) ) {
			return $this->oauth_error( 'invalid_client_metadata', 'Unsupported token endpoint authentication method.' );
		}
		$scope_value = $request->get_param( 'scope' );
		if ( null === $scope_value ) {
			$scopes = array( 'site:read' );
		} elseif ( ! is_string( $scope_value ) || '' === trim( $scope_value ) ) {
			return $this->oauth_error( 'invalid_client_metadata', 'scope must be a non-empty space-separated string.' );
		} else {
			$scopes = Scopes::parse( $scope_value );
			if ( is_wp_error( $scopes ) || array() === $scopes ) {
				return $this->oauth_error( 'invalid_client_metadata', 'scope contains one or more unsupported values.' );
			}
		}
		$registered_scope = implode( ' ', $scopes );
		$client_name      = $request->get_param( 'client_name' );
		if ( is_string( $client_name ) ) {
			$client_name = sanitize_text_field( $client_name );
			$client_name = function_exists( 'mb_substr' ) ? mb_substr( $client_name, 0, self::REGISTRATION_MAX_NAME_LENGTH ) : substr( $client_name, 0, self::REGISTRATION_MAX_NAME_LENGTH );
			// Without mbstring the cut can split a multibyte character; drop the broken tail instead of failing the insert.
			$client_name = wp_check_invalid_utf8( $client_name, true );
		} else {
			$client_name = '';
		}
		if ( '' === $client_name ) {
			$client_name = 'MCP client';
		}
		$client_id     = Ids::token( 24 );
		$client_secret = 'client_secret_post' === $method ? Ids::token( 32 ) : null;
		// Checked after validation, so malformed requests never count and nothing is written for them.
		$stored = $this->store_client_within_limit(
			array(
				'client_id'          => $client_id,
				'client_name'        => $client_name,
				'redirect_uris'      => wp_json_encode( $redirects ),
				'scopes'             => $registered_scope,
				'client_secret_hash' => $client_secret ? Ids::hash( $client_secret ) : null,
				'created_at'         => current_time( 'mysql', true ),
			)
		);
		if ( 'limited' === $stored ) {
			$limited = $this->oauth_error( 'temporarily_unavailable', 'Too many client registrations. Try again later.', 429 );
			$limited->header( 'Retry-After', (string) HOUR_IN_SECONDS );
			return $limited;
		}
		if ( 'unavailable' === $stored ) {
			return $this->oauth_error( 'temporarily_unavailable', 'Client registration needs a MySQL or MariaDB named lock, which the database did not grant. Try again later.', 503 );
		}
		if ( 'stored' !== $stored ) {
			return $this->oauth_error( 'server_error', 'The client registration could not be stored safely.', 500 );
		}
		( new AuditLog() )->record(
			'oauth.client_registered',
			'success',
			array(
				'client_id' => $client_id,
				'inputs'    => array( 'scopes' => $scopes ),
				'after'     => array( 'scopes' => $scopes ),
			)
		);
		$body = array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => time(),
			'redirect_uris'              => $redirects,
			'token_endpoint_auth_method' => $method,
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'scope'                      => $registered_scope,
		);
		if ( $client_secret ) {
			$body['client_secret']            = $client_secret;
			$body['client_secret_expires_at'] = 0;
		}
		return new \WP_REST_Response( $body, 201 );
	}

	public function authorize(): void {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$params    = wp_unslash( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth request is validated below and consent POST has a nonce.
		$validated = $this->validate_authorization_request( $params );
		if ( is_wp_error( $validated ) ) {
			wp_die( esc_html( $validated->get_error_message() ), esc_html__( 'Invalid OAuth request', 'sitepilot-mcp' ), array( 'response' => 400 ) );
		}
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) ) ) {
			$this->render_consent( $validated );
			exit;
		}
		check_admin_referer( 'sitepilot_oauth_consent' );
		if ( isset( $params['deny'] ) ) {
			$this->redirect_oauth(
				$validated['redirect_uri'],
				array(
					'error' => 'access_denied',
					'state' => $validated['state'],
				)
			);
		}
		$user_id = get_current_user_id();
		if ( ! Scopes::user_can_grant( $validated['scopes'], $user_id ) ) {
			wp_die( esc_html__( 'Your account cannot grant the requested scopes.', 'sitepilot-mcp' ), '', array( 'response' => 403 ) );
		}
		global $wpdb;
		$code = Ids::token( 32 );
		$wpdb->insert(
			$wpdb->prefix . 'sitepilot_oauth_codes',
			array(
				'code_hash'      => Ids::hash( $code ),
				'client_id'      => $validated['client_id'],
				'user_id'        => $user_id,
				'redirect_uri'   => $validated['redirect_uri'],
				'scopes'         => implode( ' ', $validated['scopes'] ),
				'code_challenge' => $validated['code_challenge'],
				'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		( new AuditLog() )->record(
			'oauth.consent_granted',
			'success',
			array(
				'client_id' => $validated['client_id'],
				'inputs'    => array( 'scopes' => $validated['scopes'] ),
			)
		);
		$this->redirect_oauth(
			$validated['redirect_uri'],
			array(
				'code'  => $code,
				'state' => $validated['state'],
			)
		);
	}

	public function token( \WP_REST_Request $request ): \WP_REST_Response {
		$grant_type = (string) $request->get_param( 'grant_type' );
		$client_id  = sanitize_text_field( (string) $request->get_param( 'client_id' ) );
		if ( ! $this->authenticate_client( $client_id, (string) $request->get_param( 'client_secret' ) ) ) {
			return $this->oauth_error( 'invalid_client', 'Client authentication failed.', 401 );
		}
		if ( ! self::is_mcp_resource( (string) $request->get_param( 'resource' ) ) ) {
			return $this->oauth_error( 'invalid_target', 'The resource parameter must identify this MCP endpoint.' );
		}
		if ( 'refresh_token' === $grant_type ) {
			$result = $this->tokens->rotate( (string) $request->get_param( 'refresh_token' ), $client_id );
			return is_wp_error( $result ) ? $this->oauth_error( $result->get_error_code(), $result->get_error_message() ) : new \WP_REST_Response( $result, 200 );
		}
		if ( 'authorization_code' !== $grant_type ) {
			return $this->oauth_error( 'unsupported_grant_type', 'Only authorization_code and refresh_token are supported.' );
		}
		return $this->exchange_code( $request, $client_id );
	}

	public function revoke( \WP_REST_Request $request ): \WP_REST_Response {
		$client_id = sanitize_text_field( (string) $request->get_param( 'client_id' ) );
		if ( ! $this->authenticate_client( $client_id, (string) $request->get_param( 'client_secret' ) ) ) {
			return $this->oauth_error( 'invalid_client', 'Client authentication failed.', 401 );
		}
		$this->tokens->revoke( (string) $request->get_param( 'token' ) );
		( new AuditLog() )->record( 'oauth.token_revoked', 'success', array( 'client_id' => $client_id ) );
		return new \WP_REST_Response( null, 200 );
	}

	public function authenticate_bearer( int|false $user_id ): int|false {
		if ( $user_id ) {
			return $user_id;
		}
		$header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';
		if ( ! preg_match( '/^Bearer\s+([^\s]+)$/i', $header, $matches ) ) {
			return $user_id;
		}
		$token = $this->tokens->authenticate_access( $matches[1] );
		if ( ! $token ) {
			return false;
		}
		$GLOBALS['sitepilot_oauth_context'] = array(
			'grant_id'  => $token['grant_id'],
			'client_id' => $token['client_id'],
			'scopes'    => explode( ' ', (string) $token['scopes'] ),
		);
		return (int) $token['user_id'];
	}

	/** @param array<string,mixed> $params @return array<string,mixed>|\WP_Error */
	private function validate_authorization_request( array $params ) {
		global $wpdb;
		$required = array( 'client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'code_challenge', 'code_challenge_method', 'resource' );
		foreach ( $required as $name ) {
			if ( empty( $params[ $name ] ) || ! is_string( $params[ $name ] ) ) {
				return new \WP_Error( 'invalid_request', sprintf( 'Missing %s.', $name ) );
			}
		}
		if ( 'code' !== $params['response_type'] || 'S256' !== $params['code_challenge_method'] ) {
			return new \WP_Error( 'invalid_request', 'Authorization Code with PKCE S256 is required.' );
		}
		if ( ! self::is_mcp_resource( $params['resource'] ) ) {
			return new \WP_Error( 'invalid_target', 'The resource parameter must identify this MCP endpoint.' );
		}
		$client = $this->lookup_client( sanitize_text_field( $params['client_id'] ) );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$redirects = is_array( $client ) ? json_decode( (string) $client['redirect_uris'], true ) : array();
		if ( ! is_array( $client ) || ! is_array( $redirects ) || ! $this->client_redirect_uri_matches( $params['redirect_uri'], $redirects ) ) {
			return new \WP_Error( 'invalid_request', 'The client or exact redirect URI is invalid.' );
		}
		$scopes = Scopes::parse( $params['scope'] );
		if ( is_wp_error( $scopes ) ) {
			return $scopes;
		}
		if ( array_key_exists( 'scopes', $client ) ) {
			$registered_scopes = Scopes::parse( (string) $client['scopes'] );
			if ( is_wp_error( $registered_scopes ) || array() === $registered_scopes ) {
				return new \WP_Error( 'invalid_client', 'The client registration has no valid scope ceiling.' );
			}
			$scopes = array_values( array_intersect( $scopes, $registered_scopes ) );
			if ( array() === $scopes ) {
				return new \WP_Error( 'invalid_scope', 'The requested scopes exceed the client registration.' );
			}
		}
		return array(
			'client_id'      => (string) $params['client_id'],
			'client_name'    => (string) $client['client_name'],
			'redirect_uri'   => (string) $params['redirect_uri'],
			'scopes'         => $scopes,
			'state'          => (string) $params['state'],
			'code_challenge' => (string) $params['code_challenge'],
			'resource'       => (string) $params['resource'],
		);
	}

	/** @param array<string,mixed> $request */
	private function render_consent( array $request ): void {

		?>
		<!doctype html>
		<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="theme-color" content="#201e1d">
			<title><?php esc_html_e( 'Authorize SitePilot connection', 'sitepilot-mcp' ); ?></title>
			<style>
				@font-face{font-family:"Archivo Variable";font-style:normal;font-display:swap;font-weight:100 900;src:url("<?php echo esc_url( plugins_url( 'assets/fonts/archivo-latin-wght-normal.woff2', SITEPILOT_MCP_FILE ) ); ?>") format("woff2-variations")}
				:root{color-scheme:light;--sp-accent:#ec3013;--sp-ink:#201e1d;--sp-ground:#f3f2f2;--sp-surface:#fff;--sp-muted:#605d5d;--sp-line:#d7d3d3}
				*{box-sizing:border-box}
				body{margin:0;background:var(--sp-ground);color:var(--sp-ink);font:16px/1.55 "Archivo Variable",Archivo,"Helvetica Neue",Arial,sans-serif}
				.sp-shell{min-height:100vh;display:grid;place-items:center;padding:32px 20px}
				.sp-card{width:min(680px,100%);background:var(--sp-surface);border:1px solid var(--sp-line);border-top:8px solid var(--sp-accent);padding:clamp(28px,6vw,56px)}
				.sp-brand{display:flex;align-items:center;gap:14px;margin-bottom:44px}
				.sp-brand svg{width:48px;height:48px;flex:none}
				.sp-wordmark{display:block;font-size:22px;font-weight:800;letter-spacing:-.03em}
				.sp-tagline{display:block;color:var(--sp-muted);font-size:12px;letter-spacing:.05em;text-transform:uppercase}
				.sp-eyebrow{margin:0 0 10px;color:var(--sp-accent);font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}
				h1{margin:0 0 16px;font-size:clamp(30px,6vw,46px);line-height:1.05;letter-spacing:-.045em}
				.sp-request{margin:0;color:var(--sp-muted);font-size:18px}
				.sp-scopes{margin:28px 0;padding:0;border-top:1px solid var(--sp-line);list-style:none}
				.sp-scopes li{display:flex;align-items:center;gap:12px;padding:14px 0;border-bottom:1px solid var(--sp-line)}
				.sp-scopes li::before{width:8px;height:8px;background:var(--sp-accent);content:"";flex:none}
				.sp-scopes code{font:700 14px/1.4 ui-monospace,SFMono-Regular,Consolas,monospace}
				.sp-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:28px}
				.sp-button{appearance:none;border:2px solid var(--sp-ink);border-radius:0;padding:12px 20px;background:var(--sp-surface);color:var(--sp-ink);font:800 14px/1 "Archivo Variable",Archivo,"Helvetica Neue",Arial,sans-serif;cursor:pointer}
				.sp-button:hover{background:var(--sp-ground)}
				.sp-button-primary{border-color:var(--sp-accent);background:var(--sp-accent);color:#fff}
				.sp-button-primary:hover{border-color:#ae1800;background:#ae1800}
				.sp-button:focus-visible{outline:3px solid var(--sp-ink);outline-offset:3px}
				.sp-security{margin:32px 0 0;padding-top:20px;border-top:1px solid var(--sp-line);color:var(--sp-muted);font-size:13px}
				@media (max-width:520px){.sp-shell{padding:0}.sp-card{min-height:100vh;border-right:0;border-bottom:0;border-left:0}.sp-actions{display:grid}.sp-button{width:100%}}
			</style>
		</head>
		<body>
			<main class="sp-shell">
				<section class="sp-card" aria-labelledby="sitepilot-consent-title">
					<header class="sp-brand">
						<svg viewBox="0 0 100 100" role="img" aria-label="<?php esc_attr_e( 'SitePilot', 'sitepilot-mcp' ); ?>">
							<path fill="#201e1d" d="M90,15 L35,45 L15,85 Z"/>
							<path fill="#ec3013" d="M90,15 L35,45 L55,90 Z"/>
						</svg>
						<span>
							<strong class="sp-wordmark">SitePilot</strong>
							<span class="sp-tagline"><?php esc_html_e( 'Bridging AI agents and WordPress safely', 'sitepilot-mcp' ); ?></span>
						</span>
					</header>
					<p class="sp-eyebrow"><?php esc_html_e( 'Secure WordPress authorization', 'sitepilot-mcp' ); ?></p>
					<h1 id="sitepilot-consent-title"><?php esc_html_e( 'Authorize SitePilot connection', 'sitepilot-mcp' ); ?></h1>
					<p class="sp-request">
						<?php
						// translators: %s: OAuth client display name.
						echo esc_html( sprintf( __( '%s is requesting access as your WordPress user.', 'sitepilot-mcp' ), $request['client_name'] ) );
						?>
					</p>
					<ul class="sp-scopes" aria-label="<?php esc_attr_e( 'Requested permissions', 'sitepilot-mcp' ); ?>">
						<?php
						foreach ( $request['scopes'] as $scope ) :
							?>
							<li><code><?php echo esc_html( $scope ); ?></code></li>
						<?php endforeach; ?>
					</ul>
					<form method="post">
						<?php wp_nonce_field( 'sitepilot_oauth_consent' ); ?>
						<?php
						foreach ( array( 'client_id', 'redirect_uri', 'state', 'code_challenge', 'resource' ) as $name ) :
							?>
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $request[ $name ] ); ?>">
						<?php endforeach; ?>
						<input type="hidden" name="response_type" value="code">
						<input type="hidden" name="code_challenge_method" value="S256">
						<input type="hidden" name="scope" value="<?php echo esc_attr( implode( ' ', $request['scopes'] ) ); ?>">
						<div class="sp-actions">
							<button class="sp-button sp-button-primary" type="submit"><?php esc_html_e( 'Authorize connection', 'sitepilot-mcp' ); ?></button>
							<button class="sp-button" type="submit" name="deny" value="1"><?php esc_html_e( 'Deny', 'sitepilot-mcp' ); ?></button>
						</div>
					</form>
					<p class="sp-security"><?php esc_html_e( 'Your WordPress password is never shared. You can revoke access at any time from SitePilot MCP → Connections.', 'sitepilot-mcp' ); ?></p>
				</section>
			</main>
		</body>
		</html>

		<?php
	}
	private function exchange_code( \WP_REST_Request $request, string $client_id ): \WP_REST_Response {
		global $wpdb;
		$code_hash = Ids::hash( (string) $request->get_param( 'code' ) );
		$wpdb->query( 'START TRANSACTION' );
		$code     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sitepilot_oauth_codes WHERE code_hash = %s FOR UPDATE", $code_hash ), ARRAY_A );
		$verifier = (string) $request->get_param( 'code_verifier' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 requires base64url encoding of the PKCE SHA-256 digest.
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		if ( ! is_array( $code ) || $code['used_at'] || strtotime( (string) $code['expires_at'] . ' UTC' ) <= time() || ! hash_equals( (string) $code['client_id'], $client_id ) || ! hash_equals( (string) $code['redirect_uri'], (string) $request->get_param( 'redirect_uri' ) ) || ! hash_equals( (string) $code['code_challenge'], $challenge ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->oauth_error( 'invalid_grant', 'The authorization code, redirect URI, or PKCE verifier is invalid.' );
		}
		$wpdb->update( $wpdb->prefix . 'sitepilot_oauth_codes', array( 'used_at' => current_time( 'mysql', true ) ), array( 'code_hash' => $code_hash ), array( '%s' ), array( '%s' ) );
		$grant_id = Ids::uuid();
		$wpdb->insert(
			$wpdb->prefix . 'sitepilot_oauth_grants',
			array(
				'grant_id'   => $grant_id,
				'client_id'  => $client_id,
				'user_id'    => $code['user_id'],
				'scopes'     => $code['scopes'],
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);
		try {
			$pair = $this->tokens->issue_pair( $grant_id, explode( ' ', (string) $code['scopes'] ) );
		} catch ( \RuntimeException ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->oauth_error( 'server_error', 'The token pair could not be issued safely.', 500 );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->oauth_error( 'server_error', 'The token pair could not be issued safely.', 500 );
		}
		return new \WP_REST_Response( $pair, 200 );
	}

	private function authenticate_client( string $client_id, string $secret ): bool {
		$client = $this->lookup_client( $client_id );
		if ( ! is_array( $client ) ) {
			return false;
		}
		return empty( $client['client_secret_hash'] ) || ( '' !== $secret && hash_equals( (string) $client['client_secret_hash'], Ids::hash( $secret ) ) );
	}

	/** @return array<string,mixed>|\WP_Error|null */
	private function lookup_client( string $client_id ) {
		global $wpdb;
		$client = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sitepilot_oauth_clients WHERE client_id = %s", $client_id ), ARRAY_A );
		if ( is_array( $client ) ) {
			return $client;
		}
		$parts = wp_parse_url( $client_id );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) || empty( $parts['path'] ) || '/' === $parts['path'] || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$response = wp_safe_remote_get(
			$client_id,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'invalid_client', __( 'The client metadata document could not be retrieved.', 'sitepilot-mcp' ) );
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'invalid_client', __( 'The client metadata document returned an invalid response.', 'sitepilot-mcp' ) );
		}
		$metadata = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $metadata ) || ! hash_equals( $client_id, (string) ( $metadata['client_id'] ?? '' ) ) || empty( $metadata['client_name'] ) || ! is_array( $metadata['redirect_uris'] ?? null ) ) {
			return new \WP_Error( 'invalid_client', __( 'The client metadata document is invalid.', 'sitepilot-mcp' ) );
		}
		$redirects = array_values( array_unique( array_filter( $metadata['redirect_uris'], fn ( $uri ): bool => is_string( $uri ) && $this->valid_redirect_uri( $uri ) ) ) );
		if ( count( $redirects ) !== count( $metadata['redirect_uris'] ) || 'none' !== ( $metadata['token_endpoint_auth_method'] ?? 'none' ) ) {
			return new \WP_Error( 'invalid_client', __( 'The client metadata redirect or authentication method is invalid.', 'sitepilot-mcp' ) );
		}
		return array(
			'client_id'          => $client_id,
			'client_name'        => sanitize_text_field( (string) $metadata['client_name'] ),
			'redirect_uris'      => wp_json_encode( $redirects ),
			'client_secret_hash' => null,
		);
	}

	/** @param array<int,string> $registered_redirects */
	private function client_redirect_uri_matches( string $requested_uri, array $registered_redirects ): bool {
		if ( in_array( $requested_uri, $registered_redirects, true ) ) {
			return true;
		}
		if ( ! $this->valid_redirect_uri( $requested_uri ) ) {
			return false;
		}
		$requested = wp_parse_url( $requested_uri );
		if ( ! is_array( $requested ) || ! $this->is_loopback_host( (string) ( $requested['host'] ?? '' ) ) ) {
			return false;
		}
		// RFC 8252 requires native-app loopback callbacks to accept an ephemeral port.
		// Client ID Metadata Documents do not have to declare an application type, so
		// the registered loopback URI itself is the authority for this narrow exception.
		foreach ( $registered_redirects as $registered_uri ) {
			$registered = wp_parse_url( $registered_uri );
			if ( ! is_array( $registered ) || ! $this->is_loopback_host( (string) ( $registered['host'] ?? '' ) ) ) {
				continue;
			}
			if ( strtolower( (string) ( $requested['scheme'] ?? '' ) ) === strtolower( (string) ( $registered['scheme'] ?? '' ) )
				&& strtolower( (string) ( $requested['host'] ?? '' ) ) === strtolower( (string) ( $registered['host'] ?? '' ) )
				&& (string) ( $requested['path'] ?? '' ) === (string) ( $registered['path'] ?? '' )
				&& (string) ( $requested['query'] ?? '' ) === (string) ( $registered['query'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	private function is_loopback_host( string $host ): bool {
		return in_array( strtolower( $host ), array( '127.0.0.1', '[::1]', 'localhost' ), true );
	}

	private function valid_redirect_uri( string $uri ): bool {
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		$is_loopback = $this->is_loopback_host( (string) $parts['host'] );
		return 'https' === strtolower( (string) $parts['scheme'] ) || ( $is_loopback && 'http' === strtolower( (string) $parts['scheme'] ) );
	}

	/** @param array<string,string> $params */
	private function redirect_oauth( string $uri, array $params ): never {
		wp_redirect( add_query_arg( array_filter( $params ), $uri ), 302, 'SitePilot MCP' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- exact registered URI was validated.
		exit;
	}

	/**
	 * Count and insert under one lock, so a burst of parallel requests cannot all pass the check before any row exists.
	 *
	 * @param array<string,mixed> $row Client row to insert.
	 * @return string 'stored', 'limited' (cap reached or lock busy), 'unavailable' (no lock) or 'failed'.
	 */
	private function store_client_within_limit( array $row ): string {
		global $wpdb;
		$lock = self::registration_lock_name();

		// A MySQL/MariaDB named lock belongs to this connection: it cannot expire while the holder is still running,
		// and the server releases it if the request dies. '1' = acquired, '0' = busy, NULL = error or no named locks.
		$suppress = $wpdb->suppress_errors( true );
		$acquired = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock, self::REGISTRATION_LOCK_WAIT ) );
		$wpdb->suppress_errors( $suppress );
		if ( '0' === $acquired ) {
			return 'limited';
		}
		// Fail closed: without the lock the cap could not be enforced, so nothing is written.
		if ( '1' !== $acquired ) {
			return 'unavailable';
		}
		try {
			$since = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sitepilot_oauth_clients WHERE created_at > %s", $since ) );
			// A failed count is NULL, not zero: refuse instead of writing past the cap.
			if ( null === $count ) {
				return 'unavailable';
			}
			if ( (int) $count >= self::REGISTRATION_LIMIT_PER_HOUR ) {
				return 'limited';
			}
			$inserted = $wpdb->insert( $wpdb->prefix . 'sitepilot_oauth_clients', $row, array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
			return false === $inserted ? 'failed' : 'stored';
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
	}

	/**
	 * Named database lock that serialises client registration for this site. Lock names are server-wide, so the site
	 * URL and table prefix are part of it.
	 *
	 * @internal Public for the wp-env OAuth scenario.
	 */
	public static function registration_lock_name(): string {
		global $wpdb;
		return 'sitepilot_oauth_register_' . md5( home_url( '/' ) . '|' . $wpdb->prefix );
	}

	private function oauth_error( string $code, string $description, int $status = 400 ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'error'             => $code,
				'error_description' => $description,
			),
			$status
		);
	}

	private static function mcp_resource_path( string $version = self::LATEST_MCP_RESOURCE_VERSION ): string {
		return (string) wp_parse_url( self::mcp_resource_url( $version ), PHP_URL_PATH );
	}

	private static function mcp_resource_url( string $version = self::LATEST_MCP_RESOURCE_VERSION ): string {
		$version = in_array( $version, self::MCP_RESOURCE_VERSIONS, true ) ? $version : self::LATEST_MCP_RESOURCE_VERSION;
		return rest_url( 'sitepilot-mcp/' . $version . '/mcp' );
	}

	private static function is_mcp_resource( string $resource_url ): bool {
		$actual = wp_parse_url( $resource_url );
		if ( ! is_array( $actual ) || isset( $actual['fragment'], $actual['query'], $actual['user'], $actual['pass'] ) ) {
			return false;
		}

		foreach ( self::MCP_RESOURCE_VERSIONS as $version ) {
			$expected = wp_parse_url( self::mcp_resource_url( $version ) );
			if ( ! is_array( $expected ) ) {
				continue;
			}
			$expected_port = isset( $expected['port'] ) ? (int) $expected['port'] : ( 'https' === strtolower( (string) $expected['scheme'] ) ? 443 : 80 );
			$actual_port   = isset( $actual['port'] ) ? (int) $actual['port'] : ( 'https' === strtolower( (string) $actual['scheme'] ) ? 443 : 80 );
			if ( strtolower( (string) $expected['scheme'] ) === strtolower( (string) $actual['scheme'] )
				&& strtolower( (string) $expected['host'] ) === strtolower( (string) $actual['host'] )
				&& $expected_port === $actual_port
				&& (string) $expected['path'] === (string) $actual['path'] ) {
				return true;
			}
		}
		return false;
	}
}
