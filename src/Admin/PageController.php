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

use Automattic\WooCommerce\SubscriptionsLite\Package;

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
	const ACTION_RENEW_NOW = 'woocommerce_subscriptions_lite_renew_now';

	/**
	 * `admin-post.php` action for the "Cancel" row action.
	 */
	const ACTION_CANCEL = 'woocommerce_subscriptions_lite_cancel_admin';

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
			add_action( 'load-' . $hook, [ self::class, 'on_load' ] );
		}
	}

	/**
	 * Page-load setup. For the detail view, hands off to {@see DetailRenderer}
	 * to register meta boxes, the columns screen option, and the postbox script
	 * before the screen renders. The list view needs no load-time setup.
	 */
	public static function on_load(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view routing.
		$view_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';
		if ( 'view' !== $view_action ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view routing.
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		DetailRenderer::setup( $id );
	}

	/**
	 * Enqueue the admin stylesheet. Fires only on this page's screen.
	 *
	 * Loads the compiled stylesheet from `build/` (authored as SCSS, built by
	 * wp-scripts) and versions it from the generated asset manifest. The
	 * `woocommerce_admin_styles` dependency pulls in WooCommerce's order-status
	 * badge chrome - WooCommerce registers that handle on every admin page but
	 * only enqueues it on its own screens, so naming it here loads it on this
	 * screen too, with our overrides layered on top.
	 */
	public static function enqueue_assets(): void {
		$asset_path = Package::get_path() . '/build/scripts/admin-php.asset.php';
		$asset      = is_readable( $asset_path ) ? (array) include $asset_path : [];
		$version    = isset( $asset['version'] ) ? (string) $asset['version'] : Package::get_version();

		// @wordpress/scripts extracts the SCSS imported from the admin entry to
		// `style-admin-php.css` (the `style-` prefix is its convention for an entry's
		// stylesheet); the matching `style-admin-php-rtl.css` is picked up via the
		// `rtl` style data below.
		wp_enqueue_style(
			'wc-subscriptions-lite-admin-php',
			Package::get_url() . '/build/scripts/style-admin-php.css',
			[ 'woocommerce_admin_styles' ],
			$version
		);
		wp_style_add_data( 'wc-subscriptions-lite-admin-php', 'rtl', 'replace' );
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
	 * Render a self-contained `admin-post.php` POST form for a state-mutating
	 * action, returning its HTML.
	 *
	 * State changes go through POST (not nonced GET links) so the nonce is not
	 * exposed in the URL and the action is not triggerable by a prefetch or a
	 * cross-site `<img>`. The form carries the action name, the target contract,
	 * and a nonce field whose action matches the handler's
	 * `check_admin_referer( $action . '_' . $contract_id )` check. The submit is
	 * a `button-link` so it can read as an inline link in the list or as a
	 * `.button` on the detail page via `$button_class`. A non-empty `$confirm`
	 * is attached as `data-confirm` on the form for the inline confirm script.
	 *
	 * @param string $action       One of the `ACTION_*` constants.
	 * @param int    $contract_id  Target contract id.
	 * @param string $label        Submit button label (already translated).
	 * @param string $button_class CSS classes for the submit button.
	 * @param string $confirm      Optional confirm prompt; empty for no confirm.
	 * @return string Form markup safe to echo.
	 */
	public static function action_form( string $action, int $contract_id, string $label, string $button_class = 'button-link', string $confirm = '' ): string {
		$confirm_attr = '' !== $confirm
			? sprintf( ' data-confirm="%s"', esc_attr( $confirm ) )
			: '';

		return sprintf(
			'<form class="wc-subs-lite-action-form" method="post" action="%1$s"%2$s>%3$s<input type="hidden" name="action" value="%4$s" /><input type="hidden" name="contract_id" value="%5$d" /><button type="submit" class="%6$s">%7$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$confirm_attr,
			wp_nonce_field( $action . '_' . $contract_id, '_wpnonce', true, false ),
			esc_attr( $action ),
			$contract_id,
			esc_attr( $button_class ),
			esc_html( $label )
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

			<?php if ( $table->has_load_error() ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Subscriptions could not be loaded. Check the WooCommerce logs for details.', 'woocommerce-subscriptions-lite' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $table->views(); ?>

			<?php
			// The search box is its own GET form ABOVE the table, carrying the page
			// slug and the active status view. The table is left unwrapped so the
			// per-row Renew now / Cancel POST forms are never nested inside a GET form
			// (invalid HTML). Sort, pagination and view links are query-arg links, so
			// they carry the search/status state without a wrapping form.
			$status = $table->current_status();
			?>
			<form method="get" class="wc-subs-lite-search-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php if ( '' !== $status ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
				<?php endif; ?>
				<?php $table->search_box( __( 'Search subscriptions', 'woocommerce-subscriptions-lite' ), 'subscription' ); ?>
			</form>

			<?php $table->display(); ?>
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
		return 'woocommerce_subscriptions_lite_admin_notice_' . $user_id;
	}

	/**
	 * Inline confirm for destructive action forms.
	 *
	 * Cancel is immediate, so its POST form gets a `window.confirm()` gate
	 * carrying the form's own copy (via `data-confirm`) on submit. Kept inline
	 * and dependency-free: a single short script for one page, no enqueue chain.
	 * Forms without the attribute (Renew now) and the plain View link submit or
	 * navigate normally.
	 */
	private static function render_confirm_script(): void {
		?>
		<script>
		( function () {
			document.querySelectorAll( 'form[data-confirm]' ).forEach( function ( form ) {
				form.addEventListener( 'submit', function ( event ) {
					if ( ! window.confirm( form.getAttribute( 'data-confirm' ) ) ) {
						event.preventDefault();
					}
				} );
			} );
		} )();
		</script>
		<?php
	}
}
