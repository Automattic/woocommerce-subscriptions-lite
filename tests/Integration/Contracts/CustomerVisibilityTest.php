<?php
/**
 * Integration tests for the customer-visible contract rule.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Contracts;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\Contracts\CustomerVisibility;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Contracts\CustomerVisibility
 */
final class CustomerVisibilityTest extends LiteIntegrationTestCase {

	public function test_a_non_draft_lite_contract_is_visible(): void {
		$this->assertTrue( CustomerVisibility::is_visible( $this->get_contract( $this->seed_contract() ) ) );
	}

	public function test_a_lite_draft_is_not_visible(): void {
		$draft = $this->get_contract(
			$this->seed_contract(
				[
					'status'           => ContractStatus::DRAFT,
					'next_payment_gmt' => null,
				]
			)
		);

		$this->assertFalse( CustomerVisibility::is_visible( $draft ) );
	}

	public function test_a_contract_of_another_extension_is_not_visible(): void {
		$this->assertFalse( CustomerVisibility::is_visible( $this->get_contract( $this->seed_foreign_contract( 1 ) ) ) );
	}
}
