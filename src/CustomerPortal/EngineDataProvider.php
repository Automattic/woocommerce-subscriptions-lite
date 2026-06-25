<?php
/**
 * EngineDataProvider - reads customer-portal data from the subscriptions engine.
 *
 * The engine-backed implementation of {@see DataProvider} and the production default
 * (see the provider resolver). It consumes engine functionality through the engine's
 * public {@see \Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions} facade
 * ONLY - never the engine's `Integration\` internals - and reduces the facade's interim
 * return types to the same domain-ish arrays {@see FixtureDataProvider} returns, so
 * {@see ViewModel} and the templates render identically whichever provider is active.
 *
 * The facade is static and database-bound, so the Contract-to-array mapping lives here
 * (unit-testable in isolation) behind one thin injectable seam, {@see Engine\SubscriptionsReader}:
 * production wires the real {@see Engine\ApiSubscriptionsReader} (which calls the facade);
 * tests inject a double that returns engine value objects without a booted database.
 *
 * Ownership / not-found: {@see self::get_contract()} delegates the asymmetric not-found
 * rule to the facade's ownership-checked read - an unknown id and a contract owned by
 * another customer both come back null - so the portal never confirms a contract the
 * requester does not own. {@see self::get_related_orders()} is gated by call order: the
 * endpoints resolve + ownership-check the contract via {@see self::get_contract()} before
 * reading its orders, so a foreign or unknown contract is already turned away before any
 * order read runs and no orders can leak across customers.
 *
 * Cadence (`billing_period` / `billing_interval`) is read off the contract's own frozen
 * plan snapshot ({@see \Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract::get_plan_snapshot()}),
 * not a live plan read, and degrades to an empty period / zero interval when the snapshot
 * or its billing policy is absent.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal;

use DateTimeInterface;
use WC_Order;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine\ApiSubscriptionsReader;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine\SubscriptionsReader;

defined( 'ABSPATH' ) || exit;

/**
 * Engine-backed implementation of {@see DataProvider}.
 */
final class EngineDataProvider implements DataProvider {

	/**
	 * The engine read seam.
	 *
	 * @var SubscriptionsReader
	 */
	private $reader;

	/**
	 * Build the provider over the engine read seam.
	 *
	 * Defaults to the production facade adapter ({@see ApiSubscriptionsReader}); tests
	 * pass a double in its place.
	 *
	 * @param SubscriptionsReader|null $reader Engine read seam; default facade adapter when omitted.
	 */
	public function __construct( ?SubscriptionsReader $reader = null ) {
		$this->reader = $reader ?? new ApiSubscriptionsReader();
	}

	/**
	 * Return the customer's contracts as domain-ish list-row arrays.
	 *
	 * Reads the customer-scoped contract list off the facade and reduces each contract to
	 * the row shape {@see ViewModel} consumes. The empty array means "no subscriptions".
	 *
	 * @param int $customer_id The logged-in customer id.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_contracts_for_customer( int $customer_id ): array {
		$rows = [];
		foreach ( $this->reader->list_for_customer( $customer_id ) as $contract ) {
			$rows[] = $this->contract_to_row( $contract );
		}
		return $rows;
	}

	/**
	 * Return one contract's detail as a domain-ish array, ownership-checked.
	 *
	 * The facade's ownership-checked read enforces the asymmetric not-found rule: a
	 * contract the customer does not own - whether it does not exist, belongs to someone
	 * else, or its row vanished under the guard - comes back null, and this returns null
	 * before any detail mapping.
	 *
	 * @param int $contract_id The contract id from the URL.
	 * @param int $customer_id The logged-in customer id (ownership check).
	 * @return array<string, mixed>|null
	 */
	public function get_contract( int $contract_id, int $customer_id ): ?array {
		$contract = $this->reader->get_for_customer( $contract_id, $customer_id );
		if ( null === $contract ) {
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
	 * @return array<int, array<string, mixed>>
	 */
	public function get_related_orders( int $contract_id ): array {
		$rows = [];
		foreach ( $this->reader->get_related_orders( $contract_id ) as $order ) {
			if ( $order instanceof WC_Order ) {
				$rows[] = $this->order_to_row( $order );
			}
		}
		return $rows;
	}

	/**
	 * Reduce a contract to the domain-ish list-row array {@see ViewModel::build_row()} reads.
	 *
	 * @param Contract $contract The contract.
	 * @return array<string, mixed>
	 */
	private function contract_to_row( Contract $contract ): array {
		$cadence = $this->billing_cadence( $contract );

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
	 * The detail array is the list row plus the contract's date stamps and line items.
	 *
	 * @param Contract $contract The contract.
	 * @return array<string, mixed>
	 */
	private function contract_to_detail( Contract $contract ): array {
		return array_merge(
			$this->contract_to_row( $contract ),
			[
				'start_gmt'        => $contract->get_start_gmt(),
				'end_gmt'          => $contract->get_end_gmt(),
				'last_payment_gmt' => $contract->get_last_payment_gmt(),
				'last_updated_gmt' => $this->resolve_last_updated_gmt( $contract ),
				'items'            => $contract->get_items(),
			]
		);
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
	 * `title` comes off the contract's stored instrument. Card expiry is not modelled on
	 * the contract row, so `expires` is left blank - a documented seam a payment-method
	 * detail read can fill when that surface lands. Matches the engine read model.
	 *
	 * @param Contract $contract The contract.
	 * @return array{title: string, expires: string}
	 */
	private function payment_method( Contract $contract ): array {
		$title = $contract->get_payment_instrument()->get_title();

		return [
			'title'   => null === $title ? '' : $title,
			'expires' => '',
		];
	}

	/**
	 * The contract's billing cadence (`period`, `interval`) from its frozen plan snapshot.
	 *
	 * Read off the contract's hydrated plan snapshot, so the cadence is the one the contract
	 * is billed under even after the plan it came from is edited or deleted - no live plan
	 * read. A contract with no hydrated snapshot (or a snapshot carrying no billing policy)
	 * degrades to an empty period and a zero interval, which the view-model renders as a
	 * price with no cadence suffix rather than fataling - matching the engine read model.
	 *
	 * @param Contract $contract The contract.
	 * @return array{period: string, interval: int}
	 */
	private function billing_cadence( Contract $contract ): array {
		$snapshot = $contract->get_plan_snapshot();
		if ( null !== $snapshot ) {
			$policy = $snapshot->get_billing_policy();
			if ( $policy instanceof BillingPolicy ) {
				return [
					'period'   => $policy->get_period(),
					'interval' => $policy->get_interval(),
				];
			}
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
	 * @param Contract $contract The contract.
	 * @return string
	 */
	private function resolve_last_updated_gmt( Contract $contract ): string {
		$last = $contract->get_last_payment_gmt();
		if ( null !== $last && '' !== $last ) {
			return $last;
		}

		return $contract->get_start_gmt();
	}
}
