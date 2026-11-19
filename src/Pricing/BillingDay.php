<?php
/**
 * BillingDay - the day of the month a contract's month and year renewals land on, kept in
 * contract meta.
 *
 * Contract creation stores it from the start (or a trial in days or weeks, from its end);
 * {@see \Automattic\WooCommerce\SubscriptionsLite\Plans\ContractPlans::get_billing_terms_for()}
 * applies it to the contract's billing terms, so every renewal returns to it in months that
 * have it. A contract without one keeps the day of the date each renewal rolls from.
 * Lite-owned data.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Pricing
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Pricing;

use RuntimeException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLock;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Utilities\Integers;

defined( 'ABSPATH' ) || exit;

/**
 * Store and read a contract's billing day.
 */
final class BillingDay {

	/**
	 * Contract meta key holding the billing day (an integer, 1-31).
	 */
	public const META_KEY = '_wcsl_billing_day';

	/**
	 * Store `$billing_day` for the locked contract.
	 *
	 * @param ContractLock $lock        The caller's lock on the contract.
	 * @param int          $billing_day Day of the month, 1-31.
	 * @throws ContractLockedException If the lock was lost.
	 * @throws RuntimeException If the billing day could not be stored.
	 */
	public static function store( ContractLock $lock, int $billing_day ): void {
		try {
			Contracts::update_meta( $lock->get_contract_id(), Package::EXTENSION_SLUG, self::META_KEY, $billing_day, null, $lock );
		} catch ( ContractLockedException $e ) {
			throw $e;
		} catch ( RuntimeException $e ) {
			throw new RuntimeException( 'The billing day could not be stored.', 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the previous exception is not output.
		}
	}

	/**
	 * The stored billing day, or null when there is none.
	 *
	 * A value that is not an integer from 1 to 31 is logged and treated as absent.
	 *
	 * @param int $contract_id Contract id.
	 */
	public static function read( int $contract_id ): ?int {
		$stored = Contracts::get_meta( $contract_id, self::META_KEY, true );
		if ( '' === $stored || null === $stored ) {
			return null;
		}

		$billing_day = Integers::parse( $stored );
		if ( null !== $billing_day && $billing_day >= 1 && $billing_day <= 31 ) {
			return $billing_day;
		}

		wc_get_logger()->warning(
			sprintf( 'BillingDay: contract %d has a malformed billing day; it is ignored.', $contract_id ),
			[
				'source'      => Package::EXTENSION_SLUG,
				'contract_id' => $contract_id,
			]
		);

		return null;
	}
}
