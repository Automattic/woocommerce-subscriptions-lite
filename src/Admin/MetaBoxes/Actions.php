<?php
/**
 * Actions meta box - Renew now / Cancel / Back.
 *
 * The side-column actions box. The status-gated state changes (Renew now, Cancel) are
 * self-contained POST forms (see {@see PageController::action_form()}) so no nonce rides
 * in a URL, and the back-to-list navigation lives here rather than the page title. Every
 * control renders as a link, stacked one per line.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes;

use Automattic\WooCommerce\SubscriptionsLite\Admin\PageController;
use Automattic\WooCommerce\SubscriptionsLite\Admin\StatusLabels;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * "Actions" meta box.
 */
final class Actions {

	/**
	 * Render the box body.
	 *
	 * @param Contract $contract The contract being viewed.
	 */
	public static function output( Contract $contract ): void {
		$id     = (int) $contract->get_id();
		$status = $contract->get_status();
		// Back-to-list navigation lives in this box, not in the page title.
		$items = [
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( PageController::page_url() ),
				esc_html__( 'Back to subscriptions', 'woocommerce-subscriptions-lite' )
			),
		];

		if ( StatusLabels::is_renewable( $status ) ) {
			$items[] = PageController::action_form(
				PageController::ACTION_RENEW_NOW,
				$id,
				__( 'Renew now', 'woocommerce-subscriptions-lite' )
			);
		}

		if ( StatusLabels::is_cancellable( $status ) ) {
			$items[] = PageController::action_form(
				PageController::ACTION_CANCEL,
				$id,
				__( 'Cancel', 'woocommerce-subscriptions-lite' ),
				'button-link wc-subs-lite-cancel-link',
				__( 'Cancel this subscription immediately? This cannot be undone.', 'woocommerce-subscriptions-lite' )
			);
		}

		$list = '';

		foreach ( $items as $item ) {
			$list .= '<li>' . $item . '</li>';
		}

		// action_form() and the back link escape their dynamic parts at source.
		echo '<ul class="wc-subs-lite-detail-actions">' . $list . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_form + escaped link markup.
	}
}
