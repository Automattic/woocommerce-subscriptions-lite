<?php
/**
 * Customer meta box - the subscription's customer.
 *
 * A side-column box linking to the customer's user profile, mirroring the
 * customer block of WooCommerce's order data box.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes;

use WP_User;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * "Customer" meta box.
 */
final class Customer {

	/**
	 * Render the box body.
	 *
	 * @param Contract $contract The contract being viewed.
	 */
	public static function output( Contract $contract ): void {
		$customer_id = $contract->get_customer_id();
		$user        = $customer_id > 0 ? get_userdata( $customer_id ) : false;

		if ( ! $user instanceof WP_User ) {
			echo '<p>' . esc_html__( '(no customer)', 'woocommerce-subscriptions-lite' ) . '</p>';
			return;
		}

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( (string) get_edit_user_link( $customer_id ) ),
			esc_html( $user->display_name )
		);
	}
}
