<?php
/**
 * Plugin Name: WooCommerce Subscriptions Lite
 * Plugin URI: https://github.com/Automattic/woocommerce-subscriptions-lite
 * Description: Free subscriptions for WooCommerce. Early development scaffold - not functional yet.
 * Author: Automattic
 * Author URI: https://automattic.com
 * Version: 0.0.1-dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woocommerce-subscriptions-lite
 * Requires Plugins: woocommerce
 *
 * @package WooCommerce\SubscriptionsLite
 */

defined( 'ABSPATH' ) || exit;

/*
 * Scaffold stage: no bootstrap yet.
 *
 * The first functional release wires this up as follows: explicitly require the
 * engine's version-register shim (never via composer files autoload), load the
 * package autoloader, then initialize after the version registry resolves at
 * plugins_loaded - guarding at runtime that the resolved engine version meets
 * this plugin's floor.
 */
