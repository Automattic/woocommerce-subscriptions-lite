<?php
/**
 * ProductPlansPanel - the "Subscriptions" tab on the product edit screen.
 *
 * Lets the merchant choose per product whether it sells one-time only (the
 * default) or on the storewide selling plans - all of them, or a selected
 * subset - with one-time purchase optionally allowed alongside. The panel is
 * PHP-rendered in the standard WooCommerce product-data metabox; a small
 * vanilla script (client/admin-php/product-plans-panel.js) only toggles
 * section visibility.
 *
 * All reads and writes go through the engine's public
 * {@see \Automattic\WooCommerce\SubscriptionsEngine\Api\SellingPlans} facade -
 * Lite owns no applicability schema. The facade validates writes (product
 * type, plan ownership) and its rejection surfaces as a product-screen
 * admin error via WC_Admin_Meta_Boxes::add_error().
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

use InvalidArgumentException;
use WC_Admin_Meta_Boxes;
use WC_Product;
use Automattic\WooCommerce\SubscriptionsEngine\Api\SellingPlans;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\ProductPage\PlanOptionFormatter;
use Automattic\WooCommerce\SubscriptionsLite\ProductPage\PlanPicker;

defined( 'ABSPATH' ) || exit;

/**
 * Product-data tab, panel render, and save for per-product plan applicability.
 *
 * Construct via the no-arg constructor in production (facade defaults); tests
 * inject fake reader / writer / lister seams to exercise the save mapping
 * without a database.
 */
final class ProductPlansPanel {

	/**
	 * Capability required to change a product's plan applicability.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Product-data tab id.
	 */
	const TAB_ID = 'wc_subscriptions_lite';

	/**
	 * Panel `<div>` id; the tab's `target` so WooCommerce's product-data JS
	 * shows and hides the right panel.
	 */
	const PANEL_ID = 'wc_subscriptions_lite_product_data';

	/**
	 * Nonce action + field for the panel save.
	 */
	const NONCE_ACTION = 'wc_subscriptions_lite_product_plans';
	const NONCE_FIELD  = '_wcsl_product_plans_nonce';

	/**
	 * POST key for the purchase-mode select. Values: MODE_ONE_TIME | MODE_PLANS.
	 */
	const POST_PURCHASE_MODE = '_wcsl_purchase_mode';

	/**
	 * POST key for the plan-scope radios. Values: SCOPE_ALL | SCOPE_SELECT.
	 */
	const POST_PLANS_SCOPE = '_wcsl_plans_scope';

	/**
	 * POST key for the per-plan checkboxes, submitted as `[]` of plan ids.
	 */
	const POST_PLAN_IDS = '_wcsl_plan_ids';

	/**
	 * POST key for the allow-one-time checkbox.
	 */
	const POST_ALLOW_ONE_TIME = '_wcsl_allow_one_time';

	/**
	 * Lite-internal purchase-mode values. The save handler maps them onto the
	 * engine VO's modes; the engine vocabulary never reaches the form.
	 */
	const MODE_ONE_TIME = 'one_time';
	const MODE_PLANS    = 'plans';

	/**
	 * Plan-scope values within MODE_PLANS.
	 */
	const SCOPE_ALL    = 'all';
	const SCOPE_SELECT = 'select';

	/**
	 * Applicability reader. Production: the facade read.
	 *
	 * @var callable(int): ProductApplicability
	 */
	private $applicability_reader;

	/**
	 * Applicability writer. Production: the facade write scoped to Lite's slug.
	 *
	 * @var callable(int, ProductApplicability): void
	 */
	private $applicability_writer;

	/**
	 * Active-plans lister for the selection table. Production: the facade list.
	 *
	 * @var callable(): array<int, \Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan>
	 */
	private $plans_lister;

	/**
	 * Construct the panel.
	 *
	 * @param (callable(int): ProductApplicability)|null       $applicability_reader Reader; defaults to the facade.
	 * @param (callable(int, ProductApplicability): void)|null $applicability_writer Writer; defaults to the facade.
	 * @param (callable(): array<int, mixed>)|null             $plans_lister         Plans lister; defaults to the facade.
	 */
	public function __construct(
		?callable $applicability_reader = null,
		?callable $applicability_writer = null,
		?callable $plans_lister = null
	) {
		$this->applicability_reader = $applicability_reader ?? static function ( int $product_id ): ProductApplicability {
			return SellingPlans::get_product_applicability( $product_id );
		};
		$this->applicability_writer = $applicability_writer ?? static function ( int $product_id, ProductApplicability $applicability ): void {
			SellingPlans::set_product_applicability( $product_id, $applicability, Package::EXTENSION_SLUG );
		};
		$this->plans_lister         = $plans_lister ?? static function (): array {
			return SellingPlans::list_plans( Package::EXTENSION_SLUG );
		};
	}

