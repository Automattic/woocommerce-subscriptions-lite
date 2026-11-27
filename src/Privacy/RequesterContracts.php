<?php
/**
 * RequesterContracts - the Lite contracts of the person a privacy request is for.
 *
 * Two reads, unioned by contract id: every Lite contract of the WordPress account with the
 * request's email, and every Lite contract with a billing or shipping address email equal to
 * it. The second finds guest contracts, contracts whose account was deleted and contracts of
 * another account that carry this email. Every status. A customer has few contracts, so the
 * whole set is read on every call, which lets the exporter and the eraser page without keeping
 * state; an erased contract has no customer and an anonymised email, so neither read finds it.
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
	 * The Lite contracts of the account with `$email` and those with an address email equal to
	 * it, each once, oldest first. The views carry no items or addresses.
	 *
	 * @param string $email The request's email address.
	 * @return array<int, ContractView>
	 * @throws \RuntimeException If an engine read fails.
	 */
	public static function get_for_email( string $email ): array {
		$contracts = self::get_account_contracts( $email ) + self::get_address_email_contracts( $email );

		ksort( $contracts );

		return array_values( $contracts );
	}

	/**
	 * The Lite contracts of the account with `$email`, by id; empty when no account has it.
	 *
	 * @param string $email The request's email address.
	 * @return array<int, ContractView>
	 */
	private static function get_account_contracts( string $email ): array {
		$user = get_user_by( 'email', $email );
		if ( false === $user ) {
			return [];
		}

		$contracts = [];
		$offset    = 0;
		do {
			$page = Contracts::list_for_customer( $user->ID, self::READ_SIZE, $offset, [ 'extension_slug' => Package::EXTENSION_SLUG ] );
			foreach ( $page as $contract ) {
				$contracts[ $contract->get_id() ] = $contract;
			}
			$offset   += self::READ_SIZE;
			$full_page = count( $page ) === self::READ_SIZE;
		} while ( $full_page );

		return $contracts;
	}

	/**
	 * The Lite contracts with a billing or shipping address email equal to `$email`, by id.
	 * The engine matches case-insensitively under the addresses table's collation.
	 *
	 * @param string $email The request's email address.
	 * @return array<int, ContractView>
	 */
	private static function get_address_email_contracts( string $email ): array {
		$contracts = [];
		$offset    = 0;
		do {
			$page = Contracts::list_for_address_email( $email, self::READ_SIZE, $offset, [ 'extension_slug' => Package::EXTENSION_SLUG ] );
			foreach ( $page as $contract ) {
				$contracts[ $contract->get_id() ] = $contract;
			}
			$offset   += self::READ_SIZE;
			$full_page = count( $page ) === self::READ_SIZE;
		} while ( $full_page );

		return $contracts;
	}
}
