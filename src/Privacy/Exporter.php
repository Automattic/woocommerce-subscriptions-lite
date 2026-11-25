<?php
/**
 * Exporter - adds the person's subscriptions to WordPress's personal data export.
 *
 * One "Subscriptions" group with an item per Lite contract: its facts, items, addresses,
 * contact details and payment method title. Ten contracts per page; the set does not change
 * during an export, so a page is a slice of it.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Privacy
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Privacy;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsLite\Admin\StatusLabels;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Personal data exporter for Lite contracts.
 */
final class Exporter {

	/**
	 * Export group id. Distinct from WooCommerce Subscriptions' group, so a store running both
	 * never merges two different records into one item.
	 */
	public const GROUP_ID = 'woocommerce-subscriptions-lite';

	/**
	 * Contracts exported per page.
	 */
	private const PAGE_SIZE = 10;

	/**
	 * Register WordPress hooks. Priority 10, after WooCommerce's exporters (5), so its groups
	 * come first in the export.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'add_exporter' ] );
	}

	/**
	 * Filter callback: the exporters with Lite's added.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return mixed
	 */
	public static function add_exporter( $exporters ) {
		if ( ! is_array( $exporters ) ) {
			return $exporters;
		}

		$exporters[ Package::EXTENSION_SLUG ] = [
			'exporter_friendly_name' => __( 'Subscriptions', 'woocommerce-subscriptions-lite' ),
			'callback'               => [ new self(), 'export' ],
		];

		return $exporters;
	}

	/**
	 * Export one page of the person's subscriptions. A failed read is logged (without personal
	 * data) and returned as an error, which stops the export.
	 *
	 * @param string $email_address The request's email address.
	 * @param int    $page          Page, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}|WP_Error
	 */
	public function export( string $email_address, int $page = 1 ) {
		$page = max( 1, $page );

		try {
			$contracts = RequesterContracts::get_for_email( $email_address );
			$data      = [];
			foreach ( array_slice( $contracts, ( $page - 1 ) * self::PAGE_SIZE, self::PAGE_SIZE ) as $listed ) {
				$contract = Contracts::get( $listed->get_id() );
				if ( null !== $contract ) {
					$data[] = $this->get_item( $contract );
				}
			}
		} catch ( Throwable $e ) {
			wc_get_logger()->error(
				sprintf( 'Privacy export: subscriptions could not be read (%s).', get_class( $e ) ),
				[ 'source' => Package::EXTENSION_SLUG ]
			);

			return new WP_Error(
				'woocommerce_subscriptions_lite_privacy_read_failed',
				__( 'Subscriptions could not be read for this request. Check the WooCommerce logs and try again.', 'woocommerce-subscriptions-lite' )
			);
		}

		return [
			'data' => $data,
			'done' => $page * self::PAGE_SIZE >= count( $contracts ),
		];
	}

	/**
	 * The export item for one contract.
	 *
	 * @param ContractView $contract The contract, with items and addresses.
	 * @return array<string, mixed>
	 */
	private function get_item( ContractView $contract ): array {
		return [
			'group_id'          => self::GROUP_ID,
			'group_label'       => __( 'Subscriptions', 'woocommerce-subscriptions-lite' ),
			'group_description' => __( 'User&#8217;s subscription data.', 'woocommerce-subscriptions-lite' ),
			'item_id'           => 'contract-' . $contract->get_id(),
			'data'              => $this->get_rows( $contract ),
		];
	}

	/**
	 * The name and value rows of one contract; empty values are left out.
	 *
	 * @param ContractView $contract The contract, with items and addresses.
	 * @return array<int, array{name: string, value: string}>
	 */
	private function get_rows( ContractView $contract ): array {
		$addresses = $contract->get_addresses() ?? [];
		$billing   = $addresses['billing'] ?? [];
		$shipping  = $addresses['shipping'] ?? [];

		$values = [
			__( 'Subscription number', 'woocommerce-subscriptions-lite' ) => '#' . $contract->get_id(),
			__( 'Status', 'woocommerce-subscriptions-lite' )          => StatusLabels::contract_label( $contract->get_status() ),
			__( 'Start date', 'woocommerce-subscriptions-lite' )      => self::format_date( $contract->get_start_gmt() ),
			__( 'Next payment', 'woocommerce-subscriptions-lite' )    => self::format_date( $contract->get_next_payment_gmt() ),
			__( 'End date', 'woocommerce-subscriptions-lite' )        => self::format_date( $contract->get_end_gmt() ),
			__( 'Recurring total', 'woocommerce-subscriptions-lite' ) => self::format_price( $contract->get_billing_total(), (string) $contract->get_currency() ),
			__( 'Items', 'woocommerce-subscriptions-lite' )           => self::format_items( $contract->get_items() ?? [] ),
			__( 'Billing address', 'woocommerce-subscriptions-lite' ) => self::format_address( $billing ),
			__( 'Shipping address', 'woocommerce-subscriptions-lite' ) => self::format_address( $shipping ),
			__( 'Email address', 'woocommerce-subscriptions-lite' )   => self::get_text( $billing, 'email' ),
			__( 'Phone number', 'woocommerce-subscriptions-lite' )    => self::get_text( $billing, 'phone' ),
			__( 'Shipping phone number', 'woocommerce-subscriptions-lite' ) => self::get_text( $shipping, 'phone' ),
			__( 'Payment method', 'woocommerce-subscriptions-lite' )  => trim( (string) $contract->get_payment_method_title() ),
		];

		$rows = [];
		foreach ( $values as $name => $value ) {
			if ( '' !== $value ) {
				$rows[] = [
					'name'  => (string) $name,
					'value' => $value,
				];
			}
		}

		return $rows;
	}

	/**
	 * A GMT moment in the store's time zone and date and time formats, or '' when absent.
	 *
	 * @param string|null $gmt GMT `Y-m-d H:i:s`, or null.
	 */
	private static function format_date( ?string $gmt ): string {
		$timestamp = null === $gmt || '' === $gmt ? false : strtotime( $gmt . ' UTC' );
		if ( false === $timestamp ) {
			return '';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * An amount in the contract currency as plain text.
	 *
	 * @param string $amount   Decimal money string.
	 * @param string $currency Currency code.
	 */
	private static function format_price( string $amount, string $currency ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, [ 'currency' => $currency ] ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * The items as `name x quantity`, comma separated.
	 *
	 * @param array<int, array<string, mixed>> $items Contract items.
	 */
	private static function format_items( array $items ): string {
		$lines = [];
		foreach ( $items as $item ) {
			$lines[] = sprintf( '%s x %s', (string) ( $item['item_name'] ?? '' ), wc_stock_amount( $item['quantity'] ?? 0 ) );
		}

		return implode( ', ', $lines );
	}

	/**
	 * An address formatted by WooCommerce, on one line.
	 *
	 * @param array<string, mixed> $address Address fields.
	 */
	private static function format_address( array $address ): string {
		$fields = array_map(
			static function ( $value ): string {
				return is_scalar( $value ) ? (string) $value : '';
			},
			$address
		);
		unset( $fields['email'], $fields['phone'] );

		$formatted = (string) WC()->countries->get_formatted_address( $fields );

		return trim( (string) preg_replace( '#<br\s*/?>#i', ', ', $formatted ) );
	}

	/**
	 * A trimmed address field, or '' when absent.
	 *
	 * @param array<string, mixed> $address Address fields.
	 * @param string               $field   Field name.
	 */
	private static function get_text( array $address, string $field ): string {
		return isset( $address[ $field ] ) && is_scalar( $address[ $field ] ) ? trim( (string) $address[ $field ] ) : '';
	}
}
