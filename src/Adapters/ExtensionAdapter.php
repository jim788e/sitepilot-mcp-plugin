<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

final class ExtensionAdapter {
	/** @param array<string,mixed> $action @return array<string,mixed>|\WP_Error */
	public function preview( array $action ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$operation = (string) $action['operation'];
		$target    = sanitize_text_field( (string) $action['target'] );
		$input     = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		if ( 'extension.install' === $operation ) {
			return array(
				'operation' => $operation,
				'target'    => $target,
				'before'    => null,
				'after'     => array(
					'slug'    => sanitize_key( $target ),
					'source'  => 'wordpress.org',
					'version' => sanitize_text_field( (string) ( $input['version'] ?? 'latest' ) ),
				),
			);
		}
		if ( 'extension.activate' === $operation ) {
			return array(
				'operation' => $operation,
				'target'    => $target,
				'before'    => array( 'active' => is_plugin_active( $target ) ),
				'after'     => array( 'active' => true ),
			);
		}
		if ( 'theme.activate' === $operation ) {
			$theme = wp_get_theme( $target );
			if ( ! $theme->exists() || $theme->errors() ) {
				return new \WP_Error( 'sitepilot_target_missing', __( 'The target theme is not installed or is invalid.', 'sitepilot-mcp' ) );
			}
			return array(
				'operation' => $operation,
				'target'    => $target,
				'before'    => array( 'stylesheet' => get_stylesheet() ),
				'after'     => array( 'stylesheet' => $target ),
			);
		}
		if ( 'core.update' === $operation ) {
			if ( empty( $input['backup_confirmed'] ) ) {
				return new \WP_Error( 'sitepilot_backup_required', __( 'A verified hosting or off-site backup is required before a core update.', 'sitepilot-mcp' ) );
			}
			return array(
				'operation' => $operation,
				'target'    => $target,
				'before'    => array( 'version' => get_bloginfo( 'version' ) ),
				'after'     => array(
					'version'            => $target,
					'rollback_available' => false,
				),
			);
		}
		return new \WP_Error( 'sitepilot_adapter_unavailable', __( 'The extensions adapter does not support this action.', 'sitepilot-mcp' ) );
	}

	/** @param array<string,mixed> $action @return array<string,mixed>|\WP_Error */
	public function execute( array $action ) {
		$preview = $this->preview( $action );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$operation = (string) $action['operation'];
		$target    = sanitize_text_field( (string) $action['target'] );
		if ( 'extension.install' === $operation ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$info = plugins_api(
				'plugin_information',
				array(
					'slug'   => sanitize_key( $target ),
					'fields' => array( 'sections' => false ),
				)
			);
			if ( is_wp_error( $info ) || empty( $info->download_link ) || 'downloads.wordpress.org' !== wp_parse_url( $info->download_link, PHP_URL_HOST ) ) {
				return new \WP_Error( 'sitepilot_plugin_source_blocked', __( 'Only packages resolved through WordPress.org are installable.', 'sitepilot-mcp' ) );
			}
			$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
			$result   = $upgrader->install( $info->download_link );
			if ( is_wp_error( $result ) || ! $result ) {
				return is_wp_error( $result ) ? $result : new \WP_Error( 'sitepilot_install_failed', __( 'Plugin installation failed.', 'sitepilot-mcp' ) );
			}
			$plugin_file = (string) $upgrader->plugin_info();
			return array(
				'result'   => array( 'plugin' => $plugin_file ),
				'rollback' => array(
					'operation' => 'delete_installed_plugin',
					'plugin'    => $plugin_file,
				),
			);
		}
		if ( 'extension.activate' === $operation ) {
			if ( str_contains( $target, 'sitepilot-mcp' ) ) {
				return array(
					'result'   => array(
						'plugin'            => $target,
						'already_protected' => true,
					),
					'rollback' => array( 'operation' => 'noop' ),
				);
			}
			$was_active = is_plugin_active( $target );
			$result     = activate_plugin( $target );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array(
				'result'   => array(
					'plugin' => $target,
					'active' => true,
				),
				'rollback' => $was_active ? array( 'operation' => 'noop' ) : array(
					'operation' => 'deactivate_plugin',
					'plugin'    => $target,
				),
			);
		}
		if ( 'theme.activate' === $operation ) {
			$before = get_stylesheet();
			switch_theme( $target );
			if ( get_stylesheet() !== $target ) {
				switch_theme( $before );
				return new \WP_Error( 'sitepilot_theme_activation_failed', __( 'WordPress did not activate the requested theme.', 'sitepilot-mcp' ) );
			}
			$health = $this->frontend_health_check();
			if ( is_wp_error( $health ) ) {
				switch_theme( $before );
				return $health;
			}
			return array(
				'result'   => array(
					'stylesheet' => get_stylesheet(),
					'health'     => $health,
				),
				'rollback' => array(
					'operation'  => 'restore_theme',
					'stylesheet' => $before,
				),
			); }
		if ( 'core.update' === $operation ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			wp_version_check();
			$updates  = get_core_updates( array( 'dismissed' => false ) );
			$selected = null;
			foreach ( is_array( $updates ) ? $updates : array() as $update ) {
				if ( (string) $update->current === $target ) {
					$selected = $update;
				}
			}
			if ( ! $selected ) {
				return new \WP_Error( 'sitepilot_update_unavailable', __( 'The requested WordPress core update is not available.', 'sitepilot-mcp' ) );
			}
			$result = ( new \Core_Upgrader( new \Automatic_Upgrader_Skin() ) )->upgrade( $selected );
			if ( is_wp_error( $result ) || ! $result ) {
				return is_wp_error( $result ) ? $result : new \WP_Error( 'sitepilot_update_failed', __( 'WordPress core update failed.', 'sitepilot-mcp' ) );
			}
			return array(
				'result'   => array(
					'version'            => get_bloginfo( 'version' ),
					'rollback_available' => false,
				),
				'rollback' => array( 'operation' => 'manual_core_restore' ),
			);
		}
		return new \WP_Error( 'sitepilot_adapter_unavailable', __( 'The extensions adapter does not support this action.', 'sitepilot-mcp' ) );
	}

