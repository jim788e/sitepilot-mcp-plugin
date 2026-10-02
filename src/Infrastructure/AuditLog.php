<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Infrastructure;

use SitePilot\Mcp\Credentials\CredentialContext;

final class AuditLog {
	/** @param array<string,mixed> $context */
	public function record( string $event, string $result, array $context = array() ): void {
		global $wpdb;
		$client_id = array_key_exists( 'client_id', $context ) ? $context['client_id'] : CredentialContext::audit_client_id();
		$wpdb->insert(
			$wpdb->prefix . 'sitepilot_audit',
			array(
				'change_set_id' => $context['change_set_id'] ?? null,
				'actor_user_id' => get_current_user_id(),
				'client_id'     => $client_id,
				'scope'         => $context['scope'] ?? null,
				'event'         => $event,
				'inputs_hash'   => Ids::canonical_hash( $context['inputs'] ?? array() ),
				'before_state'  => isset( $context['before'] ) ? wp_json_encode( $context['before'] ) : null,
				'after_state'   => isset( $context['after'] ) ? wp_json_encode( $context['after'] ) : null,
				'result'        => $result,
				'rollback_ref'  => $context['rollback_ref'] ?? null,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
