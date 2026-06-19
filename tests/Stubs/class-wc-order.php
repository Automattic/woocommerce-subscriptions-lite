<?php
/**
 * Minimal WC_Order double for the unit suite.
 *
 * The Lite slice handlers read a handful of accessors off a `WC_Order` and hand
 * the order to the engine. This double provides exactly those accessors so the
 * drivers run without booting WooCommerce. It lives in the global namespace
 * (where WooCommerce defines the real class) and is loaded only when the real
 * class is absent.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

if ( ! class_exists( 'WC_Order' ) ) {
	/**
	 * Minimal stand-in for WooCommerce's order class.
	 */
	class WC_Order {

		/**
		 * Order id.
		 *
		 * @var int
		 */
		private $id;

		/**
		 * Line items.
		 *
		 * @var array<int, mixed>
		 */
		private $items;

		/**
		 * Order meta as key => value.
		 *
		 * @var array<string, mixed>
		 */
		private $meta;

		/**
		 * Construct a double.
		 *
		 * @param int                  $id    Order id.
		 * @param array<int, mixed>    $items Line items.
		 * @param array<string, mixed> $meta  Order meta.
		 */
		public function __construct( int $id = 0, array $items = [], array $meta = [] ) {
			$this->id    = $id;
			$this->items = $items;
			$this->meta  = $meta;
		}

		/**
		 * Order id.
		 */
		public function get_id(): int {
			return $this->id;
		}

		/**
		 * Line items.
		 *
		 * @return array<int, mixed>
		 */
		public function get_items(): array {
			return $this->items;
		}

		/**
		 * Read an order meta value by key.
		 *
		 * @param string $key    Meta key.
		 * @param bool   $single Whether to return a single value (always true here).
		 * @return mixed
		 */
		public function get_meta( string $key, bool $single = true ) {
			return $this->meta[ $key ] ?? '';
		}
	}
}
