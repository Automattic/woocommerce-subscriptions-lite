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
		 * Presentation props the customer portal reads (order_number, status,
		 * date_created, formatted_order_total, view_order_url). Each falls back to a
		 * sensible default derived from the id when unset.
		 *
		 * @var array<string, mixed>
		 */
		private $props;

		/**
		 * Construct a double.
		 *
		 * @param int                  $id    Order id.
		 * @param array<int, mixed>    $items Line items.
		 * @param array<string, mixed> $meta  Order meta.
		 * @param array<string, mixed> $props Presentation prop overrides.
		 */
		public function __construct( int $id = 0, array $items = [], array $meta = [], array $props = [] ) {
			$this->id    = $id;
			$this->items = $items;
			$this->meta  = $meta;
			$this->props = $props;
		}

		/**
		 * Customer-facing order number.
		 */
		public function get_order_number(): string {
			return (string) ( $this->props['order_number'] ?? $this->id );
		}

		/**
		 * Order status slug.
		 */
		public function get_status(): string {
			return (string) ( $this->props['status'] ?? 'completed' );
		}

		/**
		 * Order creation date, as a DateTimeInterface (mirrors WC_DateTime) or null.
		 *
		 * @return \DateTimeInterface|null
		 */
		public function get_date_created() {
			return $this->props['date_created'] ?? null;
		}

		/**
		 * Formatted order total markup.
		 */
		public function get_formatted_order_total(): string {
			return (string) ( $this->props['formatted_order_total'] ?? '' );
		}

		/**
		 * Customer-facing view-order URL.
		 */
		public function get_view_order_url(): string {
			return (string) ( $this->props['view_order_url'] ?? '' );
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
