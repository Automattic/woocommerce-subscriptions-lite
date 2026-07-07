/**
 * Admin entry point for WooCommerce Subscriptions Lite.
 *
 * Imports the admin stylesheet so @wordpress/scripts compiles the SCSS, adds
 * vendor prefixes, emits the RTL stylesheet, and writes the `*.asset.php`
 * manifest. Each script module self-gates on a DOM marker so screens that do
 * not render its markup do no work.
 */
import './style.scss';
import './product-plans-panel';
