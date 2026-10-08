<?php
/**
 * CustomerActions - the customer's lifecycle actions on the engine's contract action endpoint.
 *
 * Registers `hold`, `reactivate` and `cancel` for Lite's contracts, dispatched by
 * `wc/v3/subscriptions-engine/contracts/{id}/action`. The contract's customer may run them on
 * their visible contracts; availability follows {@see CustomerActionRules}.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle;

use WP_Error;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractActions;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsLite\Contracts\CustomerVisibility;
use Automattic\WooCommerce\SubscriptionsLite\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the customer contract actions.
 */
final class CustomerActions {

	/**
	 * Register the actions on `init`. Called once from the bootstrap.
	 */
	public static function register(): void {
		add_action( 'init', [ self::class, 'register_actions' ] );
	}

	/**
	 * Register `hold`, `reactivate` and `cancel` with the engine.
	 */
	public static function register_actions(): void {
		ContractActions::register(
			Package::EXTENSION_SLUG,
			'hold',
			[
				'description'  => __( 'Put the subscription on hold.', 'woocommerce-subscriptions-lite' ),
				'permission'   => [ self::class, 'is_customer_permitted' ],
				'is_available' => static function ( ContractView $contract ): bool {
					return CustomerActionRules::can_hold( $contract->get_status() );
				},
				'callback'     => static function ( ContractView $contract ) {
					return self::run( [ new Hold(), 'hold' ], $contract->get_id() );
				},
			]
		);

		ContractActions::register(
			Package::EXTENSION_SLUG,
			'reactivate',
			[
				'description'  => __( 'Resume the subscription.', 'woocommerce-subscriptions-lite' ),
				'permission'   => [ self::class, 'is_customer_permitted' ],
				'is_available' => static function ( ContractView $contract ): bool {
					return CustomerActionRules::can_reactivate( $contract->get_status(), '' !== (string) $contract->get_next_payment_gmt() );
				},
				'callback'     => static function ( ContractView $contract ) {
					return self::run( [ new Reactivation(), 'reactivate' ], $contract->get_id() );
				},
			]
		);

		ContractActions::register(
			Package::EXTENSION_SLUG,
			'cancel',
			[
				'description'  => __( 'Cancel the subscription.', 'woocommerce-subscriptions-lite' ),
				'permission'   => [ self::class, 'is_customer_permitted' ],
				'args'         => [
					'at_period_end' => [
						'description' => __( 'Whether to cancel at the end of the current billing period (true) or immediately (false).', 'woocommerce-subscriptions-lite' ),
						'type'        => 'boolean',
						'default'     => true,
					],
				],
				'is_available' => static function ( ContractView $contract ): bool {
					return CustomerActionRules::can_cancel( $contract->get_status() );
				},
				'callback'     => static function ( ContractView $contract, array $action_args ) {
					$cancellation = new Cancellation();
					$cancel       = false === $action_args['at_period_end']
						? [ $cancellation, 'cancel' ]
						: [ $cancellation, 'cancel_at_period_end' ];

					return self::run( $cancel, $contract->get_id() );
				},
			]
		);
	}

	/**
	 * Whether the current user is the contract's customer and may see it (drafts stay hidden).
	 *
	 * @param ContractView $contract The contract.
	 */
	public static function is_customer_permitted( ContractView $contract ): bool {
		return get_current_user_id() === $contract->get_customer_id() && CustomerVisibility::is_visible( $contract );
	}

	/**
	 * Run a lifecycle flow and map its outcome: a missing contract is a 404, a status the flow
	 * rejects a 409.
	 *
	 * @param callable( int ): ?ContractView $flow        Lifecycle flow method.
	 * @param int                            $contract_id Contract id.
	 * @return ContractView|WP_Error
	 */
	private static function run( callable $flow, int $contract_id ) {
		try {
			$result = $flow( $contract_id );
		} catch ( LifecycleNotAllowed $e ) {
			return new WP_Error(
				'woocommerce_subscriptions_lite_action_not_allowed',
				__( 'That action is not available for this subscription right now.', 'woocommerce-subscriptions-lite' ),
				[ 'status' => 409 ]
			);
		}

		if ( null === $result ) {
			return new WP_Error(
				'woocommerce_subscriptions_lite_contract_not_found',
				__( 'Subscription not found.', 'woocommerce-subscriptions-lite' ),
				[ 'status' => 404 ]
			);
		}

		return $result;
	}
}
