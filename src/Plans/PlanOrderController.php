<?php
/**
 * PlanOrderController - REST route that saves Lite's plan display order.
 *
 * `POST wc/v3/subscriptions-lite/plans/reorder` with `{ ids: [ ... ] }` writes
 * the {@see PlanOrder} option. Every id must be a Lite-owned plan (any status);
 * the response echoes the saved ids. The engine's plans REST carries no order.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Plans
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Plans;

use Automattic\WooCommerce\SubscriptionsEngine\Api\SellingPlans;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lite plan order REST route.
 */
final class PlanOrderController {

	public const REST_NAMESPACE = 'wc/v3/subscriptions-lite';

	public const ROUTE = '/plans/reorder';

	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Hook the route registration. Called from the bootstrap on every request
	 * (REST requests are not admin requests).
	 */
	public static function register(): void {
		add_action( 'rest_api_init', [ new self(), 'register_routes' ] );
	}

	/**
	 * Register the reorder route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'reorder' ],
				'permission_callback' => static function (): bool {
					return current_user_can( self::CAPABILITY );
				},
				'args'                => [
					'ids' => [
						'description' => __( 'Plan ids in display order.', 'woocommerce-subscriptions-lite' ),
						'type'        => 'array',
						'items'       => [
							'type'    => 'integer',
							'minimum' => 1,
						],
						'required'    => true,
					],
				],
			]
		);
	}

	/**
	 * Save the plan display order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reorder( WP_REST_Request $request ) {
		// The args schema refuses ids below 1. Repeats are checked after the int cast:
		// `uniqueItems` would accept `[ 1, "1" ]`.
		$ids = array_map( 'intval', (array) $request->get_param( 'ids' ) );

		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			return self::invalid( __( 'Plan ids must not repeat.', 'woocommerce-subscriptions-lite' ) );
		}
		if ( [] !== $ids && count( ( new SellingPlans( [ Package::EXTENSION_SLUG ] ) )->get_plans( $ids ) ) !== count( $ids ) ) {
			return self::invalid( __( 'Every id must be an existing subscription plan of this store.', 'woocommerce-subscriptions-lite' ) );
		}

		( new PlanOrder() )->set( $ids );

		return new WP_REST_Response( [ 'ids' => $ids ], 200 );
	}

	/**
	 * A 400 for an invalid `ids` argument.
	 *
	 * @param string $message Error message.
	 */
	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'rest_invalid_param', $message, [ 'status' => 400 ] );
	}
}
