<?php
/**
 * NoteController - the admin-post handler behind the "Add note" form in the History box.
 *
 * The request entry point verifies the POST nonce, the decision method checks the capability,
 * the text and the subscription and records a `note` history entry through {@see History}, and
 * the request entry point keeps the outcome for the History box and redirects back to it. The
 * entry is written without a lock, so a renewal holding the subscription's lock never blocks a
 * note. A note for a subscription that does not exist goes back to the list with a notice.
 *
 * The text is stored as typed (unslashed, line breaks as LF, trimmed): it is plain text, escaped
 * wherever it is shown. A note that is not a string or not valid UTF-8 is refused, not repaired.
 *
 * The outcome is kept in a short-lived transient per user and subscription, separate from the
 * page's flash notice, so the History box shows it above the form. When a note is refused for
 * its length or its write fails, the typed text is kept the same way and put back in the form.
 * A transient, not a query arg: a note can be thousands of characters, too long for a URL, and
 * free text in a URL would end up in browser history and server logs.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

use Throwable;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\History;
use Automattic\WooCommerce\SubscriptionsLite\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-post handler for merchant notes on a subscription.
 */
final class NoteController {

	/**
	 * Longest note accepted, in characters. A longer note is refused, not truncated.
	 */
	const MAX_LENGTH = 2000;

	/**
	 * POST field carrying the note text.
	 */
	const FIELD_NOTE = 'wcsl_note';

	/**
	 * How long a refused note's text is kept for the form, in seconds.
	 */
	private const DRAFT_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * How long a note's outcome is kept for the History box, in seconds.
	 */
	private const NOTICE_TTL = MINUTE_IN_SECONDS;

	/**
	 * Bind the admin-post handler. Called once from the bootstrap in an admin context.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . PageController::ACTION_ADD_NOTE, [ new self(), 'add_note_request' ] );
	}

	/**
	 * Request entry point for "Add note": read POST, verify the nonce, run, keep the outcome for
	 * the History box and redirect back to it; a missing subscription goes back to the list.
	 */
	public function add_note_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() verifies the nonce right after the id is read.
		$contract_id = isset( $_POST['contract_id'] ) ? absint( wp_unslash( $_POST['contract_id'] ) ) : 0;
		check_admin_referer( PageController::ACTION_ADD_NOTE . '_' . $contract_id );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- plain text stored as typed; handle_add_note() checks it and every output escapes it.
		$text = isset( $_POST[ self::FIELD_NOTE ] ) ? wp_unslash( $_POST[ self::FIELD_NOTE ] ) : '';

		$result = $this->handle_add_note(
			[
				'contract_id' => $contract_id,
				'text'        => $text,
			]
		);
		if ( $result->is_forbidden() ) {
			wp_die( esc_html( $result->message() ), '', [ 'response' => 403 ] );
		}

		if ( $result->is_not_found() ) {
			PageController::set_flash_notice( $result->type(), $result->message() );
			wp_safe_redirect( PageController::page_url() );
			exit;
		}

