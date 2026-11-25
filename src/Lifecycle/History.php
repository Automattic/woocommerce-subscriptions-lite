<?php
/**
 * History - records what happened to a subscription as entries in its engine contract history.
 *
 * Every entry carries Lite's `actor` (a role and a user id) in its payload. The write uses the
 * lock this request holds on the contract ({@see HeldLocks}), so a flow that lost its lock
 * records nothing. An entry describes a contract write that has already happened, so a failed
 * history write is logged and never fails the flow that called it.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Writes Lite's contract history entries.
 */
final class History {

	/**
	 * Entry type: the subscription was created and activated.
	 */
	public const TYPE_CREATED = 'created';

	/**
	 * Entry type: the subscription was put on hold.
	 */
	public const TYPE_HELD = 'held';

	/**
	 * Entry type: a held subscription was reactivated.
	 */
	public const TYPE_REACTIVATED = 'reactivated';

	/**
	 * Entry type: the subscription was cancelled.
	 */
	public const TYPE_CANCELLED = 'cancelled';

	/**
	 * Entry type: the subscription was set to end with its current period.
	 */
	public const TYPE_CANCELLATION_SCHEDULED = 'cancellation-scheduled';

	/**
	 * Entry type: a renewal order was paid and its cycle billed.
	 */
	public const TYPE_RENEWAL_PAID = 'renewal-paid';

	/**
	 * Entry type: a renewal order failed and its cycle was marked failed.
	 */
	public const TYPE_RENEWAL_FAILED = 'renewal-failed';

	/**
	 * Entry type: a store manager added a note; its payload carries the `text`.
	 */
	public const TYPE_NOTE = 'note';

	/**
	 * Actor role: the subscription's customer.
	 */
	public const ROLE_CUSTOMER = 'customer';

	/**
	 * Actor role: a store manager.
	 */
	public const ROLE_MERCHANT = 'merchant';

	/**
	 * Actor role: Lite itself (renewals, settlement, background runs) with no user id, or a
	 * logged-in user who is neither the subscription's customer nor a store manager, with that
	 * user's id.
	 */
	public const ROLE_SYSTEM = 'system';

	/**
	 * Capability of a user recorded as a merchant.
	 */
	private const MERCHANT_CAPABILITY = 'manage_woocommerce';

	/**
	 * Record an entry done by the current user: the customer when it is the subscription's
	 * customer, a merchant when it can manage WooCommerce, else the system. Inside a background
	 * run (WP-Cron or the Action Scheduler queue) it is the system, whatever user the run carries.
	 *
	 * @param ContractView         $contract The contract the entry is about.
	 * @param string               $type     Entry type, one of the `TYPE_*` constants.
	 * @param array<string, mixed> $fields   Optional `cycle_id`, `payload` (beyond the actor) and `creation_key`.
	 * @return bool Whether the entry was recorded; false after a logged failure or a taken creation key.
	 */
	public static function add( ContractView $contract, string $type, array $fields = [] ): bool {
		return self::write( $contract->get_id(), $type, $fields, $contract );
	}

	/**
	 * Record an entry done by Lite itself: a renewal, a settlement or Lite's own hold.
	 *
	 * @param int                  $contract_id Contract id.
	 * @param string               $type        Entry type, one of the `TYPE_*` constants.
	 * @param array<string, mixed> $fields      Optional `cycle_id`, `payload` (beyond the actor) and `creation_key`.
	 */
	public static function add_system( int $contract_id, string $type, array $fields = [] ): void {
		self::write( $contract_id, $type, $fields, null );
	}

	/**
	 * The actor of an entry: Lite's own when there is no acting contract view, the request has no
	 * user or it runs in the background; else the current user's role on the contract.
	 *
	 * @param ContractView|null $contract The contract the current user acted on, or null for Lite itself.
	 * @return array{role: string, user_id: int|null}
	 */
	private static function get_actor( ?ContractView $contract ): array {
		$user_id = get_current_user_id();
		// A background run may carry the cookies of whoever's request started it.
		if ( null === $contract || $user_id <= 0 || wp_doing_cron() || doing_action( 'action_scheduler_run_queue' ) ) {
			return [
				'role'    => self::ROLE_SYSTEM,
				'user_id' => null,
			];
		}

		$role = self::ROLE_SYSTEM;
		if ( $contract->get_customer_id() === $user_id ) {
			$role = self::ROLE_CUSTOMER;
		} elseif ( user_can( $user_id, self::MERCHANT_CAPABILITY ) ) {
			$role = self::ROLE_MERCHANT;
		}

		return [
			'role'    => $role,
			'user_id' => $user_id,
		];
	}

	/**
	 * Write the entry with the lock held on the contract in this request, if any. Any failure,
	 * the actor lookup included, is logged with the contract id, the entry type and the message,
	 * and not thrown.
	 *
	 * @param int                  $contract_id Contract id.
	 * @param string               $type        Entry type.
	 * @param array<string, mixed> $fields      Optional `cycle_id`, `payload` and `creation_key`.
	 * @param ContractView|null    $contract    The contract the current user acted on, or null for Lite itself.
	 * @return bool Whether the entry was recorded.
	 */
	private static function write( int $contract_id, string $type, array $fields, ?ContractView $contract ): bool {
		try {
			$args = [
				'extension_slug' => Package::EXTENSION_SLUG,
				'type'           => $type,
				'payload'        => [ 'actor' => self::get_actor( $contract ) ] + (array) ( $fields['payload'] ?? [] ),
			];
			if ( isset( $fields['cycle_id'] ) ) {
				$args['cycle_id'] = $fields['cycle_id'];
			}
			if ( isset( $fields['creation_key'] ) ) {
				$args['creation_key'] = $fields['creation_key'];
			}

			$entry = Contracts::add_history_entry( $contract_id, $args, HeldLocks::get( $contract_id ) );
		} catch ( Throwable $e ) {
			wc_get_logger()->error(
				sprintf( 'History: the %s entry of contract %d could not be recorded: %s', $type, $contract_id, $e->getMessage() ),
				[
					'source'      => Package::EXTENSION_SLUG,
					'contract_id' => $contract_id,
					'type'        => $type,
				]
			);
			return false;
		}

		return null !== $entry;
	}
}
