<?php
/**
 * BillingDate - a renewal date and the billing day it re-anchored the schedule to, if any.
 *
 * {@see BillingTerms} returns one for each date it rolls to. A due date more than a day off
 * the billing-day schedule (after a plan cadence change) re-anchors: later month and year
 * steps keep that date's own store-calendar day, which the caller stores with
 * {@see BillingDay::store_reanchored_day()} under the lock it writes the date with.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Pricing
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Pricing;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable renewal date.
 */
final class BillingDate {

	/**
	 * The date, in UTC.
	 *
	 * @var DateTimeImmutable
	 */
	private $moment;

	/**
	 * The billing day the schedule was re-anchored to, or null when the stored one stands.
	 *
	 * @var int|null
	 */
	private $reanchored_billing_day;

	/**
	 * The billing day a re-anchor replaces, or null.
	 *
	 * @var int|null
	 */
	private $replaced_billing_day;

	/**
	 * Build a renewal date.
	 *
	 * @param DateTimeImmutable $moment                 The date, in UTC.
	 * @param int|null          $reanchored_billing_day The billing day the schedule was re-anchored to, or null.
	 * @param int|null          $replaced_billing_day   The billing day that re-anchor replaces, or null.
	 */
	public function __construct( DateTimeImmutable $moment, ?int $reanchored_billing_day, ?int $replaced_billing_day = null ) {
		$this->moment                 = $moment;
		$this->reanchored_billing_day = $reanchored_billing_day;
		$this->replaced_billing_day   = $replaced_billing_day;
	}

	/**
	 * The date, in UTC.
	 */
	public function get_moment(): DateTimeImmutable {
		return $this->moment;
	}

	/**
	 * The billing day later month and year steps keep when this date re-anchored the schedule;
	 * null when the stored billing day stands.
	 */
	public function get_reanchored_billing_day(): ?int {
		return $this->reanchored_billing_day;
	}

	/**
	 * The billing day a re-anchor replaces; null when there was no re-anchor.
	 */
	public function get_replaced_billing_day(): ?int {
		return $this->replaced_billing_day;
	}
}
