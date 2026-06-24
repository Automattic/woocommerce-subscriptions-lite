<?php
/**
 * Endpoints - the customer portal's My Account endpoints (list + detail).
 *
 * Registers two dedicated My Account rewrite endpoints and their render
 * callbacks through WooCommerce's My Account framework:
 *
 *  - `subscriptions-lite`      - the customer's subscriptions list.
 *  - `view-subscription-lite`  - a single subscription's detail; the URL
 *                                carries the contract id as the endpoint value,
 *                                e.g. `/my-account/view-subscription-lite/123/`.
 *
 * The `-lite` suffix is TEMPORARY. The canonical `subscriptions` /
 * `view-subscription` slugs are currently owned by the premium subscriptions
 * plugin's `subscriptions` endpoint, so the free portal uses dedicated slugs to
 * avoid the collision; the canonical slugs return once the premium plugin
 * consumes this package.
 *
 * Each render callback resolves the active data provider, builds the
 * presentation view-model via {@see ViewModel}, seeds client state with
 * `wp_interactivity_state()`, and renders a server-side template carrying the
 * Interactivity API directives.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal;

use Automattic\WooCommerce\SubscriptionsLite\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Customer-portal endpoint registration + rendering.
 */
final class Endpoints {

	/**
	 * Rewrite-endpoint slug for the subscriptions list.
	 */
	const LIST_ENDPOINT = 'subscriptions-lite';

	/**
	 * Rewrite-endpoint slug for the single-subscription detail page.
	 */
	const DETAIL_ENDPOINT = 'view-subscription-lite';

	/**
	 * Shared Interactivity API store namespace - the cross-plugin extension
	 * surface. Passed to `store()` in JS and `wp_interactivity_state()` here.
	 */
	const STORE_NAMESPACE = 'woocommerce-subscriptions-lite/customer-portal';

	/**
	 * The option that records the rewrite version the endpoints were last
	 * flushed for, so a slug change reflushes exactly once on the next boot.
	 */
	const REWRITE_VERSION_OPTION = 'woocommerce_subscriptions_lite_customer_portal_rewrite_version';

	/**
	 * The current rewrite version. Bump this whenever an endpoint slug changes
	 * so the boot-time check below reflushes the rewrite rules once.
	 */
	const REWRITE_VERSION = '1';

	/**
	 * Wire the endpoints into WooCommerce's My Account framework.
	 *
	 * Idempotent; called once from the package bootstrap.
	 */
	public static function register(): void {
		$instance = new self();
		add_action( 'init', [ $instance, 'add_endpoints' ] );
		add_action( 'init', [ $instance, 'maybe_flush_rewrite_rules' ], 11 );
		add_filter( 'woocommerce_get_query_vars', [ $instance, 'add_query_vars' ] );
		add_filter( 'woocommerce_account_menu_items', [ $instance, 'add_menu_item' ] );
		add_action( 'woocommerce_account_' . self::LIST_ENDPOINT . '_endpoint', [ $instance, 'render_list' ] );
		add_action( 'woocommerce_account_' . self::DETAIL_ENDPOINT . '_endpoint', [ $instance, 'render_detail' ] );
	}

	/**
	 * Register both rewrite endpoints on every load, the way WC core registers
	 * its own My Account endpoints.
	 */
	public function add_endpoints(): void {
		add_rewrite_endpoint( self::LIST_ENDPOINT, EP_PAGES );
		add_rewrite_endpoint( self::DETAIL_ENDPOINT, EP_PAGES );
	}

	/**
	 * Flush rewrite rules once when the recorded rewrite version is stale.
	 *
	 * Runs on `init` after {@see self::add_endpoints()} so the endpoints exist
	 * at flush time. This boot-time check is the authority: it is robust to
	 * activation paths where the activation hook does not fire reliably (wp-cli,
	 * partial deploys). The activation-hook flush in the plugin file is a
	 * belt-and-suspenders companion.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( self::REWRITE_VERSION_OPTION ) === self::REWRITE_VERSION ) {
			return;
		}
		flush_rewrite_rules();
		update_option( self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION );
	}

	/**
	 * Add both slugs to WooCommerce's query-vars so the rewrite parser
	 * recognises them.
	 *
	 * @param array<string, string> $vars Existing WC query vars.
	 * @return array<string, string>
	 */
	public function add_query_vars( array $vars ): array {
		$vars[ self::LIST_ENDPOINT ]   = self::LIST_ENDPOINT;
		$vars[ self::DETAIL_ENDPOINT ] = self::DETAIL_ENDPOINT;
		return $vars;
	}

	/**
	 * Insert a `Subscriptions` link into the My Account menu, after Orders.
	 *
	 * @param array<string, string> $items Existing menu items.
	 * @return array<string, string>
	 */
	public function add_menu_item( array $items ): array {
		$new_items = [];
		foreach ( $items as $key => $label ) {
			$new_items[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new_items[ self::LIST_ENDPOINT ] = __( 'Subscriptions', 'woocommerce-subscriptions-lite' );
			}
		}
		if ( ! isset( $new_items[ self::LIST_ENDPOINT ] ) ) {
			$new_items[ self::LIST_ENDPOINT ] = __( 'Subscriptions', 'woocommerce-subscriptions-lite' );
		}
		return $new_items;
	}

