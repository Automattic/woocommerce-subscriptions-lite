<?php
/**
 * Integration tests for the Orders-like subscriptions list table.
 *
 * Contracts are seeded through the real checkout path and the list is driven the
 * way the screen drives it - request args in, facade reads out - so pagination,
 * status views, sorting and search are exercised against real engine queries.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsLite\Admin\PageController;
use Automattic\WooCommerce\SubscriptionsLite\Admin\SubscriptionsListTable;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\SubscriptionsListTable
 */
final class SubscriptionsListTableTest extends LiteIntegrationTestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( 'subscriptions_page_wc-subscriptions-lite' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_GET     = [];
		$_REQUEST = [];
	}

	public function tear_down(): void {
		$_GET     = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function test_real_pagination_reports_the_true_total(): void {
		$customer = $this->create_customer();
		$total    = PageController::PER_PAGE + 1; // One row onto a second page.
		for ( $i = 0; $i < $total; $i++ ) {
			$this->create_contract( $customer );
		}

		$this->set_request( [ 'paged' => '2' ] );
		$table = $this->prepared_table();

		$this->assertSame( $total, (int) $table->get_pagination_arg( 'total_items' ) );
		$this->assertSame( 2, (int) $table->get_pagination_arg( 'total_pages' ) );
		$this->assertCount( 1, $table->items, 'The second page holds the single overflow row.' );
	}

	public function test_status_views_carry_per_status_counts(): void {
		$customer = $this->create_customer();
		$this->create_contract( $customer );                              // Active.
		$this->create_contract( $customer );                              // Active.
		$this->create_contract( $customer, [ 'status' => 'on-hold' ] );   // On hold.
		$this->create_contract( $customer, [ 'status' => 'cancelled' ] ); // Cancelled.

		$views = $this->call_get_views( $this->prepared_table() );

		$this->assertStringContainsString( '(4)', $views['all'] );
		$this->assertStringContainsString( '(2)', $views['active'] );
		$this->assertStringContainsString( '(1)', $views['on-hold'] );
		$this->assertStringContainsString( '(1)', $views['cancelled'] );
		$this->assertStringContainsString( '(0)', $views['expired'] );
	}

	public function test_status_view_filters_the_list(): void {
		$customer = $this->create_customer();
		$this->create_contract( $customer );                            // Active.
		$this->create_contract( $customer, [ 'status' => 'on-hold' ] ); // On hold.

		$this->set_request( [ 'status' => 'on-hold' ] );
		$table = $this->prepared_table();

		$this->assertCount( 1, $table->items );
		$this->assertSame( 'on-hold', $table->items[0]->get_status() );
	}

	public function test_sorting_by_total_orders_rows(): void {
		$customer = $this->create_customer();
		$this->create_contract( $customer, [ 'price' => '5.00' ] );
		$this->create_contract( $customer, [ 'price' => '50.00' ] );

		$this->set_request(
			[
				'orderby' => 'total',
				'order'   => 'asc',
			]
		);
		$table = $this->prepared_table();

		$this->assertCount( 2, $table->items );
		$this->assertLessThan(
			(float) $table->items[1]->get_billing_total(),
			(float) $table->items[0]->get_billing_total(),
			'Ascending sort by total puts the cheaper subscription first.'
		);
	}

	public function test_search_matches_contract_id(): void {
		$customer = $this->create_customer();
		$wanted   = $this->create_contract( $customer );
		$this->create_contract( $customer );

		$this->set_request( [ 's' => (string) $wanted ] );
		$table = $this->prepared_table();

		$this->assertCount( 1, $table->items );
		$this->assertSame( $wanted, (int) $table->items[0]->get_id() );
	}

	public function test_search_matches_customer_email(): void {
		$found_customer = $this->create_customer( [ 'user_email' => 'searchme@example.com' ] );
		$other_customer = $this->create_customer( [ 'user_email' => 'someone-else@example.com' ] );
		$wanted         = $this->create_contract( $found_customer );
		$this->create_contract( $other_customer );

		$this->set_request( [ 's' => 'searchme' ] );
		$table = $this->prepared_table();

		$this->assertCount( 1, $table->items );
		$this->assertSame( $wanted, (int) $table->items[0]->get_id() );
	}

	public function test_search_form_does_not_nest_the_row_action_forms(): void {
		$customer = $this->create_customer();
		$this->create_contract( $customer ); // Active -> renders Renew now / Cancel POST forms.

		$html = $this->render_list_page();

		$this->assertStringContainsString( 'wc-subs-lite-search-form', $html );
		$search_open  = strpos( $html, 'wc-subs-lite-search-form' );
		$search_close = strpos( $html, '</form>', (int) $search_open );
		$table_pos    = strpos( $html, 'wp-list-table' );
		$post_form    = strpos( $html, 'wc-subs-lite-action-form' );

		$this->assertNotFalse( $search_close );
		$this->assertNotFalse( $table_pos );
		$this->assertNotFalse( $post_form );
		$this->assertLessThan( $table_pos, $search_close, 'The search GET form must close before the table.' );
		$this->assertGreaterThan( $table_pos, $post_form, 'Row-action POST forms render inside the table, never inside the GET form.' );
	}

	/**
	 * Set the request superglobals the list table reads.
	 *
	 * @param array<string, string> $args Query args.
	 */
	private function set_request( array $args ): void {
		$_GET     = $args;
		$_REQUEST = $args;
	}

	/**
	 * A list table with items prepared for the current request.
	 */
	private function prepared_table(): SubscriptionsListTable {
		$table = new SubscriptionsListTable();
		$table->prepare_items();

		return $table;
	}

	/**
	 * Read the protected get_views() output.
	 *
	 * @param SubscriptionsListTable $table Prepared table.
	 * @return array<string, string>
	 */
	private function call_get_views( SubscriptionsListTable $table ): array {
		$views = ( function () {
			return $this->get_views();
		} )->call( $table );

		return is_array( $views ) ? $views : [];
	}

	/**
	 * Render the full list page through the page controller.
	 */
	private function render_list_page(): string {
		ob_start();
		PageController::render_page();
		return (string) ob_get_clean();
	}
}
