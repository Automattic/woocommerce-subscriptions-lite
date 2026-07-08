<?php
/**
 * DetailTable - the shared label/value table used by the detail meta boxes.
 *
 * The Subscription details and Schedule boxes are both a two-column read of
 * label -> value rows in WooCommerce's `widefat striped` idiom, so the markup
 * lives here once. Row labels are escaped as text; row values are trusted markup
 * (links, badges) already escaped at their source and passed through
 * `wp_kses_post()`.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes;

defined( 'ABSPATH' ) || exit;

/**
 * Shared detail label/value table renderer.
 */
final class DetailTable {

	/**
	 * Render a label/value table, or nothing when there are no rows.
	 *
	 * @param array<int, array{label: string, value: string}> $rows Label/value rows.
	 */
	public static function render( array $rows ): void {
		if ( empty( $rows ) ) {
			return;
		}
		?>
		<table class="widefat striped wc-subs-lite-detail-table">
			<tbody>
				<?php
				foreach ( $rows as $row ) {
					printf(
						'<tr><th scope="row">%s</th><td>%s</td></tr>',
						esc_html( $row['label'] ),
						wp_kses_post( $row['value'] )
					);
				}
				?>
			</tbody>
		</table>
		<?php
	}
}
