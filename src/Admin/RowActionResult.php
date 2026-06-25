<?php
/**
 * RowActionResult - the outcome of an admin subscription action.
 *
 * A small value object carrying a notice type and a message so the row-action
 * handlers' decision logic stays separable from the WordPress request plumbing
 * (capability/nonce reading, redirect, notice queueing). The handlers' pure
 * `handle_*()` methods return one of these; the request entry point maps it to a
 * 403, a flash notice, and a redirect.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of an admin subscription action.
 */
final class RowActionResult {

	/**
	 * Notice type: a successful mutation.
	 */
	const SUCCESS = 'success';

	/**
	 * Notice type: a recoverable failure (not found, engine error).
	 */
	const ERROR = 'error';

	/**
	 * Notice type: a no-op outcome (renewal skipped by the engine).
	 */
	const INFO = 'info';

	/**
	 * Notice type: a capability or nonce refusal. Mapped to a hard 403 by the
	 * request entry point rather than a flash notice.
	 */
	const FORBIDDEN = 'forbidden';

	/**
	 * The notice type (one of the constants above).
	 *
	 * @var string
	 */
	private $type;

	/**
	 * The merchant-facing message.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Use a named constructor ({@see self::success()} etc.).
	 *
	 * @param string $type    One of the type constants.
	 * @param string $message Message text.
	 */
	private function __construct( string $type, string $message ) {
		$this->type    = $type;
		$this->message = $message;
	}

	/**
	 * A success outcome.
	 *
	 * @param string $message Message text.
	 */
	public static function success( string $message ): self {
		return new self( self::SUCCESS, $message );
	}

	/**
	 * A recoverable-failure outcome.
	 *
	 * @param string $message Message text.
	 */
	public static function failed( string $message ): self {
		return new self( self::ERROR, $message );
	}

	/**
	 * A no-op (informational) outcome.
	 *
	 * @param string $message Message text.
	 */
	public static function info( string $message ): self {
		return new self( self::INFO, $message );
	}

	/**
	 * A capability/nonce refusal outcome.
	 *
	 * @param string $message Message text.
	 */
	public static function forbidden( string $message ): self {
		return new self( self::FORBIDDEN, $message );
	}

	/**
	 * The notice type.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * The message text.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Whether this is a success outcome.
	 */
	public function is_success(): bool {
		return self::SUCCESS === $this->type;
	}

	/**
	 * Whether this is a capability/nonce refusal.
	 */
	public function is_forbidden(): bool {
		return self::FORBIDDEN === $this->type;
	}
}
