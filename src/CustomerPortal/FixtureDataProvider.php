<?php
/**
 * FixtureDataProvider - hard-coded customer-portal data for UI development.
 *
 * Returns domain-ish contract arrays covering every status (active, on-hold,
 * on-hold-with-next-payment, pending-cancellation, cancelled, expired) so the
 * full portal UI - list, detail, the state-aware cancel modal, hold, reactivate,
 * the needs-payment notice, and the related-orders table - can be built and the
 * REST response shape exercised with ZERO engine dependency.
 *
 * `get_contract()` enforces the asymmetric not-found rule: an unknown id and a
 * contract owned by a different customer both return null, so the portal never
 * confirms the existence of a contract the requester does not own. All fixture
 * contracts belong to the requesting customer (the fixtures are scoped to
 * "whoever is asking"), so any well-known fixture id resolves for the current
 * customer and any other id resolves to null.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Fixture implementation of {@see DataProvider}.
 */
final class FixtureDataProvider implements DataProvider {

	/**
	 * Return all fixture contracts as the customer's list.
	 *
	 * The fixtures are written as belonging to whichever customer asks, so the
	 * list is the full status spread for any logged-in customer.
	 *
	 * @param int $customer_id The logged-in customer id.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_contracts_for_customer( int $customer_id ): array {
		$contracts = [];
		foreach ( $this->contracts( $customer_id ) as $contract ) {
			$contracts[] = $contract;
		}
		return $contracts;
	}

	/**
	 * Return one fixture contract by id, or null for not-found / not-owned.
	 *
	 * @param int $contract_id The contract id from the URL.
	 * @param int $customer_id The logged-in customer id.
	 * @return array<string, mixed>|null
	 */
	public function get_contract( int $contract_id, int $customer_id ): ?array {
		$contracts = $this->contracts( $customer_id );
		return $contracts[ $contract_id ] ?? null;
	}

