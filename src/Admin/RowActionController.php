<?php
/**
 * RowActionController - the admin-post handlers behind the Renew now and Cancel
 * actions on the subscriptions list and detail page.
 *
 * Each action POSTs to `admin-post.php`; this class authenticates the request
 * (capability + nonce), drives the engine through its public
 * {@see \Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions} facade,
 * queues a flash notice, and redirects back.
 *
 * The decision logic ({@see self::handle_renew_now()}, {@see self::handle_cancel()})
 * is separated from the request plumbing ({@see self::renew_now_request()},
 * {@see self::cancel_request()}) so every guard branch is unit-testable without a
 * booted WordPress: the `handle_*` methods take a plain params array plus injected
 * seams and return a {@see RowActionResult}; the `*_request` methods read the
 * request, run the decision, flash, and redirect.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

use Throwable;
use WC_Order;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-post handlers for the state-mutating subscription actions.
 *
 * Construct via the no-arg constructor in production (the facade calls plus the
 * WordPress capability / nonce helpers); tests inject fake renew / cancel /
 * capability / nonce seams to drive every guard branch.
 */
final class RowActionController {

	/**
	 * Renewal runner. Production: `Subscriptions::renew_now()`.
	 *
	 * @var callable(int): ?WC_Order
	 */
	private $renew;

	/**
	 * Canceller. Production: `Subscriptions::cancel()`.
	 *
	 * @var callable(int): bool
	 */
	private $cancel;

	/**
	 * Capability check. Production: `current_user_can( PageController::CAPABILITY )`.
	 *
	 * @var callable(): bool
	 */
	private $can;

	/**
	 * Nonce verifier. Production: a `wp_verify_nonce()` wrapper bound to the action.
	 *
	 * @var callable(string, string): bool
	 */
	private $verify_nonce;

	/**
	 * Construct the controller.
	 *
	 * @param (callable(int): ?WC_Order)|null       $renew        Renewal runner; defaults to `Subscriptions::renew_now()`.
	 * @param (callable(int): bool)|null            $cancel       Canceller; defaults to `Subscriptions::cancel()`.
	 * @param (callable(): bool)|null               $can          Capability check; defaults to `current_user_can()`.
	 * @param (callable(string, string): bool)|null $verify_nonce Nonce verifier; defaults to a `wp_verify_nonce()` wrapper.
	 */
	public function __construct(
		?callable $renew = null,
		?callable $cancel = null,
		?callable $can = null,
		?callable $verify_nonce = null
	) {
		$this->renew        = $renew ?? static function ( int $id ): ?WC_Order {
			return Subscriptions::renew_now( $id );
		};
		$this->cancel       = $cancel ?? static function ( int $id ): bool {
			return Subscriptions::cancel( $id );
		};
		$this->can          = $can ?? static function (): bool {
			return current_user_can( PageController::CAPABILITY );
		};
		$this->verify_nonce = $verify_nonce ?? static function ( string $nonce, string $action ): bool {
			return false !== wp_verify_nonce( $nonce, $action );
		};
	}

	/**
	 * Bind the admin-post handlers. Called once from the bootstrap in an admin
	 * context.
	 */
	public static function register(): void {
		$instance = new self();
		add_action( 'admin_post_' . PageController::ACTION_RENEW_NOW, [ $instance, 'renew_now_request' ] );
		add_action( 'admin_post_' . PageController::ACTION_CANCEL, [ $instance, 'cancel_request' ] );
	}

	/**
	 * Request entry point for "Renew now": read + verify, run, flash, redirect.
	 */
	public function renew_now_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce is verified in handle_renew_now() via the injected verifier.
		$params = [
			'contract_id' => isset( $_GET['contract_id'] ) ? absint( wp_unslash( $_GET['contract_id'] ) ) : 0,
			'nonce'       => isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '',
		];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->handle_renew_now( $params );

		$this->guard_or_flash( $result );

