<?php
/**
 * Logger double for the unit suite.
 *
 * Stands in for the object `wc_get_logger()` returns. Each level records the
 * message + context into a global so tests can assert that a code path logged.
 * Tests reset the global in their own `setUp()`.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

if ( ! class_exists( 'WC_Subscriptions_Lite_Test_Logger' ) ) {
	/**
	 * Records log calls into `$GLOBALS['wc_subscriptions_lite_test_logs']`.
	 */
	class WC_Subscriptions_Lite_Test_Logger {

		/**
		 * Record an error log line.
		 *
		 * @param string               $message Message.
		 * @param array<string, mixed> $context Context.
		 */
		public function error( string $message, array $context = [] ): void {
			$this->record( 'error', $message, $context );
		}

		/**
		 * Record a warning log line.
		 *
		 * @param string               $message Message.
		 * @param array<string, mixed> $context Context.
		 */
		public function warning( string $message, array $context = [] ): void {
			$this->record( 'warning', $message, $context );
		}

		/**
		 * Record a debug log line.
		 *
		 * @param string               $message Message.
		 * @param array<string, mixed> $context Context.
		 */
		public function debug( string $message, array $context = [] ): void {
			$this->record( 'debug', $message, $context );
		}

		/**
		 * Append one entry to the captured-logs global.
		 *
		 * @param string               $level   Log level.
		 * @param string               $message Message.
		 * @param array<string, mixed> $context Context.
		 */
		private function record( string $level, string $message, array $context ): void {
			$GLOBALS['wc_subscriptions_lite_test_logs'][] = [
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			];
		}
	}
}
