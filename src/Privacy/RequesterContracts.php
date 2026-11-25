<?php
/**
 * RequesterContracts - the Lite contracts of the person a privacy request is for.
 *
 * Found through the WordPress account with the request's email: every Lite contract of that
 * customer, in every status. A customer has few contracts, so the whole set is read on every
 * call, which lets the exporter and the eraser page without keeping state.
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
	 * The Lite contracts of the account with `$email`, oldest first; empty when no account
	 * has that email. The views carry no items or addresses.
	 *
	 * @param string $email The request's email address.
	 * @return array<int, ContractView>
	 * @throws \RuntimeException If an engine read fails.
	 */
	public static function get_for_email( string $email ): array {
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

		ksort( $contracts );

		return array_values( $contracts );
	}
}
