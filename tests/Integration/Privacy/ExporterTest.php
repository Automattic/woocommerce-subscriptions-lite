<?php
/**
 * Integration tests for the subscriptions personal data exporter.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Privacy;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Privacy\Exporter;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WP_Error;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Privacy\Exporter
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Privacy\RequesterContracts
 */
final class ExporterTest extends LiteIntegrationTestCase {

	private const EMAIL = 'grace@example.com';

	/**
	 * The requester's account.
	 *
	 * @var int
	 */
	private $customer_id;

	public function set_up(): void {
		parent::set_up();
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		$this->customer_id = $this->create_customer( [ 'user_email' => self::EMAIL ] );
	}

	/**
	 * Run the registered exporter.
	 *
	 * @param string $email Request email.
	 * @param int    $page  Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}|WP_Error
	 */
	private function export( string $email = self::EMAIL, int $page = 1 ) {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );

		return call_user_func( $exporters[ Package::EXTENSION_SLUG ]['callback'], $email, $page );
	}

	/**
	 * An export response that must not be an error.
	 *
	 * @param mixed $response Exporter response.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	private function assert_export( $response ): array {
		$this->assertIsArray( $response );

		return $response;
	}

	/**
	 * An export item's rows as name => value.
	 *
	 * @param array<string, mixed> $item Export item.
	 * @return array<string, string>
	 */
	private function rows_of( array $item ): array {
		return array_column( $item['data'], 'value', 'name' );
	}

	public function test_one_exporter_is_registered(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );

		$this->assertArrayHasKey( Package::EXTENSION_SLUG, $exporters );
		$this->assertSame( 'Subscriptions', $exporters[ Package::EXTENSION_SLUG ]['exporter_friendly_name'] );
		$this->assertIsCallable( $exporters[ Package::EXTENSION_SLUG ]['callback'] );
	}

	public function test_a_contract_exports_every_field(): void {
		$contract_id = $this->create_contract(
			$this->customer_id,
			[
				'product_name'         => 'Coffee Box',
				'quantity'             => 2,
				'payment_method_title' => 'Visa ending in 4242',
				'shipping'             => [ 'phone' => '020 7946 0999' ],
			]
		);
		$contract    = $this->get_contract( $contract_id );

		$response = $this->assert_export( $this->export() );

		$this->assertTrue( $response['done'] );
		$this->assertCount( 1, $response['data'] );
		$item = $response['data'][0];
		$this->assertSame( Exporter::GROUP_ID, $item['group_id'] );
		$this->assertSame( 'Subscriptions', $item['group_label'] );
		$this->assertSame( 'contract-' . $contract_id, $item['item_id'] );

		$this->assertSame(
			[
				'Subscription number'   => '#' . $contract_id,
				'Status'                => 'Active',
				'Start date'            => wp_date( 'Y-m-d H:i', (int) strtotime( (string) $contract->get_start_gmt() . ' UTC' ) ),
				'Next payment'          => wp_date( 'Y-m-d H:i', (int) strtotime( (string) $contract->get_next_payment_gmt() . ' UTC' ) ),
				'Recurring total'       => html_entity_decode( wp_strip_all_tags( wc_price( $contract->get_billing_total(), [ 'currency' => 'USD' ] ) ), ENT_QUOTES, 'UTF-8' ),
				'Items'                 => 'Coffee Box x 2',
				'Billing address'       => 'Ada Lovelace, 12 Analytical Row, London, N1 9GU, United Kingdom (UK)',
				'Shipping address'      => 'Ada Lovelace, 1 Engine Court, London, SW1A 1AA, United Kingdom (UK)',
				'Email address'         => 'ada@example.com',
				'Phone number'          => '020 7946 0018',
				'Shipping phone number' => '020 7946 0999',
				'Payment method'        => 'Visa ending in 4242',
			],
			$this->rows_of( $item )
		);
	}

	public function test_empty_values_are_left_out(): void {
		$this->seed_contract(
			[
				'customer_id'      => $this->customer_id,
				'status'           => ContractStatus::DRAFT,
				'next_payment_gmt' => null,
			]
		);

		$response = $this->assert_export( $this->export() );

		$this->assertSame(
			[ 'Subscription number', 'Status', 'Start date', 'Recurring total' ],
			array_keys( $this->rows_of( $response['data'][0] ) ),
			'A draft without dates, items, addresses or payment method exports only what it has.'
		);
		$this->assertSame( 'Draft', $this->rows_of( $response['data'][0] )['Status'], 'Drafts are exported.' );
	}

	public function test_an_ended_contract_exports_its_end_date(): void {
		$this->seed_contract(
			[
				'customer_id'      => $this->customer_id,
				'status'           => ContractStatus::CANCELLED,
				'next_payment_gmt' => null,
				'end_gmt'          => '2026-03-04 05:06:00',
			]
		);

		$rows = $this->rows_of( $this->assert_export( $this->export() )['data'][0] );

		$this->assertSame( 'Cancelled', $rows['Status'] );
		$this->assertSame( '2026-03-04 05:06', $rows['End date'] );
	}

	public function test_eleven_contracts_export_over_two_pages_oldest_first(): void {
		$ids = [];
		for ( $i = 0; $i < 11; $i++ ) {
			$ids[] = $this->seed_contract( [ 'customer_id' => $this->customer_id ] );
		}

		$first  = $this->assert_export( $this->export( self::EMAIL, 1 ) );
		$second = $this->assert_export( $this->export( self::EMAIL, 2 ) );

		$this->assertFalse( $first['done'] );
		$this->assertTrue( $second['done'] );
		$this->assertSame(
			array_map(
				static function ( int $id ): string {
					return 'contract-' . $id;
				},
				$ids
			),
			array_column( array_merge( $first['data'], $second['data'] ), 'item_id' )
		);
	}

	public function test_ten_contracts_finish_on_the_first_page(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->seed_contract( [ 'customer_id' => $this->customer_id ] );
		}

		$response = $this->assert_export( $this->export() );

		$this->assertCount( 10, $response['data'] );
		$this->assertTrue( $response['done'] );
	}

	public function test_contracts_past_the_first_engine_read_are_exported(): void {
		$ids = [];
		for ( $i = 0; $i < 51; $i++ ) {
			$ids[] = $this->seed_contract( [ 'customer_id' => $this->customer_id ] );
		}

		$last = $this->assert_export( $this->export( self::EMAIL, 6 ) );

		$this->assertSame( [ 'contract-' . end( $ids ) ], array_column( $last['data'], 'item_id' ), 'The 51st contract is on page six.' );
		$this->assertTrue( $last['done'] );
	}

	public function test_other_customers_and_other_extensions_contracts_are_left_out(): void {
		$own = $this->seed_contract( [ 'customer_id' => $this->customer_id ] );
		$this->seed_foreign_contract( $this->customer_id );
		$this->seed_contract( [ 'customer_id' => $this->create_customer() ] );

		$response = $this->assert_export( $this->export() );

		$this->assertSame( [ 'contract-' . $own ], array_column( $response['data'], 'item_id' ) );
	}

	public function test_an_email_without_an_account_exports_nothing(): void {
		$this->seed_contract( [ 'customer_id' => $this->customer_id ] );

		$response = $this->assert_export( $this->export( 'nobody@example.com' ) );

		$this->assertSame( [], $response['data'] );
		$this->assertTrue( $response['done'] );
	}

	public function test_a_failed_read_returns_an_error_and_logs_no_personal_data(): void {
		$this->seed_contract( [ 'customer_id' => $this->customer_id ] );
		$this->capture_log();
		$this->fail_next_query( '/FROM \S*wc_subscription_contracts\b.*customer_id/s' );

		$response = $this->export();

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'woocommerce_subscriptions_lite_privacy_read_failed', $response->get_error_code() );
		$this->assertTrue( $this->has_logged( 'Privacy export: subscriptions could not be read' ) );
		$this->assertSame( [ Package::EXTENSION_SLUG ], array_unique( $this->logged_sources_of( 'Privacy export' ) ) );
		$this->assertFalse( $this->has_logged( self::EMAIL ) );
	}
}
