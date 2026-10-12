<?php
/**
 * StatusLabels - merchant-facing labels and badge markup for engine statuses.
 *
 * A small helper shared by the admin list table and the detail renderer so contract
 * and cycle statuses read the same wherever they appear. The labels and badges are
 * pure functions of their inputs (no globals, no time, no WordPress calls beyond the
 * i18n pass-throughs); {@see self::is_renewable()} also reads the contract's newest cycle.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\UnpaidRenewal;

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
			case ContractStatus::DRAFT:
				return __( 'Draft', 'woocommerce-subscriptions-lite' );
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
	 * Whether Renew now is offered for a contract: a Lite contract that is active (bills
	 * its next period now), or on hold with an unpaid renewal (retries that renewal).
	 *
	 * @param ContractView $contract The contract.
	 */
	public static function is_renewable( ContractView $contract ): bool {
		if ( Package::EXTENSION_SLUG !== $contract->get_extension_slug() ) {
			return false;
		}

		$status = $contract->get_status();

		return ContractStatus::ACTIVE === $status
			|| ( ContractStatus::ON_HOLD === $status && null !== UnpaidRenewal::find( $contract ) );
	}

	/**
	 * Status badge markup for a contract status.
	 *
	 * Reuses WooCommerce's `mark.order-status` badge chrome (the flex/border/
	 * radius/grey base and the `mark` highlight reset, loaded as a dependency of
	 * the admin stylesheet) so the badge reads as native; the Lite stylesheet
	 * only layers the subscription-status colours on top.
	 *
	 * @param string $status Contract status slug.
	 */
	public static function contract_badge_html( string $status ): string {
		return sprintf(
			'<mark class="order-status wc-subs-lite-status-badge wc-subs-lite-status-badge--%1$s"><span>%2$s</span></mark>',
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
