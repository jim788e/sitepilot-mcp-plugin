<?php

declare(strict_types=1);

namespace SitePilot\Mcp;

use SitePilot\Mcp\Admin\Admin;
use SitePilot\Mcp\AgencyPack\AgencyPackPreview;
use SitePilot\Mcp\Abilities\AbilityRegistry;
use SitePilot\Mcp\Adapters\ElementorDocumentStore;
use SitePilot\Mcp\Adapters\EnfoldAdapter;
use SitePilot\Mcp\Adapters\EnfoldTemplateStore;
use SitePilot\Mcp\Credentials\ApplicationPasswordAuthenticator;
use SitePilot\Mcp\Credentials\CredentialController;
use SitePilot\Mcp\Infrastructure\Environment;
// direct-channel-updater import
use SitePilot\Mcp\Infrastructure\PrivateUpdater;
use SitePilot\Mcp\Infrastructure\Retention;
use SitePilot\Mcp\OAuth\AuthorizationServer;
use SitePilot\Mcp\Policy\ApprovalGuard;
use SitePilot\Mcp\Playbooks\PlaybookRegistry;

final class Plugin {
	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {

		$environment = new Environment();
		if ( ! $environment->is_supported() ) {
			add_action( 'admin_notices', array( $environment, 'render_notice' ) );
			return;
		}

		( new AuthorizationServer() )->register();
		( new ApplicationPasswordAuthenticator() )->register();
		( new CredentialController() )->register();
		( new ApprovalGuard() )->register();
		add_action( 'init', array( EnfoldTemplateStore::class, 'register_post_type' ) );
		ElementorDocumentStore::register_cache_invalidation();
		if ( did_action( 'after_setup_theme' ) ) {
			EnfoldAdapter::bootstrap_native_api();
		} else {
			add_action( 'after_setup_theme', array( EnfoldAdapter::class, 'bootstrap_native_api' ), PHP_INT_MAX );
		}
		add_filter( 'rest_post_dispatch', array( $this, 'advertise_oauth_resource_metadata' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( EnfoldAdapter::class, 'enqueue_compiled_css' ), PHP_INT_MAX );
		( new AbilityRegistry() )->register();
		( new PlaybookRegistry() )->register();
		( new Admin() )->register();
		( new AgencyPackPreview() )->register();
		( new Retention() )->register();
		// direct-channel-updater:begin (removed from the WordPress.org package by scripts/package-plugin.mjs)
		if ( class_exists( PrivateUpdater::class ) ) {
			( new PrivateUpdater() )->register();
		}
		// direct-channel-updater:end

		add_action( 'mcp_adapter_init', array( $this, 'register_mcp_server' ) );
		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			\WP\MCP\Core\McpAdapter::instance();
		} else {
			add_action( 'admin_notices', array( $this, 'render_adapter_notice' ) );
		}
	}

	public function register_mcp_server( object $adapter ): void {
		if ( ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		foreach (
			array(
				array( 'sitepilot-mcp', 'sitepilot-mcp/v1' ),
				array( 'sitepilot-mcp-v2', 'sitepilot-mcp/v2' ),
			) as $server
		) {
			$adapter->create_server(
				$server[0],
				$server[1],
				'mcp',
				'SitePilot MCP',
				__( 'Guarded WordPress operations with approval and rollback.', 'sitepilot-mcp' ),
				SITEPILOT_MCP_VERSION,
				array( \WP\MCP\Transport\HttpTransport::class ),
				\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
				\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
				AbilityRegistry::TOOLS,
				array(),
				PlaybookRegistry::prompt_abilities()
			);
		}
	}

	/**
	 * Advertise OAuth protected-resource metadata when the MCP endpoint rejects an unauthenticated request.
	 *
	 * @param \WP_REST_Response $response REST response.
	 * @param \WP_REST_Server   $server   REST server.
	 * @param \WP_REST_Request  $request  REST request.
	 */
	public function advertise_oauth_resource_metadata( \WP_REST_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_REST_Response {
		unset( $server );
		if ( ! in_array( $request->get_route(), array( '/sitepilot-mcp/v1/mcp', '/sitepilot-mcp/v2/mcp' ), true ) || 401 !== $response->get_status() ) {
			return $response;
		}
		$version = str_contains( $request->get_route(), '/v2/' ) ? 'v2' : 'v1';

		$response->header(
			'WWW-Authenticate',
			sprintf( 'Bearer resource_metadata="%s"', esc_url_raw( AuthorizationServer::protected_resource_metadata_url( $version ) ) )
		);
		return $response;
	}

	public function render_adapter_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'SitePilot MCP cannot start because its packaged MCP Adapter dependency is unavailable. Reinstall the complete release ZIP.', 'sitepilot-mcp' ) . '</p></div>';
	}
}
