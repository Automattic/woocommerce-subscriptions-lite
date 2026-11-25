<?php
/**
 * ErasureSetting - whether a personal data erasure request removes personal data from subscriptions.
 *
 * Adds the checkbox under WooCommerce > Settings > Accounts and Privacy, after WooCommerce's own
 * "Remove personal data from orders on request". The option is the one WooCommerce Subscriptions
 * uses, so a store moving between the two keeps its choice, and the checkbox is added only when
 * that plugin has not added it already.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Privacy
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * The subscription erasure option and its settings field.
 */
final class ErasureSetting {

	/**
	 * Option id; `yes` lets an erasure request remove personal data from subscriptions.
	 */
	public const OPTION = 'woocommerce_erasure_request_removes_subscription_data';

	/**
	 * WooCommerce's order erasure checkbox, which the field follows.
	 */
	private const AFTER_SETTING = 'woocommerce_erasure_request_removes_order_data';

	/**
	 * Register WordPress hooks. Priority 20, after WooCommerce Subscriptions' own filter (10),
	 * so its field is already in the array when this one checks for it.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_account_settings', [ self::class, 'add_setting' ], 20 );
	}

	/**
	 * Whether erasure requests remove personal data from subscriptions. Off by default.
	 */
	public static function is_enabled(): bool {
		return wc_string_to_bool( (string) get_option( self::OPTION, 'no' ) );
	}

	/**
	 * Filter callback: the account settings with the subscription erasure checkbox after
	 * WooCommerce's order erasure checkbox. Unchanged when the field is already present or
	 * WooCommerce's checkbox is not.
	 *
	 * @param mixed $settings WooCommerce Accounts and Privacy settings.
	 * @return mixed
	 */
	public static function add_setting( $settings ) {
		if ( ! is_array( $settings ) || in_array( self::OPTION, array_column( $settings, 'id' ), true ) ) {
			return $settings;
		}

		// Walk the array: array_column() skips entries without an id, so its positions can differ.
		$position = 0;
		foreach ( $settings as $setting ) {
			++$position;
			if ( is_array( $setting ) && self::AFTER_SETTING === ( $setting['id'] ?? null ) ) {
				array_splice( $settings, $position, 0, [ self::get_field() ] );
				break;
			}
		}

		return $settings;
	}

	/**
	 * The checkbox definition.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_field(): array {
		$erasure_text = esc_html__( 'account erasure request', 'woocommerce-subscriptions-lite' );
		if ( current_user_can( 'manage_privacy_options' ) ) {
			$erasure_text = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'erase-personal-data.php' ) ), $erasure_text );
		}

		return [
			'desc'          => __( 'Remove personal data from subscriptions', 'woocommerce-subscriptions-lite' ),
			'desc_tip'      => sprintf(
				/* translators: %s: "account erasure request", linked to the erasure requests screen when the user can manage them. */
				esc_html__( 'When handling an %s, should personal data within subscriptions be retained or removed? Running subscriptions are cancelled before their personal data is removed. When this is off, subscriptions are kept, but the erasure may still remove the customer\'s saved payment methods, which can stop automatic renewals.', 'woocommerce-subscriptions-lite' ),
				$erasure_text
			),
			'id'            => self::OPTION,
			'type'          => 'checkbox',
			'default'       => 'no',
			'checkboxgroup' => '',
			'autoload'      => false,
		];
	}
}
