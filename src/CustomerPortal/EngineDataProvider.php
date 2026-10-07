<?php
/**
 * EngineDataProvider - reads customer-portal data from the subscriptions engine.
 *
 * The portal's one data source. It consumes engine functionality through the engine's
 * public {@see Subscriptions} facade ONLY - never the engine's `Integration\` internals -
 * and reduces the facade's return types ({@see ContractView}, `WC_Order`) to the
 * domain-ish arrays {@see ViewModel} and the templates consume. Draft contracts are
 * hidden from customers: they are left out of the list and read as not found.
 *
 * Ownership / not-found: {@see self::get_contract()} delegates the asymmetric not-found
 * rule to the facade's ownership-checked read - an unknown id and a contract owned by
 * another customer both come back null - so the portal never confirms a contract the
 * requester does not own. {@see self::get_related_orders()} is gated by call order: the
 * endpoints resolve + ownership-check the contract via {@see self::get_contract()} before
 * reading its orders, so a foreign or unknown contract is already turned away before any
 * order read runs and no orders can leak across customers.
 *
 * Cadence (`billing_period` / `billing_interval`) is read off the contract's live plan
 * (one batched read per list page) and degrades to an empty period / zero interval when
 * the plan does not resolve.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal;

use DateTimeInterface;
use WC_Order;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsLite\Contracts\AddressFields;
use Automattic\WooCommerce\SubscriptionsLite\Contracts\CustomerVisibility;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ContractPlans;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the customer's contracts, one contract's detail, and related orders.
 */
final class EngineDataProvider {

	/**
	 * Return the customer's contracts as domain-ish list-row arrays.
	 *
	 * Reads the customer-scoped contract list off the facade and reduces each contract to
	 * the row shape {@see ViewModel} consumes. Drafts are filtered out before paging, so a
	 * page holds up to `$limit` visible rows. The empty array means "no subscriptions".
	 *
	 * @param int $customer_id The logged-in customer id.
	 * @param int $limit       Maximum contracts to return.
	 * @param int $offset      Contracts to skip (for paging).
	 * @return array<int, array<string, mixed>>
	 */
	public function get_contracts_for_customer( int $customer_id, int $limit = 20, int $offset = 0 ): array {
		$contracts = Contracts::list_for_customer( $customer_id, $limit, $offset, [ 'status' => CustomerVisibility::visible_statuses() ] );
		$plans     = ContractPlans::for_contracts( $contracts );

		$rows = [];
		foreach ( $contracts as $contract ) {
			$rows[] = $this->contract_to_row( $contract, $plans[ (int) $contract->get_selling_plan_id() ] ?? null );
		}
		return $rows;
	}

	/**
	 * Return one contract's detail as a domain-ish array, ownership-checked.
	 *
	 * The facade's ownership-checked read enforces the asymmetric not-found rule: a
	 * contract the customer does not own - whether it does not exist, belongs to someone
	 * else, or its row vanished under the guard - comes back null, and this returns null
	 * before any detail mapping. A draft also reads as null.
	 *
	 * @param int $contract_id The contract id from the URL.
	 * @param int $customer_id The logged-in customer id (ownership check).
	 * @return array<string, mixed>|null
	 */
	public function get_contract( int $contract_id, int $customer_id ): ?array {
		$contract = Contracts::get_for_customer( $contract_id, $customer_id );
		if ( null === $contract || ! CustomerVisibility::is_visible( $contract ) ) {
			return null;
		}

		return $this->contract_to_detail( $contract );
	}

	/**
	 * Return the related orders for a contract as domain-ish arrays.
	 *
	 * Ownership is enforced by the caller: the endpoints resolve + ownership-check the
	 * contract via {@see self::get_contract()} (which returns null and short-circuits the
	 * render for a foreign or unknown contract) before this is reached, so this read only
	 * ever runs for a contract the customer owns and orders cannot leak across customers.
	 * Live `WC_Order` objects are reduced to plain arrays here; none escape the provider.
	 *
	 * @param int $contract_id The contract id.
	 * @param int $limit       Maximum orders to return; -1 for all.
	 * @param int $offset      Orders to skip (for paging).
	 * @return array<int, array<string, mixed>>
	 */
	public function get_related_orders( int $contract_id, int $limit = -1, int $offset = 0 ): array {
		$rows = [];
		foreach ( Subscriptions::get_related_orders( $contract_id, $limit, $offset ) as $order ) {
			if ( $order instanceof WC_Order ) {
				$rows[] = $this->order_to_row( $order );
			}
		}
		return $rows;
	}

	/**
	 * Reduce a contract to the domain-ish list-row array {@see ViewModel::build_row()} reads.
	 *
	 * @param ContractView  $contract The contract.
	 * @param PlanView|null $plan     The contract's live plan.
	 * @return array<string, mixed>
	 */
	private function contract_to_row( ContractView $contract, ?PlanView $plan ): array {
		$cadence = $this->billing_cadence( $plan );

		return [
			'id'               => (int) $contract->get_id(),
			'status'           => $contract->get_status(),
			'billing_total'    => $contract->get_billing_total(),
			'currency'         => $contract->get_currency(),
			'billing_period'   => $cadence['period'],
			'billing_interval' => $cadence['interval'],
			'next_payment_gmt' => $contract->get_next_payment_gmt(),
			'payment_method'   => $this->payment_method( $contract ),
		];
	}

