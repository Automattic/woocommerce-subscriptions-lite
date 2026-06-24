<?php
/**
 * PostActionNoticeListener - queues customer-facing notices after a portal action.
 *
 * After a lifecycle action (cancel / hold / reactivate) succeeds, the portal
 * queues a WooCommerce success notice that rides the customer's session across
 * the JS-driven refresh and renders on the next page via `wc_print_notices()`.
 *
 * The listener subscribes to Lite-side post-action signals - namespaced action
 * hooks the portal fires when an action completes. Binding to the engine's own
 * lifecycle events is the eventual source of truth (so a non-portal consumer of
 * the same engine action does not get a portal notice queued by default); that
 * engine-event binding is a documented swap point, wired when the engine REST +
 * lifecycle land. The notice copy and the explicit session-persist behaviour
 * are unchanged either way.
 *
 * `wc_add_notice()` writes into the WC session, which exists for cookie-auth
 * frontend requests but not for WP-CLI / cron / unauthenticated REST. The
 * listener guards on a frontend context and on `WC()->session` being available,
 * and persists the session to the DB immediately so the notice survives the
 * redirect (REST requests bypass WC's `shutdown` save hook).
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal;

defined( 'ABSPATH' ) || exit;

/**
 * Queues post-action customer notices for the portal.
 */
final class PostActionNoticeListener {

	/**
	 * Fired by the portal when a cancel completes. Args: contract id (int),
	 * at-period-end (bool).
	 */
	const ACTION_CANCELLED = 'woocommerce_subscriptions_lite_customer_portal_cancelled';

	/**
	 * Fired by the portal when a hold completes. Args: contract id (int).
	 */
	const ACTION_HELD = 'woocommerce_subscriptions_lite_customer_portal_held';

	/**
	 * Fired by the portal when a reactivate completes. Args: contract id (int).
	 */
	const ACTION_REACTIVATED = 'woocommerce_subscriptions_lite_customer_portal_reactivated';

	/**
	 * Wire the listener. Idempotent; called once from the bootstrap.
	 *
	 * Swap point: when the engine exposes lifecycle events, ALSO (or instead)
	 * bind those here so a server-side transition that did not originate in the
	 * portal still surfaces the right notice. The Lite-side signals below stay
	 * valid - they are the portal's own post-action hooks.
	 */
	public static function register(): void {
		$instance = new self();
		add_action( self::ACTION_CANCELLED, [ $instance, 'on_cancelled' ], 10, 2 );
		add_action( self::ACTION_HELD, [ $instance, 'on_held' ] );
		add_action( self::ACTION_REACTIVATED, [ $instance, 'on_reactivated' ] );
	}

	/**
	 * Queue the post-cancel notice. Copy depends on the resolved cancel mode:
	 * at-period-end narrates the scheduled cancellation; immediate confirms it
	 * is done.
	 *
	 * @param int  $contract_id   Contract id (unused in copy; kept for hook parity).
	 * @param bool $at_period_end Whether the cancel deferred to the cycle end.
	 */
	public function on_cancelled( int $contract_id, bool $at_period_end = false ): void {
		unset( $contract_id );
		$this->queue_notice( $this->cancel_message( $at_period_end ) );
	}

	/**
	 * Queue the post-hold notice.
	 *
	 * @param int $contract_id Contract id (unused in copy; kept for hook parity).
	 */
	public function on_held( int $contract_id = 0 ): void {
		unset( $contract_id );
		$this->queue_notice( __( 'Your subscription has been put on hold.', 'woocommerce-subscriptions-lite' ) );
	}

	/**
	 * Queue the post-reactivate notice.
	 *
	 * @param int $contract_id Contract id (unused in copy; kept for hook parity).
	 */
	public function on_reactivated( int $contract_id = 0 ): void {
		unset( $contract_id );
		$this->queue_notice( __( 'Your subscription has been reactivated.', 'woocommerce-subscriptions-lite' ) );
	}

	/**
	 * The cancel notice copy for a resolved cancel mode.
	 *
	 * Pure - no session, no WC dependency - so the per-outcome copy is unit
	 * testable without a booted WooCommerce.
	 *
	 * @param bool $at_period_end Whether the cancel deferred to the cycle end.
	 */
	public function cancel_message( bool $at_period_end ): string {
		if ( $at_period_end ) {
			return __( 'Your subscription is scheduled to cancel at the end of the current billing cycle.', 'woocommerce-subscriptions-lite' );
		}
		return __( 'Your subscription has been cancelled.', 'woocommerce-subscriptions-lite' );
	}

	/**
	 * Queue a success notice and persist the session so it survives the refresh.
	 *
	 * @param string $message The notice copy.
	 */
	private function queue_notice( string $message ): void {
		if ( ! $this->can_queue_notice() ) {
			return;
		}
		wc_add_notice( $message, 'success' );
		$this->persist_session_to_db();
	}

	/**
	 * Whether there is a customer-facing session to ride across the refresh.
	 *
	 * Bails for admin (non-ajax), cron, and CLI contexts - none of which are
	 * riding a customer's redirect - and force-initialises the WC session for
	 * the cookie-auth frontend request (REST routes do not init it by default).
	 */
	private function can_queue_notice(): bool {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_add_notice' ) ) {
			return false;
		}
		if ( is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return false;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( null === WC()->session && method_exists( WC(), 'initialize_session' ) ) {
			WC()->initialize_session();
		}
		return null !== WC()->session;
	}

	/**
	 * Flush the WC session to the DB now rather than waiting for the `shutdown`
	 * save hook (which REST requests bypass), so the queued notice persists to
	 * the redirect target.
	 */
	private function persist_session_to_db(): void {
		$session = WC()->session;
		if ( null !== $session && method_exists( $session, 'save_data' ) ) {
			$session->save_data();
		}
	}
}
