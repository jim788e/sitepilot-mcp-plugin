<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Admin;

use SitePilot\Mcp\AgencyPack\AgencyPackPreview;
use SitePilot\Mcp\Changes\ChangeSetService;
use SitePilot\Mcp\Credentials\CredentialGrantRepository;
use SitePilot\Mcp\Infrastructure\AuditLog;
use SitePilot\Mcp\Infrastructure\Capabilities;
use SitePilot\Mcp\Infrastructure\Environment;
use SitePilot\Mcp\Infrastructure\SiteVersion;
use SitePilot\Mcp\OAuth\Scopes;

final class Admin {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_sitepilot_approve_changeset', array( $this, 'approve' ) );
		add_action( 'admin_post_sitepilot_cancel_changeset', array( $this, 'cancel_changeset' ) );
		add_action( 'admin_post_sitepilot_revoke_approval', array( $this, 'revoke_approval' ) );
		add_action( 'admin_post_sitepilot_revoke_grant', array( $this, 'revoke_grant' ) );
		add_action( 'admin_post_sitepilot_save_credential', array( $this, 'save_credential' ) );
		add_action( 'admin_post_sitepilot_revoke_credential', array( $this, 'revoke_credential' ) );
		add_action( 'admin_post_sitepilot_save_settings', array( $this, 'save_settings' ) );
	}

	public function menu(): void {
		add_menu_page( __( 'SitePilot MCP', 'sitepilot-mcp' ), __( 'SitePilot', 'sitepilot-mcp' ), 'sitepilot_connect', 'sitepilot-mcp', array( $this, 'render' ), plugins_url( 'assets/sitepilot-mark.svg', SITEPILOT_MCP_FILE ), 58 );
		add_submenu_page( 'sitepilot-mcp', __( 'SitePilot', 'sitepilot-mcp' ), __( 'Dashboard', 'sitepilot-mcp' ), 'sitepilot_connect', 'sitepilot-mcp', array( $this, 'render' ), 0 );
	}

	public function enqueue_assets( string $hook ): void {
		wp_enqueue_style( 'sitepilot-mcp-admin-menu', plugins_url( 'assets/admin-menu.css', SITEPILOT_MCP_FILE ), array(), SITEPILOT_MCP_VERSION );
		if ( 'toplevel_page_sitepilot-mcp' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'sitepilot-mcp-admin', plugins_url( 'assets/admin.css', SITEPILOT_MCP_FILE ), array(), SITEPILOT_MCP_VERSION );
	}

	public function render(): void {
		if ( ! current_user_can( 'sitepilot_connect' ) ) {
			wp_die( esc_html__( 'You cannot access SitePilot.', 'sitepilot-mcp' ) );
		}
		global $wpdb;
		$tab  = sanitize_key( (string) ( $_GET['tab'] ?? 'connect' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tabs = array(
			'connect'     => __( 'Connect', 'sitepilot-mcp' ),
			'status'      => __( 'Status', 'sitepilot-mcp' ),
			'agency'      => __( 'Agency Pack (preview)', 'sitepilot-mcp' ),
			'connections' => __( 'Connections', 'sitepilot-mcp' ),
			'credentials' => __( 'Credentials', 'sitepilot-mcp' ),
			'permissions' => __( 'Permissions', 'sitepilot-mcp' ),
			'approvals'   => __( 'Approvals', 'sitepilot-mcp' ),
			'history'     => __( 'Change History', 'sitepilot-mcp' ),
			'security'    => __( 'Security', 'sitepilot-mcp' ),
			'diagnostics' => __( 'Diagnostics', 'sitepilot-mcp' ),
		);
		echo '<div class="wrap sitepilot-admin"><header class="sitepilot-admin__header"><img src="' . esc_url( plugins_url( 'assets/sitepilot-mark.svg', SITEPILOT_MCP_FILE ) ) . '" alt=""><div><p>' . esc_html__( 'WordPress control plane', 'sitepilot-mcp' ) . '</p><h1>' . esc_html__( 'SitePilot', 'sitepilot-mcp' ) . '</h1><span>' . esc_html__( 'Bridging AI agents and WordPress safely', 'sitepilot-mcp' ) . '</span></div></header><nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'SitePilot sections', 'sitepilot-mcp' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			echo '<a class="nav-tab ' . ( $tab === $slug ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=sitepilot-mcp&tab=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'connect' === $tab ) {
			( new ConnectScreen() )->render();
		} elseif ( 'agency' === $tab ) {
			( new AgencyPackPreview() )->render();
		} elseif ( 'approvals' === $tab ) {
			$view        = sanitize_key( (string) ( $_GET['approval_view'] ?? 'awaiting' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
			$view_labels = array(
				'awaiting' => __( 'Awaiting execution', 'sitepilot-mcp' ),
				'approved' => __( 'Approved', 'sitepilot-mcp' ),
				'rollback' => __( 'Rollback available', 'sitepilot-mcp' ),
				'archived' => __( 'Expired / archived', 'sitepilot-mcp' ),
			);
			$view        = isset( $view_labels[ $view ] ) ? $view : 'awaiting';
			$rows        = $this->approval_rows( $view );
			echo '<h2>' . esc_html__( 'Execution and rollback approvals', 'sitepilot-mcp' ) . '</h2>';
			echo '<ul class="subsubsub">';
			foreach ( $view_labels as $slug => $label ) {
				echo '<li><a ' . ( $view === $slug ? 'class="current" aria-current="page" ' : '' ) . 'href="' . esc_url( admin_url( 'admin.php?page=sitepilot-mcp&tab=approvals&approval_view=' . $slug ) ) . '">' . esc_html( $label ) . '</a>' . ( 'archived' !== $slug ? ' | ' : '' ) . '</li>';
			}
			echo '</ul><div class="clear"></div>';
			$approval_result = sanitize_key( (string) ( $_GET['approval'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only result notice after a nonce-protected action.
			$approval_action = sanitize_key( (string) ( $_GET['approval_action'] ?? 'approved' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only result notice after a nonce-protected action.
			if ( in_array( $approval_result, array( 'success', 'error' ), true ) ) {
				$notice_class     = 'success' === $approval_result ? 'notice-success' : 'notice-error';
				$success_messages = array(
					'approved'  => __( 'The approval is valid for 30 minutes.', 'sitepilot-mcp' ),
					'cancelled' => __( 'The unexecuted change set was cancelled and retained in history.', 'sitepilot-mcp' ),
					'revoked'   => __( 'The approval was revoked. A fresh exact-change approval is required.', 'sitepilot-mcp' ),
				);
				$notice_text      = 'success' === $approval_result
					? ( $success_messages[ $approval_action ] ?? __( 'The approval lifecycle action completed.', 'sitepilot-mcp' ) )
					: __( 'The approval lifecycle action failed. Check your permissions and the current change-set state.', 'sitepilot-mcp' );
				echo '<div class="notice ' . esc_attr( $notice_class ) . ' inline"><p>' . esc_html( $notice_text ) . '</p></div>';
			}
			if ( array() === $rows ) {
				echo '<p>' . esc_html__( 'No change sets are in this view.', 'sitepilot-mcp' ) . '</p>';
			}
			foreach ( $rows as $row ) {
				$is_rollback = 'rollback' === $view;
				$status      = ( new ChangeSetService() )->status( (string) $row['change_set_id'] );
				$is_approved = is_array( $status ) && 'approved' === ( $status['approval_state'] ?? '' );
				// translators: 1: numeric risk tier, 2: change-set identifier.
				echo '<section class="card sitepilot-approval"><h3>' . esc_html( $row['intent'] ) . '</h3><div class="sitepilot-approval__action"><p>' . esc_html( sprintf( __( 'Risk tier %1$d · Change set %2$s', 'sitepilot-mcp' ), $row['risk_tier'], $row['change_set_id'] ) ) . '</p>';
				echo '<p><strong>' . esc_html( $view_labels[ $view ] ) . '</strong></p>';
				if ( $is_approved ) {
					echo '<p>' . esc_html( $is_rollback ? __( 'Rollback approved and waiting for the agent.', 'sitepilot-mcp' ) : __( 'Execution approved and waiting for the agent.', 'sitepilot-mcp' ) ) . '</p>';
				} elseif ( current_user_can( 'sitepilot_approve' ) && in_array( $view, array( 'awaiting', 'rollback' ), true ) ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sitepilot_approve_changeset"><input type="hidden" name="change_set_id" value="' . esc_attr( $row['change_set_id'] ) . '">';
					wp_nonce_field( 'sitepilot_approve_' . $row['change_set_id'] );
					submit_button( $is_rollback ? __( 'Approve rollback for 30 minutes', 'sitepilot-mcp' ) : __( 'Approve execution for 30 minutes', 'sitepilot-mcp' ), 'primary', 'submit', false );
					echo '</form>';
				} elseif ( ! current_user_can( 'sitepilot_approve' ) && in_array( $view, array( 'awaiting', 'rollback' ), true ) ) {
					echo '<p>' . esc_html__( 'Your account can review this change but cannot approve it.', 'sitepilot-mcp' ) . '</p>';
				}
				if ( current_user_can( 'sitepilot_approve' ) && in_array( $view, array( 'awaiting', 'approved' ), true ) ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sitepilot_cancel_changeset"><input type="hidden" name="change_set_id" value="' . esc_attr( $row['change_set_id'] ) . '">';
					wp_nonce_field( 'sitepilot_cancel_' . $row['change_set_id'] );
					submit_button( __( 'Cancel change set', 'sitepilot-mcp' ), 'secondary small', 'submit', false );
					echo '</form>';
				}
				if ( $is_approved && current_user_can( 'sitepilot_approve' ) ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sitepilot_revoke_approval"><input type="hidden" name="change_set_id" value="' . esc_attr( $row['change_set_id'] ) . '"><input type="hidden" name="approval_id" value="' . esc_attr( (string) ( $status['approval_id'] ?? '' ) ) . '">';
					wp_nonce_field( 'sitepilot_revoke_approval_' . (string) ( $status['approval_id'] ?? '' ) );
					submit_button( __( 'Revoke approval', 'sitepilot-mcp' ), 'secondary small', 'submit', false );
					echo '</form>';
				}
				echo '</div><details class="sitepilot-approval__details"><summary>' . esc_html__( 'Review complete change details', 'sitepilot-mcp' ) . '</summary><pre>' . esc_html( wp_json_encode( json_decode( $row['diff'], true ), JSON_PRETTY_PRINT ) ) . '</pre></details></section>';
			}
		} elseif ( 'connections' === $tab ) {
			$rows          = $wpdb->get_results( "SELECT g.*,c.client_name,c.scopes AS registered_scopes,u.user_login FROM {$wpdb->prefix}sitepilot_oauth_grants g LEFT JOIN {$wpdb->prefix}sitepilot_oauth_clients c ON c.client_id=g.client_id LEFT JOIN {$wpdb->users} u ON u.ID=g.user_id ORDER BY g.created_at DESC LIMIT 100", ARRAY_A );
			$reason_labels = array(
				'administrator'       => __( 'Administrator', 'sitepilot-mcp' ),
				'refresh_token_reuse' => __( 'Refresh token reuse', 'sitepilot-mcp' ),
			);
			echo '<h2>' . esc_html__( 'OAuth connections', 'sitepilot-mcp' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Client', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'WordPress user', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Registered ceiling', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Granted scopes', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Created', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Status', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Reason', 'sitepilot-mcp' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$reason_key = (string) ( $row['revocation_reason'] ?? '' );
				$reason     = $row['revoked_at'] ? ( $reason_labels[ $reason_key ] ?? __( 'Unknown', 'sitepilot-mcp' ) ) : '—';
				echo '<tr><td>' . esc_html( $row['client_name'] ? $row['client_name'] : $row['client_id'] ) . '</td><td>' . esc_html( $row['user_login'] ? $row['user_login'] : $row['user_id'] ) . '</td><td><code>' . esc_html( $row['registered_scopes'] ? $row['registered_scopes'] : '—' ) . '</code></td><td><code>' . esc_html( $row['scopes'] ) . '</code></td><td>' . esc_html( $row['created_at'] ) . '</td><td>' . esc_html( $row['revoked_at'] ? sprintf( '%1$s · %2$s', __( 'Revoked', 'sitepilot-mcp' ), $row['revoked_at'] ) : __( 'Active', 'sitepilot-mcp' ) ) . '</td><td><code>' . esc_html( $reason ) . '</code></td><td>';
				if ( ! $row['revoked_at'] ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sitepilot_revoke_grant"><input type="hidden" name="grant_id" value="' . esc_attr( $row['grant_id'] ) . '">';
					wp_nonce_field( 'sitepilot_revoke_' . $row['grant_id'] );
					submit_button( __( 'Revoke', 'sitepilot-mcp' ), 'secondary small', 'submit', false );
					echo '</form>'; }
				echo '</td></tr>';
			}
			echo '</tbody></table><p>' . esc_html__( 'SitePilot never stores a WordPress password. Access and refresh tokens can also be revoked through the OAuth revocation endpoint.', 'sitepilot-mcp' ) . '</p>';
		} elseif ( 'credentials' === $tab ) {
			$this->render_credentials();
		} elseif ( 'permissions' === $tab ) {
			echo '<h2>' . esc_html__( 'Available OAuth scopes', 'sitepilot-mcp' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Scope', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Who can grant it', 'sitepilot-mcp' ) . '</th></tr></thead><tbody>';
			foreach ( Scopes::ALL as $scope ) {
				echo '<tr><td><code>' . esc_html( $scope ) . '</code></td><td>' . esc_html( in_array( $scope, Scopes::ADMIN_ONLY, true ) ? __( 'Administrators only', 'sitepilot-mcp' ) : __( 'Administrators and SitePilot Operators', 'sitepilot-mcp' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} elseif ( 'history' === $tab ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sitepilot_audit ORDER BY created_at DESC LIMIT 100", ARRAY_A );
			echo '<h2>' . esc_html__( 'Audit history', 'sitepilot-mcp' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Event', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Actor', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Credential', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Result', 'sitepilot-mcp' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr><td>' . esc_html( $row['created_at'] ) . '</td><td>' . esc_html( $row['event'] ) . '</td><td>' . esc_html( $row['actor_user_id'] ) . '</td><td><code>' . esc_html( $row['client_id'] ? $row['client_id'] : '—' ) . '</code></td><td>' . esc_html( $row['result'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} elseif ( 'security' === $tab ) {
			echo '<h2>' . esc_html__( 'Security controls', 'sitepilot-mcp' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sitepilot_save_settings">';
			wp_nonce_field( 'sitepilot_save_settings' );
			echo '<label><input type="checkbox" name="cloud_opt_in" value="1" ' . checked( '1', get_option( 'sitepilot_mcp_cloud_opt_in', '0' ), false ) . '> ' . esc_html__( 'Allow the optional SitePilot Cloud connection after OAuth setup', 'sitepilot-mcp' ) . '</label><p class="description">' . esc_html__( 'No outbound cloud request is made before this explicit opt-in.', 'sitepilot-mcp' ) . '</p>';
			echo '<p><label for="sitepilot_gateway_origin"><strong>' . esc_html__( 'Gateway origin', 'sitepilot-mcp' ) . '</strong></label><br><input class="regular-text" id="sitepilot_gateway_origin" name="gateway_origin" type="url" placeholder="https://console.example.com" value="' . esc_attr( Capabilities::gateway_origin() ) . '"></p><p class="description">' . esc_html__( 'Optional cloud-only capabilities are enabled only when opt-in is checked and an exact HTTPS origin is saved.', 'sitepilot-mcp' ) . '</p>';
			if ( current_user_can( 'manage_options' ) ) {
				echo '<hr><label><input type="checkbox" name="permanent_delete" value="1" ' . checked( '1', get_option( 'sitepilot_mcp_permanent_delete', '0' ), false ) . '> <strong>' . esc_html__( 'Enable permanent content deletion', 'sitepilot-mcp' ) . '</strong></label><p class="description">' . esc_html__( 'Danger: this permits single-item Tier-3 deletion after a fresh administrator approval. It cannot be rolled back. Leave disabled and use the WordPress trash workflow for normal deletion.', 'sitepilot-mcp' ) . '</p>';
			}
			submit_button( __( 'Save security settings', 'sitepilot-mcp' ) );
			echo '</form>';
		} elseif ( 'diagnostics' === $tab ) {
			$failures = ( new Environment() )->failures();
			echo '<h2>' . esc_html__( 'Diagnostics', 'sitepilot-mcp' ) . '</h2><table class="widefat striped"><tbody><tr><th>WordPress</th><td>' . esc_html( get_bloginfo( 'version' ) ) . '</td></tr><tr><th>PHP</th><td>' . esc_html( PHP_VERSION ) . '</td></tr><tr><th>HTTPS</th><td>' . esc_html( is_ssl() ? __( 'Yes', 'sitepilot-mcp' ) : __( 'No', 'sitepilot-mcp' ) ) . '</td></tr><tr><th>Abilities API</th><td>' . esc_html( function_exists( 'wp_register_ability' ) ? __( 'Available', 'sitepilot-mcp' ) : __( 'Missing', 'sitepilot-mcp' ) ) . '</td></tr><tr><th>MCP Adapter</th><td>' . esc_html( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ? __( 'Available', 'sitepilot-mcp' ) : __( 'Missing', 'sitepilot-mcp' ) ) . '</td></tr></tbody></table><p>' . esc_html( $failures ? implode( ' ', $failures ) : __( 'All environment checks passed.', 'sitepilot-mcp' ) ) . '</p>';
		} else {
			$failures = ( new Environment() )->failures();
			echo '<h2>' . esc_html( $tabs[ $tab ] ?? $tabs['status'] ) . '</h2><p><strong>' . esc_html__( 'Direct MCP endpoint:', 'sitepilot-mcp' ) . '</strong> <code>' . esc_html( rest_url( 'sitepilot-mcp/v2/mcp' ) ) . '</code></p><p><strong>' . esc_html__( 'Site version:', 'sitepilot-mcp' ) . '</strong> <code>' . esc_html( ( new SiteVersion() )->current() ) . '</code></p><p>' . esc_html( $failures ? implode( ' ', $failures ) : __( 'Environment checks passed. Cloud connection is disabled until an administrator opts in.', 'sitepilot-mcp' ) ) . '</p>';
		}
		echo '</div>';
	}

	/** @return list<array<string,mixed>> */
	private function approval_rows( string $view ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}sitepilot_changesets WHERE status IN ('awaiting_approval','approved','completed','cancelled','rolled_back') ORDER BY created_at DESC LIMIT 200",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $view ): bool {
					$rollback = 'completed' === $row['status'] && ! in_array( (string) ( $row['rollback_data'] ?? '' ), array( '', '[]' ), true );
					$status   = ( new ChangeSetService() )->status( (string) $row['change_set_id'] );
					$approved = is_array( $status ) && 'approved' === ( $status['approval_state'] ?? '' );
					return match ( $view ) {
						'awaiting' => 'awaiting_approval' === $row['status'],
						'approved' => 'approved' === $row['status'] && $approved,
						'rollback' => $rollback,
						default    => in_array( $row['status'], array( 'cancelled', 'rolled_back' ), true ) || ( 'approved' === $row['status'] && ! $approved ),
					};
				}
			)
		);
	}

	public function approve(): void {
		$id    = sanitize_text_field( wp_unslash( (string) ( $_POST['change_set_id'] ?? '' ) ) );
		$nonce = sanitize_text_field( wp_unslash( (string) ( $_POST['_wpnonce'] ?? '' ) ) );
		check_admin_referer( 'sitepilot_approve_' . $id );
		$result = ( new ChangeSetService() )->approve( $id, $nonce );
		$url    = add_query_arg(
			array(
				'page'     => 'sitepilot-mcp',
				'tab'      => 'approvals',
				'approval' => is_wp_error( $result ) ? 'error' : 'success',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public function cancel_changeset(): void {
		$id = sanitize_text_field( wp_unslash( (string) ( $_POST['change_set_id'] ?? '' ) ) );
		check_admin_referer( 'sitepilot_cancel_' . $id );
		$result = ( new ChangeSetService() )->cancel( $id );
		$this->redirect_approval_result( is_wp_error( $result ) ? 'error' : 'success', 'archived', 'cancelled' );
	}

	public function revoke_approval(): void {
		$id          = sanitize_text_field( wp_unslash( (string) ( $_POST['change_set_id'] ?? '' ) ) );
		$approval_id = sanitize_text_field( wp_unslash( (string) ( $_POST['approval_id'] ?? '' ) ) );
		check_admin_referer( 'sitepilot_revoke_approval_' . $approval_id );
		$result = ( new ChangeSetService() )->revoke_approval( $id, $approval_id );
		$this->redirect_approval_result( is_wp_error( $result ) ? 'error' : 'success', 'awaiting', 'revoked' );
	}

	private function redirect_approval_result( string $result, string $view, string $action ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'sitepilot-mcp',
					'tab'             => 'approvals',
					'approval_view'   => $view,
					'approval'        => $result,
					'approval_action' => $action,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function revoke_grant(): void {
		if ( ! current_user_can( 'sitepilot_manage_policy' ) ) {
			wp_die( esc_html__( 'You cannot revoke SitePilot grants.', 'sitepilot-mcp' ), '', array( 'response' => 403 ) );
		}
		$id = sanitize_text_field( wp_unslash( (string) ( $_POST['grant_id'] ?? '' ) ) );
		check_admin_referer( 'sitepilot_revoke_' . $id );
		global $wpdb;
		$client_id = $wpdb->get_var( $wpdb->prepare( "SELECT client_id FROM {$wpdb->prefix}sitepilot_oauth_grants WHERE grant_id = %s", $id ) );
		$wpdb->update(
			$wpdb->prefix . 'sitepilot_oauth_grants',
			array(
				'revocation_reason' => 'administrator',
				'revoked_at'        => current_time( 'mysql', true ),
			),
			array( 'grant_id' => $id ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		$wpdb->update( $wpdb->prefix . 'sitepilot_oauth_tokens', array( 'revoked_at' => current_time( 'mysql', true ) ), array( 'grant_id' => $id ), array( '%s' ), array( '%s' ) );
		( new AuditLog() )->record(
			'oauth.grant_revoked',
			'success',
			array(
				'client_id' => is_string( $client_id ) ? $client_id : null,
				'inputs'    => array( 'grant_id' => $id ),
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=sitepilot-mcp&tab=connections' ) );
		exit;
	}

	private function render_credentials(): void {
		echo '<h2>' . esc_html__( 'Application Password credentials', 'sitepilot-mcp' ) . '</h2>';
		echo '<p>' . esc_html__( 'Unclaimed Application Passwords are read-only. A claimed credential can use only the scopes shown here for tool operations, and only a policy administrator can widen or narrow them. Change-set approval remains separate and is denied unless an administrator explicitly delegates it for non-production automation.', 'sitepilot-mcp' ) . '</p>';
		if ( ! current_user_can( 'sitepilot_manage_policy' ) ) {
			echo '<p>' . esc_html__( 'You can view SitePilot but cannot change credential policy.', 'sitepilot-mcp' ) . '</p>';
			return;
		}
		$result = sanitize_key( (string) ( $_GET['credential'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only result notice after nonce-protected actions.
		if ( in_array( $result, array( 'saved', 'revoked', 'error' ), true ) ) {
			$notice_class = 'error' === $result ? 'notice-error' : 'notice-success';
			$notice_text  = match ( $result ) {
				'saved'   => __( 'The credential grant was updated.', 'sitepilot-mcp' ),
				'revoked' => __( 'The credential and its WordPress Application Password were revoked.', 'sitepilot-mcp' ),
				default   => __( 'The credential change failed.', 'sitepilot-mcp' ),
			};
			echo '<div class="notice ' . esc_attr( $notice_class ) . ' inline"><p>' . esc_html( $notice_text ) . '</p></div>';
		}
		$rows = ( new CredentialGrantRepository() )->application_password_grants();
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No Application Password has claimed SitePilot scopes yet.', 'sitepilot-mcp' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Credential', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'WordPress user', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Scopes and controls', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Last used', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Status', 'sitepilot-mcp' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$uuid   = (string) $row['credential_uuid'];
			$scopes = json_decode( (string) $row['scopes'], true );
			$scopes = is_array( $scopes ) ? array_values( array_intersect( Scopes::ALL, $scopes ) ) : array();
			echo '<tr><td><strong>' . esc_html( (string) $row['label'] ) . '</strong><br><code>' . esc_html( $uuid ) . '</code><br><small>' . esc_html( (string) $row['created_at'] ) . '</small></td>';
			echo '<td>' . esc_html( (string) ( $row['user_login'] ? $row['user_login'] : $row['user_id'] ) ) . '</td><td>';
			if ( ! $row['revoked_at'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sitepilot_save_credential"><input type="hidden" name="credential_uuid" value="' . esc_attr( $uuid ) . '"><p><label>' . esc_html__( 'Label', 'sitepilot-mcp' ) . ' <input type="text" name="label" maxlength="191" value="' . esc_attr( (string) $row['label'] ) . '"></label></p><fieldset><legend class="screen-reader-text">' . esc_html__( 'Allowed scopes', 'sitepilot-mcp' ) . '</legend>';
				foreach ( Scopes::ALL as $scope ) {
					echo '<label class="sitepilot-scope"><input type="checkbox" name="scopes[]" value="' . esc_attr( $scope ) . '" ' . checked( in_array( $scope, $scopes, true ), true, false ) . '> <code>' . esc_html( $scope ) . '</code></label>';
				}
				echo '</fieldset>';
				if ( current_user_can( 'manage_options' ) ) {
					$delegation_disabled = 'production' === wp_get_environment_type();
					echo '<p><label><input type="checkbox" name="may_approve" value="1" ' . checked( 1, (int) ( $row['may_approve'] ?? 0 ), false ) . disabled( $delegation_disabled, true, false ) . '> <strong>' . esc_html__( 'I explicitly allow this credential to approve its own changes.', 'sitepilot-mcp' ) . '</strong></label></p><p class="description">' . esc_html__( 'Delegated approval also requires SITEPILOT_ALLOW_DELEGATED_APPROVAL=true and is always refused in production.', 'sitepilot-mcp' ) . '</p>';
				}
				wp_nonce_field( 'sitepilot_credential_' . $uuid );
				submit_button( __( 'Save credential policy', 'sitepilot-mcp' ), 'secondary small', 'submit', false );
				echo '</form>';
			}
			echo '<form class="sitepilot-revoke" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sitepilot_revoke_credential"><input type="hidden" name="credential_uuid" value="' . esc_attr( $uuid ) . '">';
			wp_nonce_field( 'sitepilot_revoke_credential_' . $uuid );
			submit_button( $row['revoked_at'] ? __( 'Remove record', 'sitepilot-mcp' ) : __( 'Revoke credential', 'sitepilot-mcp' ), 'secondary small', 'submit', false );
			echo '</form></td><td>' . esc_html( $row['last_used_at'] ? (string) $row['last_used_at'] : '—' ) . '</td><td>' . esc_html( $row['revoked_at'] ? __( 'Revoked', 'sitepilot-mcp' ) : __( 'Active', 'sitepilot-mcp' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public function save_credential(): void {
		if ( ! current_user_can( 'sitepilot_manage_policy' ) ) {
			wp_die( esc_html__( 'You cannot change credential grants.', 'sitepilot-mcp' ), '', array( 'response' => 403 ) );
		}
		$uuid = sanitize_text_field( wp_unslash( (string) ( $_POST['credential_uuid'] ?? '' ) ) );
		check_admin_referer( 'sitepilot_credential_' . $uuid );
		$posted_scopes = isset( $_POST['scopes'] ) && is_array( $_POST['scopes'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['scopes'] ) ) : array();
		$scopes        = array_values( array_unique( $posted_scopes ) );
		$label         = sanitize_text_field( wp_unslash( (string) ( $_POST['label'] ?? '' ) ) );
		$may_approve   = isset( $_POST['may_approve'] ) && current_user_can( 'manage_options' );
		$result        = ( new CredentialGrantRepository() )->update_application_password_grant( $uuid, $scopes, $label, $may_approve );
		$this->redirect_credential_result( is_wp_error( $result ) ? 'error' : 'saved' );
	}

	public function revoke_credential(): void {
		if ( ! current_user_can( 'sitepilot_manage_policy' ) ) {
			wp_die( esc_html__( 'You cannot revoke credentials.', 'sitepilot-mcp' ), '', array( 'response' => 403 ) );
		}
		$uuid = sanitize_text_field( wp_unslash( (string) ( $_POST['credential_uuid'] ?? '' ) ) );
		check_admin_referer( 'sitepilot_revoke_credential_' . $uuid );
		$result = ( new CredentialGrantRepository() )->delete_application_password_grant( $uuid );
		$this->redirect_credential_result( is_wp_error( $result ) ? 'error' : 'revoked' );
	}

	private function redirect_credential_result( string $result ): never {
		wp_safe_redirect( admin_url( 'admin.php?page=sitepilot-mcp&tab=credentials&credential=' . $result ) );
		exit;
	}

	public function save_settings(): void {
		if ( ! current_user_can( 'sitepilot_manage_policy' ) ) {
			wp_die( esc_html__( 'You cannot change SitePilot policy.', 'sitepilot-mcp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'sitepilot_save_settings' );
		update_option( 'sitepilot_mcp_cloud_opt_in', isset( $_POST['cloud_opt_in'] ) ? '1' : '0', false );
		$origin = Capabilities::sanitize_gateway_origin( sanitize_text_field( wp_unslash( (string) ( $_POST['gateway_origin'] ?? '' ) ) ) );
		update_option( Capabilities::GATEWAY_ORIGIN_OPTION, $origin, false );
		if ( current_user_can( 'manage_options' ) ) {
			$before = get_option( 'sitepilot_mcp_permanent_delete', '0' );
			$after  = isset( $_POST['permanent_delete'] ) ? '1' : '0';
			update_option( 'sitepilot_mcp_permanent_delete', $after, false );
			if ( $before !== $after ) {
				( new AuditLog() )->record( 'policy.permanent_delete', 'success', array( 'after' => array( 'enabled' => '1' === $after ) ) );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=sitepilot-mcp&tab=security&updated=1' ) );
		exit;
	}
}
