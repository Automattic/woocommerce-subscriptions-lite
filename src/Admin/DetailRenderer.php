<?php
/**
 * DetailRenderer - the admin subscription detail screen.
 *
 * A WordPress meta-box screen for `?page=...&action=view&id=N`, modelled on
 * WooCommerce's order edit screen: each section (details, items, addresses,
 * billing history in the main column; actions, schedule, customer on the side) is
 * a postbox registered on this page's screen id and rendered with
 * `do_meta_boxes()`, so the screen inherits wp-admin's collapsible two-column
 * layout and exposes an `add_meta_boxes_<screen>` extension point. The boxes are a
 * fixed order (drag-reorder is disabled), collapse only. Read + actions only - not
 * an editable save form. All data comes through the engine's public
 * {@see \Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions} facade.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

use Throwable;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\Actions;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\Addresses;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\BillingHistory;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\Customer;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\Items;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\Schedule;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\SubscriptionData;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Meta-box screen controller for the subscription detail page.
 */
final class DetailRenderer {

	/**
	 * Per-request cache of fetched contracts, so the load-time setup and the
	 * render pass share a single facade read.
	 *
	 * @var array<int, Contract|null>
	 */
	private static $cache = [];

	/**
	 * Set up the screen at page-load time: register the section meta boxes on
	 * this screen, add the columns screen option, and enqueue the postbox script.
	 *
	 * Called from {@see PageController} on `load-<hook>` for the detail view only,
	 * before the screen is rendered, as meta boxes and screen options require.
	 *
	 * @param int $contract_id Contract id from the request (already absint'd).
	 */
	public static function setup( int $contract_id ): void {
		$screen = get_current_screen();
		if ( null === $screen ) {
			return;
		}
		$screen_id = $screen->id;

		add_screen_option(
			'layout_columns',
			[
				'max'     => 2,
				'default' => 2,
			]
		);

		// Main column, all normal/high in registration order, so it reads
		// details -> items -> addresses -> billing history like the order edit screen.
		add_meta_box(
			'wc-subs-lite-data',
			__( 'Subscription details', 'woocommerce-subscriptions-lite' ),
			[ SubscriptionData::class, 'output' ],
			$screen_id,
			'normal',
			'high'
		);
		add_meta_box(
			'wc-subs-lite-items',
			__( 'Items', 'woocommerce-subscriptions-lite' ),
			[ Items::class, 'output' ],
			$screen_id,
			'normal',
			'high'
		);
		add_meta_box(
			'wc-subs-lite-addresses',
			__( 'Addresses', 'woocommerce-subscriptions-lite' ),
			[ Addresses::class, 'output' ],
			$screen_id,
			'normal',
			'high'
		);
		add_meta_box(
			'wc-subs-lite-history',
			__( 'Billing history', 'woocommerce-subscriptions-lite' ),
			[ BillingHistory::class, 'output' ],
			$screen_id,
			'normal',
			'default'
		);

		// Side column: actions on top (always registered - it carries the back-to-list
		// link and the status-gated Renew now / Cancel controls), then the schedule and
		// the customer.
		add_meta_box(
			'wc-subs-lite-actions',
			__( 'Actions', 'woocommerce-subscriptions-lite' ),
			[ Actions::class, 'output' ],
			$screen_id,
			'side',
			'high'
		);
		add_meta_box(
			'wc-subs-lite-schedule',
			__( 'Schedule', 'woocommerce-subscriptions-lite' ),
			[ Schedule::class, 'output' ],
			$screen_id,
			'side',
			'default'
		);
		add_meta_box(
			'wc-subs-lite-customer',
			__( 'Customer', 'woocommerce-subscriptions-lite' ),
			[ Customer::class, 'output' ],
			$screen_id,
			'side',
			'default'
		);

		// Keep the toggles (collapse) but lock the layout: the boxes are a fixed
		// order, not a draggable dashboard, so disable jQuery UI sortable on the
		// holders once postbox has initialised it.
		wp_enqueue_script( 'postbox' );
		wp_add_inline_script(
			'postbox',
			'jQuery(function(){postboxes.add_postbox_toggles(pagenow);jQuery(".meta-box-sortables.ui-sortable").sortable("disable");});'
		);

		/**
		 * Fires after Lite registers its detail-screen meta boxes, so extensions
		 * can add their own boxes to this screen. Mirrors WordPress core's
		 * per-screen `add_meta_boxes_<screen>` hook.
		 *
		 * @param Contract|null $contract The contract being viewed, or null when it could not be loaded.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mirrors WordPress core's per-screen add_meta_boxes_<screen> hook so extensions register boxes the standard way.
		do_action( 'add_meta_boxes_' . $screen_id, self::fetch( $contract_id ) );
	}

	/**
	 * Render the detail screen for a contract id.
	 *
	 * Capability is enforced upstream by {@see PageController::render_page()}.
	 *
	 * @param int $contract_id Contract id from the request (already absint'd).
	 */
	public static function render( int $contract_id ): void {
		$contract = self::fetch( $contract_id );
		if ( null === $contract ) {
			self::render_not_found( $contract_id );
			return;
		}

		$screen    = get_current_screen();
		$screen_id = null !== $screen ? $screen->id : '';
		$columns   = ( null !== $screen && 1 === $screen->get_columns() ) ? 1 : 2;
		?>
		<div class="wrap wc-subs-lite-admin wc-subs-lite-subscription-detail">
			<h1 class="wp-heading-inline">
				<?php
				/* translators: %d: subscription number. */
				printf( esc_html__( 'Subscription #%d', 'woocommerce-subscriptions-lite' ), (int) $contract->get_id() );
				?>
			</h1>
			<hr class="wp-header-end" />

			<?php PageController::render_flash_notice(); ?>

			<?php
			wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false );
			wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false );
			?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-<?php echo (int) $columns; ?>">
					<div id="postbox-container-1" class="postbox-container">
						<?php do_meta_boxes( $screen_id, 'side', $contract ); ?>
					</div>
					<div id="postbox-container-2" class="postbox-container">
						<?php
						do_meta_boxes( $screen_id, 'normal', $contract );
						do_meta_boxes( $screen_id, 'advanced', $contract );
						?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Not-found state - keeps the page chrome so the merchant can navigate back.
	 *
	 * @param int $contract_id The requested contract id.
	 */
	private static function render_not_found( int $contract_id ): void {
		?>
		<div class="wrap wc-subs-lite-admin wc-subs-lite-subscription-detail">
			<h1 class="wp-heading-inline">
				<?php
				/* translators: %d: subscription number. */
				printf( esc_html__( 'Subscription #%d not found.', 'woocommerce-subscriptions-lite' ), (int) $contract_id );
				?>
			</h1>
			<a href="<?php echo esc_url( PageController::page_url() ); ?>" class="page-title-action">
				<?php esc_html_e( 'Back to subscriptions', 'woocommerce-subscriptions-lite' ); ?>
			</a>
			<hr class="wp-header-end" />
		</div>
		<?php
	}

	/**
	 * Fetch a contract through the facade once per request, degrading a read
	 * failure to null (logged) rather than a fatal.
	 *
	 * @param int $contract_id Contract id.
	 */
	private static function fetch( int $contract_id ): ?Contract {
		if ( $contract_id <= 0 ) {
			return null;
		}
		if ( array_key_exists( $contract_id, self::$cache ) ) {
			return self::$cache[ $contract_id ];
		}

		try {
			$contract = Subscriptions::get( $contract_id );
		} catch ( Throwable $e ) {
			wc_get_logger()->error(
				'Admin subscription detail could not be loaded for #' . $contract_id . ': ' . $e->getMessage(),
				[
					'source'      => 'woocommerce-subscriptions-lite',
					'contract_id' => $contract_id,
					'exception'   => $e,
				]
			);
			$contract = null;
		}

		self::$cache[ $contract_id ] = $contract;
		return $contract;
	}
}