	/**
	 * Render the subscriptions list for the current customer.
	 */
	public function render_list(): void {
		$customer_id = get_current_user_id();
		$view_model  = new ViewModel();

		$contracts = $customer_id > 0
			? Providers::resolve()->get_contracts_for_customer( $customer_id )
			: [];

		$rows = $view_model->build_list( $contracts );

		/**
		 * Filters the assembled list view-model before render.
		 *
		 * The value is a zero-indexed array of row view-models, one per contract
		 * (the shape {@see ViewModel::build_list()} returns). Additive-only: an
		 * overlay may decorate the existing rows but must not change the shape the
		 * template relies on.
		 *
		 * @since 0.0.1
		 *
		 * @param array<int, array<string, mixed>> $rows The list rows.
		 */
		$rows = apply_filters( 'woocommerce_subscriptions_lite_customer_portal_list_view_model', $rows );

		// Register the store namespace on the list page so the shared store is
		// present for the interactivity root and for any overlay that adds list
		// actions. The list is fully server-rendered (View links only), so no
		// per-row data needs seeding into client state.
		wp_interactivity_state( self::STORE_NAMESPACE, [] );

		wc_get_template(
			'myaccount/subscriptions.php',
			[
				'rows'           => $rows,
				'store'          => self::STORE_NAMESPACE,
				'detail_url_for' => [ $this, 'detail_url' ],
			],
			'',
			Package::get_path() . '/templates/'
		);
	}

	/**
	 * Render the detail page for a single contract.
	 *
	 * @param int|string $endpoint_value Contract id from the URL (or empty).
	 */
	public function render_detail( $endpoint_value = 0 ): void {
		$contract_id = absint( $endpoint_value );
		$customer_id = get_current_user_id();

		$contract = ( $contract_id > 0 && $customer_id > 0 )
			? Providers::resolve()->get_contract( $contract_id, $customer_id )
			: null;

		// Asymmetric not-found: an unknown id and a foreign-owned contract both
		// land here, indistinguishable to the requester.
		if ( null === $contract ) {
			wc_get_template(
				'myaccount/subscription-not-found.php',
				[ 'list_endpoint' => self::LIST_ENDPOINT ],
				'',
				Package::get_path() . '/templates/'
			);
			return;
		}

		$related_orders = Providers::resolve()->get_related_orders( $contract_id );
		$view_model     = new ViewModel();
		$detail         = $view_model->build_detail( $contract, $related_orders );

		/**
		 * Filters the assembled detail view-model before render.
		 *
		 * The value is a single associative detail view-model for one contract
		 * (the shape {@see ViewModel::build_detail()} returns). Additive-only: an
		 * overlay may decorate the existing fields but must not change the shape
		 * the template + iAPI store rely on.
		 *
		 * @since 0.0.1
		 *
		 * @param array<string, mixed> $detail The detail view-model.
		 */
		$detail = apply_filters( 'woocommerce_subscriptions_lite_customer_portal_detail_view_model', $detail );

		wp_interactivity_state(
			self::STORE_NAMESPACE,
			[
				'contractId'  => $detail['id'],
				'status'      => $detail['status'],
				'atPeriodEnd' => (bool) $detail['at_period_end'],
				'modalOpen'   => false,
				'submitting'  => false,
				// `error` backs the cancel modal's live region; `actionError`
				// backs the in-page Pause / Reactivate live region. Separate
				// fields so the two error regions never cross-render.
				'error'       => '',
				'actionError' => '',
				'restBase'    => Assets::rest_base(),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'cancelCopy'  => $detail['cancel_modal_copy'],
				// Translatable copy the store composes failure messages from, so
				// the strings stay in the text domain without a JS i18n runtime.
				'i18n'        => [
					'cancelError'     => __( 'We could not cancel your subscription.', 'woocommerce-subscriptions-lite' ),
					'holdError'       => __( 'We could not put your subscription on hold.', 'woocommerce-subscriptions-lite' ),
					'reactivateError' => __( 'We could not reactivate your subscription.', 'woocommerce-subscriptions-lite' ),
					'errorSuffix'     => __( 'Please try again or contact support.', 'woocommerce-subscriptions-lite' ),
				],
			]
		);

		wc_get_template(
			'myaccount/subscription-detail.php',
			[
				'detail' => $detail,
				'store'  => self::STORE_NAMESPACE,
			],
			'',
			Package::get_path() . '/templates/'
		);
	}

	/**
	 * Build the detail-page URL for a contract id.
	 *
	 * @param int $contract_id Contract id.
	 */
	public function detail_url( int $contract_id ): string {
		return wc_get_endpoint_url(
			self::DETAIL_ENDPOINT,
			(string) $contract_id,
			wc_get_page_permalink( 'myaccount' )
		);
	}
}
