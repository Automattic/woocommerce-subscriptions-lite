<?php
/**
 * RowActionController - the admin-post handlers behind the Renew now and Cancel
 * actions on the subscriptions list and detail page.
 *
 * Each action POSTs to `admin-post.php`; the request entry point authenticates
 * the request (capability and a POST nonce verified with `check_admin_referer()`),
 * drives the engine through its public
 * {@see \Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions} facade,
 * queues a flash notice, and redirects back.
 *
 * The decision logic ({@see self::handle_renew_now()}, {@see self::handle_cancel()})
 * is separated from the request plumbing ({@see self::renew_now_request()},
 * {@see self::cancel_request()}) so its branches are unit-testable without a
 * booted WordPress: the `handle_*` methods take a plain params array plus injected
 * seams and return a {@see RowActionResult}; the `*_request` methods read `$_POST`,
 * verify the nonce, run the decision, flash, and redirect. The nonce is enforced
 * at the request boundary (a hard `check_admin_referer()` gate) rather than in the
 * decision method, so it never rides in a URL.
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
 * Construct via the no-arg constructor in production (the facade calls, the
 * capability helper, and the WooCommerce logger); tests inject fake renew /
 * cancel / capability / logger seams to drive every decision branch.
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
	 * Error logger. Production: `wc_get_logger()->error()`. Receives the message
	 * and a context array.
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $log_error;

	/**
	 * Construct the controller.
	 *
	 * @param (callable(int): ?WC_Order)|null                     $renew     Renewal runner; defaults to `Subscriptions::renew_now()`.
	 * @param (callable(int): bool)|null                          $cancel    Canceller; defaults to `Subscriptions::cancel()`.
	 * @param (callable(): bool)|null                             $can       Capability check; defaults to `current_user_can()`.
	 * @param (callable(string, array<string, mixed>): void)|null $log_error Error logger; defaults to `wc_get_logger()->error()`.
	 */
	public function __construct(
		?callable $renew = null,
		?callable $cancel = null,
		?callable $can = null,
		?callable $log_error = null
	) {
		$this->renew     = $renew ?? static function ( int $id ): ?WC_Order {
			return Subscriptions::renew_now( $id );
		};
		$this->cancel    = $cancel ?? static function ( int $id ): bool {
			return Subscriptions::cancel( $id );
		};
		$this->can       = $can ?? static function (): bool {
			return current_user_can( PageController::CAPABILITY );
		};
		$this->log_error = $log_error ?? static function ( string $message, array $context ): void {
			wc_get_logger()->error( $message, $context );
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
	 * Request entry point for "Renew now": read POST, verify the nonce, run,
	 * flash, redirect.
	 */
	public function renew_now_request(): void {
		$contract_id = $this->read_contract_id();
		check_admin_referer( PageController::ACTION_RENEW_NOW . '_' . $contract_id );

		$result = $this->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->guard_or_flash( $result );

		wp_safe_redirect( PageController::page_url() );
		exit;
	}

	/**
	 * Request entry point for "Cancel": read POST, verify the nonce, run, flash,
	 * redirect.
	 */
	public function cancel_request(): void {
		$contract_id = $this->read_contract_id();
		check_admin_referer( PageController::ACTION_CANCEL . '_' . $contract_id );

		$result = $this->handle_cancel( [ 'contract_id' => $contract_id ] );

		$this->guard_or_flash( $result );

		wp_safe_redirect( PageController::page_url() );
		exit;
	}

	/**
	 * Read the target contract id from the POST body.
	 *
	 * The nonce that authenticates this value is checked by `check_admin_referer()`
	 * immediately after, in the request methods.
	 */
	private function read_contract_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller verifies the nonce via check_admin_referer() right after reading the id.
		return isset( $_POST['contract_id'] ) ? absint( wp_unslash( $_POST['contract_id'] ) ) : 0;
	}

	/**
	 * Decide and perform a "Renew now".
	 *
	 * Guard order: capability, then a valid contract id, then the facade renewal.
	 * A null facade return means the renewal was not processable - the contract is
	 * awaiting a payment confirmation, inactive, or has no billing chain - surfaced
	 * as an info outcome, not an error. An engine throwable is logged in full and
	 * surfaced as a generic error outcome rather than a fatal or a leaked internal message.
	 *
	 * @param array<string, mixed> $params Request params: `contract_id`.
	 * @return RowActionResult The outcome.
	 */
	public function handle_renew_now( array $params ): RowActionResult {
		$contract_id = isset( $params['contract_id'] ) ? (int) $params['contract_id'] : 0;

		$denied = $this->authorize( $contract_id );
		if ( null !== $denied ) {
			return $denied;
		}

		try {
			$order = ( $this->renew )( $contract_id );
		} catch ( Throwable $e ) {
			$this->log_failure( 'renew', $contract_id, $e );
			return RowActionResult::failed(
				__( 'Renewal could not be processed. Check the WooCommerce logs for details.', 'woocommerce-subscriptions-lite' )
			);
		}

		if ( null === $order ) {
			return RowActionResult::info(
				sprintf(
					/* translators: %d: subscription number. */
					__( 'Renewal skipped for subscription #%d - it is not currently renewable (it may be awaiting a payment confirmation or no longer active).', 'woocommerce-subscriptions-lite' ),
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
	 * Guard order: capability, then a valid contract id, then the facade cancel.
	 * A false facade return means no such contract - surfaced as an error. An
	 * engine throwable is logged in full and surfaced as a generic error outcome
	 * rather than a fatal or a leaked internal message.
	 *
	 * @param array<string, mixed> $params Request params: `contract_id`.
	 * @return RowActionResult The outcome.
	 */
	public function handle_cancel( array $params ): RowActionResult {
		$contract_id = isset( $params['contract_id'] ) ? (int) $params['contract_id'] : 0;

		$denied = $this->authorize( $contract_id );
		if ( null !== $denied ) {
			return $denied;
		}

		try {
			$cancelled = ( $this->cancel )( $contract_id );
		} catch ( Throwable $e ) {
			$this->log_failure( 'cancel', $contract_id, $e );
			return RowActionResult::failed(
				__( 'The subscription could not be cancelled. Check the WooCommerce logs for details.', 'woocommerce-subscriptions-lite' )
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
	 * Shared capability + id guard. Returns a denied result, or null when the
	 * request may proceed. The nonce is enforced separately at the request
	 * boundary via `check_admin_referer()`.
	 *
	 * @param int $contract_id Target contract id.
	 * @return RowActionResult|null Denied outcome, or null to proceed.
	 */
	private function authorize( int $contract_id ): ?RowActionResult {
		if ( ! ( $this->can )() ) {
			return RowActionResult::forbidden( __( 'You do not have permission to manage subscriptions.', 'woocommerce-subscriptions-lite' ) );
		}

		if ( $contract_id <= 0 ) {
			return RowActionResult::failed( __( 'Subscription not found.', 'woocommerce-subscriptions-lite' ) );
		}

		return null;
	}

	/**
	 * Log a failed action with its full exception for the merchant to inspect.
	 *
	 * @param string    $action      `renew` or `cancel`.
	 * @param int       $contract_id Target contract id.
	 * @param Throwable $e           The caught exception.
	 */
	private function log_failure( string $action, int $contract_id, Throwable $e ): void {
		( $this->log_error )(
			sprintf( 'Admin subscription %1$s failed for #%2$d: %3$s', $action, $contract_id, $e->getMessage() ),
			[
				'source'      => 'woocommerce-subscriptions-lite',
				'contract_id' => $contract_id,
				'exception'   => $e,
			]
		);
	}

	/**
	 * A forbidden outcome halts with a 403; anything else flashes for the next
	 * render. Keeps the capability refusal an explicit hard stop while other
	 * outcomes round-trip through the list page's notice.
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
