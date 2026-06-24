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
 * hooks; this class only orchestrates which modules load. Feature logic is
 * intentionally absent at the scaffold stage - the modules below depend on the
 * engine's public surface, which lands in a later phase.
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
				'woocommerce-subscriptions-lite'
			);
		}

		// Product detail page: render the subscription plan picker and feed the
		// chosen plan into the add-to-cart flow.
		// TODO: PDP module - register once the PDP widening slice lands.

		// Cart: carry the chosen plan through cart item data and totals.
		// TODO: Cart module - register once the PDP widening slice lands.

		// Checkout: turn a completed order into a subscription contract via the
		// engine factory, then schedule its first renewal.
		Checkout\ContractCreationHandler::register();

		// Customer portal (My Account): the subscriptions list + single
		// subscription detail, with the lifecycle actions (cancel / hold /
		// reactivate) over the namespaced Interactivity API store. Endpoints
		// register the My Account surfaces; Assets loads the iAPI store + styles
		// on those endpoints; the notice listener queues post-action notices.
		CustomerPortal\Endpoints::register();
		CustomerPortal\Assets::register();
		CustomerPortal\PostActionNoticeListener::register();

		// Admin (back office only): the WooCommerce > Subscriptions list + detail
		// page and its Renew now / Cancel handlers, driven through the engine's
		// public Api\Subscriptions facade. Front-end requests never need this.
		if ( is_admin() ) {
			Admin\PageController::register();
			Admin\RowActionController::register();

			// Register the plans page.
			Admin\PlansPage::register();
		}

		// Email: contract and renewal notifications.
		// TODO: Email module - register once the email widening slice lands.
	}
}
