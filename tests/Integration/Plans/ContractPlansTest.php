<?php
/**
 * Integration tests for ContractPlans::get_billing_policy_for().
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Plans;

use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ContractPlans;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Plans\ContractPlans
 */
final class ContractPlansTest extends LiteIntegrationTestCase {

	public function test_billing_policy_for_a_contract_without_a_plan_is_null(): void {
		$contract_id = $this->seed_contract( [ 'selling_plan_id' => null ] );

		$this->assertNull( ContractPlans::get_billing_policy_for( $this->get_contract( $contract_id ) ) );
	}

	public function test_billing_policy_for_a_missing_plan_is_null(): void {
		$contract_id = $this->seed_contract( [ 'selling_plan_id' => 999999 ] );

		$this->assertNull( ContractPlans::get_billing_policy_for( $this->get_contract( $contract_id ) ) );
	}

	public function test_billing_policy_for_an_unparseable_payload_is_null(): void {
		$plan        = $this->make_unvalidated_plan( 'fortnight' );
		$contract_id = $this->seed_contract( [ 'selling_plan_id' => $plan->get_id() ] );

		$this->assertNull( ContractPlans::get_billing_policy_for( $this->get_contract( $contract_id ) ) );
	}

	public function test_billing_policy_for_a_plan_of_another_extension_is_null(): void {
		$plan        = $this->make_plan( 'month', 1, null, [ 'extension_slug' => 'another-extension' ] );
		$contract_id = $this->seed_contract( [ 'selling_plan_id' => $plan->get_id() ] );

		$this->assertNull( ContractPlans::get_billing_policy_for( $this->get_contract( $contract_id ) ) );
	}

	public function test_billing_policy_for_a_valid_plan_parses_its_cadence(): void {
		$plan        = $this->make_plan( 'week', 2 );
		$contract_id = $this->seed_contract( [ 'selling_plan_id' => $plan->get_id() ] );

		$policy = ContractPlans::get_billing_policy_for( $this->get_contract( $contract_id ) );

		$this->assertInstanceOf( BillingPolicy::class, $policy );
		$this->assertSame( 'week', $policy->get_period() );
		$this->assertSame( 2, $policy->get_interval() );
	}
}
