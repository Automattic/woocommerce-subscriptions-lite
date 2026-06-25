<?php
/**
 * OrderLinks - admin URLs for orders referenced by a subscription.
 *
 * Shared by the detail screen's meta boxes so the originating order and each
 * renewal order link to the same place.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Order admin link helpers.
 */
final class OrderLinks {

	/**
	 * Admin edit URL for an order. Targets the HPOS `wc-orders` screen, which the
	 * post-edit URL also resolves to on HPOS installs.
	 *
	 * @param int $order_id Order id.
	 */
	public static function edit_url( int $order_id ): string {
		return add_query_arg(
			[
				'page'   => 'wc-orders',
				'action' => 'edit',
				'id'     => $order_id,
			],
			admin_url( 'admin.php' )
		);
	}
}
