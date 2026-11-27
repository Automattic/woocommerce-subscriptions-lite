<?php
/**
 * RequesterContracts - the Lite contracts of the person a privacy request is for.
 *
 * Two reads, unioned by contract id: every Lite contract of the WordPress account with the
 * request's email, and every Lite contract whose billing email is that email, as WooCommerce
 * Subscriptions matches. The second finds guest contracts, contracts whose account was deleted
 * and contracts of another account billed to this email. Every status. A customer has few
 * contracts, so the whole set is read on every call, which lets the exporter and the eraser
 * page without keeping state; an erased contract has no customer and an anonymised email, so
 * neither read finds it.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Privacy
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Privacy;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsLite\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Contract lookup for privacy requests.
 */
final class RequesterContracts {

	/**
	 * Contracts read per engine call.
	 */
	private const READ_SIZE = 50;

	/**
	 * The id of the WordPress account with the request's email, or 0 when there is none.
	 *
	 * @param string $email The request's email address.
	 */
	public static function get_account_id( string $email ): int {
		$user = get_user_by( 'email', $email );

		return false === $user ? 0 : $user->ID;
	}

	/**
	 * The Lite contracts of the requester's account and those billed to `$email`, each once,
	 * oldest first. The views carry no items or addresses.
	 *
	 * @param string $email      The request's email address.
	 * @param int    $account_id The requester's account id from {@see self::get_account_id()}, or 0.
	 * @return array<int, ContractView>
	 * @throws \RuntimeException If an engine read fails.
	 */
	public static function get_for_email( string $email, int $account_id ): array {
		$contracts = self::get_account_contracts( $account_id ) + self::get_billing_email_contracts( $email );

		ksort( $contracts );

		return array_values( $contracts );
	}

	/**
	 * The Lite contracts of the account, by id; empty without an account.
	 *
	 * @param int $account_id Account id, or 0.
	 * @return array<int, ContractView>
	 */
	private static function get_account_contracts( int $account_id ): array {
		if ( $account_id <= 0 ) {
			return [];
		}

		$contracts = [];
		$offset    = 0;
		do {
			$page = Contracts::list_for_customer( $account_id, self::READ_SIZE, $offset, [ 'extension_slug' => Package::EXTENSION_SLUG ] );
			foreach ( $page as $contract ) {
				$contracts[ $contract->get_id() ] = $contract;
			}
			$offset   += self::READ_SIZE;
			$full_page = count( $page ) === self::READ_SIZE;
		} while ( $full_page );

		return $contracts;
	}

	/**
	 * The Lite contracts whose billing email is `$email`, by id. The engine read matches a
	 * billing or shipping email; each candidate is re-read to keep billing matches only. The
	 * engine pages over its own matches, so the filter never skips one.
	 *
	 * @param string $email The request's email address.
	 * @return array<int, ContractView>
	 */
	private static function get_billing_email_contracts( string $email ): array {
		$contracts = [];
		$offset    = 0;
		do {
			$page = Contracts::list_for_address_email( $email, self::READ_SIZE, $offset, [ 'extension_slug' => Package::EXTENSION_SLUG ] );
			foreach ( $page as $candidate ) {
				$contract  = Contracts::get( $candidate->get_id() );
				$addresses = null === $contract ? [] : ( $contract->get_addresses() ?? [] );
				if ( self::is_same_email( (string) ( $addresses['billing']['email'] ?? '' ), $email ) ) {
					$contracts[ $candidate->get_id() ] = $candidate;
				}
			}
			$offset   += self::READ_SIZE;
			$full_page = count( $page ) === self::READ_SIZE;
		} while ( $full_page );

		return $contracts;
	}

	/**
	 * Whether two emails match as the engine read matches them: trimmed, and without regard to
	 * case or accents, like the default database collation.
	 *
	 * @param string $stored    The stored email.
	 * @param string $requested The request's email.
	 */
	private static function is_same_email( string $stored, string $requested ): bool {
		$stored = trim( $stored );

		return '' !== $stored && strtolower( remove_accents( $stored ) ) === strtolower( remove_accents( trim( $requested ) ) );
	}
}
