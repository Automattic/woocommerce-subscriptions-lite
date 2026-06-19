<?php
/**
 * CancelResult - the outcome of a portal cancel request.
 *
 * A small value object carrying one of the {@see self} outcome codes so the
 * cancel handler's decision logic stays separable from the WordPress request
 * plumbing (nonce reading, redirect, notice queueing). The handler's pure
 * `handle()` returns one of these; the request entry point maps it to a notice
 * and a redirect.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Portal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Portal;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of a cancel request.
 */
final class CancelResult {

	/**
	 * The contract was cancelled.
	 */
	const CANCELLED = 'cancelled';

	/**
	 * No logged-in customer made the request.
	 */
	const NOT_AUTHENTICATED = 'not_authenticated';

	/**
	 * The nonce was missing or invalid.
	 */
	const BAD_NONCE = 'bad_nonce';

	/**
	 * The contract does not exist, or belongs to another customer. The two are
	 * deliberately collapsed so the portal does not leak the existence of a
	 * contract the requester does not own.
	 */
	const NOT_FOUND = 'not_found';

	/**
	 * The contract exists and is owned by the requester but is not in a
	 * cancelable status (already cancelled / expired / pending-cancellation).
	 */
	const NOT_CANCELABLE = 'not_cancelable';

	/**
	 * The outcome code (one of the constants above).
	 *
	 * @var string
	 */
	private $code;

	/**
	 * Build a result carrying one outcome code.
	 *
	 * @param string $code One of the outcome constants.
	 */
	public function __construct( string $code ) {
		$this->code = $code;
	}

	/**
	 * The outcome code.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Whether the request resulted in a cancellation.
	 */
	public function is_success(): bool {
		return self::CANCELLED === $this->code;
	}
}
