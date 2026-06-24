<?php
/**
 * StatusLabels - merchant-facing labels and badge markup for engine statuses.
 *
 * A small, WordPress-free helper shared by the admin list table and the detail
 * renderer so contract and cycle statuses read the same wherever they appear.
 * Pure functions of their inputs (no globals, no time, no WordPress calls beyond
 * the i18n pass-throughs), which keeps the label/badge vocabulary unit-testable.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Status label + badge helpers for the admin subscription screens.
 */
final class StatusLabels {

	/**
	 * Merchant-facing label for a contract status slug.
	 *
	 * Falls back to a humanized form of the slug so an unknown (newer-engine)
	 * status still renders sensibly rather than as a raw slug.
	 *
	 * @param string $status Contract status slug (see {@see ContractStatus}).
	 */
	public static function contract_label( string $status ): string {
		switch ( $status ) {
			case ContractStatus::ACTIVE:
				return __( 'Active', 'woocommerce-subscriptions-lite' );
			case ContractStatus::ON_HOLD:
				return __( 'On hold', 'woocommerce-subscriptions-lite' );
			case ContractStatus::PENDING_CANCELLATION:
				return __( 'Pending cancellation', 'woocommerce-subscriptions-lite' );
			case ContractStatus::CANCELLED:
				return __( 'Cancelled', 'woocommerce-subscriptions-lite' );
			case ContractStatus::EXPIRED:
				return __( 'Expired', 'woocommerce-subscriptions-lite' );
			default:
				return self::humanize( $status );
		}
	}

	/**
	 * Merchant-facing label for a cycle status slug.
	 *
	 * @param string $status Cycle status slug (see {@see CycleStatus}).
	 */
	public static function cycle_label( string $status ): string {
		switch ( $status ) {
			case CycleStatus::PENDING:
				return __( 'Pending', 'woocommerce-subscriptions-lite' );
			case CycleStatus::BILLED:
				return __( 'Billed', 'woocommerce-subscriptions-lite' );
			case CycleStatus::FAILED:
				return __( 'Failed', 'woocommerce-subscriptions-lite' );
			case CycleStatus::CANCELLED:
				return __( 'Cancelled', 'woocommerce-subscriptions-lite' );
			default:
				return self::humanize( $status );
		}
	}

	/**
	 * Whether a contract in `$status` may be cancelled from the admin UI.
	 *
	 * The facade's cancel is immediate; terminal statuses (cancelled, expired)
	 * have nothing to cancel, so the action is hidden for them. Pure so the list
	 * table and detail renderer agree on when to show the control.
	 *
	 * @param string $status Contract status slug.
	 */
	public static function is_cancellable( string $status ): bool {
		return in_array(
			$status,
			[ ContractStatus::ACTIVE, ContractStatus::ON_HOLD, ContractStatus::PENDING_CANCELLATION ],
			true
		);
	}

	/**
	 * Whether a contract in `$status` may have a renewal run now.
	 *
	 * Renewing a terminal contract is a no-op the facade would skip, so the
	 * action is offered only for non-terminal statuses.
	 *
	 * @param string $status Contract status slug.
	 */
	public static function is_renewable( string $status ): bool {
		return ! in_array( $status, [ ContractStatus::CANCELLED, ContractStatus::EXPIRED ], true );
	}

	/**
	 * Status badge markup for a contract status, mirroring WP-admin's `mark`
	 * order-status badges so the screen reads as native.
	 *
	 * @param string $status Contract status slug.
	 */
	public static function contract_badge_html( string $status ): string {
		return sprintf(
			'<mark class="wc-subs-lite-status-badge wc-subs-lite-status-badge--%1$s"><span>%2$s</span></mark>',
			esc_attr( $status ),
			esc_html( self::contract_label( $status ) )
		);
	}

	/**
	 * Humanize a status slug as a last resort: `pending-cancellation` -> `Pending cancellation`.
	 *
	 * @param string $slug Raw status slug.
	 */
	private static function humanize( string $slug ): string {
		return ucfirst( str_replace( '-', ' ', $slug ) );
	}
}
