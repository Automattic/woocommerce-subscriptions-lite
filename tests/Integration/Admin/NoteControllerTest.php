<?php
/**
 * Integration tests for the add-note handler: the nonce and capability gates, the text checks,
 * the owner and not-found refusals, the unlocked write that a held lock never blocks, a failed
 * write that keeps the text, and the outcome shown in the History box.
 *
 * The request entry point is driven end to end as a wp-admin request (admin-post.php); its
 * redirect is caught through the `wp_redirect` filter, which throws before the handler's `exit`.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsLite\Admin\DetailRenderer;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\History;
use Automattic\WooCommerce\SubscriptionsLite\Admin\NoteController;
use Automattic\WooCommerce\SubscriptionsLite\Admin\PageController;
use Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionResult;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\History as LifecycleHistory;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalProcessor;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use RuntimeException;
use WPDieException;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\NoteController
 */
final class NoteControllerTest extends LiteIntegrationTestCase {

	/**
	 * The store manager adding notes.
	 *
	 * @var int
	 */
	private $manager_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->manager_id = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		wp_set_current_user( $this->manager_id );
		// admin-post.php is a wp-admin request.
		set_current_screen( 'subscriptions_page_wc-subscriptions-lite' );
	}

	public function tear_down(): void {
		unset( $_POST['contract_id'], $_POST[ NoteController::FIELD_NOTE ], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	public function test_the_handler_is_bound_to_its_admin_post_action(): void {
		NoteController::register();

		$this->assertNotFalse( has_action( 'admin_post_' . PageController::ACTION_ADD_NOTE ) );
	}

	public function test_a_note_is_recorded_with_the_store_manager_as_actor_and_trimmed_text(): void {
		$contract_id = $this->seed_contract( [ 'customer_id' => $this->create_customer() ] );

		$result = $this->add_note( $contract_id, "  Called the customer.\nAll good.  " );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'Note added.', $result->message() );
		$entries = $this->get_history( $contract_id );
		$this->assertCount( 1, $entries );
		$this->assertSame( LifecycleHistory::TYPE_NOTE, $entries[0]->get_type() );
		$this->assertNull( $entries[0]->get_cycle_id() );
		$this->assertSame(
			[
				'actor' => [
					'role'    => LifecycleHistory::ROLE_MERCHANT,
					'user_id' => $this->manager_id,
				],
				'text'  => "Called the customer.\nAll good.",
			],
			$entries[0]->get_payload()
		);
		$this->assertSame( '', NoteController::consume_draft( $contract_id ), 'A recorded note keeps no text.' );
	}

	public function test_a_note_is_recorded_while_a_renewal_holds_the_contract_lock(): void {
		$contract_id = $this->seed_contract();
		$lock        = $this->hold_lock( $contract_id, RenewalProcessor::OPERATION );

		try {
			$result = $this->add_note( $contract_id, 'Added during a renewal.' );
		} finally {
			$lock->release();
		}

		$this->assertTrue( $result->is_success() );
		$this->assertSame( [ LifecycleHistory::TYPE_NOTE ], $this->get_history_types( $contract_id ) );
	}

	public function test_a_user_who_cannot_manage_the_store_is_refused(): void {
		$contract_id = $this->seed_contract();
		wp_set_current_user( $this->create_customer() );

		$result = $this->add_note( $contract_id, 'Not allowed.' );

		$this->assertTrue( $result->is_forbidden() );
		$this->assertSame( [], $this->get_history( $contract_id ) );
	}

	public function test_an_empty_note_is_refused(): void {
		$contract_id = $this->seed_contract();

		$result = $this->add_note( $contract_id, " \n\t " );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertStringContainsString( 'The note is empty.', $result->message() );
		$this->assertSame( [], $this->get_history( $contract_id ) );
	}

	public function test_an_over_long_note_is_refused_whole_and_its_text_kept(): void {
		$contract_id = $this->seed_contract();
		$text        = str_repeat( 'a', NoteController::MAX_LENGTH + 1 );

		$result = $this->add_note( $contract_id, $text );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertStringContainsString( 'The note is too long. Keep it to 2000 characters or fewer.', $result->message() );
		$this->assertSame( [], $this->get_history( $contract_id ) );
		$this->assertSame( $text, NoteController::consume_draft( $contract_id ) );
	}

	public function test_the_length_cap_counts_characters_not_bytes(): void {
		$contract_id = $this->seed_contract();

		$result = $this->add_note( $contract_id, str_repeat( 'é', NoteController::MAX_LENGTH ) );

		$this->assertTrue( $result->is_success() );
	}

	public function test_a_storage_failure_reports_an_error_keeps_the_text_and_records_nothing(): void {
		$contract_id = $this->seed_contract();
		$this->capture_log();
		$this->fail_next_query( self::history_insert() );

		$result = $this->add_note( $contract_id, 'Lost <b>note</b>?' );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertStringContainsString( 'The note could not be added.', $result->message() );
		$this->assertSame( [], $this->get_history( $contract_id ) );
		$this->assertTrue( $this->has_logged( sprintf( 'History: the note entry of contract %d could not be recorded', $contract_id ) ) );

		ob_start();
		History::output( $this->get_contract( $contract_id ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '>Lost &lt;b&gt;note&lt;/b&gt;?</textarea>', $html, 'The form shows the kept text, escaped.' );
		$this->assertSame( '', NoteController::consume_draft( $contract_id ), 'The kept text is shown once.' );
	}

	public function test_a_note_on_an_unknown_subscription_is_not_found_and_keeps_no_text(): void {
		$result = $this->add_note( 999999, str_repeat( 'n', NoteController::MAX_LENGTH + 1 ) );

		$this->assertTrue( $result->is_not_found() );
		$this->assertSame( 'Subscription not found.', $result->message() );
		$this->assertSame( '', NoteController::consume_draft( 999999 ) );
	}

	public function test_a_note_on_another_extensions_subscription_is_refused_up_front(): void {
		$contract_id = $this->seed_foreign_contract( $this->create_customer() );
		$this->capture_log();

		$result = $this->add_note( $contract_id, str_repeat( 'f', NoteController::MAX_LENGTH + 1 ) );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertFalse( $result->is_invalid_input() );
		$this->assertSame( 'This subscription is managed by another extension, so notes cannot be added to it here.', $result->message() );
		$this->assertSame( [], $this->get_history( $contract_id ) );
		$this->assertSame( '', NoteController::consume_draft( $contract_id ), 'A refused foreign note keeps no text.' );
		$this->assertFalse( $this->has_logged( 'could not be recorded' ), 'An expected refusal is not logged.' );
	}

	public function test_a_store_manager_who_is_the_customer_adds_a_note_as_the_merchant(): void {
		$contract_id = $this->seed_contract( [ 'customer_id' => $this->manager_id ] );

		$result = $this->add_note( $contract_id, 'My own subscription.' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame(
			[
				'role'    => LifecycleHistory::ROLE_MERCHANT,
				'user_id' => $this->manager_id,
			],
			$this->get_history( $contract_id )[0]->get_payload()['actor']
		);
	}

	public function test_the_kept_text_is_per_user(): void {
		$contract_id = $this->seed_contract();
		$this->add_note( $contract_id, str_repeat( 'b', NoteController::MAX_LENGTH + 1 ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$this->assertSame( '', NoteController::consume_draft( $contract_id ) );
	}

	public function test_a_request_with_a_bad_nonce_dies_and_records_nothing(): void {
		$contract_id = $this->seed_contract();
		$this->set_request( $contract_id, 'A note.', 'not-a-nonce' );

		try {
			( new NoteController() )->add_note_request();
			$this->fail( 'A bad nonce must stop the request.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( [], $this->get_history( $contract_id ) );
		}
	}

	public function test_a_request_without_the_capability_dies_with_a_403(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->seed_contract();
		wp_set_current_user( $customer_id );
		$this->set_request( $contract_id, 'A note.', wp_create_nonce( PageController::ACTION_ADD_NOTE . '_' . $contract_id ) );
		$response = null;
		add_filter(
			'wp_die_handler',
			static function () use ( &$response ): callable {
				return static function ( $message, $title = '', $args = [] ) use ( &$response ): void {
					$response = is_array( $args ) ? ( $args['response'] ?? null ) : null;
					throw new WPDieException( is_string( $message ) ? $message : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only signal, never output.
				};
			},
			PHP_INT_MAX
		);

		try {
			( new NoteController() )->add_note_request();
			$this->fail( 'A user without the capability must be stopped.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $response );
			$this->assertStringContainsString( 'You do not have permission to manage subscriptions.', $e->getMessage() );
			$this->assertSame( [], $this->get_history( $contract_id ) );
		}
	}

	public function test_a_request_records_the_note_shows_its_outcome_in_the_box_and_redirects_to_it(): void {
		$contract_id = $this->seed_contract();
		$this->set_request( $contract_id, 'Through the form.', wp_create_nonce( PageController::ACTION_ADD_NOTE . '_' . $contract_id ) );

		$location = $this->run_request();

		$this->assertSame(
			PageController::page_url(
				[
					'action' => 'view',
					'id'     => $contract_id,
				]
			) . '#' . DetailRenderer::HISTORY_BOX_ID,
			$location
		);
		$this->assertSame( 'Through the form.', $this->get_history( $contract_id )[0]->get_payload()['text'] );
		$this->assertSame( '', $this->render_flash(), 'The page-top flash is left to the other actions.' );
		$html = $this->render_box( $contract_id );
		$this->assertStringContainsString( '<div id="woocommerce_subscriptions_lite_note_notice" class="notice notice-success inline wc-subs-lite-note-notice"><p>Note added.</p></div>', $html );
		$this->assertStringNotContainsString( 'aria-invalid', $html );
		$this->assertStringStartsWith( '<div id="woocommerce_subscriptions_lite_note_notice"', ltrim( $html ), 'The outcome shows at the top of the box, where the redirect anchor lands.' );
		$this->assertLessThan( strpos( $html, '<table' ), strpos( $html, 'wc-subs-lite-note-notice' ) );
		$this->assertStringNotContainsString( 'wc-subs-lite-note-notice', $this->render_box( $contract_id ), 'The outcome shows once.' );
	}

	public function test_a_refused_request_shows_the_error_in_the_box_and_marks_the_field(): void {
		$contract_id = $this->seed_contract();

		$this->request_note( $contract_id, '' );

		$this->assertSame( '', $this->render_flash() );
		$html = $this->render_box( $contract_id );
		$this->assertStringContainsString( 'class="notice notice-error inline wc-subs-lite-note-notice"><p>The note is empty. Type a note before adding it.</p>', $html );
		$this->assertStringContainsString( 'aria-describedby="woocommerce_subscriptions_lite_note_notice woocommerce_subscriptions_lite_note_description" aria-invalid="true">', $html );
		$this->assertSame( [], $this->get_history( $contract_id ) );
	}

	public function test_a_failed_write_shows_the_error_in_the_box_without_marking_the_field(): void {
		$contract_id = $this->seed_contract();
		$this->capture_log();
		$this->fail_next_query( self::history_insert() );

		$this->request_note( $contract_id, 'Lost note.' );

		$html = $this->render_box( $contract_id );
		$this->assertStringContainsString( 'The note could not be added.', $html );
		$this->assertStringNotContainsString( 'aria-invalid', $html );
		$this->assertStringContainsString( 'aria-describedby="woocommerce_subscriptions_lite_note_description"', $html );
		$this->assertStringContainsString( '>Lost note.</textarea>', $html );
	}

	public function test_a_request_for_a_missing_subscription_goes_to_the_list_with_a_notice(): void {
		$this->set_request( 999999, 'Nobody home.', wp_create_nonce( PageController::ACTION_ADD_NOTE . '_999999' ) );

		$location = $this->run_request();

		$this->assertSame( PageController::page_url(), $location );
		$this->assertStringContainsString( 'Subscription not found.', $this->render_flash() );
		$this->assertNull( NoteController::consume_notice( 999999 ) );
		$this->assertSame( '', NoteController::consume_draft( 999999 ) );
	}

	public function test_a_request_stores_the_text_as_typed(): void {
		$contract_id = $this->seed_contract();
		$text        = 'price < 10; ref %20AB; a<b>c; O\'Brien said "C:\\path"; <Pro plan>';

		$this->request_note( $contract_id, $text );

		$this->assertSame( $text, $this->get_history( $contract_id )[0]->get_payload()['text'] );
	}

	public function test_a_request_keeps_markup_as_text_and_the_box_shows_it_escaped(): void {
		$contract_id = $this->seed_contract();

		$this->request_note( $contract_id, '<script>alert(1)</script><b>x</b>' );

		$this->assertSame( '<script>alert(1)</script><b>x</b>', $this->get_history( $contract_id )[0]->get_payload()['text'] );
		$html = $this->render_box( $contract_id );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;&lt;b&gt;x&lt;/b&gt;', $html );
	}

	public function test_a_request_with_an_array_note_is_refused(): void {
		$contract_id = $this->seed_contract();

		$this->request_note( $contract_id, [ 'a', 'b' ] );

		$this->assertSame( [], $this->get_history( $contract_id ) );
		$notice = NoteController::consume_notice( $contract_id );
		$this->assertSame( 'The note could not be read. Type it as plain text.', $notice['message'] ?? null );
		$this->assertTrue( $notice['invalid'] );
	}

	public function test_a_request_with_invalid_utf8_is_refused_not_stripped(): void {
		$contract_id = $this->seed_contract();

		$this->request_note( $contract_id, "Bad \xC3\x28 bytes" );

		$this->assertSame( [], $this->get_history( $contract_id ) );
		$notice = NoteController::consume_notice( $contract_id );
		$this->assertSame( 'The note contains characters that could not be read. Remove them and try again.', $notice['message'] ?? null );
		$this->assertTrue( $notice['invalid'] );
		$this->assertSame( '', NoteController::consume_draft( $contract_id ) );
	}

	public function test_invalid_utf8_is_refused_whatever_the_site_charset_option(): void {
		$contract_id = $this->seed_contract();
		update_option( 'blog_charset', 'ISO-8859-1' );

		$this->request_note( $contract_id, "Bad \xC3\x28 bytes" );

		$this->assertSame( [], $this->get_history( $contract_id ) );
		$this->assertTrue( NoteController::consume_notice( $contract_id )['invalid'] ?? false );
	}

	public function test_a_request_counts_a_crlf_line_break_as_one_character_and_stores_lf(): void {
		$contract_id = $this->seed_contract();
		// 999 "a" + line break pairs and "bc": 2000 characters with LF, 2999 with CRLF.
		$text = str_repeat( "a\r\n", 999 ) . 'bc';

		$this->request_note( $contract_id, $text );

		$stored = $this->get_history( $contract_id )[0]->get_payload()['text'];
		$this->assertSame( str_repeat( "a\n", 999 ) . 'bc', $stored );
		$this->assertSame( NoteController::MAX_LENGTH, mb_strlen( $stored ) );
	}

	public function test_a_request_trims_before_the_length_cap(): void {
		$contract_id = $this->seed_contract();

		$this->request_note( $contract_id, "  \r\n" . str_repeat( 'a', NoteController::MAX_LENGTH ) . "\r\n  " );

		$this->assertSame( str_repeat( 'a', NoteController::MAX_LENGTH ), $this->get_history( $contract_id )[0]->get_payload()['text'] );
	}

	public function test_a_request_at_the_cap_is_recorded_and_one_over_is_refused_with_its_text_kept(): void {
		$at_cap   = $this->seed_contract();
		$over_cap = $this->seed_contract();
		$too_long = str_repeat( 'z', NoteController::MAX_LENGTH + 1 );

		$this->request_note( $at_cap, str_repeat( 'y', NoteController::MAX_LENGTH ) );
		$this->request_note( $over_cap, $too_long );

		$this->assertCount( 1, $this->get_history( $at_cap ) );
		$this->assertSame( [], $this->get_history( $over_cap ) );
		$notice = NoteController::consume_notice( $over_cap );
		$this->assertSame( 'The note is too long. Keep it to 2000 characters or fewer.', $notice['message'] ?? null );
		$this->assertTrue( $notice['invalid'] );
		$this->assertSame( $too_long, NoteController::consume_draft( $over_cap ) );
	}

	/**
	 * Run the decision method as the current user.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $text        Note text.
	 */
	private function add_note( int $contract_id, string $text ): RowActionResult {
		return ( new NoteController() )->handle_add_note(
			[
				'contract_id' => $contract_id,
				'text'        => $text,
			]
		);
	}

	/**
	 * Post a note through the request entry point with a valid nonce.
	 *
	 * @param int   $contract_id Contract id.
	 * @param mixed $text        Note value as the browser sends it.
	 */
	private function request_note( int $contract_id, $text ): void {
		$this->set_request( $contract_id, $text, wp_create_nonce( PageController::ACTION_ADD_NOTE . '_' . $contract_id ) );
		$this->run_request();
	}

	/**
	 * Render the History box for a contract.
	 *
	 * @param int $contract_id Contract id.
	 */
	private function render_box( int $contract_id ): string {
		$contract = $this->get_contract( $contract_id );

		ob_start();
		History::output( $contract );
		return (string) ob_get_clean();
	}

	/**
	 * Render and clear the page-top flash notice.
	 */
	private function render_flash(): string {
		ob_start();
		PageController::render_flash_notice();
		return (string) ob_get_clean();
	}

	/**
	 * Fill the POST request the form sends, slashed as WordPress slashes request data.
	 *
	 * @param int    $contract_id Contract id.
	 * @param mixed  $text        Note value.
	 * @param string $nonce       Nonce value.
	 */
	private function set_request( int $contract_id, $text, string $nonce ): void {
		$_POST['contract_id']                = (string) $contract_id;
		$_POST[ NoteController::FIELD_NOTE ] = wp_slash( $text );
		$_POST['_wpnonce']                   = $nonce;
		$_REQUEST['_wpnonce']                = $nonce;
	}

	/**
	 * Run the request entry point up to its redirect and return the redirect location.
	 */
	private function run_request(): string {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only signal, never output.
			}
		);

		try {
			( new NoteController() )->add_note_request();
		} catch ( RuntimeException $e ) {
			$this->assertStringStartsWith( 'redirect:', $e->getMessage() );
			return substr( $e->getMessage(), strlen( 'redirect:' ) );
		}

		$this->fail( 'The request must redirect.' );
	}
}
