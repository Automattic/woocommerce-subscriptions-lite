<?php
/**
 * History meta box - what happened to the subscription, and the "Add note" form.
 *
 * Lists the contract's history entries newest first, a page at a time, read through the engine
 * facade: what happened, when (site time zone), by whom, and a note's text. Lite's labels, note
 * text and actor format apply only to a Lite-owned contract; another owner's entries show their
 * type slug. On a Lite-owned contract, a form below the list posts a merchant note to
 * {@see NoteController}, and the note's outcome shows at the top of the box. Degrades to a notice when
 * the read fails.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes;

use Throwable;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\HistoryEntryView;
use Automattic\WooCommerce\SubscriptionsLite\Admin\DetailRenderer;
use Automattic\WooCommerce\SubscriptionsLite\Admin\NoteController;
use Automattic\WooCommerce\SubscriptionsLite\Admin\PageController;
use Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionResult;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\History as LifecycleHistory;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Utilities\Formatter;

defined( 'ABSPATH' ) || exit;

/**
 * "History" meta box.
 */
final class History {

	/**
	 * Entries shown per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Detail-page query arg holding the 1-based history page.
	 */
	const PAGE_ARG = 'history_page';

	/**
	 * Element id of the add-note textarea.
	 */
	private const FIELD_ID = 'woocommerce_subscriptions_lite_note';

	/**
	 * Render the box body.
	 *
	 * @param ContractView $contract The contract being viewed.
	 */
	public static function output( ContractView $contract ): void {
		$contract_id = (int) $contract->get_id();
		$is_lite     = Package::EXTENSION_SLUG === $contract->get_extension_slug();
		$page        = self::get_current_page();

		// The outcome of a note just submitted sits at the top, where the redirect anchor lands.
		$notice = NoteController::consume_notice( $contract_id );
		if ( null !== $notice ) {
			self::render_note_notice( $notice );
		}

		try {
			// One entry past the page tells whether an older page exists.
			$entries = Contracts::get_history(
				$contract_id,
				[
					'limit'  => self::PER_PAGE + 1,
					'offset' => ( $page - 1 ) * self::PER_PAGE,
				]
			);
		} catch ( Throwable $e ) {
			wc_get_logger()->error(
				'Admin subscription history entries could not be loaded for #' . $contract_id . ': ' . $e->getMessage(),
				[
					'source'      => Package::EXTENSION_SLUG,
					'contract_id' => $contract_id,
					'exception'   => $e,
				]
			);
			$entries = null;
		}

		if ( null === $entries ) {
			echo '<p>' . esc_html__( 'History could not be loaded. Check the WooCommerce logs for details.', 'woocommerce-subscriptions-lite' ) . '</p>';
		} elseif ( [] === $entries && 1 === $page ) {
			echo '<p>' . esc_html__( 'Nothing has been recorded for this subscription yet.', 'woocommerce-subscriptions-lite' ) . '</p>';
		} elseif ( [] === $entries ) {
			echo '<p>' . esc_html__( 'No older entries.', 'woocommerce-subscriptions-lite' ) . '</p>';
			self::render_first_page_link( $contract_id );
		} else {
			$has_older = count( $entries ) > self::PER_PAGE;
			self::render_table( array_slice( $entries, 0, self::PER_PAGE ), $is_lite );
			self::render_pagination( $contract_id, $page, $has_older );
		}

		if ( $is_lite ) {
			self::render_note_form( $contract_id, null !== $notice && $notice['invalid'] );
		}
	}

	/**
	 * The 1-based history page from the request: 1 when absent, invalid or below 1, and capped so
	 * the read's offset stays an integer.
	 */
	private static function get_current_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging.
		$page = isset( $_GET[ self::PAGE_ARG ] ) ? intval( wp_unslash( $_GET[ self::PAGE_ARG ] ) ) : 1;

