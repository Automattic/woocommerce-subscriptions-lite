<?php
/**
 * History meta box - what happened to the subscription, and the "Add note" form.
 *
 * Lists the contract's history entries newest first, a page at a time, read through the engine
 * facade: what happened, when (site time zone), by whom, and a note's text. Below the list, a
 * form posts a merchant note to {@see NoteController}. Degrades to a notice when the read fails.
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
	 * Render the box body.
	 *
	 * @param ContractView $contract The contract being viewed.
	 */
	public static function output( ContractView $contract ): void {
		$contract_id = (int) $contract->get_id();
		$page        = self::get_current_page();

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
		} elseif ( [] === $entries ) {
			echo '<p>' . esc_html( 1 === $page ? __( 'Nothing has been recorded for this subscription yet.', 'woocommerce-subscriptions-lite' ) : __( 'No older entries.', 'woocommerce-subscriptions-lite' ) ) . '</p>';
			self::pagination( $contract_id, $page, false );
		} else {
			$has_older = count( $entries ) > self::PER_PAGE;
			self::table( array_slice( $entries, 0, self::PER_PAGE ) );
			self::pagination( $contract_id, $page, $has_older );
		}

		self::note_form( $contract_id );
	}

	/**
	 * The 1-based history page from the request; 1 when absent or invalid.
	 */
	private static function get_current_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging.
		$page = isset( $_GET[ self::PAGE_ARG ] ) ? absint( wp_unslash( $_GET[ self::PAGE_ARG ] ) ) : 1;

		return max( 1, $page );
	}

	/**
	 * Render the entries table.
	 *
	 * @param array<int, HistoryEntryView> $entries Entries newest first.
	 */
	private static function table( array $entries ): void {
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
					<?php self::row( $entry ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render one entry row; a note's text sits under its label.
	 *
	 * @param HistoryEntryView $entry One history entry.
	 */
	private static function row( HistoryEntryView $entry ): void {
		$payload = $entry->get_payload() ?? [];
		$note    = LifecycleHistory::TYPE_NOTE === $entry->get_type() && isset( $payload['text'] ) && is_string( $payload['text'] )
			? $payload['text']
			: null;
		?>
		<tr>
			<td><?php echo esc_html( self::get_date_label( $entry->get_date_created_gmt() ) ); ?></td>
			<td>
				<?php echo esc_html( self::get_type_label( $entry->get_type() ) ); ?>
				<?php if ( null !== $note ) : ?>
					<p class="wc-subs-lite-contract-history-note"><?php echo nl2br( esc_html( $note ) ); ?></p>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( self::get_actor_label( $payload['actor'] ?? null ) ); ?></td>
		</tr>
		<?php
	}

	/**
	 * When an entry was stored, in the site time zone and WordPress date and time formats.
	 *
	 * @param string $gmt GMT `Y-m-d H:i:s`.
	 */
	private static function get_date_label( string $gmt ): string {
		$timestamp = strtotime( $gmt . ' UTC' );
		if ( false === $timestamp ) {
			return Formatter::PLACEHOLDER;
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
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
	private static function pagination( int $contract_id, int $page, bool $has_older ): void {
		if ( 1 === $page && ! $has_older ) {
			return;
		}
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php if ( $page > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( self::page_url( $contract_id, $page - 1 ) ); ?>"><?php esc_html_e( 'Newer entries', 'woocommerce-subscriptions-lite' ); ?></a>
				<?php endif; ?>
				<?php if ( $has_older ) : ?>
					<a class="button" href="<?php echo esc_url( self::page_url( $contract_id, $page + 1 ) ); ?>"><?php esc_html_e( 'Older entries', 'woocommerce-subscriptions-lite' ); ?></a>
				<?php endif; ?>
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
	private static function page_url( int $contract_id, int $page ): string {
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
	 * The "Add note" form, prefilled with the text of a note that was just refused.
	 *
	 * @param int $contract_id Contract id.
	 */
	private static function note_form( int $contract_id ): void {
		$field_id = 'woocommerce_subscriptions_lite_note';
		?>
		<form class="wc-subs-lite-note-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<p>
				<label for="<?php echo esc_attr( $field_id ); ?>"><strong><?php esc_html_e( 'Add note', 'woocommerce-subscriptions-lite' ); ?></strong></label>
			</p>
			<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( NoteController::FIELD_NOTE ); ?>" class="large-text" rows="3" aria-describedby="<?php echo esc_attr( $field_id . '_description' ); ?>"><?php echo esc_textarea( NoteController::consume_draft( $contract_id ) ); ?></textarea>
			<p class="description" id="<?php echo esc_attr( $field_id . '_description' ); ?>">
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