	/**
	 * Return the related orders for a fixture contract.
	 *
	 * @param int $contract_id The contract id.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_related_orders( int $contract_id ): array {
		$contracts = $this->contracts( 0 );
		$contract  = $contracts[ $contract_id ] ?? null;
		if ( null === $contract ) {
			return [];
		}
		return is_array( $contract['related_orders'] ?? null ) ? $contract['related_orders'] : [];
	}

	/**
	 * The fixture contract set, keyed by contract id.
	 *
	 * Each contract is assigned `$customer_id` so ownership checks pass for the
	 * requesting customer. Dates are relative to "now" so the fixtures stay
	 * sensible whenever the page is loaded.
	 *
	 * @param int $customer_id The customer id to stamp on each fixture.
	 * @return array<int, array<string, mixed>>
	 */
	private function contracts( int $customer_id ): array {
		$day = DAY_IN_SECONDS;
		$now = time();

		$gmt = static function ( int $offset_days ) use ( $now, $day ): string {
			return gmdate( 'Y-m-d H:i:s', $now + ( $offset_days * $day ) );
		};

		$card = [
			'title'   => __( 'Visa ending in 4242', 'woocommerce-subscriptions-lite' ),
			'expires' => __( '(expires 12/30)', 'woocommerce-subscriptions-lite' ),
		];

		// One line item priced at the contract's billing total - the common case.
		$line_items = static function ( string $amount ): array {
			return [
				[
					'name'     => __( 'Monthly coffee box', 'woocommerce-subscriptions-lite' ),
					'quantity' => 1,
					'subtotal' => $amount,
					'total'    => $amount,
				],
			];
		};

		// The active contract exercises every totals row: two line items (one with a
		// line discount), shipping, and tax that sum to its 19.99 billing total.
		$rich_items = [
			[
				'name'     => __( 'Monthly coffee box', 'woocommerce-subscriptions-lite' ),
				'quantity' => 1,
				'subtotal' => '12.49',
				'total'    => '10.49',
			],
			[
				'name'     => __( 'Espresso beans top-up', 'woocommerce-subscriptions-lite' ),
				'quantity' => 2,
				'subtotal' => '5.00',
				'total'    => '5.00',
			],
		];

		// WC-style address field arrays keyed by type, matching the engine provider's
		// shape: billing carries contact fields; shipping differs so both render.
		$addresses = [
			'billing'  => [
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'address_1'  => '10 Analytical Way',
				'city'       => 'London',
				'postcode'   => 'SW1A 1AA',
				'country'    => 'GB',
				'email'      => 'ada@example.com',
				'phone'      => '+44 20 7946 0000',
			],
			'shipping' => [
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'company'    => 'Analytical Engines Ltd',
				'address_1'  => '1 Engine House',
				'address_2'  => 'Unit 2',
				'city'       => 'Manchester',
				'postcode'   => 'M1 1AE',
				'country'    => 'GB',
			],
		];

		$contracts = [
			// Active: next payment in the future, cancel -> at period end, hold available.
			101 => [
				'id'               => 101,
				'status'           => ContractStatus::ACTIVE,
				'billing_total'    => '19.99',
				'currency'         => 'USD',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'next_payment_gmt' => $gmt( 20 ),
				'start_gmt'        => $gmt( -40 ),
				'end_gmt'          => null,
				'last_payment_gmt' => $gmt( -10 ),
				'last_updated_gmt' => $gmt( -10 ),
				'payment_method'   => $card,
				'discount_total'   => '2.00',
				'shipping_total'   => '3.00',
				'tax_total'        => '1.50',
				'items'            => $rich_items,
				'related_orders'   => [
					$this->order( '1001', $gmt( -40 ), 'completed', __( 'Completed', 'woocommerce-subscriptions-lite' ), '19.99', 'USD' ),
					$this->order( '1042', $gmt( -10 ), 'processing', __( 'Processing', 'woocommerce-subscriptions-lite' ), '19.99', 'USD' ),
				],
			],
			// On hold (admin-action path): no next payment, reactivate available.
			102 => [
				'id'               => 102,
				'status'           => ContractStatus::ON_HOLD,
				'billing_total'    => '9.50',
				'currency'         => 'USD',
				'billing_period'   => 'week',
				'billing_interval' => 2,
				'next_payment_gmt' => null,
				'start_gmt'        => $gmt( -90 ),
				'end_gmt'          => null,
				'last_payment_gmt' => $gmt( -14 ),
				'last_updated_gmt' => $gmt( -7 ),
				'payment_method'   => $card,
				'items'            => $line_items( '9.50' ),
				'related_orders'   => [
					$this->order( '1002', $gmt( -90 ), 'completed', __( 'Completed', 'woocommerce-subscriptions-lite' ), '9.50', 'USD' ),
				],
			],
			// On hold (failed-payment retry path): next payment scheduled,
			// needs-payment notice, reactivate hidden.
			103 => [
				'id'               => 103,
				'status'           => ContractStatus::ON_HOLD,
				'billing_total'    => '49.00',
				'currency'         => 'USD',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'next_payment_gmt' => $gmt( 3 ),
				'start_gmt'        => $gmt( -120 ),
				'end_gmt'          => null,
				'last_payment_gmt' => $gmt( -27 ),
				'last_updated_gmt' => $gmt( -2 ),
				'payment_method'   => $card,
				'items'            => $line_items( '49.00' ),
				'related_orders'   => [
					$this->order( '1003', $gmt( -120 ), 'completed', __( 'Completed', 'woocommerce-subscriptions-lite' ), '49.00', 'USD' ),
					$this->order( '1071', $gmt( -2 ), 'failed', __( 'Failed', 'woocommerce-subscriptions-lite' ), '49.00', 'USD' ),
				],
			],
			// Pending cancellation: cancels on the end date, no actions.
			104 => [
				'id'               => 104,
				'status'           => ContractStatus::PENDING_CANCELLATION,
				'billing_total'    => '12.00',
				'currency'         => 'USD',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'next_payment_gmt' => $gmt( 8 ),
				'start_gmt'        => $gmt( -200 ),
				'end_gmt'          => $gmt( 8 ),
				'last_payment_gmt' => $gmt( -22 ),
				'last_updated_gmt' => $gmt( -1 ),
				'payment_method'   => $card,
				'items'            => $line_items( '12.00' ),
				'related_orders'   => [
					$this->order( '1004', $gmt( -200 ), 'completed', __( 'Completed', 'woocommerce-subscriptions-lite' ), '12.00', 'USD' ),
				],
			],
			// Cancelled: terminal, end date set.
			105 => [
				'id'               => 105,
				'status'           => ContractStatus::CANCELLED,
				'billing_total'    => '29.99',
				'currency'         => 'USD',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'next_payment_gmt' => null,
				'start_gmt'        => $gmt( -365 ),
				'end_gmt'          => $gmt( -30 ),
				'last_payment_gmt' => $gmt( -60 ),
				'last_updated_gmt' => $gmt( -30 ),
				'payment_method'   => $card,
				'items'            => $line_items( '29.99' ),
				'related_orders'   => [
					$this->order( '1005', $gmt( -365 ), 'completed', __( 'Completed', 'woocommerce-subscriptions-lite' ), '29.99', 'USD' ),
					$this->order( '1090', $gmt( -60 ), 'cancelled', __( 'Cancelled', 'woocommerce-subscriptions-lite' ), '29.99', 'USD' ),
				],
			],
			// Expired: terminal, end date set, no payment method retained.
			106 => [
				'id'               => 106,
				'status'           => ContractStatus::EXPIRED,
				'billing_total'    => '5.00',
				'currency'         => 'USD',
				'billing_period'   => 'year',
				'billing_interval' => 1,
				'next_payment_gmt' => null,
				'start_gmt'        => $gmt( -800 ),
				'end_gmt'          => $gmt( -70 ),
				'last_payment_gmt' => $gmt( -435 ),
				'last_updated_gmt' => $gmt( -70 ),
				'payment_method'   => [
					'title'   => '',
					'expires' => '',
				],
				'items'            => $line_items( '5.00' ),
				'related_orders'   => [
					$this->order( '1006', $gmt( -800 ), 'completed', __( 'Completed', 'woocommerce-subscriptions-lite' ), '5.00', 'USD' ),
				],
			],
		];

		foreach ( $contracts as $id => $contract ) {
			$contracts[ $id ]['customer_id'] = $customer_id;
			$contracts[ $id ]['addresses']   = $addresses;
			$contracts[ $id ]               += [
				'discount_total' => '0',
				'shipping_total' => '0',
				'tax_total'      => '0',
			];
		}

		return $contracts;
	}

	/**
	 * Build a domain-ish related-order array.
	 *
	 * @param string $number       Order number.
	 * @param string $date_gmt     Order date GMT timestamp.
	 * @param string $status       Order status slug.
	 * @param string $status_label Customer-facing order status label.
	 * @param string $total        Order total (amount only).
	 * @param string $currency     ISO currency code.
	 * @return array<string, mixed>
	 */
	private function order( string $number, string $date_gmt, string $status, string $status_label, string $total, string $currency ): array {
		return [
			'number'       => $number,
			'date_gmt'     => $date_gmt,
			'status'       => $status,
			'status_label' => $status_label,
			'total'        => wp_strip_all_tags( wc_price( (float) $total, [ 'currency' => $currency ] ) ),
			'view_url'     => '#',
		];
	}
}