	/** @return array{url:string,status_code:int,status?:string}|\WP_Error */
	private function frontend_health_check() {
		if ( '1' !== get_option( 'sitepilot_mcp_cloud_opt_in', '0' ) ) {
			return array(
				'url'         => home_url( '/' ),
				'status_code' => 0,
				'status'      => 'skipped_until_cloud_opt_in',
			);
		}
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 32 * KB_IN_BYTES,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'sitepilot_theme_health_failed', __( 'The frontend loopback health check failed after theme activation.', 'sitepilot-mcp' ) );
		}
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 400 ) {
			return new \WP_Error( 'sitepilot_theme_health_failed', __( 'The frontend health check returned an unhealthy HTTP status after theme activation.', 'sitepilot-mcp' ), array( 'status_code' => $status_code ) );
		}
		return array(
			'url'         => home_url( '/' ),
			'status_code' => $status_code,
		);
	}

	/** @param array<string,mixed> $rollback @return true|\WP_Error */
	public function rollback( array $rollback ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		switch ( $rollback['operation'] ?? '' ) {
			case 'noop':
				return true;
			case 'deactivate_plugin':
				if ( str_contains( (string) $rollback['plugin'], 'sitepilot-mcp' ) ) {
					return new \WP_Error( 'sitepilot_self_protection', __( 'SitePilot cannot deactivate itself.', 'sitepilot-mcp' ) );
				} deactivate_plugins( (string) $rollback['plugin'], true );
				return true;
			case 'delete_installed_plugin':
				if ( str_contains( (string) $rollback['plugin'], 'sitepilot-mcp' ) ) {
					return new \WP_Error( 'sitepilot_self_protection', __( 'SitePilot cannot uninstall itself.', 'sitepilot-mcp' ) );
				} require_once ABSPATH . 'wp-admin/includes/file.php';
				$result = delete_plugins( array( (string) $rollback['plugin'] ) );
				return is_wp_error( $result ) ? $result : true;
			case 'restore_theme':
				switch_theme( (string) $rollback['stylesheet'] );
				return true;
			case 'manual_core_restore':
				return new \WP_Error( 'sitepilot_manual_rollback_required', __( 'Core rollback requires the verified hosting or off-site backup.', 'sitepilot-mcp' ) );
		}
		return new \WP_Error( 'sitepilot_rollback_unknown', __( 'Unknown extension rollback operation.', 'sitepilot-mcp' ) );
	}
}