	/**
	 * Wire the tab, panel, save, and asset hooks. Called once from the
	 * bootstrap in an admin context.
	 *
	 * The tab filter runs at 65 so the tab lands between Attributes (60) and
	 * Advanced (70); the save runs at 20, after WooCommerce's own product
	 * save callbacks at the default 10.
	 */
	public static function register(): void {
		$instance = new self();
		add_filter( 'woocommerce_product_data_tabs', [ $instance, 'add_tab' ], 65 );
		add_action( 'woocommerce_product_data_panels', [ $instance, 'render_panel' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $instance, 'save' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $instance, 'enqueue_assets' ] );
	}

	/**
	 * Add the Subscriptions tab to the product-data metabox. The `show_if_*`
	 * classes make WooCommerce's product-type JS show the tab for simple and
	 * variable products only - the two types that may carry applicability.
	 *
	 * @param array<string, array<string, mixed>> $tabs Existing tab definitions.
	 * @return array<string, array<string, mixed>> Tabs with the Subscriptions entry.
	 */
	public function add_tab( array $tabs ): array {
		$tabs[ self::TAB_ID ] = [
			'label'    => __( 'Subscriptions', 'woocommerce-subscriptions-lite' ),
			'target'   => self::PANEL_ID,
			'class'    => [ 'show_if_simple', 'show_if_variable' ],
			'priority' => 65,
		];

		return $tabs;
	}

