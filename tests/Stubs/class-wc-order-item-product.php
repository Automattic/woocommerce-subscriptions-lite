<?php
/**
 * Minimal WC_Order_Item_Product double for the unit suite.
 *
 * Provides the line-item accessors the checkout handler reads (`get_id()`,
 * `get_meta()`), so the driver can iterate order items without WooCommerce.
 * Global namespace; loaded only when the real class is absent.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	/**
	 * Minimal stand-in for WooCommerce's line-item class.
	 */
	class WC_Order_Item_Product {

		/**
		 * Line-item id.
		 *
		 * @var int
		 */
		private $id;

		/**
		 * Line-item meta as key => value.
		 *
		 * @var array<string, mixed>
		 */
		private $meta;

		/**
		 * Construct a double.
		 *
		 * @param int                  $id   Line-item id.
		 * @param array<string, mixed> $meta Line-item meta.
		 */
		public function __construct( int $id = 0, array $meta = [] ) {
			$this->id   = $id;
			$this->meta = $meta;
		}

		/**
		 * Line-item id.
		 */
		public function get_id(): int {
			return $this->id;
		}

		/**
		 * Read a line-item meta value by key.
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
