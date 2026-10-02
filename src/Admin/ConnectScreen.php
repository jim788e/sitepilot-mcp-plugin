<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Admin;

/**
 * "Connect your AI" tab: the MCP endpoint and paste-ready client setup.
 *
 * Remote clients authenticate through the plugin's OAuth flow in the browser, so
 * no secret is shown or generated here. The npm adapter stays a read-only fallback.
 */
final class ConnectScreen {
	public const ADAPTER_VERSION = '0.1.6';

	/**
	 * Paste-ready setup text per client. Pure so it can be tested without WordPress.
	 *
	 * @return array<string,array{label:string,steps:string,snippet:string}>
	 */
	public static function snippets( string $endpoint, string $site_url ): array {
		$json = (string) wp_json_encode(
			array( 'mcpServers' => array( 'sitepilot' => array( 'url' => $endpoint ) ) ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		return array(
			'claude'      => array(
				'label'   => 'Claude (Desktop or claude.ai)',
				'steps'   => 'Open Settings, then Connectors, then Add custom connector. Paste the endpoint below, then approve the SitePilot request in WordPress.',
				'snippet' => $endpoint,
			),
			'claude-code' => array(
				'label'   => 'Claude Code',
				'steps'   => 'Run this command in a terminal, then run /mcp inside Claude Code and sign in to SitePilot.',
				'snippet' => 'claude mcp add --transport http sitepilot ' . $endpoint,
			),
			'cursor'      => array(
				'label'   => 'Cursor',
				'steps'   => 'Add this to .cursor/mcp.json (project) or ~/.cursor/mcp.json (global), then reload the window and sign in when prompted.',
				'snippet' => $json,
			),
			'fallback'    => array(
				'label'   => 'Terminal fallback (read-only)',
				'steps'   => 'For clients without remote OAuth. Requires Node.js 22 or later. Run the first command and approve the Application Password request in WordPress. The second command writes the client configuration (Cursor shown; use claude-code, codex, windsurf, agy or claude-desktop for another client), then reload the client. The connection is limited to site:read.',
				'snippet' => 'npx -y sitepilot-mcp@' . self::ADAPTER_VERSION . ' login --url ' . $site_url . " --scopes site:read --profile my-site\nnpx -y sitepilot-mcp@" . self::ADAPTER_VERSION . ' init --client cursor --profile my-site',
			),
		);
	}

	public static function first_prompt(): string {
		return 'Use the SitePilot tool sitepilot-inspect-site exactly once. Do not call any other tool. Report the WordPress version, SitePilot version, and granted scopes.';
	}

	public function render(): void {
		global $wpdb;
		$endpoint = rest_url( 'sitepilot-mcp/v2/mcp' );
		$active   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sitepilot_oauth_grants WHERE revoked_at IS NULL" );
		$latest   = $wpdb->get_var( "SELECT MAX(created_at) FROM {$wpdb->prefix}sitepilot_oauth_grants WHERE revoked_at IS NULL" );

		echo '<h2>' . esc_html__( 'Connect your AI assistant', 'sitepilot-mcp' ) . '</h2>';
		echo '<p>' . esc_html__( 'Your assistant proposes changes. You approve them here in WordPress, and you can undo them. Start read-only: nothing is changed until you approve it.', 'sitepilot-mcp' ) . '</p>';

		echo '<div class="card"><h3>' . esc_html__( '1. Your SitePilot endpoint', 'sitepilot-mcp' ) . '</h3>';
		echo '<p><input class="large-text code" type="text" readonly value="' . esc_attr( $endpoint ) . '" onfocus="this.select()"></p>';
		if ( ! is_ssl() ) {
			echo '<p class="notice notice-warning inline"><span>' . esc_html__( 'This site is not served over HTTPS. Most AI clients require HTTPS to connect.', 'sitepilot-mcp' ) . '</span></p>';
		}
		echo '</div>';

		echo '<div class="card"><h3>' . esc_html__( '2. Add it to your client', 'sitepilot-mcp' ) . '</h3>';
		foreach ( self::snippets( $endpoint, home_url() ) as $item ) {
			echo '<details><summary><strong>' . esc_html( $item['label'] ) . '</strong></summary><p>' . esc_html( $item['steps'] ) . '</p><pre><code>' . esc_html( $item['snippet'] ) . '</code></pre></details>';
		}
		echo '</div>';

		echo '<div class="card"><h3>' . esc_html__( '3. Test the connection', 'sitepilot-mcp' ) . '</h3>';
		echo '<p>' . esc_html__( 'Paste this into your assistant:', 'sitepilot-mcp' ) . '</p><pre><code>' . esc_html( self::first_prompt() ) . '</code></pre>';
		if ( $active > 0 ) {
			// translators: 1: number of active OAuth authorizations, 2: UTC timestamp of the newest one.
			echo '<p><strong>' . esc_html( sprintf( _n( '%1$d active OAuth authorization. Latest: %2$s UTC.', '%1$d active OAuth authorizations. Latest: %2$s UTC.', $active, 'sitepilot-mcp' ), $active, (string) $latest ) ) . '</strong> ' . esc_html__( 'This shows access was approved in WordPress. It does not prove a tool call succeeded, so run the prompt above to test the connection.', 'sitepilot-mcp' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=sitepilot-mcp&tab=connections' ) ) . '">' . esc_html__( 'Manage', 'sitepilot-mcp' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'No OAuth authorization yet. Application Password connections are listed under Credentials.', 'sitepilot-mcp' ) . '</p>';
		}
		echo '</div>';
	}
}
