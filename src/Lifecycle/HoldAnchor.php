<?php
/**
 * HoldAnchor - the next-due moment a hold clears, kept in contract meta.
 *
 * {@see Hold} stores it before disarming the contract; {@see Reactivation} resumes from
 * it and {@see Cancellation} ends a held contract at it. Its presence marks a customer hold:
 * a renewal paid during the hold moves it to the next due moment and keeps the contract on
 * hold. Lite's own holds for an unpaid renewal ({@see Hold::system_hold()}) drop it. Lite-owned data.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLock;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Store, read and clear a contract's hold anchor.
 */
final class HoldAnchor {

	/**
	 * Contract meta key holding the anchor (a GMT `Y-m-d H:i:s` string).
	 */
	public const META_KEY = '_wcsl_hold_next_payment_gmt';

	private const LOG_SOURCE = 'woocommerce-subscriptions-lite';

	/**
	 * Store `$next_payment_gmt` as the anchor, or remove the anchor when it is null.
	 *
	 * @param ContractLock $lock             The caller's lock on the contract.
	 * @param string|null  $next_payment_gmt The next-due moment the hold clears.
	 * @throws ContractLockedException If the lock was lost.
	 * @throws RuntimeException If the anchor could not be stored.
	 */
	public static function store( ContractLock $lock, ?string $next_payment_gmt ): void {
		$contract_id = $lock->get_contract_id();
		try {
			if ( null === $next_payment_gmt ) {
				Contracts::delete_meta( $contract_id, self::META_KEY, null, $lock );
			} else {
				Contracts::update_meta( $contract_id, self::META_KEY, $next_payment_gmt, null, $lock );
			}
		} catch ( ContractLockedException $e ) {
			throw $e;
		} catch ( RuntimeException $e ) {
			throw new RuntimeException( 'The hold anchor could not be stored.', 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the previous exception is not output.
		}
	}

	/**
	 * The stored anchor, or null when there is none.
	 *
	 * A value that is not a well-formed GMT `Y-m-d H:i:s` datetime is logged and treated as absent.
	 *
	 * @param int $contract_id Contract id.
	 */
	public static function read( int $contract_id ): ?string {
		$anchor = Contracts::get_meta( $contract_id, self::META_KEY, true );
		if ( '' === $anchor || null === $anchor ) {
			return null;
		}

		if ( is_string( $anchor ) ) {
			$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $anchor, new DateTimeZone( 'UTC' ) );
			if ( false !== $parsed && $parsed->format( 'Y-m-d H:i:s' ) === $anchor ) {
				return $anchor;
			}
		}

		wc_get_logger()->warning(
			sprintf( 'HoldAnchor: contract %d has a malformed hold anchor; it is ignored.', $contract_id ),
			[
				'source'      => self::LOG_SOURCE,
				'contract_id' => $contract_id,
			]
		);

		return null;
	}

	/**
	 * Remove the anchor. Best effort: a failure (a lost lock included) is logged, not thrown.
	 * A leftover anchor is not harmless: on a held contract it reads as a customer hold, so a
	 * renewal paid later keeps the contract on hold instead of resuming it; the log is how
	 * that is traced.
	 *
	 * @param ContractLock $lock The caller's lock on the contract.
	 */
	public static function clear( ContractLock $lock ): void {
		$contract_id = $lock->get_contract_id();
		try {
			Contracts::delete_meta( $contract_id, self::META_KEY, null, $lock );
		} catch ( RuntimeException $e ) {
			wc_get_logger()->warning(
				sprintf( 'HoldAnchor: the hold anchor of contract %d could not be cleared: %s', $contract_id, $e->getMessage() ),
				[
					'source'      => self::LOG_SOURCE,
					'contract_id' => $contract_id,
				]
			);
		}
	}
}
