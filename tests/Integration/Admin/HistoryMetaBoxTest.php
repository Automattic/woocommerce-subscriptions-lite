<?php
/**
 * Integration tests for the History meta box: entry rows (label, local time, actor, escaped
 * note text), paging with one entry past the page, the empty state and the add-note form.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\History;
use Automattic\WooCommerce\SubscriptionsLite\Admin\PageController;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\History as LifecycleHistory;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\History
 */
final class HistoryMetaBoxTest extends LiteIntegrationTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		unset( $_GET[ History::PAGE_ARG ] );
		parent::tear_down();
	}

	public function test_a_contract_with_no_entries_shows_the_empty_state_and_the_form(): void {
		$contract_id = $this->seed_contract();

		$html = $this->render_box( $contract_id );

		$this->assertStringContainsString( 'Nothing has been recorded for this subscription yet.', $html );
		$this->assertStringNotContainsString( '<table', $html );
		$this->assertStringContainsString( 'name="action" value="' . PageController::ACTION_ADD_NOTE . '"', $html );
		$this->assertStringContainsString( 'name="contract_id" value="' . $contract_id . '"', $html );
		$this->assertStringContainsString( 'name="wcsl_note"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
	}

	public function test_known_types_show_their_label_and_an_unknown_type_its_slug(): void {
		$contract_id = $this->seed_contract();
		$this->add_entry( $contract_id, LifecycleHistory::TYPE_RENEWAL_FAILED );
		$this->add_entry( $contract_id, 'gift-card-applied' );

		$html = $this->render_box( $contract_id );

		$this->assertStringContainsString( 'Renewal failed', $html );
		$this->assertStringContainsString( 'gift-card-applied', $html );
	}

	public function test_note_text_is_escaped_and_keeps_its_line_breaks(): void {
		$contract_id = $this->seed_contract();
		$this->add_entry( $contract_id, LifecycleHistory::TYPE_NOTE, [ 'text' => "<script>alert(1)</script>\nSecond line & more" ] );

		$html = $this->render_box( $contract_id );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;<br />', $html );
		$this->assertStringContainsString( 'Second line &amp; more', $html );
	}

	public function test_a_text_on_an_entry_that_is_not_a_note_is_not_shown(): void {
		$contract_id = $this->seed_contract();
		$this->add_entry( $contract_id, LifecycleHistory::TYPE_HELD, [ 'text' => 'Not a note' ] );

		$this->assertStringNotContainsString( 'Not a note', $this->render_box( $contract_id ) );
	}

	public function test_the_actor_shows_the_customer_the_store_managers_name_or_the_system(): void {
		$contract_id = $this->seed_contract();
		$manager_id  = self::factory()->user->create(
			[
				'role'         => 'shop_manager',
				'display_name' => 'Morgan Manager',
			]
		);
		$this->add_entry( $contract_id, LifecycleHistory::TYPE_HELD, [], LifecycleHistory::ROLE_CUSTOMER, 1 );
		$this->add_entry( $contract_id, LifecycleHistory::TYPE_CANCELLED, [], LifecycleHistory::ROLE_MERCHANT, $manager_id );
		$this->add_entry( $contract_id, LifecycleHistory::TYPE_RENEWAL_PAID, [], LifecycleHistory::ROLE_SYSTEM, null );
		$this->add_entry( $contract_id, LifecycleHistory::TYPE_REACTIVATED, [], LifecycleHistory::ROLE_MERCHANT, 999999 );

		$html = $this->render_box( $contract_id );

		$this->assertStringContainsString( '<td>Customer</td>', $html );
		$this->assertStringContainsString( '<td>Morgan Manager</td>', $html );
		$this->assertStringContainsString( '<td>System</td>', $html );
		$this->assertStringContainsString( '<td>Store manager</td>', $html, 'A deleted store manager falls back to the role.' );
	}

	public function test_entries_show_their_time_in_the_site_time_zone_and_formats(): void {
		$this->set_store_timezone( 'Europe/Berlin' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		$contract_id = $this->seed_contract();
		$this->add_entry( $contract_id, LifecycleHistory::TYPE_HELD );
		$gmt       = Contracts::get_history( $contract_id )[0]->get_date_created_gmt();
		$timestamp = (int) strtotime( $gmt . ' UTC' );

		$html = $this->render_box( $contract_id );

		$local = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( 'Europe/Berlin' ) )->format( 'Y-m-d H:i' );
		$this->assertNotSame( gmdate( 'Y-m-d H:i', $timestamp ), $local );
		$this->assertStringContainsString( '<td>' . $local . '</td>', $html );
	}

	public function test_entries_are_listed_newest_first(): void {
		$contract_id = $this->seed_contract();
		$this->add_entry( $contract_id, LifecycleHistory::TYPE_NOTE, [ 'text' => 'First note' ] );
		$this->add_entry( $contract_id, LifecycleHistory::TYPE_NOTE, [ 'text' => 'Second note' ] );

		$html = $this->render_box( $contract_id );

		$this->assertLessThan( strpos( $html, 'First note' ), strpos( $html, 'Second note' ) );
	}

	public function test_a_full_page_with_no_older_entry_shows_no_paging_links(): void {
		$contract_id = $this->seed_contract();
		$this->add_notes( $contract_id, History::PER_PAGE );

		$html = $this->render_box( $contract_id );

		$this->assertSame( History::PER_PAGE, substr_count( $html, 'class="wc-subs-lite-contract-history-note"' ) );
		$this->assertStringNotContainsString( 'Older entries', $html );
		$this->assertStringNotContainsString( 'Newer entries', $html );
	}

	public function test_a_21st_entry_adds_an_older_link_to_the_next_page(): void {
		$contract_id = $this->seed_contract();
		$this->add_notes( $contract_id, History::PER_PAGE + 1 );

		$html = $this->render_box( $contract_id );

		$this->assertSame( History::PER_PAGE, substr_count( $html, 'class="wc-subs-lite-contract-history-note"' ) );
		$this->assertStringContainsString( '>Note 21<', $html );
		$this->assertStringNotContainsString( '>Note 1<', $html, 'The oldest entry is on the next page.' );
		$this->assertStringContainsString( 'Older entries', $html );
		$this->assertStringContainsString(
			esc_url(
				PageController::page_url(
					[
						'action'          => 'view',
						'id'              => $contract_id,
						History::PAGE_ARG => 2,
					]
				)
			),
			$html
		);
		$this->assertStringNotContainsString( 'Newer entries', $html );
	}

	public function test_the_next_page_starts_after_the_first_page(): void {
		$contract_id = $this->seed_contract();
		$this->add_notes( $contract_id, History::PER_PAGE + 1 );
		$_GET[ History::PAGE_ARG ] = '2';

		$html = $this->render_box( $contract_id );

		$this->assertSame( 1, substr_count( $html, 'class="wc-subs-lite-contract-history-note"' ) );
		$this->assertStringContainsString( '>Note 1<', $html );
		$this->assertStringNotContainsString( 'Older entries', $html );
		$this->assertStringContainsString( 'Newer entries', $html );
		$this->assertStringNotContainsString( History::PAGE_ARG . '=1', $html, 'The newest page carries no paging arg.' );
	}

	public function test_an_invalid_page_shows_the_first_page(): void {
		$contract_id = $this->seed_contract();
		$this->add_notes( $contract_id, 1 );
		$_GET[ History::PAGE_ARG ] = 'zero';

		$this->assertStringContainsString( '>Note 1<', $this->render_box( $contract_id ) );
	}

	public function test_a_negative_page_shows_the_first_page(): void {
		$contract_id = $this->seed_contract();
		$this->add_notes( $contract_id, History::PER_PAGE + 1 );
		$_GET[ History::PAGE_ARG ] = '-3';

		$html = $this->render_box( $contract_id );

		$this->assertStringContainsString( '>Note 21<', $html );
		$this->assertStringNotContainsString( 'Newer entries', $html );
	}

	public function test_a_huge_page_shows_no_older_entries_without_a_read_error(): void {
		$contract_id = $this->seed_contract();
		$this->add_notes( $contract_id, 1 );
		$this->capture_log();
		$_GET[ History::PAGE_ARG ] = '99999999999999999999';

		$html = $this->render_box( $contract_id );

		$this->assertStringContainsString( 'No older entries.', $html );
		$this->assertStringNotContainsString( 'History could not be loaded.', $html );
		$this->assertFalse( $this->has_logged( 'Admin subscription history entries could not be loaded' ) );
	}

	public function test_a_page_past_the_end_links_back_to_the_newest_entries(): void {
		$contract_id = $this->seed_contract();
		$this->add_notes( $contract_id, 1 );
		$_GET[ History::PAGE_ARG ] = '5';

		$html = $this->render_box( $contract_id );

		$this->assertStringContainsString( 'No older entries.', $html );
		$this->assertStringNotContainsString( 'Newer entries', $html );
		$this->assertStringContainsString( 'href="' . esc_url( $this->page_url( $contract_id, 1 ) ) . '">Newest entries</a>', $html );
	}

	public function test_a_middle_page_links_to_the_newer_and_the_older_page(): void {
		$contract_id = $this->seed_contract();
		$this->add_notes( $contract_id, 2 * History::PER_PAGE + 1 );
		$_GET[ History::PAGE_ARG ] = '2';

		$html = $this->render_box( $contract_id );

		$this->assertSame( History::PER_PAGE, substr_count( $html, 'class="wc-subs-lite-contract-history-note"' ) );
		$this->assertStringContainsString( '>Note 21<', $html );
		$this->assertStringContainsString( '>Note 2<', $html );
		$this->assertStringNotContainsString( '>Note 22<', $html );
		$this->assertStringNotContainsString( '>Note 1<', $html );
		$this->assertStringContainsString( 'href="' . esc_url( $this->page_url( $contract_id, 1 ) ) . '">Newer entries</a>', $html );
		$this->assertStringContainsString( 'href="' . esc_url( $this->page_url( $contract_id, 3 ) ) . '">Older entries</a>', $html );
		$this->assertStringContainsString( '#wc-subs-lite-contract-history', $this->page_url( $contract_id, 1 ) );
	}

	public function test_another_extensions_contract_shows_raw_entries_and_no_form(): void {
		$contract_id = $this->seed_foreign_contract( $this->create_customer() );
		$manager_id  = self::factory()->user->create(
			[
				'role'         => 'shop_manager',
				'display_name' => 'Morgan Manager',
			]
		);
		$entry       = Contracts::add_history_entry(
			$contract_id,
			[
				'extension_slug' => self::FOREIGN_EXTENSION_SLUG,
				'type'           => LifecycleHistory::TYPE_NOTE,
				'payload'        => [
					'actor' => [
						'role'    => LifecycleHistory::ROLE_MERCHANT,
						'user_id' => $manager_id,
					],
					'text'  => 'Foreign text',
				],
			]
		);
		$this->assertNotNull( $entry );

		$html = $this->render_box( $contract_id );

		$this->assertMatchesRegularExpression( '#<td>\s*' . LifecycleHistory::TYPE_NOTE . '\s*</td>#', $html, 'The type shows its slug.' );
		$this->assertStringNotContainsString( '>Note<', $html );
		$this->assertStringNotContainsString( 'Foreign text', $html );
		$this->assertStringNotContainsString( 'Morgan Manager', $html );
		$this->assertStringContainsString( '<td>-</td>', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_dates_use_woocommerces_date_and_time_formats(): void {
		$contract_id = $this->seed_contract();
		$this->add_entry( $contract_id, LifecycleHistory::TYPE_HELD );
		add_filter(
			'woocommerce_date_format',
			static function (): string {
				return '\W\C\D';
			}
		);
		add_filter(
			'woocommerce_time_format',
			static function (): string {
				return '\W\C\T';
			}
		);

		$this->assertStringContainsString( '<td>WCD WCT</td>', $this->render_box( $contract_id ) );
	}

	public function test_a_failed_read_shows_a_notice_logs_and_keeps_the_form(): void {
		$contract_id = $this->seed_contract();
		$this->capture_log();
		$contract = $this->get_contract( $contract_id );
		$this->fail_next_query( '/FROM ' . preg_quote( SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACT_HISTORY ), '/' ) . ' /' );

		ob_start();
		History::output( $contract );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'History could not be loaded.', $html );
		$this->assertStringContainsString( 'name="wcsl_note"', $html );
		$this->assertSame( [ Package::EXTENSION_SLUG ], $this->logged_sources_of( 'Admin subscription history entries could not be loaded for #' . $contract_id ) );
	}

	/**
	 * The detail-page URL of a history page, anchored on the box.
	 *
	 * @param int $contract_id Contract id.
	 * @param int $page        1-based page.
	 */
	private function page_url( int $contract_id, int $page ): string {
		$args = [
			'action' => 'view',
			'id'     => $contract_id,
		];
		if ( $page > 1 ) {
			$args[ History::PAGE_ARG ] = $page;
		}

		return PageController::page_url( $args ) . '#wc-subs-lite-contract-history';
	}

	/**
	 * Render the box for a contract.
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
	 * Add `$count` notes, "Note 1" (oldest) to "Note {$count}".
	 *
	 * @param int $contract_id Contract id.
	 * @param int $count       Number of notes.
	 */
	private function add_notes( int $contract_id, int $count ): void {
		for ( $i = 1; $i <= $count; $i++ ) {
			$this->add_entry( $contract_id, LifecycleHistory::TYPE_NOTE, [ 'text' => 'Note ' . $i ] );
		}
	}

	/**
	 * Add a Lite history entry straight through the engine facade.
	 *
	 * @param int                  $contract_id Contract id.
	 * @param string               $type        Entry type.
	 * @param array<string, mixed> $payload     Payload beyond the actor.
	 * @param string               $role        Actor role.
	 * @param int|null             $user_id     Actor user id.
	 */
	private function add_entry( int $contract_id, string $type, array $payload = [], string $role = LifecycleHistory::ROLE_SYSTEM, ?int $user_id = null ): void {
		$entry = Contracts::add_history_entry(
			$contract_id,
			[
				'extension_slug' => Package::EXTENSION_SLUG,
				'type'           => $type,
				'payload'        => [
					'actor' => [
						'role'    => $role,
						'user_id' => $user_id,
					],
				] + $payload,
			]
		);
		$this->assertNotNull( $entry );
	}
}