	/**
	 * Reduce a contract to the domain-ish detail array {@see ViewModel::build_detail()} reads.
	 *
	 * The detail array is the list row plus the contract's date stamps, recurring totals,
	 * line items, and addresses.
	 *
	 * @param ContractView $contract The contract.
	 * @return array<string, mixed>
	 */
	private function contract_to_detail( ContractView $contract ): array {
		return array_merge(
			$this->contract_to_row( $contract, ContractPlans::for_contract( $contract ) ),
			[
				'start_gmt'        => $contract->get_start_gmt(),
				'end_gmt'          => $contract->get_end_gmt(),
				'last_payment_gmt' => $contract->get_last_payment_gmt(),
				'last_updated_gmt' => $this->resolve_last_updated_gmt( $contract ),
				'discount_total'   => $contract->get_discount_total(),
				'shipping_total'   => $contract->get_shipping_total(),
				'tax_total'        => $contract->get_tax_total(),
				'items'            => $this->items( $contract ),
				'addresses'        => $this->addresses( $contract ),
			]
		);
	}

	/**
	 * Reduce the contract's line items to the canonical provider item shape
	 * (`name`, `quantity`, `subtotal`, `total` - raw amount strings, the view-model
	 * formats).
	 *
	 * @param ContractView $contract The contract.
	 * @return array<int, array<string, mixed>>
	 */
	private function items( ContractView $contract ): array {
		$items = [];
		foreach ( $contract->get_items() ?? [] as $item ) {
			$items[] = [
				'name'     => (string) ( $item['item_name'] ?? '' ),
				'quantity' => (float) ( $item['quantity'] ?? 1 ),
				'subtotal' => (string) ( $item['subtotal'] ?? '0' ),
				'total'    => (string) ( $item['total'] ?? '0' ),
			];
		}
		return $items;
	}

	/**
	 * Reduce the contract's addresses to WC-style field arrays keyed by type
	 * (`billing` / `shipping`). Only the address
	 * fields survive - storage bookkeeping keys (contract id, type) are dropped.
	 *
	 * @param ContractView $contract The contract.
	 * @return array<string, array<string, string>>
	 */
	private function addresses( ContractView $contract ): array {
		$addresses = [];
		foreach ( $contract->get_addresses() ?? [] as $type => $row ) {
			$address = [];
			foreach ( AddressFields::FIELDS as $field ) {
				$value = $row[ $field ] ?? null;
				if ( null !== $value && '' !== (string) $value ) {
					$address[ $field ] = (string) $value;
				}
			}
			if ( [] !== $address ) {
				$addresses[ (string) $type ] = $address;
			}
		}
		return $addresses;
	}

	/**
	 * Reduce a live order to the domain-ish related-order row {@see ViewModel::build_detail()} reads.
	 *
	 * Emits the raw `date_gmt` (the view-model formats it), so the date-formatting decision
	 * stays in one place alongside every other date the portal renders.
	 *
	 * @param WC_Order $order The related order.
	 * @return array<string, mixed>
	 */
	private function order_to_row( WC_Order $order ): array {
		$date   = $order->get_date_created();
		$status = $order->get_status();

		return [
			'number'       => $order->get_order_number(),
			'date_gmt'     => $date instanceof DateTimeInterface ? gmdate( 'Y-m-d H:i:s', $date->getTimestamp() ) : '',
			'status'       => $status,
			'status_label' => wc_get_order_status_name( $status ),
			'total'        => wp_strip_all_tags( $order->get_formatted_order_total() ),
			'view_url'     => $order->get_view_order_url(),
		];
	}

	/**
	 * The contract's payment-method presentation fields (`title`, `expires`).
	 *
	 * `title` is the contract's stored payment method title. Card expiry is not modelled on
	 * the contract row, so `expires` is left blank - a documented seam a payment-method
	 * detail read can fill when that surface lands. Matches the engine read model.
	 *
	 * @param ContractView $contract The contract.
	 * @return array{title: string, expires: string}
	 */
	private function payment_method( ContractView $contract ): array {
		$title = $contract->get_payment_method_title();

		return [
			'title'   => null === $title ? '' : $title,
			'expires' => '',
		];
	}

	/**
	 * The billing cadence (`period`, `interval`) of a contract's live plan.
	 *
	 * No plan (or an unusable billing policy) degrades to an empty period and a zero
	 * interval, which the view-model renders as a price with no cadence suffix.
	 *
	 * @param PlanView|null $plan The contract's live plan.
	 * @return array{period: string, interval: int}
	 */
	private function billing_cadence( ?PlanView $plan ): array {
		$terms = BillingTerms::from_plan( $plan );
		if ( null !== $terms ) {
			return [
				'period'   => $terms->get_period(),
				'interval' => $terms->get_interval(),
			];
		}

		return [
			'period'   => '',
			'interval' => 0,
		];
	}

	/**
	 * Resolve the "last updated" GMT stamp for the detail block.
	 *
	 * The facade's interim contract carries no modified stamp (that lives behind a storage
	 * read this facade-only provider does not reach for), so this approximates it with the
	 * last successful payment, falling back to the start date so the value is always a
	 * string. The view-model uses it only as the on-hold (admin-action) date-row value -
	 * the closest available proxy for "when the status flipped".
	 *
	 * @param ContractView $contract The contract.
	 * @return string
	 */
	private function resolve_last_updated_gmt( ContractView $contract ): string {
		$last = $contract->get_last_payment_gmt();
		if ( null !== $last && '' !== $last ) {
			return $last;
		}

		return (string) $contract->get_start_gmt();
	}
}
