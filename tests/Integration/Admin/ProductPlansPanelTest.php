<?php
/**
 * Integration tests for the product plans panel.
 *
 * The save paths run END TO END: a real nonce for the real current user, a
 * real product, and the panel's default wiring into Lite's applicability
 * store - the mapped applicability is read back through the same store. A
 * store rejection surfaces through the real WC_Admin_Meta_Boxes error list.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanRepository;
use Automattic\WooCommerce\SubscriptionsLite\Admin\ProductPlansPanel;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Admin_Meta_Boxes;
use WC_Product;
use WC_Product_External;
use WC_Product_Grouped;
use WC_Product_Simple;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\ProductPlansPanel
 */
final class ProductPlansPanelTest extends LiteIntegrationTestCase {

	public function set_up(): void {
		parent::set_up();

		// The suite runs as a front-end request, so WooCommerce has not loaded
		// its admin classes; the save handler reports rejections through this one.
		require_once WC_ABSPATH . 'includes/admin/class-wc-admin-meta-boxes.php';
		WC_Admin_Meta_Boxes::$meta_box_errors = [];

		$_POST = [];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		WC_Admin_Meta_Boxes::$meta_box_errors = [];
		$_POST                                = [];
		parent::tear_down();
	}

	/**
	 * Persist a named Lite-owned plan.
	 *
	 * @param string $name   Plan name.
	 * @param string $period Billing period unit.
	 */
	private function named_plan( string $name, string $period = 'month' ): Plan {
		$plan = Plan::create(
			[
				'name'           => $name,
				'billing_policy' => new BillingPolicy( $period, 1, null, null, null ),
				'extension_slug' => Package::EXTENSION_SLUG,
			]
		);
		( new PlanRepository() )->insert( $plan );

		return $plan;
	}

	/**
	 * Create a saved simple product.
	 */
	private function simple_product(): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_name( 'Coffee Box' );
		$product->set_regular_price( '20.00' );
		$product->save();

