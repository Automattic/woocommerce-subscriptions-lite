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

			// Declare Lite as a consumer of the engine. The engine stays inert until a
			// consumer registers - its batch renewal dispatcher charges nothing while the
			// consumer registry is empty - so this registration is what puts the engine to
			// work on Lite's behalf. Registered on every load so it is present for the
			// dispatcher's own scheduled request, not just interactive ones.
			\Automattic\WooCommerce\SubscriptionsEngine\Integration\Ownership\ConsumerRegistry::register(
				Package::EXTENSION_SLUG
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

		// Cart/Checkout Blocks: expose per-item plan data on core's Store API and
		// enqueue the checkout filters (frequency suffix + "Total due today").
		// Guarded so Lite still loads on WooCommerce builds without Blocks.
		Cart\Blocks\StoreApiExtension::register();
		Cart\Blocks\Integration::register();

		// Checkout: turn a completed order into a subscription contract via the
		// engine factory, then schedule its first renewal.
		Checkout\ContractCreationHandler::register();

		// Customer portal (My Account): the subscriptions list + single
		// subscription detail, with the lifecycle actions (cancel / hold /
		// reactivate) over the namespaced Interactivity API store. Endpoints
		// register the My Account surfaces; Assets loads the iAPI store + styles
		// on those endpoints.
		CustomerPortal\Endpoints::register();
		CustomerPortal\Assets::register();

		// Admin (back office only): the WooCommerce > Subscriptions list + detail
		// page and its Renew now / Cancel handlers, driven through the engine's
		// public Api\Subscriptions facade. Front-end requests never need this.
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
