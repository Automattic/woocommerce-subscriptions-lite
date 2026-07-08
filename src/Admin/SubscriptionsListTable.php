<?php
/**
 * SubscriptionsListTable - the WooCommerce > Subscriptions admin list.
 *
 * An Orders-like `WP_List_Table` reading subscriptions through the engine's
 * public {@see \Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions}
 * facade. Six columns (ID, Status, Customer, Next payment, Total, Actions),
 * status views with counts, sortable columns, and a search box - the merchant
 * inbox modelled on the WooCommerce orders list. Paging, filtering, ordering and
 * search are all resolved by the facade's `list()`/`count()`/`count_by_status()`.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

use Throwable;
use WP_List_Table;
use WP_User;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Admin subscriptions list table.
 */
final class SubscriptionsListTable extends WP_List_Table {

	/**
	 * Whether the facade read failed while preparing items.
	 *
	 * @var bool
	 */
	private $load_error = false;

	/**
	 * Construct the table.
	 */
	public function __construct() {
		parent::__construct(
			[
				'singular' => 'subscription',
				'plural'   => 'subscriptions',
				'ajax'     => false,
			]
		);
	}

	/**
	 * Column slug => header label.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'id'           => __( 'ID', 'woocommerce-subscriptions-lite' ),
			'status'       => __( 'Status', 'woocommerce-subscriptions-lite' ),
			'customer'     => __( 'Customer', 'woocommerce-subscriptions-lite' ),
			'next_payment' => __( 'Next payment', 'woocommerce-subscriptions-lite' ),
			'total'        => __( 'Total', 'woocommerce-subscriptions-lite' ),
			'actions'      => __( 'Actions', 'woocommerce-subscriptions-lite' ),
		];
	}

	/**
	 * Make ID the primary column (the responsive "show details" anchor).
	 */
	protected function get_default_primary_column_name(): string {
		return 'id';
	}

