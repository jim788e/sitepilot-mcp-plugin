<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Changes;

use SitePilot\Mcp\Infrastructure\AuditLog;
use SitePilot\Mcp\Infrastructure\Ids;
use SitePilot\Mcp\Infrastructure\SiteVersion;
use SitePilot\Mcp\Policy\RiskEngine;
use SitePilot\Mcp\Policy\ApprovalGuard;
use SitePilot\Mcp\Policy\ScopeGuard;

final class ChangeSetService {
	private ActionExecutor $executor;
	private SiteVersion $version;
	private AuditLog $audit;

	public function __construct() {
		$this->executor = new ActionExecutor();
		$this->version  = new SiteVersion();
		$this->audit    = new AuditLog();
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function plan( array $input ) {
		global $wpdb;
		$valid = $this->validate_envelope( $input );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$current = $this->version->current();
		if ( ! hash_equals( $current, (string) $input['expected_version'] ) ) {
			return new \WP_Error( 'sitepilot_version_conflict', __( 'The site changed after inspection. Inspect again before planning.', 'sitepilot-mcp' ), array( 'current_version' => $current ) );
		}
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sitepilot_changesets WHERE actor_user_id = %d AND idempotency_key = %s", get_current_user_id(), $input['idempotency_key'] ), ARRAY_A );
		if ( is_array( $existing ) ) {
			$existing_request = array(
				'expected_version' => $existing['expected_version'],
				'intent'           => $existing['intent'],
				'actions'          => json_decode( (string) $existing['actions'], true ) ? json_decode( (string) $existing['actions'], true ) : array(),
			);
			$current_request  = array(
				'expected_version' => $input['expected_version'],
				'intent'           => sanitize_textarea_field( (string) $input['intent'] ),
				'actions'          => $input['actions'],
			);
			return hash_equals( Ids::canonical_hash( $existing_request ), Ids::canonical_hash( $current_request ) ) ? $this->format( $existing ) : new \WP_Error( 'sitepilot_idempotency_conflict', __( 'That idempotency key was already used for a different plan.', 'sitepilot-mcp' ) );
		}
		$actions      = $input['actions'];
		$irreversible = array_filter( $actions, static fn ( array $action ): bool => in_array( (string) ( $action['operation'] ?? '' ), array( 'commerce.refund', 'core.update', 'content.permanent_delete' ), true ) );
		if ( $irreversible && count( $actions ) > 1 ) {
			return new \WP_Error( 'sitepilot_irreversible_batch_blocked', __( 'Irreversible operations must be planned as a single-action change set.', 'sitepilot-mcp' ) );
		}
		$policy = ( new RiskEngine() )->classify( $actions );
		if ( is_wp_error( $policy ) ) {
			return $policy;
		}
		$guard = new ScopeGuard();
		foreach ( $policy['scopes'] as $scope ) {
			$allowed = $guard->require_scope( $scope );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}
		$diff = array();
		foreach ( $actions as $action ) {
			$item = $this->executor->preview( $action );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$diff[] = $item;
		}
		$id         = Ids::uuid();
		$input_hash = Ids::canonical_hash(
			array(
				'expected_version' => $current,
				'actions'          => $actions,
				'diff'             => $diff,
			)
		);
		$status     = $policy['tier'] >= 2 ? 'awaiting_approval' : 'approved';
		$now        = current_time( 'mysql', true );
		$wpdb->insert(
			$wpdb->prefix . 'sitepilot_changesets',
			array(
				'change_set_id'    => $id,
				'idempotency_key'  => $input['idempotency_key'],
				'actor_user_id'    => get_current_user_id(),
				'status'           => $status,
				'risk_tier'        => $policy['tier'],
				'expected_version' => $current,
				'input_hash'       => $input_hash,
				'intent'           => sanitize_textarea_field( (string) $input['intent'] ),
				'actions'          => wp_json_encode( $actions ),
				'diff'             => wp_json_encode( $diff ),
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$row = $this->find( $id );
		$this->audit->record(
			'changeset.planned',
			'success',
			array(
				'change_set_id' => $id,
				'inputs'        => $input,
			)
		);
		return $this->format( $row );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function approve( string $change_set_id, string $nonce = '' ) {
		global $wpdb;
		$authorization = ( new ApprovalGuard() )->authorize( $change_set_id, $nonce );
		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}
		$row = $this->find( $change_set_id, false );
		if ( ! $row ) {
			return new \WP_Error( 'sitepilot_not_found', __( 'Change set not found.', 'sitepilot-mcp' ) );
		}
		if ( ! in_array( $row['status'], array( 'awaiting_approval', 'approved', 'completed' ), true ) ) {
			return new \WP_Error( 'sitepilot_invalid_state', __( 'This change set is not awaiting an execution or rollback approval.', 'sitepilot-mcp' ) );
		}
		if ( 'completed' === $row['status'] && array() === $this->rollback_data( $row ) ) {
			return new \WP_Error( 'sitepilot_no_rollback', __( 'This irreversible change set has no rollback operation.', 'sitepilot-mcp' ) );
		}
		$cap = (int) $row['risk_tier'] >= 3 ? 'sitepilot_approve_sensitive' : 'sitepilot_approve_standard';
		if ( ! current_user_can( $cap ) || ( (int) $row['risk_tier'] >= 3 && ! current_user_can( 'manage_options' ) ) ) {
			return new \WP_Error( 'sitepilot_approval_denied', __( 'Your account cannot approve this risk tier.', 'sitepilot-mcp' ) );
		}
		$existing = $this->latest_valid_approval( $row );
		if ( is_array( $existing ) ) {
			return array(
				'approval_id' => $existing['approval_id'],
				'expires_in'  => max( 0, strtotime( (string) $existing['expires_at'] ) - time() ),
			);
		}
		$id = Ids::uuid();
		$wpdb->insert(
			$wpdb->prefix . 'sitepilot_approvals',
			array(
				'approval_id'      => $id,
				'change_set_id'    => $change_set_id,
				'approver_user_id' => get_current_user_id(),
				'input_hash'       => $row['input_hash'],
				'expires_at'       => gmdate( 'Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS ),
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( 'awaiting_approval' === $row['status'] ) {
			$wpdb->update(
				$wpdb->prefix . 'sitepilot_changesets',
				array(
					'status'     => 'approved',
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'change_set_id' => $change_set_id ),
				array( '%s', '%s' ),
				array( '%s' )
			);
		}
		$this->audit->record(
			'changeset.approved',
			'success',
			array(
				'change_set_id' => $change_set_id,
				'client_id'     => 'delegated' === $authorization['channel'] ? 'app-password:' . $authorization['credential_uuid'] : null,
				'after'         => array(
					'actor_user_id'   => get_current_user_id(),
					'credential_type' => $authorization['credential_type'],
					'credential_uuid' => $authorization['credential_uuid'],
					'change_set_hash' => (string) $row['input_hash'],
					'channel'         => $authorization['channel'],
				),
			)
		);
		return array(
			'approval_id' => $id,
			'expires_in'  => 30 * MINUTE_IN_SECONDS,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function cancel( string $change_set_id ) {
		global $wpdb;
		if ( ! current_user_can( 'sitepilot_approve' ) ) {
			return new \WP_Error( 'sitepilot_cancel_denied', __( 'Your account cannot cancel SitePilot change sets.', 'sitepilot-mcp' ) );
		}
		$row = $this->find( $change_set_id, false );
		if ( ! $row ) {
			return new \WP_Error( 'sitepilot_not_found', __( 'Change set not found.', 'sitepilot-mcp' ) );
		}
		if ( ! in_array( $row['status'], array( 'awaiting_approval', 'approved', 'failed' ), true ) ) {
			return new \WP_Error( 'sitepilot_invalid_state', __( 'Only an unexecuted change set can be cancelled.', 'sitepilot-mcp' ) );
		}
		$now = current_time( 'mysql', true );
		$wpdb->update(
			$wpdb->prefix . 'sitepilot_changesets',
			array(
				'status'       => 'cancelled',
				'cancelled_at' => $now,
				'archived_at'  => $now,
				'updated_at'   => $now,
			),
			array( 'change_set_id' => $change_set_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);
		$this->audit->record(
			'changeset.cancelled',
			'success',
			array(
				'change_set_id' => $change_set_id,
				'after'         => array( 'status' => 'cancelled' ),
			)
		);
		return $this->status( $change_set_id );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function revoke_approval( string $change_set_id, string $approval_id = '' ) {
		global $wpdb;
		if ( ! current_user_can( 'sitepilot_approve' ) ) {
			return new \WP_Error( 'sitepilot_revoke_denied', __( 'Your account cannot revoke SitePilot approvals.', 'sitepilot-mcp' ) );
		}
		$row = $this->find( $change_set_id, false );
		if ( ! $row || ! in_array( $row['status'], array( 'approved', 'completed' ), true ) ) {
			return new \WP_Error( 'sitepilot_invalid_state', __( 'This change set has no revocable approval.', 'sitepilot-mcp' ) );
		}
		$approval = '' !== $approval_id
			? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sitepilot_approvals WHERE approval_id = %s AND change_set_id = %s", $approval_id, $change_set_id ), ARRAY_A )
			: $this->latest_valid_approval( $row );
		if ( ! is_array( $approval ) || ! empty( $approval['used_at'] ) || ! empty( $approval['revoked_at'] ) ) {
			return new \WP_Error( 'sitepilot_approval_required', __( 'That approval is unavailable or already consumed.', 'sitepilot-mcp' ) );
		}
		$wpdb->update(
			$wpdb->prefix . 'sitepilot_approvals',
			array(
				'revoked_at'         => current_time( 'mysql', true ),
				'revoked_by_user_id' => get_current_user_id(),
			),
			array( 'approval_id' => $approval['approval_id'] ),
			array( '%s', '%d' ),
			array( '%s' )
		);
		if ( 'approved' === $row['status'] ) {
			$wpdb->update(
				$wpdb->prefix . 'sitepilot_changesets',
				array(
					'status'     => 'awaiting_approval',
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'change_set_id' => $change_set_id ),
				array( '%s', '%s' ),
				array( '%s' )
			);
		}
		$this->audit->record(
			'approval.revoked',
			'success',
			array(
				'change_set_id' => $change_set_id,
				'after'         => array(
					'approval_id' => $approval['approval_id'],
					'revoked'     => true,
				),
			)
		);
		return $this->status( $change_set_id );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function execute( array $input ) {
		global $wpdb;
		$valid = $this->validate_envelope( $input );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$row = $this->find( (string) ( $input['change_set_id'] ?? '' ) );
		if ( ! $row ) {
			return new \WP_Error( 'sitepilot_not_found', __( 'Change set not found.', 'sitepilot-mcp' ) );
		}
		if ( 'completed' === $row['status'] ) {
			return $this->format( $row );
		}
		if ( ! in_array( $row['status'], array( 'approved', 'failed' ), true ) ) {
			return new \WP_Error( 'sitepilot_invalid_state', __( 'The change set is not approved for execution.', 'sitepilot-mcp' ) );
		}
		if ( ! hash_equals( (string) $row['expected_version'], (string) $input['expected_version'] ) || ! hash_equals( $this->version->current(), (string) $input['expected_version'] ) ) {
			return new \WP_Error( 'sitepilot_version_conflict', __( 'The target site version changed. Approval is no longer valid.', 'sitepilot-mcp' ) );
		}
		if ( (int) $row['risk_tier'] >= 2 ) {
			$approval = $this->valid_approval( $row, (string) ( $input['approval_id'] ?? '' ) );
			if ( is_wp_error( $approval ) ) {
				return $approval;
			}
		}
		$cached = $this->claim_idempotency( 'execute', $input );
		if ( is_wp_error( $cached ) ) {
			return $cached;
		}
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$wpdb->update(
			$wpdb->prefix . 'sitepilot_changesets',
			array(
				'status'     => 'executing',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'change_set_id' => $row['change_set_id'] ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		$rollbacks         = array();
		$action_validation = array();
		foreach ( json_decode( (string) $row['actions'], true ) ? json_decode( (string) $row['actions'], true ) : array() as $action ) {
			$result = $this->executor->execute( $action );
			if ( is_wp_error( $result ) ) {
				$rollback_result = $this->rollback_items( $rollbacks );
				$error_data      = $result->get_error_data();
				$validation      = array(
					array(
						'check'   => 'execution',
						'status'  => 'failed',
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
						'details' => is_array( $error_data ) ? $error_data : array(),
					),
					array(
						'check'   => 'automatic_rollback',
						'status'  => is_wp_error( $rollback_result ) ? 'failed' : 'passed',
						'message' => is_wp_error( $rollback_result ) ? $rollback_result->get_error_message() : '',
					),
				);
				$wpdb->update(
					$wpdb->prefix . 'sitepilot_changesets',
					array(
						'status'             => 'failed',
						'rollback_data'      => wp_json_encode( $rollbacks ),
						'validation_results' => wp_json_encode( $validation ),
						'updated_at'         => current_time( 'mysql', true ),
					),
					array( 'change_set_id' => $row['change_set_id'] )
				);
				$this->release_idempotency( 'execute', $input );
				$this->audit->record(
					'changeset.execute',
					'failed_rolled_back',
					array(
						'change_set_id' => $row['change_set_id'],
						'inputs'        => $input,
					)
				);
				return $result;
			}
			if ( ! empty( $result['rollback'] ) ) {
				$rollbacks[] = $result['rollback'];
			}
			$action_validation[] = array(
				'check'     => 'action_execution',
				'status'    => 'passed',
				'operation' => (string) ( $action['operation'] ?? '' ),
				'target'    => (string) ( $action['target'] ?? '' ),
				'value'     => is_array( $result['result'] ?? null ) ? $result['result'] : array(),
			);
			if ( isset( $result['result']['health'] ) && is_array( $result['result']['health'] ) ) {
				$health              = $result['result']['health'];
				$action_validation[] = array(
					'check'  => 'frontend_health',
					'status' => isset( $health['status'] ) ? (string) $health['status'] : 'passed',
					'value'  => $health,
				);
			}
		}
		$validation = array_merge(
			array(
				array(
					'check'  => 'wordpress_runtime',
					'status' => 'passed',
				),
				array(
					'check'  => 'site_version',
					'status' => 'passed',
					'value'  => $this->version->current(),
				),
			),
			$action_validation
		);
		$wpdb->update(
			$wpdb->prefix . 'sitepilot_changesets',
			array(
				'status'             => 'completed',
				'rollback_data'      => wp_json_encode( $rollbacks ),
				'validation_results' => wp_json_encode( $validation ),
				'updated_at'         => current_time( 'mysql', true ),
			),
			array( 'change_set_id' => $row['change_set_id'] )
		);
		$this->version->bump();
		if ( isset( $approval ) && is_array( $approval ) ) {
			$wpdb->update( $wpdb->prefix . 'sitepilot_approvals', array( 'used_at' => current_time( 'mysql', true ) ), array( 'approval_id' => $approval['approval_id'] ) );
		}
		$audit_context = array(
			'change_set_id' => $row['change_set_id'],
			'inputs'        => $input,
		);
		if ( $rollbacks ) {
			$audit_context['rollback_ref'] = $row['change_set_id'];
		}
		$this->audit->record( 'changeset.execute', 'success', $audit_context );
		$response = $this->status( (string) $row['change_set_id'] );
		if ( is_array( $response ) ) {
			$this->complete_idempotency( 'execute', $input, $response );
		}
		return $response;
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public function rollback_change( array $input ) {
		global $wpdb;
		$valid = $this->validate_envelope( $input );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$row = $this->find( (string) ( $input['change_set_id'] ?? '' ) );
		if ( ! $row || 'completed' !== $row['status'] ) {
			return new \WP_Error( 'sitepilot_invalid_state', __( 'Only completed change sets can be rolled back.', 'sitepilot-mcp' ) );
		}
		$rollbacks = $this->rollback_data( $row );
		if ( array() === $rollbacks ) {
			return new \WP_Error( 'sitepilot_no_rollback', __( 'This irreversible change set has no rollback operation.', 'sitepilot-mcp' ) );
		}
		if ( (int) $row['risk_tier'] >= 2 ) {
			$approval = $this->valid_approval( $row, (string) ( $input['approval_id'] ?? '' ) );
			if ( is_wp_error( $approval ) ) {
				return $approval;
			}
		}
		$cached = $this->claim_idempotency( 'rollback', $input );
		if ( is_wp_error( $cached ) ) {
			return $cached;
		}
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$wpdb->update( $wpdb->prefix . 'sitepilot_changesets', array( 'status' => 'rolling_back' ), array( 'change_set_id' => $row['change_set_id'] ) );
		$result = $this->rollback_items( $rollbacks );
		if ( is_wp_error( $result ) ) {
			$wpdb->update(
				$wpdb->prefix . 'sitepilot_changesets',
				array(
					'status'             => 'failed',
					'validation_results' => wp_json_encode(
						array(
							array(
								'check'   => 'rollback',
								'status'  => 'failed',
								'code'    => $result->get_error_code(),
								'message' => $result->get_error_message(),
							),
						)
					),
					'updated_at'         => current_time( 'mysql', true ),
				),
				array( 'change_set_id' => $row['change_set_id'] )
			);
			$this->release_idempotency( 'rollback', $input );
			$this->audit->record(
				'changeset.rollback',
				'failed',
				array(
					'change_set_id' => $row['change_set_id'],
					'inputs'        => $input,
				)
			);
			return $result;
		}
		$wpdb->update(
			$wpdb->prefix . 'sitepilot_changesets',
			array(
				'status'     => 'rolled_back',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'change_set_id' => $row['change_set_id'] )
		);
		$this->version->bump();
		$this->audit->record(
			'changeset.rollback',
			'success',
			array(
				'change_set_id' => $row['change_set_id'],
				'inputs'        => $input,
			)
		);
		$response = $this->status( (string) $row['change_set_id'] );
		if ( is_array( $response ) ) {
			$this->complete_idempotency( 'rollback', $input, $response );
		}
		return $response;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function status( string $id ) {
		$row = $this->find( $id );
		return $row ? $this->format( $row ) : new \WP_Error( 'sitepilot_not_found', __( 'Change set not found.', 'sitepilot-mcp' ) );
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function format( array $row ): array {
		$approval = (int) $row['risk_tier'] >= 2 ? $this->latest_valid_approval( $row ) : null;
		$response = array(
			'change_set_id'      => $row['change_set_id'],
			'risk_tier'          => (int) $row['risk_tier'],
			'diff'               => json_decode( (string) $row['diff'], true ) ? json_decode( (string) $row['diff'], true ) : array(),
			'approval_state'     => (int) $row['risk_tier'] < 2 ? 'not_required' : ( is_array( $approval ) ? 'approved' : 'required' ),
			'status'             => $row['status'],
			'validation_results' => json_decode( (string) ( $row['validation_results'] ?? '' ), true ) ? json_decode( (string) $row['validation_results'], true ) : array(),
			'rollback_available' => 'completed' === $row['status'] && array() !== $this->rollback_data( $row ),
			'site_version'       => $this->version->current(),
		);
		if ( is_array( $approval ) ) {
			$response['approval_id']         = $approval['approval_id'];
			$response['approval_expires_at'] = $approval['expires_at'];
		}
		return $response;
	}

	/** @param array<string,mixed> $row @return list<array<string,mixed>> */
	private function rollback_data( array $row ): array {
		$decoded = json_decode( (string) ( $row['rollback_data'] ?? '' ), true );
		return is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_array' ) ) : array();
	}

	/** @return array<string,mixed>|null */
	private function find( string $id, bool $enforce_actor = true ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sitepilot_changesets WHERE change_set_id = %s", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		if ( $enforce_actor && get_current_user_id() !== (int) $row['actor_user_id'] && ! current_user_can( 'manage_options' ) ) {
			return null;
		}
		return $row;
	}

	/** @param array<string,mixed> $input @return true|array<string,mixed>|\WP_Error */
	private function claim_idempotency( string $operation, array $input ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'sitepilot_idempotency' );
		$hash  = Ids::canonical_hash( $input );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is constructed solely from the trusted WordPress prefix and a constant suffix; all values use placeholders.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE actor_user_id=%d AND operation=%s AND idempotency_key=%s", get_current_user_id(), $operation, $input['idempotency_key'] ), ARRAY_A );
		if ( is_array( $row ) ) {
			if ( ! hash_equals( (string) $row['input_hash'], $hash ) ) {
				return new \WP_Error( 'sitepilot_idempotency_conflict', __( 'That idempotency key was used with different input.', 'sitepilot-mcp' ) );
			}
			return $row['response'] ? ( json_decode( (string) $row['response'], true ) ? json_decode( (string) $row['response'], true ) : array() ) : new \WP_Error( 'sitepilot_operation_in_progress', __( 'An operation with that idempotency key is already in progress.', 'sitepilot-mcp' ) );
		}
		$inserted = $wpdb->insert(
			$table,
			array(
				'actor_user_id'   => get_current_user_id(),
				'operation'       => $operation,
				'idempotency_key' => $input['idempotency_key'],
				'input_hash'      => $hash,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
		return false === $inserted ? new \WP_Error( 'sitepilot_operation_in_progress', __( 'A concurrent operation already claimed that idempotency key.', 'sitepilot-mcp' ) ) : true;
	}

	/** @param array<string,mixed> $input @param array<string,mixed> $response */
	private function complete_idempotency( string $operation, array $input, array $response ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'sitepilot_idempotency',
			array( 'response' => wp_json_encode( $response ) ),
			array(
				'actor_user_id'   => get_current_user_id(),
				'operation'       => $operation,
				'idempotency_key' => $input['idempotency_key'],
			),
			array( '%s' ),
			array( '%d', '%s', '%s' )
		);
	}

	/** @param array<string,mixed> $input */
	private function release_idempotency( string $operation, array $input ): void {
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'sitepilot_idempotency',
			array(
				'actor_user_id'   => get_current_user_id(),
				'operation'       => $operation,
				'idempotency_key' => $input['idempotency_key'],
			),
			array( '%d', '%s', '%s' )
		);
	}

	/** @param array<string,mixed> $input */
	private function validate_envelope( array $input ): true|\WP_Error {
		if ( empty( $input['idempotency_key'] ) || strlen( (string) $input['idempotency_key'] ) < 16 || empty( $input['expected_version'] ) ) {
			return new \WP_Error( 'sitepilot_invalid_envelope', __( 'idempotency_key and expected_version are required for every mutation.', 'sitepilot-mcp' ) );
		}
		return true;
	}

	/** @param array<string,mixed> $row @return array<string,mixed>|\WP_Error */
	private function valid_approval( array $row, string $approval_id ) {

		global $wpdb;
		$approval = '' === $approval_id
			? $this->latest_valid_approval( $row )
		: $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sitepilot_approvals WHERE approval_id = %s AND change_set_id = %s", $approval_id, $row['change_set_id'] ), ARRAY_A );
		if ( ! is_array( $approval ) || ! empty( $approval['used_at'] ) || ! empty( $approval['revoked_at'] ) || strtotime( (string) $approval['expires_at'] ) <= time() || ! hash_equals( (string) $approval['input_hash'], (string) $row['input_hash'] ) ) {
			return new \WP_Error( 'sitepilot_approval_required', __( 'A fresh approval bound to this exact change set is required.', 'sitepilot-mcp' ) );
		}
		return $approval;
	}

	/** @param array<string,mixed> $row @return array<string,mixed>|null */
	private function latest_valid_approval( array $row ): ?array {
		global $wpdb;
		$approval = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}sitepilot_approvals WHERE change_set_id = %s AND input_hash = %s AND used_at IS NULL AND revoked_at IS NULL AND expires_at > %s ORDER BY created_at DESC LIMIT 1",
				$row['change_set_id'],
				$row['input_hash'],
				current_time( 'mysql', true )
			),
			ARRAY_A
		);
		return is_array( $approval ) ? $approval : null;
	}

	/** @param list<array<string,mixed>> $items */
	private function rollback_items( array $items ): true|\WP_Error {
		foreach ( array_reverse( $items ) as $item ) {
			$result = $this->executor->rollback( $item );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}
}
