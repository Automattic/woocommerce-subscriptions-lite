<?php
/**
 * Deactivation - what Lite undoes when it is deactivated.
 *
 * Lite deregisters as an engine consumer. The engine then re-checks its due scan and, with
 * no due handler left, removes the recurring action. This must happen here because the
 * engine only loads through its consumers: once Lite is inactive, no engine code runs to
 * clean up after it. When WooCommerce is already inactive the engine skips the re-check,
 * and removes the stored action on its next load with no due handler.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite;

use Automattic\WooCommerce\SubscriptionsEngine\Integration\Ownership\ConsumerRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin deactivation.
 */
final class Deactivation {

	/**
	 * Deregister Lite as an engine consumer.
	 */
	public static function run(): void {
		if ( class_exists( ConsumerRegistry::class ) ) {
			ConsumerRegistry::unregister( Package::EXTENSION_SLUG );
		}
	}
}
