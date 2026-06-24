<?php
/**
 * SubscriptionsListTable - the WooCommerce > Subscriptions admin list.
 *
 * A trimmed `WP_List_Table` showing the most recent subscriptions read through
 * the engine's public {@see \Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions}
 * facade. Six columns (ID, Status, Customer, Next payment, Total, Actions), no
 * filters, no sorting, no bulk actions - the basic merchant inbox. Paging is a
 * single forward/back window over the facade's `list()`.
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
	 * One forward/back window: `list( PER_PAGE + 1 )` peeks one row past the
	 * page to decide whether a "next" link is warranted, without a count query
	 * the facade does not expose. The peeked row is trimmed before display.
	 */
	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], [] ];

		$per_page = PageController::PER_PAGE;
		$page     = max( 1, (int) $this->get_pagenum() );
		$offset   = ( $page - 1 ) * $per_page;

		// Peek one extra row to know whether a further page exists. An engine
		// failure degrades to an empty list plus a notice rather than a fatal.
		try {
			$rows = Subscriptions::list( $per_page + 1, $offset );
		} catch ( Throwable $e ) {
			$this->load_error = true;
			wc_get_logger()->error(
				'Admin subscriptions list could not be loaded: ' . $e->getMessage(),
				[
					'source'    => 'woocommerce-subscriptions-lite',
					'exception' => $e,
				]
			);
			$rows = [];
		}

		$has_next    = count( $rows ) > $per_page;
		$this->items = array_slice( $rows, 0, $per_page );

		// `total_items` is unknown without a count query; report a lower bound so
		// the pager renders a Next link while a full page (plus the peek) came back.
		$total_items = $offset + count( $this->items ) + ( $has_next ? 1 : 0 );

		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => $has_next ? $page + 1 : $page,
			]
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

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( (string) get_edit_user_link( $customer_id ) ),
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