		return $product;
	}

	/**
	 * Seed a valid panel POST (real nonce for the current user); tests
	 * override individual keys.
	 *
	 * @param array<string, mixed> $overrides POST keys to override or add.
	 */
	private function seed_post( array $overrides = [] ): void {
		$_POST = array_merge(
			[
				ProductPlansPanel::NONCE_FIELD        => wp_create_nonce( ProductPlansPanel::NONCE_ACTION ),
				ProductPlansPanel::POST_PURCHASE_MODE => ProductPlansPanel::MODE_ONE_TIME,
			],
			$overrides
		);
	}

	/**
	 * Mark a product as selling on all plans, so a save that must be a no-op
	 * has a non-default state to be verified against.
	 *
	 * @param WC_Product $product Product to mark.
	 */
	private function preset_inherit_all( WC_Product $product ): void {
		( new ApplicabilityStore() )->set(
			$product->get_id(),
			new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL, [], true )
		);
	}

	/**
	 * Find the priority the panel's save callback is hooked at, or null when
	 * no ProductPlansPanel callback is on the hook.
	 *
	 * @param string $hook Hook name to scan.
	 */
	private function panel_hook_priority( string $hook ): ?int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return null;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof ProductPlansPanel ) {
					return (int) $priority;
				}
			}
		}

		return null;
	}

	public function test_add_tab_registers_the_subscriptions_tab_for_simple_and_variable(): void {
		$tabs = ( new ProductPlansPanel() )->add_tab( [] );

		$this->assertArrayHasKey( ProductPlansPanel::TAB_ID, $tabs );
		$tab = $tabs[ ProductPlansPanel::TAB_ID ];
		$this->assertSame( ProductPlansPanel::PANEL_ID, $tab['target'] );
		$this->assertContains( 'show_if_simple', $tab['class'] );
		$this->assertContains( 'show_if_variable', $tab['class'] );
	}

	public function test_register_binds_the_tab_panel_save_and_asset_hooks(): void {
		ProductPlansPanel::register();

		$this->assertNotNull( $this->panel_hook_priority( 'woocommerce_product_data_tabs' ) );
		$this->assertNotNull( $this->panel_hook_priority( 'woocommerce_product_data_panels' ) );
		$this->assertNotNull( $this->panel_hook_priority( 'admin_enqueue_scripts' ) );
		$this->assertSame(
			20,
			$this->panel_hook_priority( 'woocommerce_admin_process_product_object' ),
			'Save runs after WooCommerce core save callbacks.'
		);
	}

	public function test_save_is_a_noop_without_the_nonce_field(): void {
		$product = $this->simple_product();
		$this->preset_inherit_all( $product );
		$_POST = [ ProductPlansPanel::POST_PURCHASE_MODE => ProductPlansPanel::MODE_ONE_TIME ];

		( new ProductPlansPanel() )->save( $product );

		$this->assertSame(
			ProductApplicability::MODE_INHERIT_ALL,
			( new ApplicabilityStore() )->get( $product->get_id() )->get_mode(),
			'No store write without the panel nonce.'
		);
	}

	public function test_save_is_a_noop_with_an_invalid_nonce(): void {
		$product = $this->simple_product();
		$this->preset_inherit_all( $product );
		$this->seed_post( [ ProductPlansPanel::NONCE_FIELD => 'not-a-real-nonce' ] );

		( new ProductPlansPanel() )->save( $product );

		$this->assertSame(
			ProductApplicability::MODE_INHERIT_ALL,
			( new ApplicabilityStore() )->get( $product->get_id() )->get_mode(),
			'No store write on a failed nonce check.'
		);
	}

	public function test_save_is_a_noop_without_the_capability(): void {
		$product = $this->simple_product();
		$this->preset_inherit_all( $product );

		// The nonce is minted for the same underprivileged user, so it verifies
		// and the save falls through to the capability gate.
		wp_set_current_user( $this->create_customer() );
		$this->seed_post();

		( new ProductPlansPanel() )->save( $product );

		$this->assertSame(
			ProductApplicability::MODE_INHERIT_ALL,
			( new ApplicabilityStore() )->get( $product->get_id() )->get_mode(),
			'No store write without manage_woocommerce.'
		);
	}

	public function test_one_time_mode_maps_to_disable(): void {
		$product = $this->simple_product();
		$this->preset_inherit_all( $product );
		$this->seed_post(
			[
				ProductPlansPanel::POST_PURCHASE_MODE  => ProductPlansPanel::MODE_ONE_TIME,
				ProductPlansPanel::POST_ALLOW_ONE_TIME => 'yes',
			]
		);

		( new ProductPlansPanel() )->save( $product );

		$applicability = ( new ApplicabilityStore() )->get( $product->get_id() );
		$this->assertSame( ProductApplicability::MODE_DISABLE, $applicability->get_mode() );
		$this->assertSame( [], $applicability->get_plan_ids() );
		$this->assertTrue( $applicability->allows_one_time() );
	}

	public function test_plans_all_maps_to_inherit_all_and_ignores_submitted_plans(): void {
		$product = $this->simple_product();
		$plan    = $this->named_plan( 'Monthly' );
		$this->seed_post(
			[
				ProductPlansPanel::POST_PURCHASE_MODE  => ProductPlansPanel::MODE_PLANS,
				ProductPlansPanel::POST_PLANS_SCOPE    => ProductPlansPanel::SCOPE_ALL,
				ProductPlansPanel::POST_PLAN_IDS       => [ (string) $plan->get_id() ],
				ProductPlansPanel::POST_ALLOW_ONE_TIME => 'yes',
			]
		);

		( new ProductPlansPanel() )->save( $product );

		$applicability = ( new ApplicabilityStore() )->get( $product->get_id() );
		$this->assertSame( ProductApplicability::MODE_INHERIT_ALL, $applicability->get_mode() );
		$this->assertSame( [], $applicability->get_plan_ids(), 'All-mode is virtual; submitted checkboxes are ignored.' );
	}

	public function test_plans_select_maps_to_inherit_select_with_the_submitted_plans(): void {
		$product = $this->simple_product();
		$monthly = $this->named_plan( 'Monthly' );
		$yearly  = $this->named_plan( 'Yearly', 'year' );
		$this->seed_post(
			[
				ProductPlansPanel::POST_PURCHASE_MODE => ProductPlansPanel::MODE_PLANS,
				ProductPlansPanel::POST_PLANS_SCOPE   => ProductPlansPanel::SCOPE_SELECT,
				ProductPlansPanel::POST_PLAN_IDS      => [ (string) $monthly->get_id(), (string) $yearly->get_id() ],
			]
		);

		( new ProductPlansPanel() )->save( $product );

		$applicability = ( new ApplicabilityStore() )->get( $product->get_id() );
		$this->assertSame( ProductApplicability::MODE_INHERIT_SELECT, $applicability->get_mode() );
		$this->assertSame( [ (int) $monthly->get_id(), (int) $yearly->get_id() ], $applicability->get_plan_ids() );
		$this->assertFalse( $applicability->allows_one_time(), 'Unchecked one-time checkbox maps to false.' );
	}

	/**
	 * Clearing every checkbox and saving must not detach the plans: an empty
	 * select-scope submission keeps the previously stored selection while the
	 * rest of the panel (mode, allow-one-time) still saves.
	 */
	public function test_an_empty_select_submission_retains_the_previously_stored_plan_ids(): void {
		$product = $this->simple_product();
		$monthly = $this->named_plan( 'Monthly' );
		$yearly  = $this->named_plan( 'Yearly', 'year' );
		( new ApplicabilityStore() )->set(
			$product->get_id(),
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ (int) $monthly->get_id(), (int) $yearly->get_id() ], false )
		);

		// No POST_PLAN_IDS key at all - an all-unchecked table submits nothing.
		$this->seed_post(
			[
				ProductPlansPanel::POST_PURCHASE_MODE  => ProductPlansPanel::MODE_PLANS,
				ProductPlansPanel::POST_PLANS_SCOPE    => ProductPlansPanel::SCOPE_SELECT,
				ProductPlansPanel::POST_ALLOW_ONE_TIME => 'yes',
			]
		);

		( new ProductPlansPanel() )->save( $product );

		$applicability = ( new ApplicabilityStore() )->get( $product->get_id() );
		$this->assertSame( ProductApplicability::MODE_INHERIT_SELECT, $applicability->get_mode() );
		$this->assertSame(
			[ (int) $monthly->get_id(), (int) $yearly->get_id() ],
			$applicability->get_plan_ids(),
			'The empty submission leaves the stored selection untouched.'
		);
		$this->assertTrue( $applicability->allows_one_time(), 'The allow-one-time change still saves.' );
		$this->assertSame( [], WC_Admin_Meta_Boxes::$meta_box_errors, 'Retaining the selection is not an error.' );
	}

	/**
	 * With no previously stored selection there is nothing to retain: an empty
	 * select submission persists select mode with an empty selection (allowed
	 * by the store; it resolves to no plans).
	 */
	public function test_an_empty_select_submission_with_no_prior_selection_persists_empty(): void {
		$product = $this->simple_product();
		$this->named_plan( 'Monthly' );
		$this->preset_inherit_all( $product );

		$this->seed_post(
			[
				ProductPlansPanel::POST_PURCHASE_MODE => ProductPlansPanel::MODE_PLANS,
				ProductPlansPanel::POST_PLANS_SCOPE   => ProductPlansPanel::SCOPE_SELECT,
			]
		);

		( new ProductPlansPanel() )->save( $product );

		$applicability = ( new ApplicabilityStore() )->get( $product->get_id() );
		$this->assertSame( ProductApplicability::MODE_INHERIT_SELECT, $applicability->get_mode() );
		$this->assertSame( [], $applicability->get_plan_ids(), 'All-mode stores no ids, so there is nothing to retain.' );
	}

	public function test_garbage_plan_ids_are_dropped_before_the_store_write(): void {
		$product = $this->simple_product();
		$plan    = $this->named_plan( 'Monthly' );
		$this->seed_post(
			[
				ProductPlansPanel::POST_PURCHASE_MODE => ProductPlansPanel::MODE_PLANS,
				ProductPlansPanel::POST_PLANS_SCOPE   => ProductPlansPanel::SCOPE_SELECT,
				ProductPlansPanel::POST_PLAN_IDS      => [ 'abc', '0', (string) $plan->get_id() ],
			]
		);

		( new ProductPlansPanel() )->save( $product );

		$this->assertSame(
			[ (int) $plan->get_id() ],
			( new ApplicabilityStore() )->get( $product->get_id() )->get_plan_ids()
		);
	}

	public function test_tampered_mode_falls_back_to_one_time(): void {
		$product = $this->simple_product();
		$this->preset_inherit_all( $product );
		$this->seed_post( [ ProductPlansPanel::POST_PURCHASE_MODE => 'evil_mode' ] );

		( new ProductPlansPanel() )->save( $product );

		$this->assertSame(
			ProductApplicability::MODE_DISABLE,
			( new ApplicabilityStore() )->get( $product->get_id() )->get_mode()
		);
	}

	public function test_tampered_scope_falls_back_to_all(): void {
		$product = $this->simple_product();
		$plan    = $this->named_plan( 'Monthly' );
		$this->seed_post(
			[
				ProductPlansPanel::POST_PURCHASE_MODE => ProductPlansPanel::MODE_PLANS,
				ProductPlansPanel::POST_PLANS_SCOPE   => 'evil_scope',
				ProductPlansPanel::POST_PLAN_IDS      => [ (string) $plan->get_id() ],
			]
		);

		( new ProductPlansPanel() )->save( $product );

		$this->assertSame(
			ProductApplicability::MODE_INHERIT_ALL,
			( new ApplicabilityStore() )->get( $product->get_id() )->get_mode()
		);
	}

	/**
	 * Core fires `woocommerce_admin_process_product_object` unwrapped, so a
	 * throwing save handler would fatal the whole product save. The store's
	 * rejection of an unknown plan id must surface as a metabox error instead.
	 */
	public function test_a_tampered_plan_id_registers_an_admin_error_and_does_not_throw(): void {
		$product = $this->simple_product();
		$this->preset_inherit_all( $product );
		$this->seed_post(
			[
				ProductPlansPanel::POST_PURCHASE_MODE => ProductPlansPanel::MODE_PLANS,
				ProductPlansPanel::POST_PLANS_SCOPE   => ProductPlansPanel::SCOPE_SELECT,
				ProductPlansPanel::POST_PLAN_IDS      => [ '999999' ],
			]
		);

		( new ProductPlansPanel() )->save( $product );

		$errors = WC_Admin_Meta_Boxes::$meta_box_errors;
		$this->assertCount( 1, $errors, 'The rejection registers exactly one metabox error.' );
		$this->assertStringContainsString( 'plan 999999', $errors[0] );
		$this->assertSame(
			ProductApplicability::MODE_INHERIT_ALL,
			( new ApplicabilityStore() )->get( $product->get_id() )->get_mode(),
			'The rejected write leaves the stored applicability untouched.'
		);
	}

	public function test_a_plan_owned_by_another_extension_is_rejected(): void {
		$product = $this->simple_product();
		$foreign = Plan::create(
			[
				'name'           => 'Foreign plan',
				'billing_policy' => new BillingPolicy( 'month', 1, null, null, null ),
				'extension_slug' => 'another-extension',
			]
		);
		( new PlanRepository() )->insert( $foreign );

		$this->seed_post(
			[
				ProductPlansPanel::POST_PURCHASE_MODE => ProductPlansPanel::MODE_PLANS,
				ProductPlansPanel::POST_PLANS_SCOPE   => ProductPlansPanel::SCOPE_SELECT,
				ProductPlansPanel::POST_PLAN_IDS      => [ (string) $foreign->get_id() ],
			]
		);

		( new ProductPlansPanel() )->save( $product );

		$this->assertCount( 1, WC_Admin_Meta_Boxes::$meta_box_errors, 'Another extension\'s plan cannot be attached through Lite.' );
		$this->assertSame(
			ProductApplicability::MODE_DISABLE,
			( new ApplicabilityStore() )->get( $product->get_id() )->get_mode()
		);
	}

	/**
	 * The panel markup renders (and posts) for every product type, but only
	 * simple and variable products may carry applicability - other types must
	 * never reach the store, which would reject them with an error notice.
	 *
	 * @dataProvider unsupported_product_provider
	 *
	 * @param string $product_class Product class (a WC_Product subclass) that cannot carry applicability.
	 */
	public function test_save_skips_product_types_that_cannot_carry_applicability( string $product_class ): void {
		$product = new $product_class();
		$product->set_name( 'Unsupported' );
		$product->save();

		$this->seed_post(
			[
				ProductPlansPanel::POST_PURCHASE_MODE => ProductPlansPanel::MODE_PLANS,
				ProductPlansPanel::POST_PLANS_SCOPE   => ProductPlansPanel::SCOPE_ALL,
			]
		);

		( new ProductPlansPanel() )->save( $product );

		$this->assertSame( [], WC_Admin_Meta_Boxes::$meta_box_errors, 'The skip is silent - the store (which would reject) is never called.' );
		$this->assertSame(
			ProductApplicability::MODE_DISABLE,
			( new ApplicabilityStore() )->get( $product->get_id() )->get_mode(),
			'The product keeps the default applicability.'
		);
	}

	/**
	 * Product classes the save handler must skip.
	 *
	 * @return array<string, array{0: class-string<WC_Product>}>
	 */
	public function unsupported_product_provider(): array {
		return [
			'grouped'  => [ WC_Product_Grouped::class ],
			'external' => [ WC_Product_External::class ],
		];
	}

	/**
	 * Attach granularity is individual PLANS: every storewide plan renders its
	 * own checkbox row, and the plan's name is a real label targeting the
	 * row's checkbox.
	 */
	public function test_selection_table_renders_one_labeled_row_per_plan(): void {
		$product = $this->simple_product();
		$monthly = $this->named_plan( 'Monthly' );
		$yearly  = $this->named_plan( 'Yearly', 'year' );
		$weekly  = $this->named_plan( 'Weekly', 'week' );

		$html = $this->render_panel_for( $product );

		$this->assertSame( 3, substr_count( $html, 'data-wcsl-plan-checkbox' ), 'One checkbox per plan.' );
		foreach ( [ $monthly, $yearly, $weekly ] as $plan ) {
			$this->assertSame( 1, substr_count( $html, 'value="' . (int) $plan->get_id() . '"' ), 'Each checkbox carries its plan id.' );
		}
		$this->assertStringContainsString( 'id="' . ProductPlansPanel::POST_PLAN_IDS . '-' . (int) $monthly->get_id() . '"', $html, 'The checkbox carries an id its name label targets.' );
		$this->assertStringContainsString( 'for="' . ProductPlansPanel::POST_PLAN_IDS . '-' . (int) $monthly->get_id() . '"', $html, 'The plan name is a real label for the checkbox.' );
		$this->assertStringContainsString( 'Monthly', $html );
		$this->assertStringContainsString( 'Yearly', $html );
		$this->assertStringContainsString( 'Weekly', $html );
		$this->assertStringContainsString( 'Every 1 month', $html );
		$this->assertStringContainsString( 'Every 1 year', $html );
		$this->assertStringContainsString( 'Every 1 week', $html );
	}

	/**
	 * All-scope needs no picking: the checkbox column renders hidden (the
	 * `is-scope-all` table class hides it; the cells stay in the markup for
	 * the live scope toggle) and its checkboxes are disabled so they do not
	 * submit.
	 */
	public function test_all_scope_renders_the_plans_table_without_a_visible_checkbox_column(): void {
		$product = $this->simple_product();
		$this->named_plan( 'Monthly' );
		$this->preset_inherit_all( $product );

		$html = $this->render_panel_for( $product );

		$this->assertStringContainsString( 'wc-subscriptions-lite-plans-table is-scope-all', $html, 'The all-scope table carries the column-hiding class.' );
		$this->assertStringContainsString( "disabled='disabled'", $html, 'All-scope checkboxes are disabled and never submit.' );
	}

	public function test_select_scope_renders_an_editable_checkbox_column_with_the_saved_selection(): void {
		$product = $this->simple_product();
		$monthly = $this->named_plan( 'Monthly' );
		$this->named_plan( 'Yearly', 'year' );
		( new ApplicabilityStore() )->set(
			$product->get_id(),
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ (int) $monthly->get_id() ], false )
		);

		$html = $this->render_panel_for( $product );

		$this->assertStringNotContainsString( 'is-scope-all', $html, 'Select-scope shows the checkbox column.' );
		$this->assertStringNotContainsString( "disabled='disabled'", $html, 'Select-scope checkboxes are editable.' );
		$this->assertMatchesRegularExpression(
			'/id="' . preg_quote( ProductPlansPanel::POST_PLAN_IDS . '-' . (int) $monthly->get_id(), '/' ) . "\"[^>]*checked='checked'/s",
			$html,
			'The saved selection renders checked.'
		);
	}

	/**
	 * The checkbox column header carries a select-all control with an
	 * accessible name. It has no name attribute, so it never posts - only the
	 * row checkboxes carry plan ids.
	 */
	public function test_selection_table_header_renders_a_select_all_checkbox_with_an_accessible_name(): void {
		$product = $this->simple_product();
		$monthly = $this->named_plan( 'Monthly' );
		$this->named_plan( 'Yearly', 'year' );
		( new ApplicabilityStore() )->set(
			$product->get_id(),
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ (int) $monthly->get_id() ], false )
		);

		$html = $this->render_panel_for( $product );

		$this->assertSame( 1, substr_count( $html, 'data-wcsl-select-all' ), 'Exactly one select-all control.' );
		$this->assertMatchesRegularExpression(
			'/<input[^>]*aria-label="Select all plans"[^>]*data-wcsl-select-all/s',
			$html,
			'The select-all checkbox carries an accessible name.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<input[^>]*aria-label="Select all plans"[^>]*name=/s',
			$html,
			'The select-all checkbox has no name attribute and never posts.'
		);
	}

	/**
	 * A partial saved selection renders the header unchecked (the script adds
	 * the indeterminate DOM state); a full one renders it checked.
	 */
	public function test_select_all_header_checkbox_renders_the_saved_selection_state(): void {
		$product = $this->simple_product();
		$monthly = $this->named_plan( 'Monthly' );
		$yearly  = $this->named_plan( 'Yearly', 'year' );
		$store   = new ApplicabilityStore();

		$store->set(
			$product->get_id(),
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ (int) $monthly->get_id() ], false )
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<input[^>]*aria-label="Select all plans"[^>]*checked=/s',
			$this->render_panel_for( $product ),
			'A partial selection renders the header unchecked.'
		);

		$store->set(
			$product->get_id(),
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ (int) $monthly->get_id(), (int) $yearly->get_id() ], false )
		);
		$this->assertMatchesRegularExpression(
			'/<input[^>]*aria-label="Select all plans"[^>]*checked=/s',
			$this->render_panel_for( $product ),
			'A full selection renders the header checked.'
		);
	}

	/**
	 * The empty-selection warning renders with the table and is visible only
	 * for a saved select-scope empty selection; role="alert" announces it.
	 */
	public function test_empty_selection_warning_renders_visible_for_a_saved_empty_select_scope(): void {
		$product = $this->simple_product();
		$this->named_plan( 'Monthly' );
		( new ApplicabilityStore() )->set(
			$product->get_id(),
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [], true )
		);

		$html = $this->render_panel_for( $product );

		// esc_html renders the quotes around the mode name as &quot; entities.
		$this->assertStringContainsString( 'Please select at least one plan, or switch to &quot;Use all storewide subscription plans&quot; mode.', $html );
		$this->assertMatchesRegularExpression(
			'/<div[^>]*data-wcsl-empty-warning[^>]*>/s',
			$html
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<div[^>]*data-wcsl-empty-warning[^>]*hidden[^>]*>/s',
			$html,
			'A saved empty selection shows the warning.'
		);
		$this->assertMatchesRegularExpression(
			'/<div[^>]*role="alert"[^>]*data-wcsl-empty-warning/s',
			$html,
			'The warning announces via role="alert".'
		);
	}

	public function test_empty_selection_warning_renders_hidden_when_plans_are_selected_or_scope_is_all(): void {
		$product = $this->simple_product();
		$monthly = $this->named_plan( 'Monthly' );
		$store   = new ApplicabilityStore();

		$store->set(
			$product->get_id(),
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ (int) $monthly->get_id() ], false )
		);
		$this->assertMatchesRegularExpression(
			'/<div[^>]*data-wcsl-empty-warning[^>]*hidden[^>]*>/s',
			$this->render_panel_for( $product ),
			'A non-empty selection hides the warning.'
		);

		$this->preset_inherit_all( $product );
		$this->assertMatchesRegularExpression(
			'/<div[^>]*data-wcsl-empty-warning[^>]*hidden[^>]*>/s',
			$this->render_panel_for( $product ),
			'All-scope hides the warning.'
		);
	}

	/**
	 * The panel copy mirrors the section structure: a help line under the
	 * mode select, a note nested under the all-scope radio, and a one-time
	 * purchases section heading over its checkbox.
	 */
	public function test_panel_renders_the_section_headings_and_help_copy(): void {
		$product = $this->simple_product();
		$this->named_plan( 'Monthly' );
		$this->preset_inherit_all( $product );

		$html = $this->render_panel_for( $product );

		$this->assertStringContainsString( 'This product will use the storewide subscription plans from your subscription settings.', $html );
		$this->assertStringContainsString( 'Subscription plan selection', $html );
		$this->assertStringContainsString( 'New storewide subscription plans will be included automatically.', $html );
		$this->assertStringContainsString( 'One-time purchases', $html );
		$this->assertStringContainsString( 'Customers can buy this product without subscribing', $html );
	}

	/**
	 * Screen readers announce the help text with its control only when the
	 * markup links the two - the mode select to its (JS-swapped) help
	 * paragraph, the all-scope radio to its always-included note.
	 */
	public function test_panel_render_links_help_text_to_its_controls(): void {
		$this->named_plan( 'Monthly' );

		$html = $this->render_panel_for( $this->simple_product() );

		$mode_help_id  = ProductPlansPanel::POST_PURCHASE_MODE . '-help';
		$scope_help_id = ProductPlansPanel::POST_PLANS_SCOPE . '-all-help';

		$this->assertStringContainsString( 'aria-describedby="' . $mode_help_id . '"', $html );
		$this->assertStringContainsString( 'id="' . $mode_help_id . '"', $html );
		$this->assertStringContainsString( 'aria-describedby="' . $scope_help_id . '"', $html );
		$this->assertStringContainsString( 'id="' . $scope_help_id . '"', $html );
	}

	/**
	 * Render the panel for a product through the production wiring.
	 *
	 * @param WC_Product $product Product whose edit screen is rendering.
	 * @return string Panel markup.
	 */
	private function render_panel_for( WC_Product $product ): string {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- seeding the post global the panel reads; WP_UnitTestCase resets it.
		$GLOBALS['post'] = get_post( $product->get_id() );

		ob_start();
		( new ProductPlansPanel() )->render_panel();

		return (string) ob_get_clean();
	}
}
