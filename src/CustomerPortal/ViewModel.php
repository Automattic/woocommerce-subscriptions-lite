<?php
/**
 * ViewModel - the single shaping function for the customer portal.
 *
 * Both the server render (templates + the `wp_interactivity_state()` seed) and
 * the REST response use this builder, so the page-load shape and the refetch
 * shape never drift. The {@see EngineDataProvider} returns domain-ish data; this
 * class formats it into the presentation shape: customer-facing status labels,
 * money strings, formatted dates, the per-status detail date-row, and the
 * action-visibility flags the templates and the iAPI store both read.
 *
 * Pure presentation logic: no contract lookups, no provider calls, no engine
 * reach-through. Hand it provider arrays, get back presentation arrays.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\Utilities\Formatter;

defined( 'ABSPATH' ) || exit;

/**
 * Builds list-row and detail presentation arrays from provider data.
 */
final class ViewModel {

	/**
	 * Statuses a customer may cancel from. `active` and `on-hold` are
	 * cancelable; terminal and pending-cancellation states are not (a
	 * customer-driven re-cancel of a winding-down contract is a merchant-only
	 * decision, so it is hidden from the portal).
	 *
	 * @var array<int, string>
	 */
	private const CANCELABLE_STATUSES = [ ContractStatus::ACTIVE, ContractStatus::ON_HOLD ];

	/**
	 * Build the list-row presentation array for one contract.
	 *
	 * @param array<string, mixed> $contract Domain-ish contract array from the provider.
	 * @return array<string, mixed> Presentation row for the list template + iAPI seed.
	 */
	public function build_row( array $contract ): array {
		$status         = (string) ( $contract['status'] ?? '' );
		$next_payment   = $this->to_string( $contract['next_payment_gmt'] ?? null );
		$payment_method = $this->normalize_payment_method( $contract['payment_method'] ?? [] );

		return [
			'id'                   => (int) ( $contract['id'] ?? 0 ),
			'status'               => $status,
			'status_label'         => $this->status_label( $status ),
			'next_payment'         => $this->should_dash_next_payment( $status, $next_payment )
				? ''
				: $this->format_date( $next_payment ),
			'payment_method_title' => $payment_method['title'],
			'total'                => $this->recurring_summary( $contract ),
		];
	}

	/**
	 * Build the full list view-model: the rows plus the detail URL helper data.
	 *
	 * @param array<int, array<string, mixed>> $contracts Domain-ish contract arrays.
	 * @return array<int, array<string, mixed>> Presentation rows.
	 */
	public function build_list( array $contracts ): array {
		$rows = [];
		foreach ( $contracts as $contract ) {
			if ( is_array( $contract ) ) {
				$rows[] = $this->build_row( $contract );
			}
		}
		return $rows;
	}

