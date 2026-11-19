<?php
/**
 * BillingDate - a renewal date and the billing day it re-anchored the schedule to, if any.
 *
 * {@see BillingTerms} returns one for each date it rolls to. A due date off the billing-day
 * schedule (after a plan cadence or store time zone change) re-anchors: later month and year
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
	 * Build a renewal date.
	 *
	 * @param DateTimeImmutable $moment                 The date, in UTC.
	 * @param int|null          $reanchored_billing_day The billing day the schedule was re-anchored to, or null.
	 */
	public function __construct( DateTimeImmutable $moment, ?int $reanchored_billing_day ) {
		$this->moment                 = $moment;
		$this->reanchored_billing_day = $reanchored_billing_day;
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
}
