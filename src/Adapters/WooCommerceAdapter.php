<?php

declare(strict_types=1);

namespace SitePilot\Mcp\Adapters;

final class WooCommerceAdapter {
	/** @param array<string,mixed> $action @return array<string,mixed>|\WP_Error */
	public function preview( array $action ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new \WP_Error( 'sitepilot_woocommerce_unavailable', __( 'WooCommerce is not active.', 'sitepilot-mcp' ) );
		}
		$operation = (string) $action['operation'];
		$target    = (int) $action['target'];
		$input     = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		if ( in_array( $operation, array( 'commerce.update_price', 'commerce.update_stock' ), true ) ) {
			$product = wc_get_product( $target );
			if ( ! $product ) {
				return new \WP_Error( 'sitepilot_target_missing', __( 'The target product does not exist.', 'sitepilot-mcp' ) );
			}
			$before = $this->product_snapshot( $product );
			$after  = $before;
			if ( 'commerce.update_price' === $operation ) {
				if ( isset( $input['regular_price'] ) ) {
					$after['regular_price'] = wc_format_decimal( $input['regular_price'] );
				}
				if ( array_key_exists( 'sale_price', $input ) ) {
					$after['sale_price'] = wc_format_decimal( $input['sale_price'] );
				}
			} else {
				$after['manage_stock']   = (bool) ( $input['manage_stock'] ?? true );
				$after['stock_quantity'] = wc_stock_amount( $input['stock_quantity'] ?? 0 );
				$after['stock_status']   = sanitize_key( (string) ( $input['stock_status'] ?? $after['stock_status'] ) );
			}
			return array(
				'operation' => $operation,
				'target'    => (string) $target,
				'before'    => $before,
				'after'     => $after,
			);
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $target ) : false;
		if ( ! $order ) {
			return new \WP_Error( 'sitepilot_target_missing', __( 'The target order does not exist.', 'sitepilot-mcp' ) );
		}
		if ( 'commerce.refund' === $operation ) {
			$amount = wc_format_decimal( $input['amount'] ?? 0 );
			if ( (float) $amount <= 0 || (float) $amount > (float) $order->get_remaining_refund_amount() ) {
				return new \WP_Error( 'sitepilot_invalid_refund', __( 'The refund amount is invalid.', 'sitepilot-mcp' ) );
			}
			return array(
				'operation' => $operation,
				'target'    => (string) $target,
				'before'    => array(
					'refunded'  => $order->get_total_refunded(),
					'remaining' => $order->get_remaining_refund_amount(),
				),
				'after'     => array(
					'refund_amount'      => $amount,
					'reason'             => sanitize_text_field( (string) ( $input['reason'] ?? '' ) ),
					'rollback_available' => false,
				),
			);
		}
		if ( 'commerce.update_order' === $operation ) {
			return array(
				'operation' => $operation,
				'target'    => (string) $target,
				'before'    => $this->order_snapshot( $order ),
				'after'     => array_merge( $this->order_snapshot( $order ), $this->order_payload( $input ) ),
			);
		}
		return new \WP_Error( 'sitepilot_adapter_unavailable', __( 'The WooCommerce adapter does not support this action.', 'sitepilot-mcp' ) );
	}

	/** @param array<string,mixed> $action @return array<string,mixed>|\WP_Error */
	public function execute( array $action ) {
		$preview = $this->preview( $action );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$operation = (string) $action['operation'];
		$target    = (int) $action['target'];
		$input     = is_array( $action['input'] ?? null ) ? $action['input'] : array();
		if ( in_array( $operation, array( 'commerce.update_price', 'commerce.update_stock' ), true ) ) {
			$product = wc_get_product( $target );
			$before  = $this->product_snapshot( $product );
			if ( 'commerce.update_price' === $operation ) {
				if ( isset( $input['regular_price'] ) ) {
					$product->set_regular_price( wc_format_decimal( $input['regular_price'] ) );
				}
				if ( array_key_exists( 'sale_price', $input ) ) {
					$product->set_sale_price( wc_format_decimal( $input['sale_price'] ) );
				}
			} else {
				$product->set_manage_stock( (bool) ( $input['manage_stock'] ?? true ) );
				$product->set_stock_quantity( wc_stock_amount( $input['stock_quantity'] ?? 0 ) );
				if ( isset( $input['stock_status'] ) ) {
					$product->set_stock_status( sanitize_key( (string) $input['stock_status'] ) );
				}
			}
			$product->save();
			return array(
				'result'   => array( 'product_id' => $target ),
				'rollback' => array(
					'operation' => 'restore_product',
					'snapshot'  => $before,
				),
			);
		}
		$order = wc_get_order( $target );
		if ( 'commerce.refund' === $operation ) {
			$refund = wc_create_refund(
				array(
					'order_id'       => $target,
					'amount'         => wc_format_decimal( $input['amount'] ?? 0 ),
					'reason'         => sanitize_text_field( (string) ( $input['reason'] ?? '' ) ),
					'refund_payment' => (bool) ( $input['refund_payment'] ?? true ),
					'restock_items'  => (bool) ( $input['restock_items'] ?? false ),
				)
			);
			if ( is_wp_error( $refund ) ) {
				return $refund;
			}
			return array(
				'result'   => array(
					'order_id'           => $target,
					'refund_id'          => $refund->get_id(),
					'rollback_available' => false,
				),
				'rollback' => array(
					'operation' => 'irreversible_refund',
					'refund_id' => $refund->get_id(),
				),
			);
		}
		$before  = $this->order_snapshot( $order );
		$payload = $this->order_payload( $input );
		if ( isset( $payload['status'] ) ) {
			$order->set_status( $payload['status'] );
		}
		if ( isset( $payload['customer_note'] ) ) {
			$order->set_customer_note( $payload['customer_note'] );
		}
		if ( isset( $payload['billing'] ) ) {
			$order->set_address( $payload['billing'], 'billing' );
		}
		if ( isset( $payload['shipping'] ) ) {
			$order->set_address( $payload['shipping'], 'shipping' );
		}
		$order->save();
		return array(
			'result'   => array(
				'order_id' => $target,
				'status'   => $order->get_status(),
			),
			'rollback' => array(
				'operation' => 'restore_order',
				'snapshot'  => $before,
			),
		);
	}

	/** @param array<string,mixed> $rollback @return true|\WP_Error */
	public function rollback( array $rollback ) {
		if ( 'irreversible_refund' === ( $rollback['operation'] ?? '' ) ) {
			return new \WP_Error( 'sitepilot_manual_rollback_required', __( 'A processed payment refund cannot be automatically reversed.', 'sitepilot-mcp' ) );
		}
		if ( 'restore_product' === ( $rollback['operation'] ?? '' ) ) {
			$snapshot = (array) $rollback['snapshot'];
			$product  = wc_get_product( (int) $snapshot['id'] );
			if ( ! $product ) {
				return new \WP_Error( 'sitepilot_rollback_failed', __( 'The product no longer exists.', 'sitepilot-mcp' ) );
			}
			$product->set_regular_price( (string) $snapshot['regular_price'] );
			$product->set_sale_price( (string) $snapshot['sale_price'] );
			$product->set_manage_stock( (bool) $snapshot['manage_stock'] );
			$product->set_stock_quantity( $snapshot['stock_quantity'] );
			$product->set_stock_status( (string) $snapshot['stock_status'] );
			$product->save();
			return true;
		}
		if ( 'restore_order' === ( $rollback['operation'] ?? '' ) ) {
			$snapshot = (array) $rollback['snapshot'];
			$order    = wc_get_order( (int) $snapshot['id'] );
			if ( ! $order ) {
				return new \WP_Error( 'sitepilot_rollback_failed', __( 'The order no longer exists.', 'sitepilot-mcp' ) );
			}
			$order->set_status( (string) $snapshot['status'] );
			$order->set_customer_note( (string) $snapshot['customer_note'] );
			$order->set_address( (array) $snapshot['billing'], 'billing' );
			$order->set_address( (array) $snapshot['shipping'], 'shipping' );
			$order->save();
			return true;
		}
		return new \WP_Error( 'sitepilot_rollback_unknown', __( 'Unknown commerce rollback operation.', 'sitepilot-mcp' ) );
	}

	/** @return array<string,mixed> */
	private function product_snapshot( \WC_Product $product ): array {
		return array(
			'id'             => $product->get_id(),
			'regular_price'  => $product->get_regular_price(),
			'sale_price'     => $product->get_sale_price(),
			'manage_stock'   => $product->get_manage_stock(),
			'stock_quantity' => $product->get_stock_quantity(),
			'stock_status'   => $product->get_stock_status(),
		); }
	/** @return array<string,mixed> */
	private function order_snapshot( \WC_Order $order ): array {
		return array(
			'id'            => $order->get_id(),
			'status'        => $order->get_status(),
			'customer_note' => $order->get_customer_note(),
			'billing'       => $order->get_address( 'billing' ),
			'shipping'      => $order->get_address( 'shipping' ),
		); }
	/** @param array<string,mixed> $input @return array<string,mixed> */
	private function order_payload( array $input ): array {
		$result = array();
		if ( isset( $input['status'] ) ) {
			$result['status'] = sanitize_key( (string) $input['status'] );
		} if ( isset( $input['customer_note'] ) ) {
			$result['customer_note'] = sanitize_textarea_field( (string) $input['customer_note'] );
		} foreach ( array( 'billing', 'shipping' ) as $kind ) {
			if ( is_array( $input[ $kind ] ?? null ) ) {
				$result[ $kind ] = array_map( 'sanitize_text_field', $input[ $kind ] );
			}
		} return $result; }
}
