<?php
/**
 * Customer meta box - the subscription's customer.
 *
 * A side-column box showing the customer's name (linked to their user profile
 * when the current user can edit it) and account email, mirroring the customer
 * block of WooCommerce's order data box.
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

		$name = self::display_name( $user );
		// get_edit_user_link() is empty when the current user cannot edit this customer;
		// fall back to the plain name rather than a dead `<a href="">`.
		$edit = (string) get_edit_user_link( $customer_id );

		echo '<p class="wc-subs-lite-customer-name">';
		if ( '' !== $edit ) {
			printf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html( $name ) );
		} else {
			echo esc_html( $name );
		}
		echo '</p>';

		$email = trim( (string) $user->user_email );
		if ( '' !== $email ) {
			printf(
				'<p class="wc-subs-lite-customer-email"><a href="%s">%s</a></p>',
				esc_url( 'mailto:' . $email ),
				esc_html( $email )
			);
		}
	}

	/**
	 * The customer's full name when set, otherwise their display name - so the box
	 * reads a person, not a login slug, whenever the account carries a name.
	 *
	 * @param WP_User $user The customer.
	 */
	private static function display_name( WP_User $user ): string {
		$full = trim( $user->first_name . ' ' . $user->last_name );
		return '' !== $full ? $full : $user->display_name;
	}
}
