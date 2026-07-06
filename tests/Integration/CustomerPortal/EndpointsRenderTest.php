<?php
/**
 * Integration tests for the customer-portal render pipeline.
 *
 * Renders the real endpoints over seeded contracts: real provider, real
 * view-model, real templates via wc_get_template, real Interactivity API state.
 * Restores the render coverage retired with the stub-based suite.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\CustomerPortal;

use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Endpoints;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Endpoints
 * @covers \Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\ViewModel
 */
final class EndpointsRenderTest extends LiteIntegrationTestCase {

	/**
	 * The customer the portal renders for.
	 *
	 * @var int
	 */
	private $customer_id;

	public function set_up(): void {
		parent::set_up();
		// Pretty permalinks, so endpoint URLs take their production path form
		// (/view-subscription-lite/123/) instead of plain query args.
		$this->set_permalink_structure( '/%postname%/' );
		$this->customer_id = $this->create_customer();
		wp_set_current_user( $this->customer_id );
	}

	/**
	 * Render a callback's output to a string.
	 *
	 * @param callable $render The render callback.
	 * @return string Captured markup.
	 */
	private function capture( callable $render ): string {
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	/**
	 * Read the store state seeded for the client during the last render.
	 *
	 * @return array<string, mixed>
	 */
	private function seeded_state(): array {
		return (array) wp_interactivity_state( Endpoints::STORE_NAMESPACE );
	}

	/**
	 * Clear a contract's next payment date - the on-hold "admin action" shape
	 * (no missed charge pending), as opposed to the failed-payment retry shape.
	 *
	 * @param int $contract_id Contract id.
	 */
	private function clear_next_payment( int $contract_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS ),
			[ 'next_payment_gmt' => null ],
			[ 'id' => $contract_id ]
		);
	}

	public function test_list_renders_every_status_with_badges(): void {
		$this->create_contract( $this->customer_id );
		$this->create_contract( $this->customer_id, [ 'status' => 'on-hold' ] );
		$this->create_contract( $this->customer_id, [ 'status' => 'pending-cancellation' ] );
		$this->create_contract( $this->customer_id, [ 'status' => 'cancelled' ] );
		$expired = $this->create_contract( $this->customer_id );
		$this->force_contract_status( $expired, 'expired' );

		$endpoints = new Endpoints();
		$html      = $this->capture( [ $endpoints, 'render_list' ] );

		$this->assertStringContainsString( 'account-subscriptions-table', $html );
		$this->assertStringContainsString( 'data-wp-interactive="' . Endpoints::STORE_NAMESPACE . '"', $html );

		foreach ( [ 'active', 'on-hold', 'pending-cancellation', 'cancelled', 'expired' ] as $status ) {
			$this->assertStringContainsString(
				'wc-subs-lite-status-badge--' . $status,
				$html,
				"The list shows a badge for the {$status} status."
			);
		}
	}

	public function test_list_paginates_at_ten_rows_per_page(): void {
		$ids = [];
		for ( $i = 0; $i < 12; $i++ ) {
			$ids[] = $this->create_contract( $this->customer_id );
		}
		$oldest = (string) min( $ids );

		$endpoints = new Endpoints();

		// Page 1: ten rows, a Next link, no Previous; oldest falls to page two.
		$page_one = $this->capture( [ $endpoints, 'render_list' ] );
		$this->assertSame( 10, substr_count( $page_one, '<tr class="subscription">' ), 'Page one carries ten rows.' );
		$this->assertStringContainsString( 'woocommerce-pagination--without-numbers', $page_one );
		$this->assertStringContainsString( 'woocommerce-button--next', $page_one );
		$this->assertStringNotContainsString( 'woocommerce-button--previous', $page_one );
		$this->assertStringNotContainsString( Endpoints::DETAIL_ENDPOINT . '/' . $oldest, $page_one );

		// Page 2: the remaining two rows, a Previous link, no Next.
		$page_two = $this->capture(
			static function () use ( $endpoints ): void {
				$endpoints->render_list( '2' );
			}
		);
		$this->assertSame( 2, substr_count( $page_two, '<tr class="subscription">' ), 'Page two carries the remainder.' );
		$this->assertStringContainsString( Endpoints::DETAIL_ENDPOINT . '/' . $oldest, $page_two );
		$this->assertStringContainsString( 'woocommerce-button--previous', $page_two );
		$this->assertStringNotContainsString( 'woocommerce-button--next', $page_two );
	}

	public function test_related_orders_paginate_on_the_detail_page(): void {
		$contract_id = $this->create_contract( $this->customer_id );
		// Origin order + fourteen renewals = fifteen related orders.
		for ( $i = 1; $i <= 14; $i++ ) {
			$this->create_renewal_order(
				$contract_id,
				$this->customer_id,
				[ 'date_created' => sprintf( '2026-%02d-01 00:00:00', min( $i, 12 ) ) ]
			);
		}

		$endpoints = new Endpoints();

		$page_one = $this->capture(
			static function () use ( $endpoints, $contract_id ): void {
				$endpoints->render_detail( $contract_id );
			}
		);
		$this->assertSame( 10, substr_count( $page_one, '<tr class="subscription-related-order">' ), 'Ten related orders per page.' );
		$this->assertStringContainsString( 'woocommerce-button--next', $page_one );
		$this->assertStringContainsString( Endpoints::ORDERS_PAGE_QUERY_ARG . '=2', $page_one );
		$this->assertStringNotContainsString( 'woocommerce-button--previous', $page_one );

		$_GET[ Endpoints::ORDERS_PAGE_QUERY_ARG ] = '2';
		try {
			$page_two = $this->capture(
				static function () use ( $endpoints, $contract_id ): void {
					$endpoints->render_detail( $contract_id );
				}
			);
		} finally {
			unset( $_GET[ Endpoints::ORDERS_PAGE_QUERY_ARG ] );
		}
		$this->assertSame( 5, substr_count( $page_two, '<tr class="subscription-related-order">' ), 'Page two carries the remainder.' );
		$this->assertStringContainsString( 'woocommerce-button--previous', $page_two );
		$this->assertStringNotContainsString( 'woocommerce-button--next', $page_two );
	}

	public function test_active_detail_renders_actions_sections_and_seeds_state(): void {
		$contract_id = $this->create_contract( $this->customer_id, [ 'product_name' => 'Monthly Coffee Box' ] );

		$endpoints = new Endpoints();
		$html      = $this->capture(
			static function () use ( $endpoints, $contract_id ): void {
				$endpoints->render_detail( $contract_id );
			}
		);

		$this->assertStringContainsString( 'subscription-detail-block', $html );
		$this->assertStringContainsString( 'wc-subs-lite-status-badge--active', $html );

		// Active shows cancel + pause, not reactivate.
		$this->assertStringContainsString( 'data-wp-on--click="actions.openCancelModal"', $html );
		$this->assertStringContainsString( 'data-wp-on--click="actions.submitHold"', $html );
		$this->assertStringNotContainsString( 'actions.submitReactivate', $html );

		// The cancel modal and both live-region error fields are present.
		$this->assertStringContainsString( 'wc-subscriptions-lite-cancel-modal', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'data-wp-text="state.actionError"', $html );
		$this->assertStringContainsString( 'data-wp-text="state.error"', $html );

		// Subscription totals: the real line item and the always-on rows; the
		// conditional rows stay hidden for an order without discount/shipping/tax.
		$this->assertStringContainsString( 'subscription-totals', $html );
		$this->assertStringContainsString( 'Monthly Coffee Box', $html );
		$this->assertStringContainsString( 'Subtotal', $html );
		$this->assertStringNotContainsString( 'Discount', $html, 'No discount row without a discount.' );

		// Addresses in WooCommerce's native my-account column layout.
		$this->assertStringContainsString( 'subscription-detail-addresses-heading', $html );
		$this->assertStringContainsString( 'u-columns woocommerce-Addresses col2-set addresses', $html );
		$this->assertStringContainsString( '>Billing</h4>', $html );
		$this->assertStringContainsString( '>Shipping</h4>', $html );
		$this->assertStringContainsString( '12 Analytical Row', $html );
		$this->assertStringContainsString( '1 Engine Court', $html );
		$this->assertStringContainsString( 'ada@example.com', $html );

		// Section order: totals, then addresses, then related orders last.
		$totals_at    = strpos( $html, 'subscription-detail-totals-heading' );
		$addresses_at = strpos( $html, 'subscription-detail-addresses' );
		$related_at   = strpos( $html, 'subscription-related-orders' );
		$this->assertIsInt( $totals_at );
		$this->assertIsInt( $addresses_at );
		$this->assertIsInt( $related_at );
		$this->assertLessThan( $addresses_at, $totals_at, 'Totals render before addresses.' );
		$this->assertLessThan( $related_at, $addresses_at, 'Addresses render before related orders.' );

		// The store state seeded for the client carries the contract + cancel mode.
		$state = $this->seeded_state();
		$this->assertSame( $contract_id, $state['contractId'] );
		$this->assertSame( 'active', $state['status'] );
		$this->assertTrue( $state['atPeriodEnd'], 'Active subscription cancels at period end.' );
		$this->assertArrayHasKey( 'i18n', $state );
		$this->assertSame( '', $state['error'] );
		$this->assertSame( '', $state['actionError'] );
	}

	public function test_on_hold_admin_path_shows_reactivate(): void {
		$contract_id = $this->create_contract( $this->customer_id, [ 'status' => 'on-hold' ] );
		$this->clear_next_payment( $contract_id );

		$endpoints = new Endpoints();
		$html      = $this->capture(
			static function () use ( $endpoints, $contract_id ): void {
				$endpoints->render_detail( $contract_id );
			}
		);

		$this->assertStringContainsString( 'data-wp-on--click="actions.submitReactivate"', $html );
		$this->assertStringNotContainsString( 'subscription-needs-payment-notice', $html );
		$this->assertFalse( $this->seeded_state()['atPeriodEnd'], 'On-hold cancels immediately.' );
	}

	public function test_on_hold_retry_path_shows_needs_payment_notice(): void {
		// A held contract keeps its next payment date - the failed-payment
		// retry shape, where reactivating without a payment fix is unsafe.
		$contract_id = $this->create_contract( $this->customer_id, [ 'status' => 'on-hold' ] );

		$endpoints = new Endpoints();
		$html      = $this->capture(
			static function () use ( $endpoints, $contract_id ): void {
				$endpoints->render_detail( $contract_id );
			}
		);

		$this->assertStringContainsString( 'subscription-needs-payment-notice', $html );
		$this->assertStringNotContainsString( 'actions.submitReactivate', $html );
	}

	public function test_pending_cancellation_hides_all_lifecycle_actions(): void {
		$contract_id = $this->create_contract( $this->customer_id, [ 'status' => 'pending-cancellation' ] );

		$endpoints = new Endpoints();
		$html      = $this->capture(
			static function () use ( $endpoints, $contract_id ): void {
				$endpoints->render_detail( $contract_id );
			}
		);

		$this->assertStringContainsString( 'wc-subs-lite-status-badge--pending-cancellation', $html );
		$this->assertStringNotContainsString( 'actions.openCancelModal', $html );
		$this->assertStringNotContainsString( 'actions.submitHold', $html );
		$this->assertStringNotContainsString( 'actions.submitReactivate', $html );
	}

	public function test_unknown_and_foreign_contracts_render_not_found_identically(): void {
		$stranger_contract = $this->create_contract( $this->create_customer() );

		$endpoints = new Endpoints();

		$unknown = $this->capture(
			static function () use ( $endpoints ): void {
				$endpoints->render_detail( 999999 );
			}
		);
		$foreign = $this->capture(
			static function () use ( $endpoints, $stranger_contract ): void {
				$endpoints->render_detail( $stranger_contract );
			}
		);

		$this->assertStringContainsString( 'Subscription not found.', $unknown );
		$this->assertStringContainsString( 'Back to subscriptions', $unknown );
		$this->assertSame( $unknown, $foreign, 'Unknown and foreign-owned render byte-identical not-found.' );
		$this->assertStringNotContainsString( 'subscription-detail-block', $foreign );
	}
}
