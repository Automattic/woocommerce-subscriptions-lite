<?php
/**
 * CustomerActions - the customer's lifecycle actions on the engine's contract action endpoint.
 *
 * Registers `hold`, `reactivate` and `cancel` for Lite's contracts, dispatched by
 * `wc/v3/subscriptions-engine/contracts/{id}/action`. The engine's `manage_subscription_contract`
 * capability lets the contract's customer and store managers run them; drafts stay hidden from
 * customers, for reads (`read_subscription_contract`) too. Availability follows {@see CustomerActionRules}.
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
	 * The engine capability for acting on one contract.
	 */
	private const CAPABILITY = 'manage_subscription_contract';

	/**
	 * The engine capability for reading one contract.
	 */
	private const READ_CAPABILITY = 'read_subscription_contract';

	/**
	 * Register the actions on `init` and the draft rule. Called once from the bootstrap.
	 */
	public static function register(): void {
		add_action( 'init', [ self::class, 'register_actions' ] );
		add_filter( 'map_meta_cap', [ self::class, 'hide_drafts_from_customers' ], 20, 4 );
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
				'permission'   => self::CAPABILITY,
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
				'permission'   => self::CAPABILITY,
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
				'permission'   => self::CAPABILITY,
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
	 * Keep Lite drafts from customers: reading or managing one needs `manage_woocommerce`, even for
	 * its customer.
	 *
	 * @param mixed $caps    Primitive capabilities so far.
	 * @param mixed $cap     Capability being checked.
	 * @param mixed $user_id User id.
	 * @param mixed $args    Extra `current_user_can()` arguments; the first is the contract.
	 * @return mixed
	 */
	public static function hide_drafts_from_customers( $caps, $cap, $user_id, $args ) {
		$contract = in_array( $cap, [ self::READ_CAPABILITY, self::CAPABILITY ], true ) && is_array( $args ) ? ( $args[0] ?? null ) : null;
		if ( ! $contract instanceof ContractView
			|| Package::EXTENSION_SLUG !== $contract->get_extension_slug()
			|| CustomerVisibility::is_visible( $contract ) ) {
			return $caps;
		}

		return [ 'manage_woocommerce' ];
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
