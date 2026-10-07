<?php
/**
 * ContractActionsController - the customer's subscription action routes.
 *
 * Namespace `wc-subscriptions-lite/v1`, base `contracts`:
 *
 *   POST /{id}/hold        Put an active subscription on hold.
 *   POST /{id}/reactivate  Resume a held subscription.
 *   POST /{id}/cancel      Cancel; body `{ at_period_end: bool }`, default true.
 *
 * A logged-in customer may act on their own visible contracts only: an unknown, foreign
 * or draft contract is the same 404, so a caller cannot probe for other contracts. A
 * response is the `{ id, status }` summary of the resulting contract.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Rest
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Rest;

use Throwable;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsLite\Contracts\CustomerVisibility;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Cancellation;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\LifecycleNotAllowed;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Reactivation;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the customer contract actions.
 */
final class ContractActionsController extends WP_REST_Controller {

	/**
	 * Set the route namespace and base.
	 */
	public function __construct() {
		$this->namespace = 'wc-subscriptions-lite/v1';
		$this->rest_base = 'contracts';
	}

	/**
	 * Register the routes on `rest_api_init`. Called once from the bootstrap.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', [ new self(), 'register_routes' ] );
	}

	/**
	 * Register the action routes.
	 */
	public function register_routes(): void {
		$this->register_action_route( 'hold' );
		$this->register_action_route( 'reactivate' );
		$this->register_action_route(
			'cancel',
			[
				'at_period_end' => [
					'description'       => __( 'Whether to cancel at the end of the current billing period (true) or immediately (false).', 'woocommerce-subscriptions-lite' ),
					'type'              => 'boolean',
					'default'           => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'validate_callback' => 'rest_validate_request_arg',
				],
			]
		);
	}

	/**
	 * Register `POST /{id}/{action}`, handled by `{action}_item()`.
	 *
	 * @param string               $action        Action segment.
	 * @param array<string, mixed> $endpoint_args Body arguments of the action.
	 */
	private function register_action_route( string $action, array $endpoint_args = [] ): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/' . $action,
			[
				'args'   => [
					'id' => [
						'description'       => __( 'Unique identifier for the subscription.', 'woocommerce-subscriptions-lite' ),
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, $action . '_item' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => $endpoint_args,
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * Any logged-in user passes; per-contract ownership is checked by the handlers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permissions_check( $request ) {
		if ( is_user_logged_in() ) {
			return true;
		}

		return new WP_Error(
			'woocommerce_subscriptions_lite_not_authenticated',
			__( 'You must be logged in to manage subscriptions.', 'woocommerce-subscriptions-lite' ),
			[ 'status' => 401 ]
		);
	}

	/**
	 * POST /{id}/hold.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function hold_item( $request ) {
		return $this->run_action( $request, 'hold' );
	}

	/**
	 * POST /{id}/reactivate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reactivate_item( $request ) {
		return $this->run_action( $request, 'reactivate' );
	}

	/**
	 * POST /{id}/cancel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_item( $request ) {
		return $this->run_action( $request, 'cancel' );
	}

	/**
	 * The `{ id, status }` summary of a contract.
	 *
	 * @param ContractView    $item    Contract.
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$data = $this->add_additional_fields_to_object(
			[
				'id'     => $item->get_id(),
				'status' => $item->get_status(),
			],
			$request
		);

		return rest_ensure_response( $data );
	}

	/**
	 * The action response schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( ! $this->schema ) {
			$this->schema = [
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'woocommerce_subscriptions_lite_contract_action',
				'type'       => 'object',
				'properties' => [
					'id'     => [
						'description' => __( 'Unique identifier for the subscription.', 'woocommerce-subscriptions-lite' ),
						'type'        => 'integer',
						'context'     => [ 'view' ],
						'readonly'    => true,
					],
					'status' => [
						'description' => __( 'Subscription status after the action.', 'woocommerce-subscriptions-lite' ),
						'type'        => 'string',
						'context'     => [ 'view' ],
						'readonly'    => true,
					],
				],
			];
		}

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Run `$action` on the requester's contract and map the outcome to a response:
	 * not allowed in the current status is a 409, any other failure a logged 500.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $action  `hold`, `reactivate` or `cancel`.
	 * @return WP_REST_Response|WP_Error
	 */
	private function run_action( WP_REST_Request $request, string $action ) {
		$contract_id = (int) $request->get_param( 'id' );
		$contract    = Contracts::get_for_customer( $contract_id, get_current_user_id() );
		if ( null === $contract || ! CustomerVisibility::is_visible( $contract ) ) {
			return $this->get_not_found_error();
		}

		try {
			switch ( $action ) {
				case 'hold':
					$result = ( new Hold() )->hold( $contract_id );
					break;
				case 'reactivate':
					$result = ( new Reactivation() )->reactivate( $contract_id );
					break;
				default: // cancel.
					$cancellation = new Cancellation();
					$result       = false === $request->get_param( 'at_period_end' )
						? $cancellation->cancel( $contract_id )
						: $cancellation->cancel_at_period_end( $contract_id );
					break;
			}
		} catch ( LifecycleNotAllowed $e ) {
			return new WP_Error(
				'woocommerce_subscriptions_lite_action_not_allowed',
				__( 'That action is not available for this subscription right now.', 'woocommerce-subscriptions-lite' ),
				[ 'status' => 409 ]
			);
		} catch ( Throwable $e ) {
			wc_get_logger()->error(
				sprintf( 'Customer subscription %1$s failed for #%2$d: %3$s', $action, $contract_id, $e->getMessage() ),
				[
					'source'      => 'woocommerce-subscriptions-lite',
					'contract_id' => $contract_id,
					'exception'   => $e,
				]
			);

			return new WP_Error(
				'woocommerce_subscriptions_lite_action_failed',
				__( 'The subscription could not be updated. Please try again.', 'woocommerce-subscriptions-lite' ),
				[ 'status' => 500 ]
			);
		}

		if ( null === $result ) {
			return $this->get_not_found_error();
		}

		return $this->prepare_item_for_response( $result, $request );
	}

	/**
	 * The 404 shared by unknown, foreign and draft contracts.
	 */
	private function get_not_found_error(): WP_Error {
		return new WP_Error(
			'woocommerce_subscriptions_lite_contract_not_found',
			__( 'Subscription not found.', 'woocommerce-subscriptions-lite' ),
			[ 'status' => 404 ]
		);
	}
}
