<?php
/**
 * Deactivation - what Lite undoes when it is deactivated.
 *
 * Lite deregisters as an engine consumer and lets the engine's due dispatcher re-evaluate
 * its schedule: with no due handler left it removes its recurring scan. This must happen
 * here because the engine only loads through its consumers - once Lite is inactive, no
 * engine code runs to clean up after it. The re-evaluation needs Action Scheduler, which
 * ships with WooCommerce; when WooCommerce is already inactive there is no scheduler (and no
 * scan to run), so it is skipped rather than fataling the deactivation request.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite;

use Automattic\WooCommerce\SubscriptionsEngine\Integration\Dispatch\DueDispatcher;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Ownership\ConsumerRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin deactivation.
 */
final class Deactivation {

	/**
	 * Action Scheduler functions the due dispatcher's schedule check calls.
	 */
	private const SCHEDULER_FUNCTIONS = [ 'as_next_scheduled_action', 'as_schedule_recurring_action', 'as_unschedule_all_actions' ];

	/**
	 * Deregister Lite and let the due dispatcher re-evaluate its schedule.
	 *
	 * @param (callable(string): bool)|null $function_exists Function lookup; defaults to
	 *                                                       `function_exists()` (tests pass
	 *                                                       a lookup without the scheduler).
	 */
	public static function run( ?callable $function_exists = null ): void {
		if ( class_exists( ConsumerRegistry::class ) ) {
			ConsumerRegistry::unregister( Package::EXTENSION_SLUG );
		}

		if ( class_exists( DueDispatcher::class ) && self::scheduler_available( $function_exists ?? 'function_exists' ) ) {
			DueDispatcher::ensure_scheduled();
		}
	}

	/**
	 * Whether Action Scheduler is loaded.
	 *
	 * @param callable(string): bool $function_exists Function lookup.
	 */
	private static function scheduler_available( callable $function_exists ): bool {
		foreach ( self::SCHEDULER_FUNCTIONS as $function ) {
			if ( ! $function_exists( $function ) ) {
				return false;
			}
		}

		return true;
	}
}
