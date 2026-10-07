<?php
/**
 * LifecycleNotAllowed - a lifecycle operation the contract's current status does not allow.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle;

use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a lifecycle operation's status precondition is not met, so callers can
 * tell it apart from a missing contract (a null result) and from storage failures.
 */
final class LifecycleNotAllowed extends DomainException {
}
