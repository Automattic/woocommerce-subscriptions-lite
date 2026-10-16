<?php
/**
 * StatusLabels - labels and badge markup for engine statuses and contract locks.
 *
 * Shared by the admin screens and the customer actions, so statuses and lock conflicts read
 * the same wherever they appear. Pure functions of their inputs, beyond the i18n calls.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Cancellation;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Reactivation;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalProcessor;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\Settlement;

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
	 * Lower-case label for the operation holding a contract's lock, for inline use.
	 * Unknown labels (other extensions) read as a generic "update".
	 *
	 * @param string $operation Lock operation label.
	 */
	public static function get_lock_operation_label( string $operation ): string {
		switch ( $operation ) {
			case 'due': // The engine dispatcher's label: a scheduled renewal.
			case RenewalProcessor::OPERATION:
				return __( 'renewal', 'woocommerce-subscriptions-lite' );
			case Settlement::OPERATION:
				return __( 'payment settlement', 'woocommerce-subscriptions-lite' );
			case ContractCreationHandler::OPERATION:
				return __( 'checkout', 'woocommerce-subscriptions-lite' );
			case Cancellation::OPERATION:
				return __( 'cancellation', 'woocommerce-subscriptions-lite' );
			case Hold::OPERATION:
				return __( 'putting on hold', 'woocommerce-subscriptions-lite' );
			case Reactivation::OPERATION:
				return __( 'reactivation', 'woocommerce-subscriptions-lite' );
			default:
				return __( 'update', 'woocommerce-subscriptions-lite' );
		}
	}

	/**
	 * The one phrasing of a lock conflict: another operation (named when known) is changing
	 * the subscription. Callers add their own retry advice.
	 *
	 * @param int         $contract_id Subscription number.
	 * @param string|null $operation   Operation label of the lock holder, when known.
	 */
	public static function get_lock_conflict_message( int $contract_id, ?string $operation ): string {
		if ( null === $operation ) {
			/* translators: %d: subscription number. */
			return sprintf( __( 'Another change to subscription #%d is in progress.', 'woocommerce-subscriptions-lite' ), $contract_id );
		}

		return sprintf(
			/* translators: 1: subscription number, 2: operation label (e.g. "renewal"). */
			__( 'Another change to subscription #%1$d is in progress (%2$s).', 'woocommerce-subscriptions-lite' ),
			$contract_id,
			self::get_lock_operation_label( $operation )
		);
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
	 * Humanize a slug as a last resort: `pending-cancellation` -> `Pending cancellation`.
	 *
	 * @param string $slug Raw slug.
	 */
	private static function humanize( string $slug ): string {
		return ucfirst( str_replace( [ '-', '_' ], ' ', $slug ) );
	}
}
