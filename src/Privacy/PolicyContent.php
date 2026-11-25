<?php
/**
 * PolicyContent - suggested privacy policy text for the store's privacy policy guide.
 *
 * Says what Lite stores on a subscription and why, that the payment gateway may receive personal
 * data, and, for the merchant, how an erasure request interacts with saved payment methods and
 * that deleting the plugin keeps subscription data.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Privacy
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy policy guide content.
 */
final class PolicyContent {

	/**
	 * Register WordPress hooks.
	 */
	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'add' ] );
	}

	/**
	 * Add the suggested text to WordPress's privacy policy guide.
	 */
	public static function add(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content( __( 'WooCommerce Subscriptions Lite', 'woocommerce-subscriptions-lite' ), self::get_content() );
	}

	/**
	 * The suggested policy text. Paragraphs marked `privacy-policy-tutorial` are guidance for
	 * the merchant, which WordPress leaves out when the text is copied.
	 */
	public static function get_content(): string {
		return '<p>' . esc_html__( 'We store subscription data to renew and manage your subscriptions. Depending on the payment provider that takes subscription payments, personal data may also be shared with that provider.', 'woocommerce-subscriptions-lite' ) . '</p>'
			. '<h3>' . esc_html__( 'What we collect and store', 'woocommerce-subscriptions-lite' ) . '</h3>'
			. '<p>' . esc_html__( 'For each subscription we store your name, billing and shipping addresses, email address and phone number, a reference to the saved payment method used for renewals, and the subscription\'s history. We use this data to renew the subscription, take its payments and let you manage it from your account.', 'woocommerce-subscriptions-lite' ) . '</p>'
			. '<h3>' . esc_html__( 'What we share with others', 'woocommerce-subscriptions-lite' ) . '</h3>'
			. '<p class="privacy-policy-tutorial">' . esc_html__( 'What personal data is shared with external services depends on the payment provider plugins that collect subscription payments. Consult their privacy policies to complete this section.', 'woocommerce-subscriptions-lite' ) . '</p>'
			. '<p class="privacy-policy-tutorial">' . esc_html__( 'When WordPress\'s Erase Personal Data tool handles a request, WooCommerce also removes the customer\'s saved payment methods. A subscription that keeps its data after the erasure can then no longer renew automatically. Turn on "Remove personal data from subscriptions" under WooCommerce > Settings > Accounts and Privacy to cancel and anonymise subscriptions instead.', 'woocommerce-subscriptions-lite' ) . '</p>'
			. '<p class="privacy-policy-tutorial">' . esc_html__( 'Deleting WooCommerce Subscriptions Lite keeps subscription data in the database, and the export and erasure tools no longer include it. Reinstall the plugin to export or erase that data.', 'woocommerce-subscriptions-lite' ) . '</p>';
	}
}
