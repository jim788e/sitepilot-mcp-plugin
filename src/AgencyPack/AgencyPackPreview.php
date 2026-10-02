<?php

declare(strict_types=1);

namespace SitePilot\Mcp\AgencyPack;

/**
 * A no-billing prototype for validating agency workflows and client reports.
 *
 * The prototype deliberately uses the existing change-set engine. It does not
 * add mutation endpoints, relax policy, or enforce a paid entitlement.
 */
final class AgencyPackPreview {
	private const BRAND_NAME_OPTION  = 'sitepilot_agency_brand_name';
	private const BRAND_COLOR_OPTION = 'sitepilot_agency_brand_color';

	/** @var array<string,array{title:string,summary:string,prompt:string}> */
	private const WORKFLOWS = array(
		'content-refresh'  => array(
			'title'   => 'Refresh client page content',
			'summary' => 'Prepare requested copy or metadata updates across selected pages for review.',
			'prompt'  => 'CONTENT REFRESH — SitePilot agency workflow' . "\n\n" . 'Ask me for the client request, the exact page URLs or slugs, and the fields to change. Inspect the current site and each page first. Confirm the target pages and requested values before planning. Prepare only supported content.bulk_update actions using each page’s freshly inspected state and current site version. Show the complete before/after diff, then stop at every required WordPress approval. Never publish, alter unrequested fields, reuse a prior approval, or claim a change succeeded until execution reports success. Report changed pages, skipped pages, errors, approval status, and rollback availability.',
		),
		'campaign-section' => array(
			'title'   => 'Apply a campaign section',
			'summary' => 'Adapt an existing site template for a selected campaign page and review the proposed change.',
			'prompt'  => 'CAMPAIGN SECTION — SitePilot agency workflow' . "\n\n" . 'Ask me for the campaign brief and exact target page. Inspect the current site, target page, and available saved templates. Show the template name and target before proposing work. Use only a compatible, currently available template and supported design operations. Prefer append mode; never replace existing page content unless I explicitly ask. Use freshly inspected page and template identifiers, current site version, and a new idempotency key. Plan the exact change, show its diff and risk tier, then stop for the WordPress approval when required. Report actual execution status, unsupported elements, and rollback availability. Do not claim visual verification unless a separate visual check really ran.',
		),
	);

