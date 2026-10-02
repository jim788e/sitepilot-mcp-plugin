<?php
/**
 * Plugin Name: SitePilot MCP
 * Plugin URI: https://sitepilot.tools/
 * Description: A guarded OAuth and MCP control plane for WordPress sites.
 * Version: 0.4.13
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Author: SitePilot
 * Author URI: https://sitepilot.tools/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sitepilot-mcp
 * Domain Path: /languages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SITEPILOT_MCP_VERSION', '0.4.13' );
define( 'SITEPILOT_MCP_FILE', __FILE__ );
define( 'SITEPILOT_MCP_DIR', plugin_dir_path( __FILE__ ) );

$sitepilot_autoloader = SITEPILOT_MCP_DIR . 'vendor/autoload_packages.php';
if ( file_exists( $sitepilot_autoloader ) ) {
	require_once $sitepilot_autoloader;
} elseif ( file_exists( SITEPILOT_MCP_DIR . 'vendor/autoload.php' ) ) {
	require_once SITEPILOT_MCP_DIR . 'vendor/autoload.php';
}

require_once SITEPILOT_MCP_DIR . 'src/Autoload.php';
\SitePilot\Mcp\Autoload::register();

register_activation_hook( __FILE__, array( \SitePilot\Mcp\Infrastructure\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \SitePilot\Mcp\Infrastructure\Activator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! \SitePilot\Mcp\Infrastructure\Activator::maybe_upgrade() ) {
			add_action( 'admin_notices', array( \SitePilot\Mcp\Infrastructure\Activator::class, 'render_schema_error_notice' ) );
			return;
		}
		\SitePilot\Mcp\Plugin::instance()->boot();
	},
	20
);
