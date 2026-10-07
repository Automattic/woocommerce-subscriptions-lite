<?php
/**
 * HoldAnchor - the next-due moment a hold clears, kept in contract meta.
 *
 * {@see Hold} stores it before disarming the contract; {@see Reactivation} resumes from
 * it and {@see Cancellation} ends a held contract at it. Lite-owned data.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
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
	 * @param int         $contract_id      Contract id.
	 * @param string|null $next_payment_gmt The next-due moment the hold clears.
	 * @throws RuntimeException If the anchor could not be stored.
	 */
	public static function store( int $contract_id, ?string $next_payment_gmt ): void {
		try {
			if ( null === $next_payment_gmt ) {
				Contracts::delete_meta( $contract_id, self::META_KEY );
			} else {
				Contracts::update_meta( $contract_id, self::META_KEY, $next_payment_gmt );
			}
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
	 * Remove the anchor after a status write. Best effort: a failure is logged, not thrown,
	 * since a leftover anchor is harmless (only an on-hold contract reads it).
	 *
	 * @param int $contract_id Contract id.
	 */
	public static function clear( int $contract_id ): void {
		try {
			Contracts::delete_meta( $contract_id, self::META_KEY );
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