	public function register(): void {
		add_action( 'admin_post_sitepilot_save_agency_brand', array( $this, 'save_brand' ) );
		add_action( 'admin_post_sitepilot_agency_report_json', array( $this, 'download_report' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_sitepilot-mcp' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'sitepilot-mcp-agency-preview',
			plugins_url( 'assets/agency-preview.js', SITEPILOT_MCP_FILE ),
			array(),
			SITEPILOT_MCP_VERSION,
			true
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'sitepilot_connect' ) ) {
			wp_die( esc_html__( 'You cannot view agency workflow reports.', 'sitepilot-mcp' ), '', array( 'response' => 403 ) );
		}

		$change_set_id = sanitize_text_field( wp_unslash( (string) ( $_GET['change_set_id'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report navigation; export is independently nonce-protected.
		if ( '' !== $change_set_id ) {
			$this->render_report( $change_set_id );
			return;
		}

		$this->render_overview();
	}

	private function render_overview(): void {
		$brand_name  = (string) get_option( self::BRAND_NAME_OPTION, '' );
		$brand_color = $this->brand_color();
		$rows        = $this->recent_changes();

		echo '<h2>' . esc_html__( 'Agency Pack prototype', 'sitepilot-mcp' ) . '</h2>';
		if ( 'saved' === sanitize_key( (string) ( $_GET['branding'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice after a nonce-protected action.
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Report branding saved.', 'sitepilot-mcp' ) . '</p></div>';
		}
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Preview only · included in the free prototype', 'sitepilot-mcp' ) . '</strong> ' . esc_html__( 'These agency workflows and client reports are being evaluated. There is no purchase, license check, or paid-only capability here.', 'sitepilot-mcp' ) . '</p></div>';
		echo '<p>' . esc_html__( 'Copy a workflow into your MCP client. The agent will inspect the live site and propose work through SitePilot’s existing scopes, change plans, WordPress approvals, audit history, and supported rollback.', 'sitepilot-mcp' ) . '</p>';

		echo '<div class="sitepilot-agency-workflows">';
		foreach ( self::WORKFLOWS as $key => $workflow ) {
			echo '<section class="card"><h3>' . esc_html( $workflow['title'] ) . '</h3><p>' . esc_html( $workflow['summary'] ) . '</p>';
			echo '<label class="screen-reader-text" for="sitepilot-agency-prompt-' . esc_attr( $key ) . '">' . esc_html__( 'Workflow prompt', 'sitepilot-mcp' ) . '</label><textarea readonly rows="7" id="sitepilot-agency-prompt-' . esc_attr( $key ) . '" class="large-text code">' . esc_textarea( $workflow['prompt'] ) . '</textarea>';
			echo '<p><button type="button" class="button button-secondary sitepilot-copy-workflow" data-prompt-id="sitepilot-agency-prompt-' . esc_attr( $key ) . '">' . esc_html__( 'Copy workflow prompt', 'sitepilot-mcp' ) . '</button> <span class="sitepilot-copy-status" aria-live="polite"></span></p></section>';
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Client report branding', 'sitepilot-mcp' ) . '</h2>';
		echo '<form class="sitepilot-agency-brand" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sitepilot_save_agency_brand">';
		wp_nonce_field( 'sitepilot_save_agency_brand' );
		echo '<p><label for="sitepilot-agency-brand-name"><strong>' . esc_html__( 'Agency name', 'sitepilot-mcp' ) . '</strong></label><br><input id="sitepilot-agency-brand-name" class="regular-text" type="text" name="brand_name" maxlength="120" value="' . esc_attr( $brand_name ) . '" placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '"></p>';
		echo '<p><label for="sitepilot-agency-brand-color"><strong>' . esc_html__( 'Brand colour', 'sitepilot-mcp' ) . '</strong></label><br><input id="sitepilot-agency-brand-color" type="color" name="brand_color" value="' . esc_attr( $brand_color ) . '"></p>';
		submit_button( __( 'Save report branding', 'sitepilot-mcp' ), 'secondary' );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Recent change sets', 'sitepilot-mcp' ) . '</h2>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No change sets are available for your account yet.', 'sitepilot-mcp' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Request', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Status', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Risk', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Created', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Report', 'sitepilot-mcp' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$report_url = admin_url( 'admin.php?page=sitepilot-mcp&tab=agency&change_set_id=' . rawurlencode( (string) $row['change_set_id'] ) );
			// translators: %d: numeric risk tier of the change set.
			echo '<tr><td>' . esc_html( (string) $row['intent'] ) . '</td><td>' . esc_html( (string) $row['status'] ) . '</td><td>' . esc_html( sprintf( __( 'Tier %d', 'sitepilot-mcp' ), (int) $row['risk_tier'] ) ) . '</td><td>' . esc_html( (string) $row['created_at'] ) . '</td><td><a class="button button-secondary" href="' . esc_url( $report_url ) . '">' . esc_html__( 'View report', 'sitepilot-mcp' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	private function brand_color(): string {
		$color = sanitize_hex_color( (string) get_option( self::BRAND_COLOR_OPTION, '#ec3013' ) );
		return is_string( $color ) && '' !== $color ? $color : '#ec3013';
	}

	private function agency_name(): string {
		$name = (string) get_option( self::BRAND_NAME_OPTION, '' );
		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	/** @return list<array<string,mixed>> */
	private function recent_changes(): array {
		global $wpdb;
		if ( current_user_can( 'manage_options' ) || current_user_can( 'sitepilot_view_audit' ) ) {
			$rows = $wpdb->get_results( "SELECT change_set_id,intent,status,risk_tier,actor_user_id,created_at FROM {$wpdb->prefix}sitepilot_changesets ORDER BY created_at DESC LIMIT 50", ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT change_set_id,intent,status,risk_tier,actor_user_id,created_at FROM {$wpdb->prefix}sitepilot_changesets WHERE actor_user_id=%d ORDER BY created_at DESC LIMIT 50", get_current_user_id() ), ARRAY_A );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,mixed>|null */
	private function report( string $change_set_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sitepilot_changesets WHERE change_set_id=%s", $change_set_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) || ( get_current_user_id() !== (int) $row['actor_user_id'] && ! current_user_can( 'sitepilot_view_audit' ) && ! current_user_can( 'manage_options' ) ) ) {
			return null;
		}

		$approvals          = $wpdb->get_results(
			$wpdb->prepare( "SELECT approver_user_id,input_hash,created_at,expires_at,used_at,revoked_at,revoked_by_user_id FROM {$wpdb->prefix}sitepilot_approvals WHERE change_set_id=%s ORDER BY created_at ASC", $change_set_id ),
			ARRAY_A
		);
		$events             = $wpdb->get_results(
			$wpdb->prepare( "SELECT actor_user_id,event,result,created_at FROM {$wpdb->prefix}sitepilot_audit WHERE change_set_id=%s ORDER BY created_at ASC", $change_set_id ),
			ARRAY_A
		);
		$rollback_data      = json_decode( (string) ( $row['rollback_data'] ?? '' ), true );
		$diff               = json_decode( (string) ( $row['diff'] ?? '' ), true );
		$actions            = json_decode( (string) ( $row['actions'] ?? '' ), true );
		$validation         = json_decode( (string) ( $row['validation_results'] ?? '' ), true );
		$rollback_available = 'completed' === (string) $row['status'] && is_array( $rollback_data ) && array() !== $rollback_data;

		$audit_events = array();
		foreach ( is_array( $events ) ? $events : array() as $event ) {
			$audit_events[] = array(
				'event'      => (string) $event['event'],
				'result'     => (string) $event['result'],
				'actor'      => $this->user_label( (int) $event['actor_user_id'] ),
				'created_at' => (string) $event['created_at'],
			);
		}

		return array(
			'report_version'     => 1,
			'generated_at'       => current_time( 'mysql', true ),
			'timezone'           => 'UTC',
			'site'               => get_bloginfo( 'name' ),
			'site_url'           => home_url( '/' ),
			'agency_name'        => $this->agency_name(),
			'agency_color'       => $this->brand_color(),
			'change_set_id'      => (string) $row['change_set_id'],
			'request'            => (string) $row['intent'],
			'status'             => (string) $row['status'],
			'risk_tier'          => (int) $row['risk_tier'],
			'created_at'         => (string) $row['created_at'],
			'updated_at'         => (string) $row['updated_at'],
			'actor'              => $this->user_label( (int) $row['actor_user_id'] ),
			'actions'            => is_array( $actions ) ? $actions : array(),
			'diff'               => is_array( $diff ) ? $diff : array(),
			'validation_results' => is_array( $validation ) ? $validation : array(),
			'rollback_available' => $rollback_available,
			'rollback_summary'   => $this->rollback_summary( (string) $row['status'], $rollback_available ),
			'approvals'          => array_map( fn ( array $approval ): array => $this->format_approval( $approval, (string) $row['input_hash'] ), is_array( $approvals ) ? $approvals : array() ),
			'audit_events'       => $audit_events,
		);
	}

	private function rollback_summary( string $status, bool $available ): string {
		if ( $available ) {
			return __( 'Available for this completed change', 'sitepilot-mcp' );
		}
		return match ( $status ) {
			'rolled_back' => __( 'Rollback completed', 'sitepilot-mcp' ),
			'awaiting_approval', 'approved', 'planned', 'executing' => __( 'No completed change to roll back yet', 'sitepilot-mcp' ),
			'cancelled' => __( 'Change set cancelled', 'sitepilot-mcp' ),
			'failed' => __( 'Unavailable or failed; see execution history', 'sitepilot-mcp' ),
			default => __( 'No rollback is currently available', 'sitepilot-mcp' ),
		};
	}

	/** @param array<string,mixed> $approval @return array<string,string> */
	private function format_approval( array $approval, string $change_hash ): array {
		$expires_at = (string) ( $approval['expires_at'] ?? '' );
		$expires_ts = '' !== $expires_at ? strtotime( $expires_at . ' UTC' ) : false;
		$state      = ! empty( $approval['revoked_at'] )
			? __( 'Revoked', 'sitepilot-mcp' )
			: ( ! empty( $approval['used_at'] )
				? __( 'Consumed', 'sitepilot-mcp' )
				: ( false === $expires_ts
					? __( 'Unknown', 'sitepilot-mcp' )
					: ( ! hash_equals( $change_hash, (string) ( $approval['input_hash'] ?? '' ) )
						? __( 'Change binding mismatch', 'sitepilot-mcp' )
						: ( $expires_ts <= time() ? __( 'Expired', 'sitepilot-mcp' ) : __( 'Unused at report time', 'sitepilot-mcp' ) ) ) ) );
		return array(
			'approver'   => $this->user_label( (int) $approval['approver_user_id'] ),
			'state'      => $state,
			'created_at' => (string) $approval['created_at'],
			'expires_at' => (string) $approval['expires_at'],
			'used_at'    => (string) ( $approval['used_at'] ?? '' ),
			'revoked_at' => (string) ( $approval['revoked_at'] ?? '' ),
			'revoked_by' => empty( $approval['revoked_by_user_id'] ) ? '' : $this->user_label( (int) $approval['revoked_by_user_id'] ),
		);
	}

	private function user_label( int $user_id ): string {
		$user = get_userdata( $user_id );
		// translators: %d: WordPress user ID.
		return $user instanceof \WP_User ? (string) $user->display_name : sprintf( __( 'WordPress user #%d', 'sitepilot-mcp' ), $user_id );
	}

	private function render_report( string $change_set_id ): void {
		$report = $this->report( $change_set_id );
		if ( ! is_array( $report ) ) {
			wp_die( esc_html__( 'This change set was not found or you cannot view its report.', 'sitepilot-mcp' ), '', array( 'response' => 404 ) );
		}

		$json_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=sitepilot_agency_report_json&change_set_id=' . rawurlencode( $change_set_id ) ),
			'sitepilot_agency_report_' . $change_set_id
		);
		echo '<article class="sitepilot-agency-report" style="--sitepilot-report-accent:' . esc_attr( $report['agency_color'] ) . '">';
		echo '<div class="sitepilot-agency-report__controls"><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=sitepilot-mcp&tab=agency' ) ) . '">' . esc_html__( 'Back to Agency Pack', 'sitepilot-mcp' ) . '</a> <a class="button button-secondary" href="' . esc_url( $json_url ) . '">' . esc_html__( 'Download JSON', 'sitepilot-mcp' ) . '</a> <button class="button button-primary sitepilot-print-report" type="button">' . esc_html__( 'Print or save PDF', 'sitepilot-mcp' ) . '</button></div>';
		echo '<header class="sitepilot-agency-report__header"><p>' . esc_html( $report['agency_name'] ) . '</p><h2>' . esc_html__( 'Client change report', 'sitepilot-mcp' ) . '</h2><p>' . esc_html( (string) $report['site'] ) . ' · ' . esc_html( (string) $report['site_url'] ) . '</p><p>' . esc_html__( 'Report generated (UTC):', 'sitepilot-mcp' ) . ' ' . esc_html( (string) $report['generated_at'] ) . '</p></header>';
		// translators: %d: numeric risk tier of the change set.
		echo '<dl class="sitepilot-agency-report__summary"><div><dt>' . esc_html__( 'Request', 'sitepilot-mcp' ) . '</dt><dd>' . esc_html( (string) $report['request'] ) . '</dd></div><div><dt>' . esc_html__( 'Current change-set status', 'sitepilot-mcp' ) . '</dt><dd>' . esc_html( (string) $report['status'] ) . '</dd></div><div><dt>' . esc_html__( 'Risk', 'sitepilot-mcp' ) . '</dt><dd>' . esc_html( sprintf( __( 'Tier %d', 'sitepilot-mcp' ), (int) $report['risk_tier'] ) ) . '</dd></div><div><dt>' . esc_html__( 'Requested by', 'sitepilot-mcp' ) . '</dt><dd>' . esc_html( (string) $report['actor'] ) . '</dd></div><div><dt>' . esc_html__( 'Created (UTC)', 'sitepilot-mcp' ) . '</dt><dd>' . esc_html( (string) $report['created_at'] ) . '</dd></div><div><dt>' . esc_html__( 'Rollback', 'sitepilot-mcp' ) . '</dt><dd>' . esc_html( (string) $report['rollback_summary'] ) . '</dd></div></dl>';

		$this->render_json_section( __( 'Recorded changes', 'sitepilot-mcp' ), $report['diff'] );
		if ( array() !== $report['approvals'] ) {
			echo '<h3>' . esc_html__( 'Approval history', 'sitepilot-mcp' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Approver', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'State at report time', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Expires (UTC)', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Recorded (UTC)', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Used (UTC)', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Revoked (UTC)', 'sitepilot-mcp' ) . '</th></tr></thead><tbody>';
			foreach ( $report['approvals'] as $approval ) {
				echo '<tr><td>' . esc_html( $approval['approver'] ) . '</td><td>' . esc_html( $approval['state'] ) . '</td><td>' . esc_html( $approval['expires_at'] ) . '</td><td>' . esc_html( $approval['created_at'] ) . '</td><td>' . esc_html( '' !== (string) $approval['used_at'] ? $approval['used_at'] : '—' ) . '</td><td>' . esc_html( $approval['revoked_at'] ? $approval['revoked_at'] . ' · ' . $approval['revoked_by'] : '—' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<h3>' . esc_html__( 'Approval history', 'sitepilot-mcp' ) . '</h3><p>' . esc_html__( 'No approval record is attached to this change set.', 'sitepilot-mcp' ) . '</p>';
		}
		$this->render_audit_events( $report['audit_events'] );
		$this->render_json_section( __( 'Validation record', 'sitepilot-mcp' ), $report['validation_results'] );
		echo '<p class="description">' . esc_html__( 'This report reflects SitePilot records at generation time. A recorded API outcome is not a visual review or a guarantee of the rendered result. Rollback depends on operation support and can fail.', 'sitepilot-mcp' ) . '</p>';
		echo '</article>';
	}

	/** @param array<mixed> $value */
	private function render_json_section( string $heading, array $value ): void {
		echo '<section class="sitepilot-agency-report__section"><h3>' . esc_html( $heading ) . '</h3>';
		if ( array() === $value ) {
			echo '<p>' . esc_html__( 'No details were recorded.', 'sitepilot-mcp' ) . '</p></section>';
			return;
		}
		echo '<pre>' . esc_html( (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></section>';
	}

	/** @param list<array<string,string>> $events */
	private function render_audit_events( array $events ): void {
		echo '<h3>' . esc_html__( 'Execution history', 'sitepilot-mcp' ) . '</h3>';
		if ( array() === $events ) {
			echo '<p>' . esc_html__( 'No execution events are recorded for this change set.', 'sitepilot-mcp' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Event', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Result', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Actor', 'sitepilot-mcp' ) . '</th><th>' . esc_html__( 'Time (UTC)', 'sitepilot-mcp' ) . '</th></tr></thead><tbody>';
		foreach ( $events as $event ) {
			echo '<tr><td>' . esc_html( $event['event'] ) . '</td><td>' . esc_html( $event['result'] ) . '</td><td>' . esc_html( $event['actor'] ) . '</td><td>' . esc_html( $event['created_at'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public function save_brand(): void {
		if ( ! current_user_can( 'sitepilot_manage_policy' ) ) {
			wp_die( esc_html__( 'You cannot change agency report branding.', 'sitepilot-mcp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'sitepilot_save_agency_brand' );
		// Array values (for example brand_name[]) are rejected rather than cast to the text "Array".
		$name  = isset( $_POST['brand_name'] ) && is_string( $_POST['brand_name'] ) ? sanitize_text_field( wp_unslash( $_POST['brand_name'] ) ) : '';
		$color = isset( $_POST['brand_color'] ) && is_string( $_POST['brand_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['brand_color'] ) ) : null;
		update_option( self::BRAND_NAME_OPTION, wp_html_excerpt( $name, 120, '' ), false );
		update_option( self::BRAND_COLOR_OPTION, is_string( $color ) && '' !== $color ? $color : '#ec3013', false );
		wp_safe_redirect( admin_url( 'admin.php?page=sitepilot-mcp&tab=agency&branding=saved' ) );
		exit;
	}

	public function download_report(): void {
		if ( ! current_user_can( 'sitepilot_connect' ) ) {
			wp_die( esc_html__( 'You cannot download agency workflow reports.', 'sitepilot-mcp' ), '', array( 'response' => 403 ) );
		}
		$change_set_id = sanitize_text_field( wp_unslash( (string) ( $_GET['change_set_id'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce check follows immediately.
		check_admin_referer( 'sitepilot_agency_report_' . $change_set_id );
		$report = $this->report( $change_set_id );
		if ( ! is_array( $report ) ) {
			wp_die( esc_html__( 'This change set was not found or you cannot view its report.', 'sitepilot-mcp' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sitepilot-client-report-' . sanitize_file_name( $change_set_id ) . '.json"' );
		echo (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body, sent with application/json content type.
		exit;
	}
}