		wp_safe_redirect( PageController::page_url() );
		exit;
	}

	/**
	 * Request entry point for "Cancel": read + verify, run, flash, redirect.
	 */
	public function cancel_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce is verified in handle_cancel() via the injected verifier.
		$params = [
			'contract_id' => isset( $_GET['contract_id'] ) ? absint( wp_unslash( $_GET['contract_id'] ) ) : 0,
			'nonce'       => isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '',
		];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->handle_cancel( $params );

		$this->guard_or_flash( $result );

		wp_safe_redirect( PageController::page_url() );
		exit;
	}

	/**
	 * Decide and perform a "Renew now".
	 *
	 * Guard order: capability, then nonce, then a valid contract id, then the
	 * facade renewal. A null facade return means the renewal was skipped (the
	 * contract's status did not allow one between render and click) - surfaced
	 * as an info outcome, not an error. Engine throwables become an error
	 * outcome rather than a fatal.
	 *
	 * @param array<string, mixed> $params Request params: `contract_id`, `nonce`.
	 * @return RowActionResult The outcome.
	 */
	public function handle_renew_now( array $params ): RowActionResult {
		$contract_id = isset( $params['contract_id'] ) ? (int) $params['contract_id'] : 0;

		$denied = $this->authorize( $params, PageController::ACTION_RENEW_NOW, $contract_id );
		if ( null !== $denied ) {
			return $denied;
		}

		try {
			$order = ( $this->renew )( $contract_id );
		} catch ( Throwable $e ) {
			return RowActionResult::failed(
				sprintf(
					/* translators: 1: subscription number, 2: error message. */
					__( 'Could not renew subscription #%1$d: %2$s', 'woocommerce-subscriptions-lite' ),
					$contract_id,
					$e->getMessage()
				)
			);
		}

		if ( null === $order ) {
			return RowActionResult::info(
				sprintf(
					/* translators: %d: subscription number. */
					__( 'Renewal skipped for subscription #%d - its status does not allow a renewal.', 'woocommerce-subscriptions-lite' ),
					$contract_id
				)
			);
		}

		return RowActionResult::success(
			sprintf(
				/* translators: 1: renewal order number, 2: subscription number. */
				__( 'Renewal order #%1$d created for subscription #%2$d.', 'woocommerce-subscriptions-lite' ),
				(int) $order->get_id(),
				$contract_id
			)
		);
	}

	/**
	 * Decide and perform an immediate "Cancel".
	 *
	 * Guard order: capability, then nonce, then a valid contract id, then the
	 * facade cancel. A false facade return means no such contract - surfaced as
	 * an error. Engine throwables become an error outcome rather than a fatal.
	 *
	 * @param array<string, mixed> $params Request params: `contract_id`, `nonce`.
	 * @return RowActionResult The outcome.
	 */
	public function handle_cancel( array $params ): RowActionResult {
		$contract_id = isset( $params['contract_id'] ) ? (int) $params['contract_id'] : 0;

		$denied = $this->authorize( $params, PageController::ACTION_CANCEL, $contract_id );
		if ( null !== $denied ) {
			return $denied;
		}

		try {
			$cancelled = ( $this->cancel )( $contract_id );
		} catch ( Throwable $e ) {
			return RowActionResult::failed(
				sprintf(
					/* translators: 1: subscription number, 2: error message. */
					__( 'Could not cancel subscription #%1$d: %2$s', 'woocommerce-subscriptions-lite' ),
					$contract_id,
					$e->getMessage()
				)
			);
		}

		if ( ! $cancelled ) {
			return RowActionResult::failed( __( 'Subscription not found.', 'woocommerce-subscriptions-lite' ) );
		}

		return RowActionResult::success(
			sprintf(
				/* translators: %d: subscription number. */
				__( 'Subscription #%d cancelled.', 'woocommerce-subscriptions-lite' ),
				$contract_id
			)
		);
	}

	/**
	 * Shared capability + nonce + id guard. Returns a denied result, or null
	 * when the request may proceed.
	 *
	 * @param array<string, mixed> $params      Request params.
	 * @param string               $action      Action constant (for the nonce).
	 * @param int                  $contract_id Target contract id.
	 * @return RowActionResult|null Denied outcome, or null to proceed.
	 */
	private function authorize( array $params, string $action, int $contract_id ): ?RowActionResult {
		if ( ! ( $this->can )() ) {
			return RowActionResult::forbidden( __( 'You do not have permission to manage subscriptions.', 'woocommerce-subscriptions-lite' ) );
		}

		$nonce = isset( $params['nonce'] ) ? (string) $params['nonce'] : '';
		if ( ! ( $this->verify_nonce )( $nonce, $action . '_' . $contract_id ) ) {
			return RowActionResult::forbidden( __( 'Your link has expired. Please try again.', 'woocommerce-subscriptions-lite' ) );
		}

		if ( $contract_id <= 0 ) {
			return RowActionResult::failed( __( 'Subscription not found.', 'woocommerce-subscriptions-lite' ) );
		}

		return null;
	}

	/**
	 * A forbidden outcome halts with a 403; anything else flashes for the next
	 * render. Keeps the capability/nonce refusal an explicit hard stop while
	 * other outcomes round-trip through the list page's notice.
	 *
	 * @param RowActionResult $result The decided outcome.
	 */
	private function guard_or_flash( RowActionResult $result ): void {
		if ( $result->is_forbidden() ) {
			wp_die( esc_html( $result->message() ), '', [ 'response' => 403 ] );
		}

		PageController::set_flash_notice( $result->type(), $result->message() );
	}
}
