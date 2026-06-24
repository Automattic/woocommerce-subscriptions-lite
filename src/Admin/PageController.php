<?php
/**
 * PageController - the WooCommerce > Subscriptions admin page.
 *
 * Registers the submenu under WooCommerce, enqueues the page's one stylesheet,
 * and dispatches between the list view and the contract detail view. It also
 * owns the per-user flash-notice plumbing the row-action handlers post their
 * outcomes through, and the URL/action-name constants those handlers and the
 * list table share so the page chrome and the handlers stay aligned.
 *
 * Read/act surface only: every read and write goes through the engine's public
 * {@see \Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions} facade
 * (used by {@see SubscriptionsListTable} and {@see DetailRenderer}); this class
 * touches no engine internals.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page controller for the subscriptions list + detail views.
 *
 * Static, no instance state. Render path:
 *
 *   admin_menu        -> register_menu()
 *   admin_print_styles-<hook> -> enqueue_assets()
 *   render            -> render_page() -> list view | DetailRenderer::render()
 */
final class PageController {

	/**
	 * Capability required to view and act on subscriptions.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Page slug for `?page=...` and the submenu id.
	 */
	const PAGE_SLUG = 'wc-subscriptions-lite';

	/**
	 * `admin-post.php` action for the "Renew now" row action.
	 */
	const ACTION_RENEW_NOW = 'wc_subscriptions_lite_renew_now';

	/**
	 * `admin-post.php` action for the "Cancel" row action.
	 */
	const ACTION_CANCEL = 'wc_subscriptions_lite_cancel_admin';

	/**
	 * Default number of subscriptions shown on the list view.
	 */
	const PER_PAGE = 20;

	/**
	 * Bind the admin page to WordPress. Called once from the bootstrap in an
	 * admin context. Idempotent at the registration level.
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'register_menu' ] );
	}

	/**
	 * Register the submenu under the WooCommerce top-level menu.
	 *
	 * Hooked on `admin_menu` so it runs after WooCommerce has set up its menu
	 * root. Enqueues assets via the page-specific `admin_print_styles-<hook>`
	 * so the stylesheet loads only on this screen.
	 */
	public static function register_menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Subscriptions', 'woocommerce-subscriptions-lite' ),
			__( 'Subscriptions', 'woocommerce-subscriptions-lite' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);

		if ( false !== $hook ) {
			add_action( 'admin_print_styles-' . $hook, [ self::class, 'enqueue_assets' ] );
		}
	}

	/**
	 * Enqueue the admin stylesheet. Fires only on this page's screen.
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_style(
			'wc-subscriptions-lite-admin',
			WC_SUBSCRIPTIONS_LITE_URL . 'src/css/admin.css',
			[],
			WC_SUBSCRIPTIONS_LITE_VERSION
		);
	}

	/**
	 * Build a URL to this page, optionally merging query args.
	 *
	 * @param array<string, scalar> $args Extra `?key=value` pairs.
	 */
	public static function page_url( array $args = [] ): string {
		$args = array_merge( [ 'page' => self::PAGE_SLUG ], $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Build a nonced `admin-post.php` URL for a state-mutating action.
	 *
	 * Centralizes the URL shape so a handler's nonce check stays aligned with
	 * the URL the list table and detail page render.
	 *
	 * @param string $action      One of the `ACTION_*` constants.
	 * @param int    $contract_id Target contract id.
	 */
	public static function action_url( string $action, int $contract_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'      => $action,
					'contract_id' => $contract_id,
				],
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $contract_id
		);
	}

	/**
	 * Top-level page dispatcher. Renders the detail view for
	 * `?action=view&id=N`, the list view otherwise.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view subscriptions.', 'woocommerce-subscriptions-lite' ),
				'',
				[ 'response' => 403 ]
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view routing.
		$view_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';

		if ( 'view' === $view_action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view routing.
			$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
			DetailRenderer::render( $id );
		} else {
			self::render_list_view();
		}

		// The inline confirm script drives the Cancel links on both views.
		self::render_confirm_script();
	}

	/**
	 * Render the list view: heading, flash notice, then the list table.
	 */
	private static function render_list_view(): void {
		$table = new SubscriptionsListTable();
		$table->prepare_items();

		?>
		<div class="wrap wc-subs-lite-admin wc-subs-lite-subscriptions-list">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Subscriptions', 'woocommerce-subscriptions-lite' ); ?>
			</h1>
			<hr class="wp-header-end" />

			<?php self::render_flash_notice(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render any pending flash notice for the current user, then clear it.
	 *
	 * Public so {@see DetailRenderer} can surface notices from its own render
	 * path without duplicating the transient read.
	 */
	public static function render_flash_notice(): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return;
		}

		$notice = get_transient( self::notice_key( $user_id ) );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( self::notice_key( $user_id ) );

		$type  = isset( $notice['type'] ) ? (string) $notice['type'] : 'info';
		$class = 'error' === $type ? 'notice notice-error' : ( 'success' === $type ? 'notice notice-success' : 'notice notice-info' );

		printf(
			'<div class="%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Stash a one-shot notice for the current user, read on the next render.
	 *
	 * @param string $type    `success`, `error`, or `info`.
	 * @param string $message Translated message text.
	 */
	public static function set_flash_notice( string $type, string $message ): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return;
		}
		set_transient(
			self::notice_key( $user_id ),
			[
				'type'    => $type,
				'message' => $message,
			],
			60
		);
	}

	/**
	 * Transient key for a user's pending flash notice.
	 *
	 * @param int $user_id User id.
	 */
	private static function notice_key( int $user_id ): string {
		return 'wc_subscriptions_lite_admin_notice_' . $user_id;
	}

	/**
	 * Inline confirm for destructive Cancel links.
	 *
	 * Cancel is immediate, so the click gets a `window.confirm()` gate carrying
	 * the link's own copy (via `data-confirm`). Kept inline and dependency-free:
	 * a single short script for one page, no enqueue chain. Links without the
	 * attribute (Renew now, View) navigate normally.
	 */
	private static function render_confirm_script(): void {
		?>
		<script>
		( function () {
			document.querySelectorAll( '[data-confirm]' ).forEach( function ( link ) {
				link.addEventListener( 'click', function ( event ) {
					if ( ! window.confirm( link.getAttribute( 'data-confirm' ) ) ) {
						event.preventDefault();
					}
				} );
			} );
		} )();
		</script>
		<?php
	}
}