	/**
	 * Build the detail-page presentation array for one contract.
	 *
	 * Carries the per-status logic: the dynamic date-row label/value, the
	 * recurring summary, the payment-method line, and the action-visibility
	 * flags (`cancel_visible`, `hold_visible`, `reactivate_visible`,
	 * `needs_payment_notice`, `at_period_end`).
	 *
	 * @param array<string, mixed>             $contract       Domain-ish contract array.
	 * @param array<int, array<string, mixed>> $related_orders Domain-ish related-order arrays.
	 * @return array<string, mixed> Presentation detail view-model.
	 */
	public function build_detail( array $contract, array $related_orders = [] ): array {
		$status           = (string) ( $contract['status'] ?? '' );
		$has_next_payment = '' !== $this->to_string( $contract['next_payment_gmt'] ?? null );
		$payment_method   = $this->normalize_payment_method( $contract['payment_method'] ?? [] );

		// "Needs payment" heuristic: on-hold + a scheduled next payment is the
		// failed-payment retry path (the customer must update payment before
		// reactivate is safe); on-hold + no next payment is the admin-action
		// path, where reactivate is safe.
		$needs_payment = ContractStatus::ON_HOLD === $status && $has_next_payment;

		return [
			'id'                     => (int) ( $contract['id'] ?? 0 ),
			'status'                 => $status,
			'status_label'           => $this->status_label( $status ),
			'recurring_summary'      => $this->recurring_summary( $contract ),
			'start_date'             => $this->format_date( $this->to_string( $contract['start_gmt'] ?? null ) ),
			'last_order_date'        => $this->format_date( $this->resolve_last_order_gmt( $contract ) ),
			'date_row_label'         => $this->date_row_label( $status, $contract ),
			'date_row_value'         => $this->date_row_value( $status, $contract ),
			'payment_method_title'   => $payment_method['title'],
			'payment_method_expires' => $payment_method['expires'],
			'cancel_visible'         => in_array( $status, self::CANCELABLE_STATUSES, true ),
			'hold_visible'           => ContractStatus::ACTIVE === $status,
			'reactivate_visible'     => ContractStatus::ON_HOLD === $status && ! $needs_payment,
			'needs_payment_notice'   => $needs_payment,
			// Cancel mode the action forwards: active cancels at period end
			// (graceful -> pending-cancellation); on-hold cancels immediately
			// (no period to ride out -> cancelled).
			'at_period_end'          => ContractStatus::ACTIVE === $status,
			'cancel_modal_copy'      => $this->cancel_modal_copy( $status, $contract ),
			'related_orders'         => $this->build_related_orders( $related_orders ),
			'items'                  => $this->build_items( $contract ),
			'totals_rows'            => $this->build_totals_rows( $contract ),
			'billing_address'        => $this->format_address( $this->address( $contract, 'billing' ) ),
			'billing_phone'          => $this->to_string( $this->address( $contract, 'billing' )['phone'] ?? null ),
			'billing_email'          => $this->to_string( $this->address( $contract, 'billing' )['email'] ?? null ),
			'shipping_address'       => $this->format_address( $this->address( $contract, 'shipping' ) ),
		];
	}

	/**
	 * Build the subscription-totals line-item rows: name, display quantity, and the
	 * formatted line subtotal (discounts surface as their own totals row, WC-style).
	 *
	 * @param array<string, mixed> $contract Domain-ish contract array.
	 * @return array<int, array<string, string>>
	 */
	private function build_items( array $contract ): array {
		$items = [];
		foreach ( (array) ( $contract['items'] ?? [] ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name = $this->to_string( $item['name'] ?? null );
			if ( '' === $name ) {
				continue;
			}
			$items[] = [
				'name'     => $name,
				'quantity' => $this->format_quantity( $item['quantity'] ?? 1 ),
				'subtotal' => $this->format_price( $this->to_string( $item['subtotal'] ?? '0' ), $contract ),
			];
		}
		return $items;
	}

	/**
	 * Build the subscription-totals footer rows: Subtotal, then the conditional
	 * Discount / Shipping / Tax rows (only when non-zero), then the recurring Total
	 * (price + cadence). Empty when the contract carries no line items - the
	 * template skips the whole section.
	 *
	 * @param array<string, mixed> $contract Domain-ish contract array.
	 * @return array<int, array<string, string>>
	 */
	private function build_totals_rows( array $contract ): array {
		$items = (array) ( $contract['items'] ?? [] );
		if ( [] === $items ) {
			return [];
		}

		$subtotal = 0.0;
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$subtotal += (float) $this->to_string( $item['subtotal'] ?? '0' );
			}
		}

		$rows = [
			[
				'label' => __( 'Subtotal', 'woocommerce-subscriptions-lite' ),
				'value' => $this->format_price( (string) $subtotal, $contract ),
			],
		];

		$discount = (float) $this->to_string( $contract['discount_total'] ?? '0' );
		if ( $discount > 0 ) {
			$rows[] = [
				'label' => __( 'Discount', 'woocommerce-subscriptions-lite' ),
				'value' => '-' . $this->format_price( (string) $discount, $contract ),
			];
		}

		$shipping = (float) $this->to_string( $contract['shipping_total'] ?? '0' );
		if ( $shipping > 0 ) {
			$rows[] = [
				'label' => __( 'Shipping', 'woocommerce-subscriptions-lite' ),
				'value' => $this->format_price( (string) $shipping, $contract ),
			];
		}

