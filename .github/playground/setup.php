<?php
/**
 * Sample data for the WordPress Playground preview of a pull request build.
 *
 * The "Test the build" workflow uploads this file next to the plugin zip, and the
 * Blueprint behind the preview links runs it once, after WooCommerce and the plugin
 * are active. It is not part of the plugin and does not ship.
 *
 * It creates two plans, a product that sells on them and one subscription, so a
 * reviewer lands on screens that have something to show.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 */

declare( strict_types=1 );

use Automattic\WooCommerce\SubscriptionsEngine\Api\Plans;
use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\PriceCalculator;

defined( 'ABSPATH' ) || exit;

try {
	// Plans, written through the engine's public facade as the plans manager writes them.
	$woocommerce_subscriptions_lite_monthly = Plans::create(
		[
			'extension_slug' => Package::EXTENSION_SLUG,
			'name'           => 'Monthly',
			'billing_policy' => [
				'period'     => 'month',
				'interval'   => 1,
				'max_cycles' => null,
			],
			'pricing_policy' => [
				'policies' => [
					[
						'type'  => 'percentage',
						'value' => 10,
					],
				],
			],
		]
	);

	Plans::create(
		[
			'extension_slug' => Package::EXTENSION_SLUG,
			'name'           => 'Every 3 months',
			'billing_policy' => [
				'period'     => 'month',
				'interval'   => 3,
				'max_cycles' => null,
			],
		]
	);

	// A product that sells on every plan and can still be bought once.
	$woocommerce_subscriptions_lite_product = new WC_Product_Simple();
	$woocommerce_subscriptions_lite_product->set_name( 'Coffee beans' );
	$woocommerce_subscriptions_lite_product->set_slug( 'coffee-beans' );
	$woocommerce_subscriptions_lite_product->set_regular_price( '20' );
	$woocommerce_subscriptions_lite_product->set_short_description( 'Sample product for the pull request preview. Choose a plan, add it to the cart and pay with "Cash on delivery".' );
	$woocommerce_subscriptions_lite_product->save();

	( new ApplicabilityStore() )->set(
		$woocommerce_subscriptions_lite_product->get_id(),
		new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL )
	);

	// The reviewer is logged in as the admin (user 1). A saved billing address means
	// checkout has nothing left to fill in.
	$woocommerce_subscriptions_lite_address = [
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'address_1'  => '60 29th Street',
		'city'       => 'San Francisco',
		'state'      => 'CA',
		'postcode'   => '94110',
		'country'    => 'US',
		'email'      => 'admin@example.com',
		'phone'      => '555-0100',
	];

	$woocommerce_subscriptions_lite_customer = new WC_Customer( 1 );
	foreach ( $woocommerce_subscriptions_lite_address as $woocommerce_subscriptions_lite_field => $woocommerce_subscriptions_lite_value ) {
		$woocommerce_subscriptions_lite_customer->{"set_billing_$woocommerce_subscriptions_lite_field"}( $woocommerce_subscriptions_lite_value );
	}
	$woocommerce_subscriptions_lite_customer->save();

	// One subscription for the logged-in admin, created the way checkout creates it: an
	// order whose line carries the chosen plan becomes a subscription when it is paid.
	// The line is priced as the cart prices it: the product's price under the plan.
	$woocommerce_subscriptions_lite_price = PriceCalculator::for_plan( $woocommerce_subscriptions_lite_monthly )
		->unit_price( (float) $woocommerce_subscriptions_lite_product->get_price() );

	$woocommerce_subscriptions_lite_order = wc_create_order( [ 'customer_id' => 1 ] );
	$woocommerce_subscriptions_lite_order->add_product(
		$woocommerce_subscriptions_lite_product,
		1,
		[
			'subtotal' => $woocommerce_subscriptions_lite_price,
			'total'    => $woocommerce_subscriptions_lite_price,
		]
	);
	foreach ( $woocommerce_subscriptions_lite_order->get_items() as $woocommerce_subscriptions_lite_item ) {
		$woocommerce_subscriptions_lite_item->update_meta_data(
			CartPlanHooks::SELLING_PLAN_ID_KEY,
			(string) $woocommerce_subscriptions_lite_monthly->get_id()
		);
		$woocommerce_subscriptions_lite_item->save();
	}
	$woocommerce_subscriptions_lite_order->set_address( $woocommerce_subscriptions_lite_address, 'billing' );
	$woocommerce_subscriptions_lite_order->set_payment_method( 'cod' );
	$woocommerce_subscriptions_lite_order->set_payment_method_title( 'Cash on delivery' );
	$woocommerce_subscriptions_lite_order->calculate_totals();
	$woocommerce_subscriptions_lite_order->update_status( 'processing' );
} catch ( Throwable $woocommerce_subscriptions_lite_error ) {
	// A pull request can change the code this file calls. The preview then opens without
	// sample data instead of failing to open, and the reason goes to the PHP error log.
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Preview only.
	error_log( 'Playground preview: sample data was not created. ' . $woocommerce_subscriptions_lite_error->getMessage() );
}

// The product and My Account subscription URLs need the rewrite rules of both plugins.
flush_rewrite_rules();
