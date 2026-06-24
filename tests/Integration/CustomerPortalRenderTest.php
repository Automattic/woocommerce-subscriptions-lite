<?php
/**
 * Integration test for the default, non-mocked customer-portal render wiring.
 *
 * Boots the REAL components together - the {@see Endpoints} render path through
 * the real {@see Providers} resolver, {@see FixtureDataProvider},
 * {@see ViewModel}, and the actual template files - and asserts the rendered
 * markup and the seeded Interactivity API state for each status. This is the M0
 * learning made concrete: unit tests mock the seams and miss wiring bugs, so
 * this exercises the components wired together with no mocks.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Endpoints;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\FixtureDataProvider;

/**
 * @coversNothing
 */
final class CustomerPortalRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wc_subs_lite_current_user_id'] = 1;
		$GLOBALS['wc_subs_lite_iapi_state']      = [];

		// The production default provider is the engine-backed one, which reads
		// from the database. This suite exercises the render wiring without a
		// booted database, so it installs the fixture provider through the same
		// public filter an overlay or demo would use.
		$GLOBALS['woocommerce_subscriptions_lite_test_filters'] = [];
		add_filter(
			'woocommerce_subscriptions_lite_customer_portal_data_provider',
			static function () {
				return new FixtureDataProvider();
			}
		);
	}

	protected function tearDown(): void {
		$GLOBALS['woocommerce_subscriptions_lite_test_filters'] = [];
		parent::tearDown();
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

	public function test_list_renders_every_fixture_status_with_badges(): void {
		$endpoints = new Endpoints();
		$html      = $this->capture( [ $endpoints, 'render_list' ] );

		$this->assertStringContainsString( 'account-subscriptions-table', $html );
		$this->assertStringContainsString( 'data-wp-interactive="' . Endpoints::STORE_NAMESPACE . '"', $html );

		// A status badge for every known status appears in the list.
		foreach ( ContractStatus::all() as $status ) {
			$this->assertStringContainsString(
				'subscription-status-badge--' . $status,
				$html,
				"The list shows a badge for the {$status} status."
			);
		}

		// The View link points at the detail endpoint slug.
		$this->assertStringContainsString( Endpoints::DETAIL_ENDPOINT . '/101', $html );
	}

	public function test_active_detail_renders_actions_and_seeds_state(): void {
		$endpoints = new Endpoints();
		$html      = $this->capture(
			static function () use ( $endpoints ): void {
				$endpoints->render_detail( 101 );
			}
		);

		$this->assertStringContainsString( 'subscription-detail-block', $html );
		$this->assertStringContainsString( 'subscription-status-badge--active', $html );
		// Active shows cancel + pause, not reactivate.
		$this->assertStringContainsString( 'data-wp-on--click="actions.openCancelModal"', $html );
		$this->assertStringContainsString( 'data-wp-on--click="actions.submitHold"', $html );
		$this->assertStringNotContainsString( 'actions.submitReactivate', $html );
		// The cancel modal is present with its live-region error.
		$this->assertStringContainsString( 'wc-subscriptions-lite-cancel-modal', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
		// The in-page actions carry their own live-region error, bound to a
		// distinct state field so it never cross-renders with the modal error.
		$this->assertStringContainsString( 'subscription-detail-actions__error', $html );
		$this->assertStringContainsString( 'data-wp-text="state.actionError"', $html );
		$this->assertStringContainsString( 'data-wp-text="state.error"', $html );
		// Related orders render.
		$this->assertStringContainsString( 'subscription-related-orders', $html );

		// The store state seeded for the client carries the contract + cancel mode.
		$state = $GLOBALS['wc_subs_lite_iapi_state'][ Endpoints::STORE_NAMESPACE ];
		$this->assertSame( 101, $state['contractId'] );
		$this->assertTrue( $state['atPeriodEnd'], 'Active subscription cancels at period end.' );
		$this->assertSame( ContractStatus::ACTIVE, $state['status'] );
		$this->assertArrayHasKey( 'i18n', $state );
		// Both error-region state fields are seeded so the live regions can bind.
		$this->assertSame( '', $state['error'] );
		$this->assertSame( '', $state['actionError'] );
	}

	public function test_on_hold_admin_path_detail_shows_reactivate(): void {
		$endpoints = new Endpoints();
		$html      = $this->capture(
			static function () use ( $endpoints ): void {
				$endpoints->render_detail( 102 );
			}
		);

		$this->assertStringContainsString( 'data-wp-on--click="actions.submitReactivate"', $html );
		$this->assertStringNotContainsString( 'subscription-needs-payment-notice', $html );

		$state = $GLOBALS['wc_subs_lite_iapi_state'][ Endpoints::STORE_NAMESPACE ];
		$this->assertFalse( $state['atPeriodEnd'], 'On-hold cancels immediately.' );
	}

	public function test_on_hold_retry_path_detail_shows_needs_payment_notice(): void {
		$endpoints = new Endpoints();
		$html      = $this->capture(
			static function () use ( $endpoints ): void {
				$endpoints->render_detail( 103 );
			}
		);

		$this->assertStringContainsString( 'subscription-needs-payment-notice', $html );
		$this->assertStringNotContainsString( 'actions.submitReactivate', $html );
	}

	public function test_pending_cancellation_detail_hides_all_lifecycle_actions(): void {
		$endpoints = new Endpoints();
		$html      = $this->capture(
			static function () use ( $endpoints ): void {
				$endpoints->render_detail( 104 );
			}
		);

		$this->assertStringContainsString( 'subscription-status-badge--pending-cancellation', $html );
		$this->assertStringNotContainsString( 'actions.openCancelModal', $html );
		$this->assertStringNotContainsString( 'actions.submitHold', $html );
		$this->assertStringNotContainsString( 'actions.submitReactivate', $html );
	}

	public function test_unknown_contract_renders_not_found(): void {
		$endpoints = new Endpoints();
		$html      = $this->capture(
			static function () use ( $endpoints ): void {
				$endpoints->render_detail( 999999 );
			}
		);

		$this->assertStringContainsString( 'Subscription not found.', $html );
		$this->assertStringContainsString( Endpoints::LIST_ENDPOINT, $html );
	}

	public function test_foreign_or_anonymous_request_is_not_found(): void {
		$GLOBALS['wc_subs_lite_current_user_id'] = 0;
		$endpoints                               = new Endpoints();
		$html                                    = $this->capture(
			static function () use ( $endpoints ): void {
				$endpoints->render_detail( 101 );
			}
		);

		$this->assertStringContainsString( 'Subscription not found.', $html );
	}
}
