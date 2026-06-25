<?php
/**
 * Actions meta box - Renew now / Cancel.
 *
 * The side-column actions box, mirroring WooCommerce's "Order actions" box. Each
 * action is a self-contained POST form (see {@see PageController::action_form()})
 * so no nonce rides in a URL; the controls are status-gated to match what the
 * facade will accept.
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
	 * Whether the contract has any action available, so the screen can skip
	 * registering an empty box.
	 *
	 * @param Contract $contract The contract.
	 */
	public static function has_actions( Contract $contract ): bool {
		$status = $contract->get_status();
		return StatusLabels::is_renewable( $status ) || StatusLabels::is_cancellable( $status );
	}

	/**
	 * Render the box body.
	 *
	 * @param Contract $contract The contract being viewed.
	 */
	public static function output( Contract $contract ): void {
		$id     = (int) $contract->get_id();
		$status = $contract->get_status();
		$items  = '';

		if ( StatusLabels::is_renewable( $status ) ) {
			$items .= '<li class="wide">' . PageController::action_form(
				PageController::ACTION_RENEW_NOW,
				$id,
				__( 'Renew now', 'woocommerce-subscriptions-lite' ),
				'button'
			) . '</li>';
		}

		if ( StatusLabels::is_cancellable( $status ) ) {
			$items .= '<li class="wide">' . PageController::action_form(
				PageController::ACTION_CANCEL,
				$id,
				__( 'Cancel', 'woocommerce-subscriptions-lite' ),
				'button wc-subs-lite-cancel-link',
				__( 'Cancel this subscription immediately? This cannot be undone.', 'woocommerce-subscriptions-lite' )
			) . '</li>';
		}

		// action_form() escapes its dynamic parts at source.
		echo '<ul class="order_actions submitbox">' . $items . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_form markup escaped at source.
	}
}
