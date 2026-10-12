<?php
/**
 * SystemHold - the hold Lite places on a contract while a renewal waits to be paid.
 *
 * A failed or unbuildable renewal, a dead renewal order and a manual renewal order all put
 * the contract on hold with no due moment, and drop any {@see HoldAnchor}: an anchor marks a
 * customer hold, which a payment would leave in place, while this hold ends when the unpaid
 * renewal is paid.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Place the system hold.
 */
final class SystemHold {

	/**
	 * Put the contract on hold with no due moment and drop its hold anchor. The anchor goes
	 * first, so a reader that sees the contract on hold never finds a stale one.
	 *
	 * @param int                  $contract_id Contract id.
	 * @param array<string, mixed> $fields      Further contract fields to write with the hold.
	 */
	public static function place( int $contract_id, array $fields = [] ): void {
		HoldAnchor::clear( $contract_id );
		Contracts::update(
			$contract_id,
			[
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => null,
			] + $fields
		);
	}
}