		return min( max( 1, $page ), intdiv( PHP_INT_MAX, self::PER_PAGE ) );
	}

	/**
	 * Render the entries table.
	 *
	 * @param array<int, HistoryEntryView> $entries Entries newest first.
	 * @param bool                         $is_lite Whether the contract is Lite's, so its entries use Lite's format.
	 */
	private static function render_table( array $entries, bool $is_lite ): void {
		if ( $is_lite ) {
			self::prime_merchant_users( $entries );
		}
		?>
		<table class="widefat striped wc-subs-lite-contract-history-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'woocommerce-subscriptions-lite' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event', 'woocommerce-subscriptions-lite' ); ?></th>
					<th scope="col"><?php esc_html_e( 'By', 'woocommerce-subscriptions-lite' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php self::render_row( $entry, $is_lite ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Load the store managers named on the page's entries in one query.
	 *
	 * @param array<int, HistoryEntryView> $entries Entries on the page.
	 */
	private static function prime_merchant_users( array $entries ): void {
		$user_ids = [];
		foreach ( $entries as $entry ) {
			$actor = ( $entry->get_payload() ?? [] )['actor'] ?? null;
			if ( is_array( $actor ) && LifecycleHistory::ROLE_MERCHANT === ( $actor['role'] ?? null ) && isset( $actor['user_id'] ) && (int) $actor['user_id'] > 0 ) {
				$user_ids[] = (int) $actor['user_id'];
			}
		}

		if ( [] !== $user_ids ) {
			cache_users( array_values( array_unique( $user_ids ) ) );
		}
	}

	/**
	 * Render one entry row; on a Lite-owned contract a note's text sits under its label.
	 *
	 * @param HistoryEntryView $entry   One history entry.
	 * @param bool             $is_lite Whether the contract is Lite's.
	 */
	private static function render_row( HistoryEntryView $entry, bool $is_lite ): void {
		$payload = $entry->get_payload() ?? [];
		$note    = $is_lite && LifecycleHistory::TYPE_NOTE === $entry->get_type() && isset( $payload['text'] ) && is_string( $payload['text'] )
			? $payload['text']
			: null;
		?>
		<tr>
			<td><?php echo esc_html( self::get_date_label( $entry->get_date_created_gmt() ) ); ?></td>
			<td>
				<?php echo esc_html( $is_lite ? self::get_type_label( $entry->get_type() ) : $entry->get_type() ); ?>
				<?php if ( null !== $note ) : ?>
					<p class="wc-subs-lite-contract-history-note"><?php echo nl2br( esc_html( $note ) ); ?></p>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $is_lite ? self::get_actor_label( $payload['actor'] ?? null ) : Formatter::PLACEHOLDER ); ?></td>
		</tr>
		<?php
	}

	/**
	 * When an entry was stored, in the site time zone and WooCommerce's date and time formats.
	 *
	 * @param string $gmt GMT `Y-m-d H:i:s`.
	 */
	private static function get_date_label( string $gmt ): string {
		$timestamp = strtotime( $gmt . ' UTC' );
		if ( false === $timestamp ) {
			return Formatter::PLACEHOLDER;
		}

		return (string) wp_date( wc_date_format() . ' ' . wc_time_format(), $timestamp );
	}

	/**
	 * The label of an entry type: Lite's types are translated, any other type shows its slug.
	 *
	 * @param string $type Entry type.
	 */
	private static function get_type_label( string $type ): string {
		$labels = [
			LifecycleHistory::TYPE_CREATED                => __( 'Subscription created', 'woocommerce-subscriptions-lite' ),
			LifecycleHistory::TYPE_HELD                   => __( 'Put on hold', 'woocommerce-subscriptions-lite' ),
			LifecycleHistory::TYPE_REACTIVATED            => __( 'Reactivated', 'woocommerce-subscriptions-lite' ),
			LifecycleHistory::TYPE_CANCELLED              => __( 'Cancelled', 'woocommerce-subscriptions-lite' ),
			LifecycleHistory::TYPE_CANCELLATION_SCHEDULED => __( 'Set to end with the current period', 'woocommerce-subscriptions-lite' ),
			LifecycleHistory::TYPE_RENEWAL_PAID           => __( 'Renewal paid', 'woocommerce-subscriptions-lite' ),
			LifecycleHistory::TYPE_RENEWAL_FAILED         => __( 'Renewal failed', 'woocommerce-subscriptions-lite' ),
			LifecycleHistory::TYPE_NOTE                   => __( 'Note', 'woocommerce-subscriptions-lite' ),
		];

		return $labels[ $type ] ?? $type;
	}

	/**
	 * Who did it: the customer, a store manager by display name, or the system.
	 *
	 * @param mixed $actor The payload's `actor`: `role` and `user_id`.
	 */
	private static function get_actor_label( $actor ): string {
		$role    = is_array( $actor ) && isset( $actor['role'] ) && is_string( $actor['role'] ) ? $actor['role'] : '';
		$user_id = is_array( $actor ) && isset( $actor['user_id'] ) ? (int) $actor['user_id'] : 0;

		if ( LifecycleHistory::ROLE_CUSTOMER === $role ) {
			return __( 'Customer', 'woocommerce-subscriptions-lite' );
		}

		if ( LifecycleHistory::ROLE_MERCHANT === $role ) {
			$user = $user_id > 0 ? get_userdata( $user_id ) : false;
			return false !== $user && '' !== $user->display_name ? $user->display_name : __( 'Store manager', 'woocommerce-subscriptions-lite' );
		}

		if ( LifecycleHistory::ROLE_SYSTEM === $role ) {
			return __( 'System', 'woocommerce-subscriptions-lite' );
		}

		return '' !== $role ? $role : Formatter::PLACEHOLDER;
	}

	/**
	 * Newer and older page links, when there is a page in that direction.
	 *
	 * @param int  $contract_id Contract id.
	 * @param int  $page        Current 1-based page.
	 * @param bool $has_older   Whether an older page exists.
	 */
	private static function render_pagination( int $contract_id, int $page, bool $has_older ): void {
		if ( 1 === $page && ! $has_older ) {
			return;
		}
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php if ( $page > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( self::get_page_url( $contract_id, $page - 1 ) ); ?>"><?php esc_html_e( 'Newer entries', 'woocommerce-subscriptions-lite' ); ?></a>
				<?php endif; ?>
				<?php if ( $has_older ) : ?>
					<a class="button" href="<?php echo esc_url( self::get_page_url( $contract_id, $page + 1 ) ); ?>"><?php esc_html_e( 'Older entries', 'woocommerce-subscriptions-lite' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * A link back to the newest entries, for a page past the end.
	 *
	 * @param int $contract_id Contract id.
	 */
	private static function render_first_page_link( int $contract_id ): void {
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<a class="button" href="<?php echo esc_url( self::get_page_url( $contract_id, 1 ) ); ?>"><?php esc_html_e( 'Newest entries', 'woocommerce-subscriptions-lite' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * The detail-page URL of a history page, anchored on this box.
	 *
	 * @param int $contract_id Contract id.
	 * @param int $page        1-based page; page 1 carries no paging arg.
	 */
	private static function get_page_url( int $contract_id, int $page ): string {
		$args = [
			'action' => 'view',
			'id'     => $contract_id,
		];
		if ( $page > 1 ) {
			$args[ self::PAGE_ARG ] = $page;
		}

		return PageController::page_url( $args ) . '#' . DetailRenderer::HISTORY_BOX_ID;
	}

	/**
	 * The outcome of the note just submitted, as an inline wp-admin notice at the top of the box.
	 *
	 * @param array{type: string, message: string, invalid: bool} $notice The kept outcome.
	 */
	private static function render_note_notice( array $notice ): void {
		$classes = [
			RowActionResult::SUCCESS => 'notice-success',
			RowActionResult::ERROR   => 'notice-error',
		];
		printf(
			'<div id="%1$s" class="notice %2$s inline wc-subs-lite-note-notice"><p>%3$s</p></div>',
			esc_attr( self::FIELD_ID . '_notice' ),
			esc_attr( $classes[ $notice['type'] ] ?? 'notice-info' ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Render the "Add note" form, prefilled with the text of a note that was just refused.
	 *
	 * @param int  $contract_id Contract id.
	 * @param bool $is_invalid  Whether the note just refused was refused for its text, so the field is
	 *                          marked and focused.
	 */
	private static function render_note_form( int $contract_id, bool $is_invalid ): void {
		$description_id = self::FIELD_ID . '_description';
		$described_by   = $is_invalid ? self::FIELD_ID . '_notice ' . $description_id : $description_id;
		?>
		<form class="wc-subs-lite-note-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<p>
				<label for="<?php echo esc_attr( self::FIELD_ID ); ?>"><strong><?php esc_html_e( 'Add note', 'woocommerce-subscriptions-lite' ); ?></strong></label>
			</p>
			<textarea id="<?php echo esc_attr( self::FIELD_ID ); ?>" name="<?php echo esc_attr( NoteController::FIELD_NOTE ); ?>" class="large-text" rows="3" aria-describedby="<?php echo esc_attr( $described_by ); ?>"<?php echo $is_invalid ? ' aria-invalid="true" autofocus' : ''; ?>><?php echo esc_textarea( NoteController::consume_draft( $contract_id ) ); ?></textarea>
			<p class="description" id="<?php echo esc_attr( $description_id ); ?>">
				<?php
				/* translators: %d: maximum number of characters in a note. */
				echo esc_html( sprintf( __( 'Private to store managers. Up to %d characters.', 'woocommerce-subscriptions-lite' ), NoteController::MAX_LENGTH ) );
				?>
			</p>
			<?php wp_nonce_field( PageController::ACTION_ADD_NOTE . '_' . $contract_id ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( PageController::ACTION_ADD_NOTE ); ?>" />
			<input type="hidden" name="contract_id" value="<?php echo (int) $contract_id; ?>" />
			<p>
				<button type="submit" class="button"><?php esc_html_e( 'Add note', 'woocommerce-subscriptions-lite' ); ?></button>
			</p>
		</form>
		<?php
	}
}
