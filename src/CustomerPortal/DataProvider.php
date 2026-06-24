<?php
/**
 * DataProvider - the read seam behind the customer portal.
 *
 * The portal renders from a provider rather than reaching into the engine
 * directly, so the UI and the REST response shape can be built and tested
 * against fixtures first ({@see FixtureDataProvider}) and swapped for an
 * engine-backed implementation later ({@see EngineDataProvider}) without
 * touching the templates, the view-model builder, or the iAPI store.
 *
 * This is an INTERNAL Lite seam, NOT a consumer-implementable public
 * interface: Lite selects the implementation itself (see the provider
 * resolver), so adding methods here later does not break any third party.
 *
 * The provider returns domain-ish associative arrays; {@see ViewModel} turns
 * those into the presentation shape (status labels, money strings, formatted
 * dates, action-visibility flags) used by both the server render and the REST
 * response, so the page-load shape and the refetch shape never drift.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the customer's contracts and a single contract's detail.
 */
interface DataProvider {

	/**
	 * Return the logged-in customer's contracts as domain-ish arrays.
	 *
	 * Each entry carries the raw contract fields {@see ViewModel} needs to
	 * build a list row: `id`, `status`, `billing_total`, `currency`,
	 * `billing_period`, `billing_interval`, `next_payment_gmt`, plus a
	 * `payment_method` array (`title`, `expires`). Implementations return only
	 * contracts the customer owns; the empty array means "no subscriptions".
	 *
	 * @param int $customer_id The logged-in customer id.
	 * @return array<int, array<string, mixed>> Domain-ish contract arrays.
	 */
	public function get_contracts_for_customer( int $customer_id ): array;

	/**
	 * Return one contract's detail as a domain-ish array, ownership-checked.
	 *
	 * Returns null for BOTH a contract that does not exist AND a contract owned
	 * by another customer (the asymmetric not-found rule), so the portal never
	 * confirms the existence of a contract the requester does not own.
	 *
	 * The array carries the list fields plus the detail fields {@see ViewModel}
	 * needs: `start_gmt`, `end_gmt`, `last_payment_gmt`, `last_updated_gmt`, and
	 * an `items` array of line items.
	 *
	 * @param int $contract_id The contract id from the URL.
	 * @param int $customer_id The logged-in customer id (ownership check).
	 * @return array<string, mixed>|null Domain-ish contract array, or null when not owned / not found.
	 */
	public function get_contract( int $contract_id, int $customer_id ): ?array;

	/**
	 * Return the related orders for a contract as domain-ish arrays.
	 *
	 * Each entry carries `number`, `date_gmt`, `status`, `status_label`,
	 * `total` (formatted), and `view_url`. The owning contract is assumed to be
	 * resolved + ownership-checked by the caller via {@see self::get_contract()}
	 * before this is called.
	 *
	 * @param int $contract_id The contract id.
	 * @return array<int, array<string, mixed>> Domain-ish related-order arrays.
	 */
	public function get_related_orders( int $contract_id ): array;
}
