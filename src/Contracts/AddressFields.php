<?php
/**
 * AddressFields - the address fields Lite carries on a contract.
 *
 * One list for both directions: checkout writes these fields onto the contract, and the
 * customer portal reads the same fields back.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Contracts
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * The contract address field list.
 */
final class AddressFields {

	/**
	 * WooCommerce address fields carried on a contract address.
	 */
	public const FIELDS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ];
}
