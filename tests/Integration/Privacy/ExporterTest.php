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

	public function test_a_contract_exports_every_field_in_the_store_time_zone(): void {
		$this->set_store_timezone( 'America/New_York' );
		$contract_id = $this->seed_contract(
			[
				'customer_id'          => $this->customer_id,
				'status'               => ContractStatus::PENDING_CANCELLATION,
				'start_gmt'            => '2026-01-01 00:00:00',
				'next_payment_gmt'     => '2026-07-01 03:30:00',
				'end_gmt'              => '2026-08-01 12:00:00',
				'billing_total'        => '19.99',
				'payment_method_title' => 'Visa ending in 4242',
				'items'                => [
					[
						'item_name' => 'Coffee Box',
						'item_type' => 'line_item',
						'quantity'  => 2,
						'subtotal'  => '19.99',
						'total'     => '19.99',
					],
				],
				'addresses'            => [
					'billing'  => [
						'first_name' => 'Ada',
						'last_name'  => 'Lovelace',
						'company'    => 'Analytical Engines Ltd',
						'address_1'  => '12 Analytical Row',
						'address_2'  => 'Flat 3',
						'city'       => 'London',
						'state'      => 'Greater London',
						'postcode'   => 'N1 9GU',
						'country'    => 'GB',
						'email'      => 'ada@example.com',
						'phone'      => '020 7946 0018',
					],
					'shipping' => [
						'first_name' => 'Ada',
						'last_name'  => 'Lovelace',
						'address_1'  => '1 Engine Court',
						'city'       => 'London',
						'postcode'   => 'SW1A 1AA',
						'country'    => 'GB',
						'email'      => 'ada.shipping@example.com',
						'phone'      => '020 7946 0999',
					],
				],
			]
		);

		$response = $this->assert_export( $this->export() );

		$this->assertTrue( $response['done'] );
		$this->assertCount( 1, $response['data'] );
		$item = $response['data'][0];
		$this->assertSame( Exporter::GROUP_ID, $item['group_id'] );
		$this->assertSame( 'Subscriptions', $item['group_label'] );
		$this->assertSame( 'contract-' . $contract_id, $item['item_id'] );

		$this->assertSame(
			[
				[ 'Subscription number', '#' . $contract_id ],
				[ 'Status', 'Pending cancellation' ],
				[ 'Start date', '2025-12-31 19:00' ],
				[ 'Next payment', '2026-06-30 23:30' ],
				[ 'End date', '2026-08-01 08:00' ],
				[ 'Recurring total', '$19.99' ],
				[ 'Items', 'Coffee Box x 2' ],
				[ 'Billing address', 'Ada Lovelace, Analytical Engines Ltd, 12 Analytical Row, Flat 3, London, Greater London, N1 9GU, United Kingdom (UK)' ],
				[ 'Shipping address', 'Ada Lovelace, 1 Engine Court, London, SW1A 1AA, United Kingdom (UK)' ],
				[ 'Email address', 'ada@example.com' ],
				[ 'Phone number', '020 7946 0018' ],
				[ 'Shipping email address', 'ada.shipping@example.com' ],
				[ 'Shipping phone number', '020 7946 0999' ],
				[ 'Payment method', 'Visa ending in 4242' ],
			],
			array_map(
				static function ( array $row ): array {
					return [ $row['name'], $row['value'] ];
				},
				$item['data']
			)
		);
	}

	public function test_a_checkout_created_contract_exports_its_items_addresses_and_payment_method(): void {
		$this->create_contract(
			$this->customer_id,
			[
				'product_name'         => 'Coffee Box',
				'quantity'             => 2,
				'payment_method_title' => 'Visa ending in 4242',
				'shipping'             => [ 'phone' => '020 7946 0999' ],
			]
		);

		$rows = $this->rows_of( $this->assert_export( $this->export() )['data'][0] );

		$this->assertSame( 'Active', $rows['Status'] );
		$this->assertSame( 'Coffee Box x 2', $rows['Items'] );
		$this->assertSame( 'Ada Lovelace, 12 Analytical Row, London, N1 9GU, United Kingdom (UK)', $rows['Billing address'] );
		$this->assertSame( 'Ada Lovelace, 1 Engine Court, London, SW1A 1AA, United Kingdom (UK)', $rows['Shipping address'] );
		$this->assertSame( 'ada@example.com', $rows['Email address'] );
		$this->assertSame( '020 7946 0018', $rows['Phone number'] );
		$this->assertSame( '020 7946 0999', $rows['Shipping phone number'] );
		$this->assertSame( 'Visa ending in 4242', $rows['Payment method'] );
		$this->assertArrayHasKey( 'Start date', $rows );
		$this->assertArrayHasKey( 'Next payment', $rows );
		$this->assertArrayHasKey( 'Recurring total', $rows );
	}

	public function test_the_store_base_country_stays_in_the_address(): void {
		update_option( 'woocommerce_default_country', 'GB' );
		$this->seed_contract(
			[
				'customer_id' => $this->customer_id,
				'addresses'   => [ 'billing' => self::BILLING_ADDRESS ],
			]
		);

		$rows = $this->rows_of( $this->assert_export( $this->export() )['data'][0] );

		$this->assertSame( 'Ada Lovelace, 12 Analytical Row, London, N1 9GU, United Kingdom (UK)', $rows['Billing address'] );
		$this->assertFalse( apply_filters( 'woocommerce_formatted_address_force_country_display', false ), 'The country is forced for the export only.' );
	}

	public function test_a_state_the_address_format_leaves_out_gets_its_own_row(): void {
		$this->seed_contract(
			[
				'customer_id' => $this->customer_id,
				'addresses'   => [
					'shipping' => [
						'first_name' => 'Ada',
						'last_name'  => 'Lovelace',
						'address_1'  => 'Marienplatz 1',
						'city'       => 'Munich',
						'state'      => 'DE-BY',
						'postcode'   => '80331',
						'country'    => 'DE',
					],
				],
			]
		);

		$rows = $this->rows_of( $this->assert_export( $this->export() )['data'][0] );

		$this->assertSame( 'Ada Lovelace, Marienplatz 1, 80331 Munich, Germany', $rows['Shipping address'] );
		$this->assertSame( 'Bavaria', $rows['Shipping state'] );
		$this->assertArrayNotHasKey( 'Shipping country', $rows, 'Fields the format shows get no row of their own.' );
	}

	public function test_two_labels_translated_alike_keep_both_rows(): void {
		$this->seed_contract(
			[
				'customer_id' => $this->customer_id,
				'addresses'   => [
					'billing'  => [ 'phone' => '020 7946 0018' ],
					'shipping' => [ 'phone' => '020 7946 0999' ],
				],
			]
		);
		add_filter(
			'gettext_woocommerce-subscriptions-lite',
			static function ( string $translation, string $text ): string {
				return in_array( $text, [ 'Phone number', 'Shipping phone number' ], true ) ? 'Telefon' : $translation;
			},
			10,
			2
		);

		$item = $this->assert_export( $this->export() )['data'][0];

		$this->assertSame(
			[ '020 7946 0018', '020 7946 0999' ],
			array_values(
				array_column(
					array_filter(
						$item['data'],
						static function ( array $row ): bool {
							return 'Telefon' === $row['name'];
						}
					),
					'value'
				)
			)
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

	/**
	 * Seed a Lite contract whose billing address email is `$email`.
	 *
	 * @param int|null             $customer_id Customer id, or null for a guest contract.
	 * @param string               $email       Billing address email.
	 * @param array<string, mixed> $args        Contract fields overriding the defaults.
	 * @return int Contract id.
	 */
	private function seed_contract_with_email( ?int $customer_id, string $email, array $args = [] ): int {
		return $this->seed_contract(
			array_merge(
				[
					'customer_id' => $customer_id,
					'addresses'   => [ 'billing' => array_merge( self::BILLING_ADDRESS, [ 'email' => $email ] ) ],
				],
				$args
			)
		);
	}

	public function test_a_guest_contract_is_found_by_its_address_email(): void {
		$guest = $this->seed_contract_with_email( null, self::EMAIL );

		$response = $this->assert_export( $this->export() );

		$this->assertSame( [ 'contract-' . $guest ], array_column( $response['data'], 'item_id' ) );
		$this->assertSame( self::EMAIL, $this->rows_of( $response['data'][0] )['Email address'] );
	}

	public function test_a_contract_whose_account_was_deleted_is_found_by_its_address_email(): void {
		$email       = 'orphan@example.com';
		$customer_id = $this->create_customer( [ 'user_email' => $email ] );
		$orphan      = $this->seed_contract_with_email( $customer_id, $email );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $customer_id );

		$response = $this->assert_export( $this->export( $email ) );

		$this->assertSame( [ 'contract-' . $orphan ], array_column( $response['data'], 'item_id' ) );
	}

	public function test_a_contract_found_by_both_reads_is_exported_once_in_id_order(): void {
		$account_only = $this->seed_contract( [ 'customer_id' => $this->customer_id ] );
		$both         = $this->seed_contract_with_email( $this->customer_id, self::EMAIL );
		$guest        = $this->seed_contract_with_email( null, self::EMAIL );

		$response = $this->assert_export( $this->export() );

		$this->assertSame(
			[ 'contract-' . $account_only, 'contract-' . $both, 'contract-' . $guest ],
			array_column( $response['data'], 'item_id' )
		);
		$this->assertTrue( $response['done'] );
	}

	public function test_another_accounts_contract_with_the_email_is_exported(): void {
		$other = $this->seed_contract_with_email( $this->create_customer(), self::EMAIL );

		$response = $this->assert_export( $this->export() );

		$this->assertSame( [ 'contract-' . $other ], array_column( $response['data'], 'item_id' ) );
	}

	public function test_the_address_email_matches_a_shipping_email_and_ignores_case(): void {
		$shipping = $this->seed_contract(
			[
				'customer_id' => null,
				'addresses'   => [ 'shipping' => array_merge( self::SHIPPING_ADDRESS, [ 'email' => self::EMAIL ] ) ],
			]
		);
		$upper    = $this->seed_contract_with_email( null, strtoupper( self::EMAIL ) );

		$response = $this->assert_export( $this->export( 'nobody-' . self::EMAIL ) );
		$this->assertSame( [], $response['data'], 'Another email matches nothing.' );

		$response = $this->assert_export( $this->export() );

		$this->assertSame( [ 'contract-' . $shipping, 'contract-' . $upper ], array_column( $response['data'], 'item_id' ) );
	}

	public function test_another_extensions_contract_with_the_email_is_left_out(): void {
		$this->seed_contract_with_email( null, self::EMAIL, [ 'extension_slug' => self::FOREIGN_EXTENSION_SLUG ] );

		$response = $this->assert_export( $this->export() );

		$this->assertSame( [], $response['data'] );
	}

	public function test_guest_contracts_past_the_first_engine_read_are_exported(): void {
		$ids = [];
		for ( $i = 0; $i < 51; $i++ ) {
			$ids[] = $this->seed_contract_with_email( null, self::EMAIL );
		}

		$last = $this->assert_export( $this->export( self::EMAIL, 6 ) );

		$this->assertSame( [ 'contract-' . end( $ids ) ], array_column( $last['data'], 'item_id' ), 'The 51st contract is on page six.' );
		$this->assertTrue( $last['done'] );
	}

	public function test_a_failed_address_email_read_returns_an_error(): void {
		$this->seed_contract_with_email( null, self::EMAIL );
		$this->capture_log();
		$this->fail_next_query( '/FROM \S*wc_subscription_contract_addresses\b.*email/s' );

		$response = $this->export();

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'woocommerce_subscriptions_lite_privacy_read_failed', $response->get_error_code() );
		$this->assertFalse( $this->has_logged( self::EMAIL ) );
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