		self::keep_notice( $contract_id, $result );
		$url = PageController::page_url(
			[
				'action' => 'view',
				'id'     => $contract_id,
			]
		);
		wp_safe_redirect( $url . '#' . DetailRenderer::HISTORY_BOX_ID );
		exit;
	}

	/**
	 * Decide and record a note. Guard order: capability, contract id, text (a string of valid
	 * UTF-8, not empty once line breaks are normalised and it is trimmed), the subscription
	 * (it exists and is Lite's), length, write. A note refused for its length or a failed write
	 * keeps its text for the form ({@see self::consume_draft()}).
	 *
	 * @param array<string, mixed> $params Request params: `contract_id` and `text` (unslashed, unchecked).
	 * @return RowActionResult The outcome.
	 */
	public function handle_add_note( array $params ): RowActionResult {
		if ( ! current_user_can( PageController::CAPABILITY ) ) {
			return RowActionResult::forbidden( __( 'You do not have permission to manage subscriptions.', 'woocommerce-subscriptions-lite' ) );
		}

		$contract_id = isset( $params['contract_id'] ) ? (int) $params['contract_id'] : 0;
		if ( $contract_id <= 0 ) {
			return RowActionResult::not_found( __( 'Subscription not found.', 'woocommerce-subscriptions-lite' ) );
		}

		$text = $params['text'] ?? '';
		if ( ! is_string( $text ) ) {
			return RowActionResult::invalid_input( __( 'The note could not be read. Type it as plain text.', 'woocommerce-subscriptions-lite' ) );
		}

		// Refused, not repaired: stripping the invalid bytes would change the text silently.
		if ( '' !== $text && '' === wp_check_invalid_utf8( $text ) ) {
			return RowActionResult::invalid_input( __( 'The note contains characters that could not be read. Remove them and try again.', 'woocommerce-subscriptions-lite' ) );
		}

		// Browsers post line breaks as CRLF; one line break counts as one character.
		$text = trim( str_replace( [ "\r\n", "\r" ], "\n", $text ) );
		if ( '' === $text ) {
			return RowActionResult::invalid_input( __( 'The note is empty. Type a note before adding it.', 'woocommerce-subscriptions-lite' ) );
		}

		try {
			$contract = Contracts::get( $contract_id );
		} catch ( Throwable $e ) {
			wc_get_logger()->error(
				sprintf( 'Admin subscription note failed for #%1$d: %2$s', $contract_id, $e->getMessage() ),
				[
					'source'      => Package::EXTENSION_SLUG,
					'contract_id' => $contract_id,
					'exception'   => $e,
				]
			);
			$this->keep_draft( $contract_id, $text );
			return RowActionResult::failed( __( 'The note could not be added. Check the WooCommerce logs for details.', 'woocommerce-subscriptions-lite' ) );
		}
		if ( null === $contract ) {
			return RowActionResult::not_found( __( 'Subscription not found.', 'woocommerce-subscriptions-lite' ) );
		}

		if ( Package::EXTENSION_SLUG !== $contract->get_extension_slug() ) {
			return RowActionResult::failed( __( 'This subscription is managed by another extension, so notes cannot be added to it here.', 'woocommerce-subscriptions-lite' ) );
		}

		if ( mb_strlen( $text ) > self::MAX_LENGTH ) {
			$this->keep_draft( $contract_id, $text );
			return RowActionResult::invalid_input(
				sprintf(
					/* translators: %d: maximum number of characters in a note. */
					__( 'The note is too long. Keep it to %d characters or fewer.', 'woocommerce-subscriptions-lite' ),
					self::MAX_LENGTH
				)
			);
		}

		$recorded = History::add( $contract, History::TYPE_NOTE, [ 'payload' => [ 'text' => $text ] ] );
		if ( ! $recorded ) {
			$this->keep_draft( $contract_id, $text );
			return RowActionResult::failed( __( 'The note could not be added. Check the WooCommerce logs for details.', 'woocommerce-subscriptions-lite' ) );
		}

		return RowActionResult::success( __( 'Note added.', 'woocommerce-subscriptions-lite' ) );
	}

	/**
	 * The current user's kept note text for a subscription, cleared once read; '' when none.
	 *
	 * @param int $contract_id Contract id.
	 */
	public static function consume_draft( int $contract_id ): string {
		$draft = self::consume( self::get_draft_key( get_current_user_id(), $contract_id ) );

		return is_string( $draft ) ? $draft : '';
	}

	/**
	 * Whether the current user has a note outcome waiting for a subscription's History box.
	 *
	 * @param int $contract_id Contract id.
	 */
	public static function has_notice( int $contract_id ): bool {
		$user_id = get_current_user_id();

		return $user_id > 0 && false !== get_transient( self::get_notice_key( $user_id, $contract_id ) );
	}

	/**
	 * The current user's note outcome for a subscription, cleared once read; null when none.
	 * `invalid` is true when the text itself was refused, so the form marks the field.
	 *
	 * @param int $contract_id Contract id.
	 * @return array{type: string, message: string, invalid: bool}|null
	 */
	public static function consume_notice( int $contract_id ): ?array {
		$notice = self::consume( self::get_notice_key( get_current_user_id(), $contract_id ) );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return null;
		}

		return [
			'type'    => isset( $notice['type'] ) ? (string) $notice['type'] : RowActionResult::INFO,
			'message' => (string) $notice['message'],
			'invalid' => ! empty( $notice['invalid'] ),
		];
	}

	/**
	 * Keep a note's outcome for the current user's next render of the History box.
	 *
	 * @param int             $contract_id Contract id.
	 * @param RowActionResult $result      The outcome.
	 */
	private static function keep_notice( int $contract_id, RowActionResult $result ): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return;
		}

		set_transient(
			self::get_notice_key( $user_id, $contract_id ),
			[
				'type'    => $result->type(),
				'message' => $result->message(),
				'invalid' => $result->is_invalid_input(),
			],
			self::NOTICE_TTL
		);
	}

	/**
	 * Keep a refused note's text for the current user's next render of the form.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $text        The note text.
	 */
	private function keep_draft( int $contract_id, string $text ): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return;
		}

		set_transient( self::get_draft_key( $user_id, $contract_id ), $text, self::DRAFT_TTL );
	}

	/**
	 * Read a transient of the current user and delete it; false when none or no user.
	 *
	 * @param string $key Transient key.
	 * @return mixed
	 */
	private static function consume( string $key ) {
		if ( get_current_user_id() < 1 ) {
			return false;
		}

		$value = get_transient( $key );
		if ( false !== $value ) {
			delete_transient( $key );
		}

		return $value;
	}

	/**
	 * Transient key for a user's kept note text on a subscription.
	 *
	 * @param int $user_id     User id.
	 * @param int $contract_id Contract id.
	 */
	private static function get_draft_key( int $user_id, int $contract_id ): string {
		return 'woocommerce_subscriptions_lite_note_draft_' . $user_id . '_' . $contract_id;
	}

	/**
	 * Transient key for a user's note outcome on a subscription.
	 *
	 * @param int $user_id     User id.
	 * @param int $contract_id Contract id.
	 */
	private static function get_notice_key( int $user_id, int $contract_id ): string {
		return 'woocommerce_subscriptions_lite_note_notice_' . $user_id . '_' . $contract_id;
	}
}
