<?php
/**
 * Addresses meta box - the subscription's billing and shipping addresses.
 *
 * Mirrors the billing/shipping columns of WooCommerce's order data box: a
 * read-only render of the two addresses frozen on the contract, formatted with
 * WooCommerce's own `get_formatted_address()` so they read the same as an order.
 * The billing block also surfaces the contact email and phone. Read-only - there
 * is no edit-address UI here.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * "Addresses" meta box.
 */
final class Addresses {

	/**
	 * Render the box body.
	 *
	 * @param Contract $contract The contract being viewed.
	 */
	public static function output( Contract $contract ): void {
		$addresses = $contract->get_addresses();
		$billing   = self::address_of( $addresses, Contract::ADDRESS_BILLING );
		$shipping  = self::address_of( $addresses, Contract::ADDRESS_SHIPPING );
		?>
		<div class="wc-subs-lite-addresses">
			<div class="wc-subs-lite-address">
				<h4><?php esc_html_e( 'Billing', 'woocommerce-subscriptions-lite' ); ?></h4>
				<?php self::render_address( $billing, true ); ?>
			</div>
			<div class="wc-subs-lite-address">
				<h4><?php esc_html_e( 'Shipping', 'woocommerce-subscriptions-lite' ); ?></h4>
				<?php self::render_address( $shipping, false ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * One address array from the contract's address map, or an empty array.
	 *
	 * @param array<string, mixed> $addresses The contract address map.
	 * @param string               $type      Contract::ADDRESS_BILLING | ADDRESS_SHIPPING.
	 * @return array<string, mixed>
	 */
	private static function address_of( array $addresses, string $type ): array {
		return isset( $addresses[ $type ] ) && is_array( $addresses[ $type ] ) ? $addresses[ $type ] : [];
	}

	/**
	 * Render one address block: the formatted postal address, plus the contact
	 * email and phone for the billing block. Falls back to a neutral note when the
	 * address is empty.
	 *
	 * @param array<string, mixed> $address         The address fields.
	 * @param bool                 $include_contact Whether to show email/phone (billing only).
	 */
	private static function render_address( array $address, bool $include_contact ): void {
		$formatted = self::formatted( $address );
		if ( '' === $formatted ) {
			echo '<p>' . esc_html__( 'No address.', 'woocommerce-subscriptions-lite' ) . '</p>';
			return;
		}

		// $formatted is WooCommerce's own address markup (fields joined by <br/>);
		// wp_kses_post keeps the line breaks while stripping anything unexpected.
		echo '<address>' . wp_kses_post( $formatted );

		if ( $include_contact ) {
			$email = isset( $address['email'] ) ? trim( (string) $address['email'] ) : '';
			$phone = isset( $address['phone'] ) ? trim( (string) $address['phone'] ) : '';
			if ( '' !== $email ) {
				printf( '<br/><a href="%s">%s</a>', esc_url( 'mailto:' . $email ), esc_html( $email ) );
			}
			if ( '' !== $phone ) {
				printf( '<br/><a href="%s">%s</a>', esc_url( 'tel:' . $phone ), esc_html( $phone ) );
			}
		}

		echo '</address>';
	}

	/**
	 * Format an address the WooCommerce way when WooCommerce is loaded, so it reads
	 * identically to an order address; otherwise a minimal name/location fallback.
	 *
	 * @param array<string, mixed> $address The address fields.
	 * @return string Formatted address markup (may contain <br/>), or '' when empty.
	 */
	private static function formatted( array $address ): string {
		if ( function_exists( 'WC' ) && WC()->countries ) {
			// Cast the loosely-typed contract fields to the string map WooCommerce expects.
			$fields = array_map(
				static function ( $value ): string {
					return is_scalar( $value ) ? (string) $value : '';
				},
				$address
			);
			return (string) WC()->countries->get_formatted_address( $fields );
		}

		$name = trim( (string) ( $address['first_name'] ?? '' ) . ' ' . (string) ( $address['last_name'] ?? '' ) );
		return '' !== $name ? esc_html( $name ) : '';
	}
}
