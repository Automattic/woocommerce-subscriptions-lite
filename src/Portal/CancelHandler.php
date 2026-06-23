<?php
/**
 * CancelHandler - the authenticated My Account cancel-subscription handler.
 *
 * The portal's one write action in Slice 0. A logged-in customer submits the
 * cancel form; this handler authenticates the request (logged-in session +
 * nonce), resolves the contract, enforces ownership, checks the status is
 * cancelable, and asks the engine to cancel. There is no Store API: this is a
 * plain front-end form post handled on `template_redirect`, with the logged-in
 * check, nonce, and ownership guard enforced in {@see self::handle()}.
 *
 * **Asymmetric not-found.** A contract that does not exist and a contract owned
 * by another customer both resolve to {@see CancelResult::NOT_FOUND}, so the
 * portal never confirms the existence of a contract the requester does not own.
 *
 * The decision logic ({@see self::handle()}) is separated from the request
 * plumbing ({@see self::handle_request()}) so every guard branch is unit-testable
 * without a booted WordPress: `handle()` takes a plain params array and injected
 * seams and returns a {@see CancelResult}; `handle_request()` reads `$_POST`,
 * runs the decision, queues a notice, and redirects.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Portal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Portal;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Renewal\RenewalEngine;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\ContractRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated cancel handler for the customer portal.
 *
 * Construct via the no-arg constructor in production (engine defaults + the
 * WordPress current-user / nonce helpers); tests inject fake finder / canceller
 * / current-user / nonce seams to drive every guard branch.
 */
final class CancelHandler {

	/**
	 * Hidden form-field value identifying a cancel submission. The handler runs on
	 * `template_redirect` and acts only when `$_POST['action']` matches this slug.
	 */
	const ACTION = 'woocommerce_subscriptions_lite_cancel';

	/**
	 * The nonce action the form's nonce field is created against.
	 */
	const NONCE_ACTION = 'woocommerce_subscriptions_lite_cancel';

	/**
	 * The nonce request field name.
	 */
	const NONCE_FIELD = 'woocommerce_subscriptions_lite_cancel_nonce';

	/**
	 * Statuses a customer may cancel from. Mirrors the round-1 portal: `active`
	 * and `on-hold` are cancelable; terminal and pending-cancellation states are
	 * not (a customer-driven re-cancel of a winding-down contract is a
	 * merchant-only decision).
	 *
	 * @var array<int, string>
	 */
	private const CANCELABLE_STATUSES = [ ContractStatus::ACTIVE, ContractStatus::ON_HOLD ];

	/**
	 * Contract finder. Production: `ContractRepository::find()`.
	 *
	 * @var callable(int): ?Contract
	 */
	private $contract_finder;

	/**
	 * Engine canceller. Production: `RenewalEngine::cancel()`.
	 *
	 * @var callable(Contract): bool
	 */
	private $canceller;

	/**
	 * Current-user resolver. Production: `get_current_user_id()`.
	 *
	 * @var callable(): int
	 */
	private $current_user;

	/**
	 * Nonce verifier. Production: a `wp_verify_nonce()` wrapper.
	 *
	 * @var callable(string): bool
	 */
	private $verify_nonce;

	/**
	 * Construct the handler.
	 *
	 * @param (callable(int): ?Contract)|null $contract_finder Finder; defaults to `ContractRepository::find()`.
	 * @param (callable(Contract): bool)|null $canceller       Canceller; defaults to `RenewalEngine::cancel()`.
	 * @param (callable(): int)|null          $current_user    Current-user resolver; defaults to `get_current_user_id()`.
	 * @param (callable(string): bool)|null   $verify_nonce    Nonce verifier; defaults to a `wp_verify_nonce()` wrapper.
	 */
	public function __construct(
		?callable $contract_finder = null,
		?callable $canceller = null,
		?callable $current_user = null,
		?callable $verify_nonce = null
	) {
		$this->contract_finder = $contract_finder ?? static function ( int $id ): ?Contract {
			return ( new ContractRepository() )->find( $id );
		};
		$this->canceller       = $canceller ?? static function ( Contract $contract ): bool {
			return ( new RenewalEngine() )->cancel( $contract );
		};
		$this->current_user    = $current_user ?? static function (): int {
			return (int) get_current_user_id();
		};
		$this->verify_nonce    = $verify_nonce ?? static function ( string $nonce ): bool {
			return false !== wp_verify_nonce( $nonce, self::NONCE_ACTION );
		};
	}

