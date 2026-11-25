<?php
/**
 * Integration tests for the add-note handler: the nonce and capability gates, the text checks,
 * the unlocked write that a held lock never blocks, and a failed write that keeps the text.
 *
 * The request entry point is driven end to end; its redirect is caught through the
 * `wp_redirect` filter, which throws before the handler's `exit`.
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

	public function test_a_note_on_an_unknown_subscription_reports_an_error(): void {
		$result = $this->add_note( 999999, 'Nobody home.' );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertSame( 'Nobody home.', NoteController::consume_draft( 999999 ) );
	}

	public function test_a_note_on_another_extensions_subscription_records_nothing(): void {
		$contract_id = $this->seed_foreign_contract( $this->create_customer() );
		$this->capture_log();

		$result = $this->add_note( $contract_id, 'Not ours.' );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertSame( [], $this->get_history( $contract_id ) );
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

		try {
			( new NoteController() )->add_note_request();
			$this->fail( 'A user without the capability must be stopped.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'You do not have permission to manage subscriptions.', $e->getMessage() );
			$this->assertSame( [], $this->get_history( $contract_id ) );
		}
	}

	public function test_a_request_records_the_note_flashes_and_redirects_to_the_history_box(): void {
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
		ob_start();
		PageController::render_flash_notice();
		$this->assertStringContainsString( 'notice-success', (string) ob_get_clean() );
	}

	public function test_a_refused_request_flashes_an_error_and_redirects(): void {
		$contract_id = $this->seed_contract();
		$this->set_request( $contract_id, '', wp_create_nonce( PageController::ACTION_ADD_NOTE . '_' . $contract_id ) );

		$this->run_request();

		ob_start();
		PageController::render_flash_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'The note is empty.', $notice );
		$this->assertSame( [], $this->get_history( $contract_id ) );
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
	 * Fill the POST request the form sends.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $text        Note text.
	 * @param string $nonce       Nonce value.
	 */
	private function set_request( int $contract_id, string $text, string $nonce ): void {
		$_POST['contract_id']                = (string) $contract_id;
		$_POST[ NoteController::FIELD_NOTE ] = $text;
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
