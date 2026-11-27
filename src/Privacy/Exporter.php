<?php
/**
 * Exporter - adds the person's subscriptions to WordPress's personal data export.
 *
 * One "Subscriptions" group with an item per Lite contract: its number, status, start, next
 * payment and end dates, recurring total, items, every stored address field and payment method
 * title. That covers what WooCommerce Subscriptions exports for a subscription, except the
 * created date, IP address and browser user agent, which a contract does not store. Like that
 * export, it leaves out the last payment, last attempt and trial end dates and the discount,
 * shipping and tax totals. A "Subscription history" group adds an item per contract with history
 * entries: one row per entry, its date and Lite's label, without a note's text. Ten contracts per
 * page; the set does not change during an export, so a page is a slice of it.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Privacy
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Privacy;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\HistoryEntryView;
use Automattic\WooCommerce\SubscriptionsLite\Admin\StatusLabels;
use Automattic\WooCommerce\SubscriptionsLite\Contracts\AddressFields;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\History;
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
	 * Export group id of the contracts' history entries.
	 */
	public const HISTORY_GROUP_ID = 'woocommerce-subscriptions-lite-history';

	/**
	 * Contracts exported per page.
	 */
	private const PAGE_SIZE = 10;

	/**
	 * History entries read per engine call.
	 */
	private const HISTORY_READ_SIZE = 100;

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
			$requester_id = RequesterContracts::get_account_id( $email_address );
			$contracts    = RequesterContracts::get_for_email( $email_address, $requester_id );
			$data         = [];
			foreach ( array_slice( $contracts, ( $page - 1 ) * self::PAGE_SIZE, self::PAGE_SIZE ) as $listed ) {
				$contract = Contracts::get( $listed->get_id() );
				if ( null === $contract ) {
					continue;
				}

				$data[]  = $this->get_item( $contract );
				$history = $this->get_history_item( $contract->get_id(), $requester_id );
				if ( null !== $history ) {
					$data[] = $history;
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
	 * The history export item of one contract, newest entry first; null when it has none.
	 *
	 * @param int $contract_id  Contract id.
	 * @param int $requester_id The requester's user id, or 0 when no account has the email.
	 * @return array<string, mixed>|null
	 */
	private function get_history_item( int $contract_id, int $requester_id ): ?array {
		$rows   = [];
		$offset = 0;
		do {
			$entries = Contracts::get_history(
				$contract_id,
				[
					'limit'  => self::HISTORY_READ_SIZE,
					'offset' => $offset,
				]
			);
			foreach ( $entries as $entry ) {
				$rows[] = $this->get_history_row( $entry, $requester_id );
			}
			$offset   += self::HISTORY_READ_SIZE;
			$full_page = count( $entries ) === self::HISTORY_READ_SIZE;
		} while ( $full_page );

		if ( [] === $rows ) {
			return null;
		}

		return [
			'group_id'          => self::HISTORY_GROUP_ID,
			'group_label'       => __( 'Subscription history', 'woocommerce-subscriptions-lite' ),
			'group_description' => __( 'What happened to the user&#8217;s subscriptions.', 'woocommerce-subscriptions-lite' ),
			'item_id'           => 'contract-' . $contract_id . '-history',
			'data'              => $rows,
		];
	}

	/**
	 * The export row of one history entry: when, and Lite's label for what happened, with
	 * "by you" when the requester did it as the subscription's customer. The only place that
	 * decides what an entry exports: no payload content is exported, so a note shows its label
	 * and date but never its text.
	 *
	 * @param HistoryEntryView $entry        History entry.
	 * @param int              $requester_id The requester's user id, or 0 when no account has the email.
	 * @return array{name: string, value: string}
	 */
	private function get_history_row( HistoryEntryView $entry, int $requester_id ): array {
		$actor        = ( $entry->get_payload() ?? [] )['actor'] ?? [];
		$label        = StatusLabels::history_type_label( $entry->get_type() );
		$by_requester = $requester_id > 0
			&& History::ROLE_CUSTOMER === ( $actor['role'] ?? null )
			&& (int) ( $actor['user_id'] ?? 0 ) === $requester_id;

		return [
			'name'  => self::format_date( $entry->get_date_created_gmt() ),
			/* translators: %s: what happened to the subscription, e.g. "Cancelled". */
			'value' => $by_requester ? sprintf( __( '%s, by you', 'woocommerce-subscriptions-lite' ), $label ) : $label,
		];
	}

	/**
	 * The name and value rows of one contract; empty values are left out. Rows are built by a
	 * stable key, so two labels a translation renders alike never overwrite each other.
	 *
	 * @param ContractView $contract The contract, with items and addresses.
	 * @return array<int, array{name: string, value: string}>
	 */
	private function get_rows( ContractView $contract ): array {
		$addresses = $contract->get_addresses() ?? [];
		$billing   = $addresses['billing'] ?? [];
		$shipping  = $addresses['shipping'] ?? [];

		$values = [
			'number'           => [ __( 'Subscription number', 'woocommerce-subscriptions-lite' ), '#' . $contract->get_id() ],
			'status'           => [ __( 'Status', 'woocommerce-subscriptions-lite' ), StatusLabels::contract_label( $contract->get_status() ) ],
			'start_date'       => [ __( 'Start date', 'woocommerce-subscriptions-lite' ), self::format_date( $contract->get_start_gmt() ) ],
			'next_payment'     => [ __( 'Next payment', 'woocommerce-subscriptions-lite' ), self::format_date( $contract->get_next_payment_gmt() ) ],
			'end_date'         => [ __( 'End date', 'woocommerce-subscriptions-lite' ), self::format_date( $contract->get_end_gmt() ) ],
			'recurring_total'  => [ __( 'Recurring total', 'woocommerce-subscriptions-lite' ), self::format_price( $contract->get_billing_total(), (string) $contract->get_currency() ) ],
			'items'            => [ __( 'Items', 'woocommerce-subscriptions-lite' ), self::format_items( $contract->get_items() ?? [] ) ],
			'billing_address'  => [ __( 'Billing address', 'woocommerce-subscriptions-lite' ), self::format_address( $billing ) ],
			'shipping_address' => [ __( 'Shipping address', 'woocommerce-subscriptions-lite' ), self::format_address( $shipping ) ],
		];

		$values = array_merge(
			$values,
			self::get_unformatted_field_values( 'billing', $billing ),
			self::get_unformatted_field_values( 'shipping', $shipping ),
			[ 'payment_method' => [ __( 'Payment method', 'woocommerce-subscriptions-lite' ), trim( (string) $contract->get_payment_method_title() ) ] ]
		);

		$rows = [];
		foreach ( $values as $value ) {
			if ( '' !== $value[1] ) {
				$rows[] = [
					'name'  => $value[0],
					'value' => $value[1],
				];
			}
		}

		return $rows;
	}

	/**
	 * Name and value pairs, keyed `{type}_{field}`, for the stored address fields the formatted
	 * address does not show: always email and phone, and any field the country's address format
	 * leaves out (a German address has no `{state}`, for example). With the formatted address,
	 * every field in {@see AddressFields::FIELDS} is exported.
	 *
	 * @param string               $type    `billing` or `shipping`.
	 * @param array<string, mixed> $address Address fields.
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function get_unformatted_field_values( string $type, array $address ): array {
		$country = self::get_text( $address, 'country' );
		$formats = WC()->countries->get_address_formats();
		$format  = '' !== $country && isset( $formats[ $country ] ) ? (string) $formats[ $country ] : (string) $formats['default'];

		$values = [];
		foreach ( AddressFields::FIELDS as $field ) {
			if ( self::is_in_address_format( $field, $format ) ) {
				continue;
			}
			$values[ $type . '_' . $field ] = [ self::get_field_label( $type, $field ), self::get_field_value( $address, $field ) ];
		}

		return $values;
	}

	/**
	 * Whether WooCommerce's address `$format` shows `$field`, through any of its placeholders
	 * (`{state}`, `{state_upper}`, `{state_code}`; first and last names also through `{name}`).
	 *
	 * @param string $field  Address field.
	 * @param string $format WooCommerce address format.
	 */
	private static function is_in_address_format( string $field, string $format ): bool {
		$placeholders = in_array( $field, [ 'first_name', 'last_name' ], true ) ? [ '{' . $field, '{name' ] : [ '{' . $field ];
		foreach ( $placeholders as $placeholder ) {
			if ( false !== strpos( $format, $placeholder ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A stored address field as text: state and country by their WooCommerce names.
	 *
	 * @param array<string, mixed> $address Address fields.
	 * @param string               $field   Field name.
	 */
	private static function get_field_value( array $address, string $field ): string {
		$value   = self::get_text( $address, $field );
		$country = self::get_text( $address, 'country' );
		if ( '' === $value ) {
			return '';
		}

		if ( 'state' === $field ) {
			$states = '' !== $country ? WC()->countries->get_states( $country ) : [];
			$value  = is_array( $states ) && isset( $states[ $value ] ) ? (string) $states[ $value ] : $value;
		} elseif ( 'country' === $field ) {
			$countries = WC()->countries->get_countries();
			$value     = isset( $countries[ $value ] ) ? (string) $countries[ $value ] : $value;
		}

		return html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * The export label of an address field. The billing email and phone keep the labels
	 * WooCommerce's order export gives them.
	 *
	 * @param string $type  `billing` or `shipping`.
	 * @param string $field Field name.
	 */
	private static function get_field_label( string $type, string $field ): string {
		$labels = 'billing' === $type
			? [
				'first_name' => __( 'Billing first name', 'woocommerce-subscriptions-lite' ),
				'last_name'  => __( 'Billing last name', 'woocommerce-subscriptions-lite' ),
				'company'    => __( 'Billing company', 'woocommerce-subscriptions-lite' ),
				'address_1'  => __( 'Billing address line 1', 'woocommerce-subscriptions-lite' ),
				'address_2'  => __( 'Billing address line 2', 'woocommerce-subscriptions-lite' ),
				'city'       => __( 'Billing city', 'woocommerce-subscriptions-lite' ),
				'state'      => __( 'Billing state', 'woocommerce-subscriptions-lite' ),
				'postcode'   => __( 'Billing postcode', 'woocommerce-subscriptions-lite' ),
				'country'    => __( 'Billing country', 'woocommerce-subscriptions-lite' ),
				'email'      => __( 'Email address', 'woocommerce-subscriptions-lite' ),
				'phone'      => __( 'Phone number', 'woocommerce-subscriptions-lite' ),
			]
			: [
				'first_name' => __( 'Shipping first name', 'woocommerce-subscriptions-lite' ),
				'last_name'  => __( 'Shipping last name', 'woocommerce-subscriptions-lite' ),
				'company'    => __( 'Shipping company', 'woocommerce-subscriptions-lite' ),
				'address_1'  => __( 'Shipping address line 1', 'woocommerce-subscriptions-lite' ),
				'address_2'  => __( 'Shipping address line 2', 'woocommerce-subscriptions-lite' ),
				'city'       => __( 'Shipping city', 'woocommerce-subscriptions-lite' ),
				'state'      => __( 'Shipping state', 'woocommerce-subscriptions-lite' ),
				'postcode'   => __( 'Shipping postcode', 'woocommerce-subscriptions-lite' ),
				'country'    => __( 'Shipping country', 'woocommerce-subscriptions-lite' ),
				'email'      => __( 'Shipping email address', 'woocommerce-subscriptions-lite' ),
				'phone'      => __( 'Shipping phone number', 'woocommerce-subscriptions-lite' ),
			];

		return $labels[ $field ] ?? $field;
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
	 * An address formatted by WooCommerce, on one line. The country is shown even when it is
	 * the store's base country, which WooCommerce otherwise leaves out.
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

		$force_country = static function (): bool {
			return true;
		};
		add_filter( 'woocommerce_formatted_address_force_country_display', $force_country, PHP_INT_MAX );
		try {
			$formatted = (string) WC()->countries->get_formatted_address( $fields );
		} finally {
			remove_filter( 'woocommerce_formatted_address_force_country_display', $force_country, PHP_INT_MAX );
		}

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