	/**
	 * Fetch the current page of subscriptions via the facade.
	 *
	 * Reads the active status view, search term and sort from the request and
	 * resolves them through the facade: `list()` for the page, `count()` for the
	 * matching total (real pagination). An engine read failure degrades to an
	 * empty list plus a notice rather than a fatal.
	 */
	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns(), $this->get_default_primary_column_name() ];

		$per_page = PageController::PER_PAGE;
		$page     = max( 1, (int) $this->get_pagenum() );
		$offset   = ( $page - 1 ) * $per_page;

		$status = $this->current_status();
		$search = $this->current_search();
		$sort   = $this->current_sort();

		try {
			$rows  = Subscriptions::list(
				[
					'limit'   => $per_page,
					'offset'  => $offset,
					'status'  => $status,
					'search'  => $search,
					'orderby' => $sort['orderby'],
					'order'   => $sort['order'],
				]
			);
			$total = Subscriptions::count(
				[
					'status' => $status,
					'search' => $search,
				]
			);
		} catch ( Throwable $e ) {
			$this->load_error = true;
			wc_get_logger()->error(
				'Admin subscriptions list could not be loaded: ' . $e->getMessage(),
				[
					'source'    => 'woocommerce-subscriptions-lite',
					'exception' => $e,
				]
			);
			$rows  = [];
			$total = 0;
		}

		$this->items = $rows;

		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			]
		);
	}

	/**
	 * Status views (All + one per contract status) with counts, modelled on the
	 * orders list. Counts come from the facade's `count_by_status()` in a single
	 * read and are global (independent of the active search), so a view link
	 * resets the search and paging and filters by status alone.
	 *
	 * @return array<string, string> View slug => link markup.
	 */
	protected function get_views(): array {
		try {
			$counts = Subscriptions::count_by_status();
		} catch ( Throwable $e ) {
			return [];
		}

		$current = $this->current_status();
		$views   = [
			'all' => $this->view_link( '', __( 'All', 'woocommerce-subscriptions-lite' ), (int) array_sum( $counts ), '' === $current ),
		];

		foreach ( ContractStatus::all() as $status ) {
			$views[ $status ] = $this->view_link(
				$status,
				StatusLabels::contract_label( $status ),
				isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0,
				$status === $current
			);
		}

		return $views;
	}

	/**
	 * Sortable columns mapped to the facade's `orderby` keys. ID sorts descending
	 * first (newest); the date and amount columns ascending first.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return [
			'id'           => [ 'id', true ],
			'next_payment' => [ 'next_payment', false ],
			'total'        => [ 'total', false ],
		];
	}

	/**
	 * The active status view from the request, or '' for All. An unknown status
	 * falls back to All rather than an empty list. Public so the page chrome can
	 * carry the current view through the search form.
	 */
	public function current_status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';

		return ContractStatus::is_valid( $status ) ? $status : '';
	}

	/**
	 * The active search term from the request (trimmed), or ''.
	 */
	private function current_search(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list search.
		return isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) ) : '';
	}

	/**
	 * The active sort from the request: a whitelisted `orderby` key (empty falls
	 * back to the engine default id) and an `order` direction (default DESC). The
	 * whitelist is the sortable-column map, so an unknown key never reaches SQL.
	 *
	 * @return array{orderby: string, order: string}
	 */
	private function current_sort(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list sort.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : '';
		if ( ! array_key_exists( $orderby, $this->get_sortable_columns() ) ) {
			$orderby = '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list sort.
		$order_raw = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_GET['order'] ) ) ) : '';

		return [
			'orderby' => $orderby,
			'order'   => 'ASC' === $order_raw ? 'ASC' : 'DESC',
		];
	}

	/**
	 * Build a single status-view link.
	 *
	 * @param string $status  Status slug, or '' for All.
	 * @param string $label   Human label.
	 * @param int    $count   Count badge.
	 * @param bool   $current Whether this is the active view.
	 */
	private function view_link( string $status, string $label, int $count, bool $current ): string {
		$url = PageController::page_url( '' === $status ? [] : [ 'status' => $status ] );

		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Whether the facade read failed during {@see self::prepare_items()}. The
	 * page chrome reads this to render an error notice above the table.
	 */
	public function has_load_error(): bool {
		return $this->load_error;
	}

	/**
	 * Empty state - neutral when the list is genuinely empty, an error hint when
	 * the engine read failed.
	 */
	public function no_items(): void {
		if ( $this->load_error ) {
			esc_html_e( 'Subscriptions could not be loaded. Check the WooCommerce logs for details.', 'woocommerce-subscriptions-lite' );
			return;
		}
		esc_html_e( 'No subscriptions found.', 'woocommerce-subscriptions-lite' );
	}

	/**
	 * ID cell: linked subscription number.
	 *
	 * @param Contract $item Current row.
	 */
	public function column_id( $item ): string {
		$id = (int) $item->get_id();
		return sprintf(
			'<strong><a href="%s">#%d</a></strong>',
			esc_url(
				PageController::page_url(
					[
						'action' => 'view',
						'id'     => $id,
					]
				)
			),
			$id
		);
	}

	/**
	 * Status cell: badge.
	 *
	 * @param Contract $item Current row.
	 */
	public function column_status( $item ): string {
		return StatusLabels::contract_badge_html( $item->get_status() );
	}

	/**
	 * Customer cell: display name linked to the user edit screen, or a neutral
	 * placeholder when the customer cannot be resolved.
	 *
	 * @param Contract $item Current row.
	 */
	public function column_customer( $item ): string {
		$customer_id = $item->get_customer_id();
		$user        = $customer_id > 0 ? get_userdata( $customer_id ) : false;

		if ( ! $user instanceof WP_User ) {
			return esc_html__( '(no customer)', 'woocommerce-subscriptions-lite' );
		}

		// get_edit_user_link() is empty when the current user cannot edit this customer;
		// fall back to the plain name rather than a dead `<a href="">`.
		$edit_link = (string) get_edit_user_link( $customer_id );
		if ( '' === $edit_link ) {
			return esc_html( $user->display_name );
		}

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $edit_link ),
			esc_html( $user->display_name )
		);
	}

	/**
	 * Next-payment cell: localized date, or a dash when none is scheduled.
	 *
	 * @param Contract $item Current row.
	 */
	public function column_next_payment( $item ): string {
		return esc_html( Formatting::date( $item->get_next_payment_gmt() ) );
	}

	/**
	 * Total cell: the recurring billing total in the contract currency.
	 *
	 * @param Contract $item Current row.
	 */
	public function column_total( $item ): string {
		return Formatting::price( $item->get_billing_total(), $item->get_currency() );
	}

	/**
	 * Actions cell: View, then Renew now / Cancel where the status allows.
	 *
	 * View is a plain GET link; the state-mutating actions are POST forms (see
	 * {@see PageController::action_form()}) so no nonce rides in the URL.
	 *
	 * @param Contract $item Current row.
	 */
	public function column_actions( $item ): string {
		$id     = (int) $item->get_id();
		$status = $item->get_status();
		$parts  = [];

		$parts[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				PageController::page_url(
					[
						'action' => 'view',
						'id'     => $id,
					]
				)
			),
			esc_html__( 'View', 'woocommerce-subscriptions-lite' )
		);

		if ( StatusLabels::is_renewable( $status ) ) {
			$parts[] = PageController::action_form(
				PageController::ACTION_RENEW_NOW,
				$id,
				__( 'Renew now', 'woocommerce-subscriptions-lite' )
			);
		}

		if ( StatusLabels::is_cancellable( $status ) ) {
			$parts[] = PageController::action_form(
				PageController::ACTION_CANCEL,
				$id,
				__( 'Cancel', 'woocommerce-subscriptions-lite' ),
				'button-link wc-subs-lite-cancel-link',
				__( 'Cancel this subscription immediately? This cannot be undone.', 'woocommerce-subscriptions-lite' )
			);
		}

		return '<span class="wc-subs-lite-row-actions">' . implode( ' ', $parts ) . '</span>';
	}

	/**
	 * Fallback for any column without a dedicated renderer. Returns empty rather
	 * than the parent's debug dump so a stray column shows blank.
	 *
	 * @param Contract $item        Current row.
	 * @param string   $column_name Column slug.
	 */
	public function column_default( $item, $column_name ): string {
		return '';
	}
}
