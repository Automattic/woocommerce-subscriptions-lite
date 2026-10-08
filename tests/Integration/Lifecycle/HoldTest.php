<?php
/**
 * Integration tests for Hold: active -> on-hold, the next-due moment disarmed and kept
 * as the hold anchor, and the held action fired.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Lifecycle;

use RuntimeException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HoldAnchor;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\LifecycleNotAllowed;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HoldAnchor
 */
final class HoldTest extends LiteIntegrationTestCase {

	/**
	 * The stored hold anchor ('' when absent).
	 *
	 * @param int $contract_id Contract id.
	 * @return mixed
	 */
	private function get_stored_anchor( int $contract_id ) {
		return Contracts::get_meta( $contract_id, HoldAnchor::META_KEY, true );
	}

	public function test_hold_moves_an_active_contract_on_hold_and_returns_the_view(): void {
		$contract_id = $this->seed_contract();

		$held = ( new Hold() )->hold( $contract_id );

		$this->assertInstanceOf( ContractView::class, $held );
		$this->assertSame( ContractStatus::ON_HOLD, $held->get_status() );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_hold_clears_the_next_payment_and_keeps_the_anchor(): void {
		$contract_id = $this->seed_contract();

		( new Hold() )->hold( $contract_id );

		$this->assertNull( $this->get_contract( $contract_id )->get_next_payment_gmt() );
		$this->assertSame( '2099-01-01 00:00:00', $this->get_stored_anchor( $contract_id ) );
	}

	public function test_hold_without_a_next_payment_stores_no_anchor(): void {
		$contract_id = $this->seed_contract( [ 'next_payment_gmt' => null ] );

		( new Hold() )->hold( $contract_id );

		$held = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ON_HOLD, $held->get_status() );
		$this->assertNull( $held->get_next_payment_gmt() );
		$this->assertSame( '', $this->get_stored_anchor( $contract_id ) );
	}

	public function test_hold_on_an_on_hold_contract_is_an_idempotent_no_op(): void {
		$contract_id = $this->seed_contract();
		( new Hold() )->hold( $contract_id );

		$fired = 0;
		add_action(
			'woocommerce_subscriptions_lite_contract_held',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		// A second hold must not wipe the anchor stored by the first.
		$held = ( new Hold() )->hold( $contract_id );

		$this->assertInstanceOf( ContractView::class, $held );
		$this->assertSame( 1, $fired );
		$this->assertSame( ContractStatus::ON_HOLD, $held->get_status() );
		$this->assertNull( $this->get_contract( $contract_id )->get_next_payment_gmt() );
		$this->assertSame( '2099-01-01 00:00:00', $this->get_stored_anchor( $contract_id ) );
	}

	public function test_the_anchor_survives_a_contract_update(): void {
		$contract_id = $this->seed_contract();
		( new Hold() )->hold( $contract_id );

		Contracts::update( $contract_id, [ 'end_gmt' => '2099-12-31 00:00:00' ] );

		$this->assertSame( '2099-01-01 00:00:00', HoldAnchor::read( $contract_id ) );
	}

	public function test_hold_fires_the_held_action_with_the_resulting_view(): void {
		$contract_id = $this->seed_contract();
		$received    = [];
		add_action(
			'woocommerce_subscriptions_lite_contract_held',
			static function ( ContractView $contract ) use ( &$received ): void {
				$received[] = $contract;
			}
		);

		( new Hold() )->hold( $contract_id );

		$this->assertCount( 1, $received );
		$this->assertSame( $contract_id, $received[0]->get_id() );
		$this->assertSame( ContractStatus::ON_HOLD, $received[0]->get_status() );
	}

	/**
	 * The anchor is stored before the hold disarms the contract, so a failed meta write
	 * aborts the hold with nothing disarmed.
	 */
	public function test_hold_aborts_without_disarming_when_the_anchor_cannot_be_stored(): void {
		$contract_id = $this->seed_contract();
		$meta_table  = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACT_META );
		$break       = static function ( string $query ) use ( $meta_table ): string {
			return 0 === strpos( $query, "INSERT INTO `{$meta_table}`" ) ? 'SELECT broken syntax (' : $query;
		};
		add_filter( 'query', $break );

		try {
			( new Hold() )->hold( $contract_id );
			$this->fail( 'Expected the hold to abort when the anchor cannot be stored.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'anchor could not be stored', $e->getMessage() );
		} finally {
			remove_filter( 'query', $break );
		}

		$stored = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ACTIVE, $stored->get_status() );
		$this->assertSame( '2099-01-01 00:00:00', $stored->get_next_payment_gmt(), 'The next-due moment was not disarmed.' );
	}

	/**
	 * @dataProvider provide_rejected_statuses
	 *
	 * @param string $status Stored status the hold must reject.
	 */
	public function test_hold_rejects_a_contract_that_is_not_active( string $status ): void {
		$contract_id = $this->seed_contract( [ 'status' => $status ] );
		$before      = did_action( 'woocommerce_subscriptions_lite_contract_held' );

		try {
			( new Hold() )->hold( $contract_id );
			$this->fail( 'Expected LifecycleNotAllowed.' );
		} catch ( LifecycleNotAllowed $e ) {
			$stored = $this->get_contract( $contract_id );
			$this->assertSame( $status, $stored->get_status() );
			$this->assertSame( '2099-01-01 00:00:00', $stored->get_next_payment_gmt(), 'Nothing was written.' );
			$this->assertSame( $before, did_action( 'woocommerce_subscriptions_lite_contract_held' ) );
		}
	}

	/**
	 * Statuses hold rejects.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_rejected_statuses(): array {
		return [
			'cancelled'            => [ ContractStatus::CANCELLED ],
			'pending cancellation' => [ ContractStatus::PENDING_CANCELLATION ],
			'expired'              => [ ContractStatus::EXPIRED ],
			'draft'                => [ ContractStatus::DRAFT ],
		];
	}

	public function test_hold_rejects_an_unregistered_stored_status(): void {
		$contract_id = $this->seed_contract();
		$this->store_raw_status( $contract_id, 'legacy-paused' );

		try {
			( new Hold() )->hold( $contract_id );
			$this->fail( 'Expected LifecycleNotAllowed for an unregistered stored status.' );
		} catch ( LifecycleNotAllowed $e ) {
			$stored = $this->get_contract( $contract_id );
			$this->assertSame( 'legacy-paused', $stored->get_status() );
			$this->assertSame( '2099-01-01 00:00:00', $stored->get_next_payment_gmt() );
		}
	}

	public function test_hold_returns_null_for_a_missing_contract(): void {
		$this->assertNull( ( new Hold() )->hold( 4242424 ) );
	}

	public function test_hold_leaves_a_contract_of_another_extension_untouched(): void {
		$contract_id = $this->seed_contract( [ 'extension_slug' => 'another-extension' ] );

		$this->assertNull( ( new Hold() )->hold( $contract_id ) );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}
}