	/**
	 * Bind the cancel-form handler. Called once from the bootstrap.
	 *
	 * Handled on `template_redirect` (a front-end context) rather than through
	 * `admin-post.php`, so the post-cancel notice goes through WooCommerce's
	 * front-end notice system the way its own My Account forms do - `wc_add_notice()`
	 * is not loaded in the admin-post request. The handler is inert unless our
	 * cancel POST is present, and {@see self::handle()} still enforces the
	 * logged-in + nonce + ownership guards, so an anonymous or forged post is
	 * refused there.
	 */
	public static function register(): void {
		$instance = new self();
		add_action( 'template_redirect', [ $instance, 'handle_request' ] );
	}

	/**
	 * Request entry point: ignore everything except our cancel POST, then read +
	 * verify the nonce, run the decision, queue a notice, and redirect back to the
	 * My Account subscriptions page.
	 *
	 * Bound on `template_redirect`, so it fires on every front-end view and bails
	 * immediately unless the request is our cancel submission. All branching lives
	 * in {@see self::handle()}; this method only adapts the WordPress request
	 * (superglobals, nonce, notice, redirect) to it.
	 */
	public function handle_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		// Route only our own cancel POST - template_redirect fires on every
		// front-end view. The nonce is read here and verified inside handle() via
		// the injected verifier, so the sniff does not apply to this routing and
		// extraction step.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
		if ( 'POST' !== $method || self::ACTION !== $action ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) )
			: '';

		$params = [
			'contract_id' => isset( $_POST['contract_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['contract_id'] ) ) : '',
			'nonce'       => $nonce,
		];
		// phpcs:enable WordPress.Security.NonceVerification

		$result = $this->handle( $params );

		$this->add_notice_for( $result );

		wp_safe_redirect( $this->redirect_url() );
		exit;
	}

	/**
	 * Decide and perform the cancel for `$params`.
	 *
	 * Guard order: authentication, then nonce, then resolve + ownership, then
	 * status, then the engine cancel. Ownership failure and a missing contract
	 * both return {@see CancelResult::NOT_FOUND}.
	 *
	 * @param array<string, mixed> $params Request params: `contract_id`, `nonce`.
	 * @return CancelResult The outcome.
	 */
	public function handle( array $params ): CancelResult {
		$user_id = ( $this->current_user )();
		if ( $user_id <= 0 ) {
			return new CancelResult( CancelResult::NOT_AUTHENTICATED );
		}

		$nonce = isset( $params['nonce'] ) ? (string) $params['nonce'] : '';
		if ( ! ( $this->verify_nonce )( $nonce ) ) {
			return new CancelResult( CancelResult::BAD_NONCE );
		}

		$contract_id = isset( $params['contract_id'] ) ? (int) $params['contract_id'] : 0;
		if ( $contract_id <= 0 ) {
			return new CancelResult( CancelResult::NOT_FOUND );
		}

		$contract = ( $this->contract_finder )( $contract_id );

		// Asymmetric not-found: a missing contract and a contract owned by another
		// customer are indistinguishable to the requester.
		if ( ! $contract instanceof Contract || $contract->get_customer_id() !== $user_id ) {
			return new CancelResult( CancelResult::NOT_FOUND );
		}

		if ( ! in_array( $contract->get_status(), self::CANCELABLE_STATUSES, true ) ) {
			return new CancelResult( CancelResult::NOT_CANCELABLE );
		}

		( $this->canceller )( $contract );

		return new CancelResult( CancelResult::CANCELLED );
	}

	/**
	 * Queue the WooCommerce notice that matches `$result`.
	 *
	 * `wc_add_notice()` is registered by WooCommerce's front-end includes only.
	 * This runs on `template_redirect`, where it is loaded, but guard anyway so a
	 * non-front-end invocation degrades to no notice instead of a fatal.
	 *
	 * @param CancelResult $result The cancel outcome.
	 */
	private function add_notice_for( CancelResult $result ): void {
		if ( ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		switch ( $result->code() ) {
			case CancelResult::CANCELLED:
				wc_add_notice( __( 'Your subscription has been cancelled.', 'woocommerce-subscriptions-lite' ), 'success' );
				break;
			case CancelResult::NOT_FOUND:
				wc_add_notice( __( 'Subscription not found.', 'woocommerce-subscriptions-lite' ), 'error' );
				break;
			case CancelResult::NOT_CANCELABLE:
				wc_add_notice( __( 'This subscription cannot be cancelled.', 'woocommerce-subscriptions-lite' ), 'error' );
				break;
			default:
				// Authentication / nonce failures: a generic refusal, no detail.
				wc_add_notice( __( 'We could not process that request. Please try again.', 'woocommerce-subscriptions-lite' ), 'error' );
				break;
		}
	}

	/**
	 * The My Account subscriptions URL to redirect back to after a cancel.
	 */
	private function redirect_url(): string {
		return wc_get_endpoint_url( SubscriptionsEndpoint::ENDPOINT, '', wc_get_page_permalink( 'myaccount' ) );
	}
}
