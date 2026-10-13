<?php
/**
 * Deactivation - what Lite undoes when it is deactivated.
 *
 * Lite deregisters as an engine consumer and lets the engine's due dispatcher re-evaluate
 * its schedule: with no due handler left it removes its recurring scan. This must happen
 * here because the engine only loads through its consumers - once Lite is inactive, no
 * engine code runs to clean up after it. The re-evaluation needs Action Scheduler, which
 * ships with WooCommerce. When WooCommerce is already inactive it is skipped rather than
 * fataling the deactivation request: the stored scan action is left in place, and the
 * engine removes it on its next load with no due handler (Action Scheduler also stops
 * retrying an action that has no callback). Current engine builds skip it themselves;
 * Lite keeps its own check because highest-version-wins can load an older engine copy of
 * the same version that does not.
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
	 */
	public static function run(): void {
		if ( class_exists( ConsumerRegistry::class ) ) {
			ConsumerRegistry::unregister( Package::EXTENSION_SLUG );
		}

		if ( class_exists( DueDispatcher::class ) && self::scheduler_available() ) {
			DueDispatcher::ensure_scheduled();
		}
	}

	/**
	 * Whether Action Scheduler is loaded.
	 */
	private static function scheduler_available(): bool {
		foreach ( self::SCHEDULER_FUNCTIONS as $function ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
		}

		return true;
	}
}
