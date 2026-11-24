<?php
/**
 * ScheduledActions - Lite's single Action Scheduler actions, in one group.
 *
 * Recovery jobs (a settlement refused by a lock, a checkout creation cut short) are scheduled
 * once: an action with the same hook and arguments already pending to run by then covers the
 * new request. Only pending actions count, so a running action refused again schedules the next.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Utilities
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Utilities;

use ActionScheduler_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Schedule and unschedule Lite's single actions.
 */
final class ScheduledActions {

	/**
	 * Action Scheduler group of every Lite action.
	 */
	private const GROUP = 'woocommerce-subscriptions-lite';

	/**
	 * Seconds a recovery action waits past a lock: the retry delay after a refusal, and the
	 * margin added to a lock's TTL for an action that must run once the lock has expired.
	 */
	public const LOCK_RETRY_DELAY_SECONDS = 60;

	/**
	 * Schedule `$hook` with `$args` in `$delay_seconds`, unless one is pending to run by then.
	 *
	 * @param string               $hook          Action hook.
	 * @param array<string, mixed> $args          Action arguments.
	 * @param int                  $delay_seconds Seconds from now.
	 * @return bool False when no action is pending and Action Scheduler could not create one.
	 */
	public static function schedule_once( string $hook, array $args, int $delay_seconds ): bool {
		$run_at  = time() + $delay_seconds;
		$pending = as_get_scheduled_actions(
			[
				'hook'         => $hook,
				'args'         => $args,
				'group'        => self::GROUP,
				'status'       => ActionScheduler_Store::STATUS_PENDING,
				'date'         => as_get_datetime_object( $run_at ),
				'date_compare' => '<=',
				'per_page'     => 1,
			],
			'ids'
		);
		if ( [] !== $pending ) {
			return true;
		}
		$action_id = as_schedule_single_action( $run_at, $hook, $args, self::GROUP );

		// Action Scheduler returns 0, rather than throwing, when it cannot store the action.
		return $action_id > 0;
	}

	/**
	 * Whether a `$hook` action with `$args` is pending.
	 *
	 * @param string               $hook Action hook.
	 * @param array<string, mixed> $args Action arguments.
	 */
	public static function is_pending( string $hook, array $args ): bool {
		$pending = as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			],
			'ids'
		);

		return [] !== $pending;
	}

	/**
	 * Drop every pending `$hook` action with `$args`.
	 *
	 * @param string               $hook Action hook.
	 * @param array<string, mixed> $args Action arguments.
	 */
	public static function unschedule( string $hook, array $args ): void {
		as_unschedule_all_actions( $hook, $args, self::GROUP );
	}
}