	/**
	 * Render the panel. The server paints the state matching the saved
	 * applicability (plans section hidden in one-time mode, checkboxes
	 * checked + disabled in all-scope); the toggle script only mirrors these
	 * rules on input changes.
	 */
	public function render_panel(): void {
		global $post;

		$product_id    = isset( $post->ID ) ? (int) $post->ID : 0;
		$applicability = ( $this->applicability_reader )( $product_id );
		$plans         = ( $this->plans_lister )();

		$is_plans_mode = ProductApplicability::MODE_DISABLE !== $applicability->get_mode();
		$is_select     = ProductApplicability::MODE_INHERIT_SELECT === $applicability->get_mode();
		$selected_ids  = $applicability->get_plan_ids();
		$current_mode  = $is_plans_mode ? self::MODE_PLANS : self::MODE_ONE_TIME;
		$mode_options  = self::mode_options();

		// Base price for the Discount column; a variable product reports its
		// minimum variation price, matching the PDP picker's seed price.
		$product    = wc_get_product( $product_id );
		$base_price = $product instanceof WC_Product ? (float) $product->get_price() : 0.0;

		?>
		<div id="<?php echo esc_attr( self::PANEL_ID ); ?>" class="panel woocommerce_options_panel hidden">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

			<div class="wc-subscriptions-lite-product-plans" data-wcsl-product-plans-panel>
				<div class="wc-subscriptions-lite-purchase-mode">
					<label for="<?php echo esc_attr( self::POST_PURCHASE_MODE ); ?>">
						<?php esc_html_e( 'Purchase options', 'woocommerce-subscriptions-lite' ); ?>
					</label>
					<select
						id="<?php echo esc_attr( self::POST_PURCHASE_MODE ); ?>"
						name="<?php echo esc_attr( self::POST_PURCHASE_MODE ); ?>"
						aria-describedby="<?php echo esc_attr( self::POST_PURCHASE_MODE . '-help' ); ?>"
						data-wcsl-mode-select
					>
						<?php foreach ( $mode_options as $value => $option ) : ?>
							<option
								value="<?php echo esc_attr( $value ); ?>"
								data-wcsl-help="<?php echo esc_attr( $option['help'] ); ?>"
								<?php selected( $current_mode, $value ); ?>
							>
								<?php echo esc_html( $option['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p
						id="<?php echo esc_attr( self::POST_PURCHASE_MODE . '-help' ); ?>"
						class="wc-subscriptions-lite-purchase-mode-help"
						data-wcsl-mode-help
					>
						<?php echo esc_html( $mode_options[ $current_mode ]['help'] ); ?>
					</p>
				</div>

				<div class="wc-subscriptions-lite-plans-section" data-wcsl-plans-section<?php echo $is_plans_mode ? '' : ' hidden'; ?>>
					<fieldset class="wc-subscriptions-lite-plans-scope">
						<legend><?php esc_html_e( 'Plan selection', 'woocommerce-subscriptions-lite' ); ?></legend>
						<label>
							<input
								type="radio"
								name="<?php echo esc_attr( self::POST_PLANS_SCOPE ); ?>"
								value="<?php echo esc_attr( self::SCOPE_ALL ); ?>"
								aria-describedby="<?php echo esc_attr( self::POST_PLANS_SCOPE . '-all-help' ); ?>"
								<?php checked( ! $is_select ); ?>
								data-wcsl-scope-radio
							/>
							<?php esc_html_e( 'Use all storewide subscription plans', 'woocommerce-subscriptions-lite' ); ?>
						</label>
						<span
							id="<?php echo esc_attr( self::POST_PLANS_SCOPE . '-all-help' ); ?>"
							class="wc-subscriptions-lite-plans-scope-help"
						>
							<?php esc_html_e( 'New storewide plans are included automatically.', 'woocommerce-subscriptions-lite' ); ?>
						</span>
						<label>
							<input
								type="radio"
								name="<?php echo esc_attr( self::POST_PLANS_SCOPE ); ?>"
								value="<?php echo esc_attr( self::SCOPE_SELECT ); ?>"
								<?php checked( $is_select ); ?>
								data-wcsl-scope-radio
							/>
							<?php esc_html_e( 'Select storewide subscription plans', 'woocommerce-subscriptions-lite' ); ?>
						</label>
					</fieldset>

					<?php if ( empty( $plans ) ) : ?>
						<p class="wc-subscriptions-lite-plans-empty">
							<?php esc_html_e( 'No storewide subscription plans yet.', 'woocommerce-subscriptions-lite' ); ?>
						</p>
					<?php else : ?>
						<table class="widefat wc-subscriptions-lite-plans-table" data-wcsl-plans-table>
							<thead>
								<tr>
									<th scope="col" class="wc-subscriptions-lite-plans-table-check">
										<span class="screen-reader-text"><?php esc_html_e( 'Selected', 'woocommerce-subscriptions-lite' ); ?></span>
									</th>
									<th scope="col"><?php esc_html_e( 'Plan', 'woocommerce-subscriptions-lite' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Frequency', 'woocommerce-subscriptions-lite' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Discount', 'woocommerce-subscriptions-lite' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $plans as $plan ) : ?>
									<?php
									$plan_id     = (int) $plan->get_id();
									$checkbox_id = self::POST_PLAN_IDS . '-' . (string) $plan_id;
									?>
									<tr>
										<td class="wc-subscriptions-lite-plans-table-check">
											<input
												type="checkbox"
												id="<?php echo esc_attr( $checkbox_id ); ?>"
												name="<?php echo esc_attr( self::POST_PLAN_IDS ); ?>[]"
												value="<?php echo esc_attr( (string) $plan_id ); ?>"
												<?php checked( ! $is_select || in_array( $plan_id, $selected_ids, true ) ); ?>
												<?php disabled( ! $is_select ); ?>
												data-wcsl-plan-checkbox
											/>
										</td>
										<td class="wc-subscriptions-lite-plans-table-name">
											<label for="<?php echo esc_attr( $checkbox_id ); ?>">
												<?php echo esc_html( $plan->get_name() ); ?>
											</label>
										</td>
										<td><?php echo esc_html( PlanOptionFormatter::format_frequency( $plan ) ); ?></td>
										<td><?php echo wp_kses_post( PlanOptionFormatter::format_discount( $plan, $base_price ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<p class="wc-subscriptions-lite-manage-plans">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=' . SettingsPage::TAB_SLUG ) ); ?>">
							<?php esc_html_e( 'Manage plans', 'woocommerce-subscriptions-lite' ); ?>
						</a>
					</p>

					<p class="wc-subscriptions-lite-one-time">
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( self::POST_ALLOW_ONE_TIME ); ?>"
								value="yes"
								<?php checked( $applicability->allows_one_time() ); ?>
							/>
							<?php esc_html_e( 'Allow one-time purchase', 'woocommerce-subscriptions-lite' ); ?>
						</label>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Map the panel POST onto the engine VO and write through the facade.
	 *
	 * Runs only for saves that rendered the panel (the nonce marks them);
	 * REST / CLI / programmatic saves skip, as do product types that cannot
	 * carry applicability (the panel markup renders for every type, so its
	 * fields post even when the tab is hidden). Mode and scope pass an
	 * allow-list with tampered values falling back to the defaults
	 * (one-time, all). Never throws: core fires
	 * `woocommerce_admin_process_product_object` unwrapped, so an exception
	 * here would fatal the whole product save - a facade rejection reports
	 * through the metabox error list instead.
	 *
	 * @param WC_Product $product The product being saved.
	 */
	public function save( WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by wp_verify_nonce immediately below.
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( ! in_array( $product->get_type(), PlanPicker::SUPPORTED_PRODUCT_TYPES, true ) ) {
			return;
		}

		$mode_raw  = isset( $_POST[ self::POST_PURCHASE_MODE ] ) ? sanitize_key( wp_unslash( $_POST[ self::POST_PURCHASE_MODE ] ) ) : '';
		$scope_raw = isset( $_POST[ self::POST_PLANS_SCOPE ] ) ? sanitize_key( wp_unslash( $_POST[ self::POST_PLANS_SCOPE ] ) ) : '';

		$mode  = in_array( $mode_raw, [ self::MODE_ONE_TIME, self::MODE_PLANS ], true ) ? $mode_raw : self::MODE_ONE_TIME;
		$scope = in_array( $scope_raw, [ self::SCOPE_ALL, self::SCOPE_SELECT ], true ) ? $scope_raw : self::SCOPE_ALL;

		$plan_ids = [];
		if ( isset( $_POST[ self::POST_PLAN_IDS ] ) && is_array( $_POST[ self::POST_PLAN_IDS ] ) ) {
			// Non-numeric entries absint to 0 and are dropped; the facade
			// validates the surviving ids exist and belong to Lite.
			$plan_ids = array_values( array_filter( array_map( 'absint', wp_unslash( $_POST[ self::POST_PLAN_IDS ] ) ) ) );
		}

		$allow_one_time = ! empty( $_POST[ self::POST_ALLOW_ONE_TIME ] );

		if ( self::MODE_ONE_TIME === $mode ) {
			$engine_mode = ProductApplicability::MODE_DISABLE;
			$plan_ids    = [];
		} elseif ( self::SCOPE_ALL === $scope ) {
			$engine_mode = ProductApplicability::MODE_INHERIT_ALL;
			$plan_ids    = [];
		} else {
			$engine_mode = ProductApplicability::MODE_INHERIT_SELECT;
		}

		try {
			( $this->applicability_writer )(
				$product->get_id(),
				new ProductApplicability( $engine_mode, $plan_ids, $allow_one_time )
			);
		} catch ( InvalidArgumentException $e ) {
			// The facade refused the write. Report through the metabox error
			// list so the merchant sees a notice; the rest of the product save
			// proceeds untouched.
			WC_Admin_Meta_Boxes::add_error(
				sprintf(
					/* translators: %s: reason the subscription plan settings were rejected. */
					__( 'Subscription plan settings were not saved: %s', 'woocommerce-subscriptions-lite' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Enqueue the admin-php bundle on the product edit screens only. Same
	 * handles as {@see PageController::enqueue_assets()} - one bundle, gated
	 * per surface; the script self-gates on the panel's DOM marker.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'product' !== $screen->post_type ) {
			return;
		}

		$asset_path = Package::get_path() . '/build/scripts/admin-php.asset.php';
		$asset      = is_readable( $asset_path ) ? (array) include $asset_path : [];
		$version    = isset( $asset['version'] ) ? (string) $asset['version'] : Package::get_version();

		if ( ! wp_script_is( 'wc-subscriptions-lite-admin-php', 'enqueued' ) ) {
			wp_enqueue_script(
				'wc-subscriptions-lite-admin-php',
				Package::get_url() . '/build/scripts/admin-php.js',
				isset( $asset['dependencies'] ) ? (array) $asset['dependencies'] : [],
				$version,
				true
			);
		}

		if ( ! wp_style_is( 'wc-subscriptions-lite-admin-php', 'enqueued' ) ) {
			wp_enqueue_style(
				'wc-subscriptions-lite-admin-php',
				Package::get_url() . '/build/scripts/style-admin-php.css',
				[ 'woocommerce_admin_styles' ],
				$version
			);
			wp_style_add_data( 'wc-subscriptions-lite-admin-php', 'rtl', 'replace' );
		}
	}

	/**
	 * Purchase-mode options: value => label + help. One table so the render
	 * and the save allow-list cannot drift.
	 *
	 * @return array<string, array{label: string, help: string}>
	 */
	private static function mode_options(): array {
		return [
			self::MODE_ONE_TIME => [
				'label' => __( 'Sell one-time only', 'woocommerce-subscriptions-lite' ),
				'help'  => __( 'This product will only be available as a one-time purchase.', 'woocommerce-subscriptions-lite' ),
			],
			self::MODE_PLANS    => [
				'label' => __( 'Use storewide subscription plans', 'woocommerce-subscriptions-lite' ),
				'help'  => __( 'This product will be offered on your storewide subscription plans.', 'woocommerce-subscriptions-lite' ),
			],
		];
	}
}
