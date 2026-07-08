<?php
/**
 * Items meta box - the subscription's line items.
 *
 * Mirrors WooCommerce's order "Items" box: a read-only table of the line items
 * the contract renews (name, quantity, line total), read straight off the
 * contract's hydrated items. Each item links to its product edit screen when a
 * product id is known. Read-only - there is no add/remove/edit item UI here.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes;

use Automattic\WooCommerce\SubscriptionsLite\Admin\Formatting;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * "Items" meta box.
 */
final class Items {

	/**
	 * Render the box body.
	 *
	 * @param Contract $contract The contract being viewed.
	 */
	public static function output( Contract $contract ): void {
		$items = $contract->get_items();
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No items on this subscription.', 'woocommerce-subscriptions-lite' ) . '</p>';
			return;
		}

		$currency = $contract->get_currency();
		?>
		<table class="widefat striped wc-subs-lite-items-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Item', 'woocommerce-subscriptions-lite' ); ?></th>
					<th scope="col" class="wc-subs-lite-col-qty"><?php esc_html_e( 'Qty', 'woocommerce-subscriptions-lite' ); ?></th>
					<th scope="col" class="wc-subs-lite-col-total"><?php esc_html_e( 'Total', 'woocommerce-subscriptions-lite' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $item ) : ?>
					<?php self::row( is_array( $item ) ? $item : [], $currency ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render one line-item row.
	 *
	 * @param array<string, mixed> $item     One item row (`item_name`, `product_id`, `variation_id`, `quantity`, `total`).
	 * @param string               $currency Contract currency.
	 */
	private static function row( array $item, string $currency ): void {
		$name = isset( $item['item_name'] ) ? (string) $item['item_name'] : '';
		if ( '' === $name ) {
			$name = __( '(unnamed item)', 'woocommerce-subscriptions-lite' );
		}

		$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
		$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
		$link_id      = $product_id > 0 ? $product_id : $variation_id;

		$quantity = isset( $item['quantity'] ) ? (float) $item['quantity'] : 1.0;
		$total    = isset( $item['total'] ) ? (string) $item['total'] : '0';
		?>
		<tr>
			<td><?php echo wp_kses_post( self::name_html( $name, $link_id ) ); ?></td>
			<td class="wc-subs-lite-col-qty"><?php echo esc_html( self::quantity_label( $quantity ) ); ?></td>
			<td class="wc-subs-lite-col-total"><?php echo Formatting::price( $total, $currency ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- price markup escaped at source. ?></td>
		</tr>
		<?php
	}

	/**
	 * The item name, linked to its product edit screen when an editable product
	 * (or variation) id resolves to a URL; otherwise the plain escaped name.
	 *
	 * @param string $name    Item name.
	 * @param int    $link_id Product or variation id to link to (0 for none).
	 * @return string Name markup safe to echo after wp_kses_post().
	 */
	private static function name_html( string $name, int $link_id ): string {
		$url = $link_id > 0 ? get_edit_post_link( $link_id ) : null;
		if ( null === $url || '' === $url ) {
			return esc_html( $name );
		}

		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $name ) );
	}

	/**
	 * A quantity as a compact localized string: whole numbers drop their decimals
	 * (`2`, not `2.0000`); fractional quantities keep their significant digits.
	 *
	 * @param float $quantity Line-item quantity.
	 */
	private static function quantity_label( float $quantity ): string {
		if ( floor( $quantity ) === $quantity ) {
			return number_format_i18n( (int) $quantity );
		}

		return rtrim( rtrim( number_format( $quantity, 4, '.', '' ), '0' ), '.' );
	}
}
