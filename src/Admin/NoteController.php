<?php
/**
 * NoteController - the admin-post handler behind the "Add note" form in the History box.
 *
 * The request entry point verifies the POST nonce, the decision method checks the capability
 * and the text and records a `note` history entry through {@see History}, and the request entry
 * point flashes the outcome and redirects back to the detail page. The entry is written without
 * a lock, so a renewal holding the subscription's lock never blocks a note.
 *
 * When a note is refused or its write fails, the typed text is kept in a short-lived transient
 * per user and subscription and put back in the form on the next render. A transient, not a
 * query arg: a note can be thousands of characters, too long for a URL, and free text in a URL
 * would end up in browser history and server logs.
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
	 * Bind the admin-post handler. Called once from the bootstrap in an admin context.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . PageController::ACTION_ADD_NOTE, [ new self(), 'add_note_request' ] );
	}

	/**
	 * Request entry point for "Add note": read POST, verify the nonce, run, flash, redirect
	 * back to the History box on the detail page.
	 */
	public function add_note_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() verifies the nonce right after the id is read.
		$contract_id = isset( $_POST['contract_id'] ) ? absint( wp_unslash( $_POST['contract_id'] ) ) : 0;
		check_admin_referer( PageController::ACTION_ADD_NOTE . '_' . $contract_id );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$text = isset( $_POST[ self::FIELD_NOTE ] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST[ self::FIELD_NOTE ] ) ) : '';

		$result = $this->handle_add_note(
			[
				'contract_id' => $contract_id,
				'text'        => $text,
			]
		);
		if ( $result->is_forbidden() ) {
			wp_die( esc_html( $result->message() ), '', [ 'response' => 403 ] );
		}
		PageController::set_flash_notice( $result->type(), $result->message() );

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
	 * Decide and record a note. Guard order: capability, contract id, text, contract, write.
	 * A refused or failed note keeps its text for the form ({@see self::consume_draft()}).
	 *
	 * @param array<string, mixed> $params Request params: `contract_id` and `text` (sanitized).
	 * @return RowActionResult The outcome.
	 */
	public function handle_add_note( array $params ): RowActionResult {
		if ( ! current_user_can( PageController::CAPABILITY ) ) {
			return RowActionResult::forbidden( __( 'You do not have permission to manage subscriptions.', 'woocommerce-subscriptions-lite' ) );
		}

		$contract_id = isset( $params['contract_id'] ) ? (int) $params['contract_id'] : 0;
		$text        = trim( isset( $params['text'] ) ? (string) $params['text'] : '' );
		if ( $contract_id <= 0 ) {
			return RowActionResult::failed( __( 'Subscription not found.', 'woocommerce-subscriptions-lite' ) );
		}

		if ( '' === $text ) {
			return RowActionResult::failed( __( 'The note is empty. Type a note before adding it.', 'woocommerce-subscriptions-lite' ) );
		}

		if ( mb_strlen( $text ) > self::MAX_LENGTH ) {
			$this->keep_draft( $contract_id, $text );
			return RowActionResult::failed(
				sprintf(
					/* translators: %d: maximum number of characters in a note. */
					__( 'The note is too long. Keep it to %d characters or fewer.', 'woocommerce-subscriptions-lite' ),
					self::MAX_LENGTH
				)
			);
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
			$contract = null;
		}
		if ( null === $contract ) {
			$this->keep_draft( $contract_id, $text );
			return RowActionResult::failed( __( 'The note could not be added. Check the WooCommerce logs for details.', 'woocommerce-subscriptions-lite' ) );
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
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return '';
		}

		$key   = self::draft_key( $user_id, $contract_id );
		$draft = get_transient( $key );
		if ( false === $draft ) {
			return '';
		}
		delete_transient( $key );

		return is_string( $draft ) ? $draft : '';
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

		set_transient( self::draft_key( $user_id, $contract_id ), $text, self::DRAFT_TTL );
	}

	/**
	 * Transient key for a user's kept note text on a subscription.
	 *
	 * @param int $user_id     User id.
	 * @param int $contract_id Contract id.
	 */
	private static function draft_key( int $user_id, int $contract_id ): string {
		return 'woocommerce_subscriptions_lite_note_draft_' . $user_id . '_' . $contract_id;
	}
}