		$tax = (float) $this->to_string( $contract['tax_total'] ?? '0' );
		if ( $tax > 0 ) {
			$rows[] = [
				'label' => __( 'Tax', 'woocommerce-subscriptions-lite' ),
				'value' => $this->format_price( (string) $tax, $contract ),
			];
		}

		$rows[] = [
			'label' => __( 'Total', 'woocommerce-subscriptions-lite' ),
			'value' => $this->recurring_summary( $contract ),
		];

		return $rows;
	}

	/**
	 * One typed address off the contract's `addresses` map, or an empty array.
	 *
	 * @param array<string, mixed> $contract Domain-ish contract array.
	 * @param string               $type     Address type: `billing` or `shipping`.
	 * @return array<string, string>
	 */
	private function address( array $contract, string $type ): array {
		$addresses = $contract['addresses'] ?? [];
		$address   = is_array( $addresses ) && isset( $addresses[ $type ] ) && is_array( $addresses[ $type ] ) ? $addresses[ $type ] : [];

		$fields = [];
		foreach ( $address as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$fields[ $key ] = (string) $value;
			}
		}
		return $fields;
	}

	/**
	 * Format an address field array into localized display HTML through WooCommerce's
	 * per-country address formatter. Contact fields (email/phone) are not part of the
	 * formatted block - the template renders them as their own lines, WC-style.
	 *
	 * @param array<string, string> $address WC-style address fields.
	 * @return string Formatted address HTML (line breaks as `<br/>`), or '' when empty.
	 */
	private function format_address( array $address ): string {
		unset( $address['email'], $address['phone'] );
		if ( [] === array_filter( $address ) ) {
			return '';
		}

		$formatted = WC()->countries->get_formatted_address( $address );

		return is_string( $formatted ) ? $formatted : '';
	}

	/**
	 * Display form of a line-item quantity: whole quantities drop the decimals
	 * (storage is DECIMAL, so `1.0000` renders as `1`), fractional ones keep them.
	 *
	 * @param mixed $quantity Raw quantity.
	 */
	private function format_quantity( $quantity ): string {
		$quantity = is_numeric( $quantity ) ? (float) $quantity : 1.0;

		return floor( $quantity ) === $quantity ? (string) (int) $quantity : (string) $quantity;
	}

	/**
	 * Format an amount in the contract's currency, tags stripped - the one money
	 * formatter every portal money string goes through.
	 *
	 * @param string               $amount   Raw amount string.
	 * @param array<string, mixed> $contract Domain-ish contract array (for the currency).
	 */
	private function format_price( string $amount, array $contract ): string {
		$currency = (string) ( $contract['currency'] ?? '' );

		return wp_strip_all_tags( wc_price( (float) $amount, [ 'currency' => '' !== $currency ? $currency : null ] ) );
	}

	/**
	 * Customer-facing status label.
	 *
	 * Most match the admin status names verbatim; `pending-cancellation` is the
	 * deliberate customer-facing divergence - customers see "Cancels soon"
	 * rather than the admin-speak status name. Unknown statuses fall through to
	 * a humanized form so an unexpected value still renders something usable.
	 *
	 * @param string $status Status slug.
	 */
	public function status_label( string $status ): string {
		switch ( $status ) {
			case ContractStatus::ACTIVE:
				return __( 'Active', 'woocommerce-subscriptions-lite' );
			case ContractStatus::ON_HOLD:
				return __( 'On hold', 'woocommerce-subscriptions-lite' );
			case ContractStatus::PENDING_CANCELLATION:
				return __( 'Cancels soon', 'woocommerce-subscriptions-lite' );
			case ContractStatus::CANCELLED:
				return __( 'Cancelled', 'woocommerce-subscriptions-lite' );
			case ContractStatus::EXPIRED:
				return __( 'Expired', 'woocommerce-subscriptions-lite' );
			default:
				return ucfirst( str_replace( '-', ' ', $status ) );
		}
	}

	/**
	 * Shape the related-order arrays into presentation rows (formatted date).
	 *
	 * @param array<int, array<string, mixed>> $related_orders Domain-ish related-order arrays.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_related_orders( array $related_orders ): array {
		$rows = [];
		foreach ( $related_orders as $order ) {
			if ( ! is_array( $order ) ) {
				continue;
			}
			$rows[] = [
				'number'       => (string) ( $order['number'] ?? '' ),
				'date'         => $this->format_date( $this->to_string( $order['date_gmt'] ?? null ) ),
				'status'       => (string) ( $order['status'] ?? '' ),
				'status_label' => (string) ( $order['status_label'] ?? '' ),
				'total'        => (string) ( $order['total'] ?? '' ),
				'view_url'     => (string) ( $order['view_url'] ?? '' ),
			];
		}
		return $rows;
	}

	/**
	 * Whether the next-payment cell should render a dash rather than a date.
	 *
	 *  - Pending cancellation / cancelled / expired: always dash (no future
	 *    payment).
	 *  - On-hold: dash when no next payment is scheduled (admin-action path);
	 *    show the date when one is (failed-payment retry path).
	 *  - Active: show the date.
	 *
	 * @param string $status           Status slug.
	 * @param string $next_payment_gmt GMT next-payment timestamp (empty if unset).
	 */
	private function should_dash_next_payment( string $status, string $next_payment_gmt ): bool {
		if ( in_array( $status, [ ContractStatus::PENDING_CANCELLATION, ContractStatus::CANCELLED, ContractStatus::EXPIRED ], true ) ) {
			return true;
		}
		if ( ContractStatus::ON_HOLD === $status && '' === $next_payment_gmt ) {
			return true;
		}
		return false;
	}

	/**
	 * Per-status label for the dynamic date-row in the detail data block.
	 *
	 * @param string               $status   Status slug.
	 * @param array<string, mixed> $contract Domain-ish contract array.
	 */
	private function date_row_label( string $status, array $contract ): string {
		switch ( $status ) {
			case ContractStatus::ACTIVE:
				return __( 'Next payment date', 'woocommerce-subscriptions-lite' );
			case ContractStatus::PENDING_CANCELLATION:
				return __( 'Cancels on', 'woocommerce-subscriptions-lite' );
			case ContractStatus::CANCELLED:
			case ContractStatus::EXPIRED:
				return __( 'End date', 'woocommerce-subscriptions-lite' );
			case ContractStatus::ON_HOLD:
				if ( '' !== $this->to_string( $contract['next_payment_gmt'] ?? null ) ) {
					return __( 'Next payment date', 'woocommerce-subscriptions-lite' );
				}
				return __( 'On-hold since', 'woocommerce-subscriptions-lite' );
			default:
				return __( 'Date', 'woocommerce-subscriptions-lite' );
		}
	}

	/**
	 * Per-status value for the dynamic date-row in the detail data block.
	 *
	 * @param string               $status   Status slug.
	 * @param array<string, mixed> $contract Domain-ish contract array.
	 */
	private function date_row_value( string $status, array $contract ): string {
		$next_payment = $this->to_string( $contract['next_payment_gmt'] ?? null );
		$end          = $this->to_string( $contract['end_gmt'] ?? null );
		$updated      = $this->to_string( $contract['last_updated_gmt'] ?? null );

		switch ( $status ) {
			case ContractStatus::ACTIVE:
				return $this->format_date( $next_payment );
			case ContractStatus::PENDING_CANCELLATION:
				return $this->format_date( '' !== $end ? $end : $next_payment );
			case ContractStatus::CANCELLED:
			case ContractStatus::EXPIRED:
				return $this->format_date( $end );
			case ContractStatus::ON_HOLD:
				if ( '' !== $next_payment ) {
					return $this->format_date( $next_payment );
				}
				// Admin-action path: fall back to the last-updated timestamp as
				// the closest proxy for "when the status flipped".
				return $this->format_date( $updated );
			default:
				return '';
		}
	}

	/**
	 * State-aware cancel-modal body copy.
	 *
	 * Narrates the cancel endpoint's state-machine decision before the customer
	 * confirms: active cancels at the end of the current cycle (with the date
	 * when available); on-hold cancels immediately.
	 *
	 * @param string               $status   Status slug.
	 * @param array<string, mixed> $contract Domain-ish contract array.
	 */
	private function cancel_modal_copy( string $status, array $contract ): string {
		if ( ContractStatus::ACTIVE === $status ) {
			$end_gmt = $this->to_string( $contract['end_gmt'] ?? null );
			if ( '' === $end_gmt ) {
				$end_gmt = $this->to_string( $contract['next_payment_gmt'] ?? null );
			}
			$end_date = $this->format_date( $end_gmt );
			if ( '' !== $end_date ) {
				return sprintf(
					/* translators: %s: end-of-current-billing-cycle date */
					__( 'Your subscription will be cancelled at the end of your current billing cycle (%s). You will continue to receive your orders until then.', 'woocommerce-subscriptions-lite' ),
					$end_date
				);
			}
			return __(
				'Your subscription will be cancelled at the end of your current billing cycle. You will continue to receive your orders until then.',
				'woocommerce-subscriptions-lite'
			);
		}

		if ( ContractStatus::ON_HOLD === $status ) {
			return __( 'Your subscription will be cancelled immediately.', 'woocommerce-subscriptions-lite' );
		}

		return '';
	}

	/**
	 * Resolve the "last order date" GMT timestamp for the detail block.
	 *
	 * Falls back to the start date when last-payment is unset: a freshly active
	 * contract has no recorded last payment between creation and its first
	 * renewal, but the origin payment did happen, so the start date is the
	 * right stand-in.
	 *
	 * @param array<string, mixed> $contract Domain-ish contract array.
	 */
	private function resolve_last_order_gmt( array $contract ): string {
		$last = $this->to_string( $contract['last_payment_gmt'] ?? null );
		if ( '' !== $last ) {
			return $last;
		}
		return $this->to_string( $contract['start_gmt'] ?? null );
	}

	/**
	 * Build the recurring summary string: `{price} / {period}` for interval 1,
	 * or `{price} every {N} {period}s` for interval > 1.
	 *
	 * @param array<string, mixed> $contract Domain-ish contract array.
	 */
	private function recurring_summary( array $contract ): string {
		$billing_total = $this->to_string( $contract['billing_total'] ?? null );
		if ( '' === $billing_total ) {
			return '';
		}

		$price = $this->format_price( $billing_total, $contract );

		$period   = (string) ( $contract['billing_period'] ?? '' );
		$interval = (int) ( $contract['billing_interval'] ?? 0 );
		if ( '' === $period || $interval < 1 ) {
			return $price;
		}

		$cadence = Formatter::price_cadence( $period, $interval );
		return '' === $cadence ? $price : $price . ' ' . $cadence;
	}

	/**
	 * Normalize a provider payment-method array to `title` + `expires` strings.
	 *
	 * @param mixed $payment_method The provider's `payment_method` value.
	 * @return array{title: string, expires: string}
	 */
	private function normalize_payment_method( $payment_method ): array {
		if ( ! is_array( $payment_method ) ) {
			return [
				'title'   => '',
				'expires' => '',
			];
		}
		return [
			'title'   => (string) ( $payment_method['title'] ?? '' ),
			'expires' => (string) ( $payment_method['expires'] ?? '' ),
		];
	}

	/**
	 * Format a GMT timestamp string into the site's date format. Empty string
	 * for an empty input or an unparseable value.
	 *
	 * @param string $gmt GMT timestamp ('Y-m-d H:i:s') or empty.
	 */
	private function format_date( string $gmt ): string {
		if ( '' === $gmt ) {
			return '';
		}
		$timestamp = strtotime( $gmt . ' UTC' );
		if ( false === $timestamp ) {
			return '';
		}
		return date_i18n( get_option( 'date_format' ), $timestamp );
	}

	/**
	 * Coerce a nullable scalar to a trimmed string.
	 *
	 * @param mixed $value The value to coerce.
	 */
	private function to_string( $value ): string {
		if ( null === $value ) {
			return '';
		}
		return trim( (string) $value );
	}
}
