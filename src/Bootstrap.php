<?php
/**
 * Package bootstrap for WooCommerce Subscriptions Lite.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the Lite feature modules into WordPress and WooCommerce.
 *
 * The wrapper plugin calls {@see self::init()} once the engine has resolved at
 * runtime. Each feature module is a small registration site that binds its own
 * hooks; this class only orchestrates which modules load. Its `$initialized`
 * guard is the single idempotency gate: modules register unconditionally and
 * rely on init() running once.
 */
final class Bootstrap {

	/**
	 * Guards against double-initialization if `init()` is called more than once.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Boot the Lite feature modules.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		// Boot the subscriptions engine. The engine is consumed as a bundled
		// composer package; while it is not yet part of WooCommerce core, the
		// consumer is responsible for initializing it (the engine's own
		// Package::init wires its integration layer - storage, schema, services).
		// Guarded so Lite degrades gracefully if the engine class is unavailable.
		if ( class_exists( \Automattic\WooCommerce\SubscriptionsEngine\Package::class ) ) {
			\Automattic\WooCommerce\SubscriptionsEngine\Package::init();

			// Declare Lite as a consumer of the engine with its due handler. Lite renews its
			// own contracts; the engine's due scan only notifies the owner when a contract is
			// due. Registered on every load so the handler is present for the scan's own
			// scheduled request, not just interactive ones.
			\Automattic\WooCommerce\SubscriptionsEngine\Integration\Ownership\ConsumerRegistry::register(
				Package::EXTENSION_SLUG,
				[ 'contract_due_handler' => [ new Renewal\DueHandler(), 'handle' ] ]
			);
		}

		// Product detail page: the plan picker on the add-to-cart form and the
		// per-variation option HTML in variation payloads. Both register
		// unconditionally - variation payloads are also assembled in admin
		// contexts (product previews).
		ProductPage\PlanPicker::register();
		ProductPage\VariationPlanData::register();

		// Cart: carry the chosen plan through cart item data, price the line at
		// the recurring amount, and write the plan onto the order line item.
		Cart\CartPlanHooks::register();

		// Pricing: validate Lite's pricing terms on plan writes through
		// the engine's plans REST route (REST requests are not is_admin()).
		Pricing\PlanWriteValidation::register();

		// Plans: the REST route that saves Lite's plan display order, used by
		// the plans manager (REST requests are not is_admin()).
		Plans\PlanOrderController::register();

		// Cart/Checkout Blocks: expose per-item plan data on core's Store API and
		// enqueue the checkout filters (frequency suffix + "Total due today").
		// Guarded so Lite still loads on WooCommerce builds without Blocks.
		Cart\Blocks\StoreApiExtension::register();
		Cart\Blocks\Integration::register();

		// Checkout: on payment, turn the order into a subscription contract through
		// the engine's contracts facade; require an account for subscription carts;
		// tell gateways when a checkout must leave a reusable payment method; and show
		// the new subscription on the order-received page.
		Checkout\ContractCreationHandler::register();
		Checkout\AccountRequirement::register();
		Checkout\PaymentRequirement::register();
		Checkout\OrderReceived::register();

		// Renewal: settle Lite renewal orders from their status. Gateways report charge
		// outcomes from any request (Action Scheduler, webhooks), so this registers on
		// every request.
		Renewal\Settlement::register();

		// Customer portal (My Account): the subscriptions list + single
		// subscription detail, with the lifecycle actions (cancel / hold /
		// reactivate) over the namespaced Interactivity API store. Endpoints
		// register the My Account surfaces; Assets loads the iAPI store + styles
		// on those endpoints; the customer actions are registered on the engine's
		// contract action endpoint the store posts to.
		CustomerPortal\Endpoints::register();
		CustomerPortal\Assets::register();
		Lifecycle\CustomerActions::register();

		// Admin (back office only): the WooCommerce > Subscriptions list + detail
		// page and its Renew now / Cancel handlers (Lite's renewal and cancel flows).
		// Front-end requests never need this.
		if ( is_admin() ) {
			Admin\PageController::register();
			Admin\RowActionController::register();

			// Register the WooCommerce Settings > Subscriptions tab (plans manager).
			Admin\SettingsPage::register();

			// Product edit screen: the Subscriptions product-data tab writing
			// Lite-owned plan applicability, validated against the engine's
			// plans catalog.
			Admin\ProductPlansPanel::register();
		}

		// Email: contract and renewal notifications.
		// TODO: Email module - register once the email widening slice lands.
	}
}
