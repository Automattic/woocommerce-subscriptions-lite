<?php
/**
 * Integration-test bootstrap for WooCommerce Subscriptions Lite.
 *
 * Loads the WordPress test framework, activates WooCommerce and this plugin the
 * way a real site loads them, and installs the WooCommerce + engine schema once
 * up front so per-test transaction rollback (provided by WP_UnitTestCase) keeps
 * each test isolated without re-running DDL.
 *
 * Runs inside the wp-env tests environment: `composer test:integration`.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Bootstrap file mixes a class and procedural setup.

/**
 * Bootstrap runner for the integration suite.
 */
class SubscriptionsLiteTestsBootstrap {

	/**
	 * Singleton instance.
	 *
	 * @var SubscriptionsLiteTestsBootstrap|null
	 */
	protected static $instance = null;

	/**
	 * Path to the WordPress tests directory.
	 *
	 * @var string
	 */
	public $wp_tests_dir;

	/**
	 * Path to this tests directory.
	 *
	 * @var string
	 */
	public $tests_dir;

	/**
	 * Path to the plugin root.
	 *
	 * @var string
	 */
	public $plugin_dir;

	/**
	 * Set up the integration testing environment.
	 */
	public function __construct() {
		$this->tests_dir  = __DIR__;
		$this->plugin_dir = dirname( dirname( $this->tests_dir ) );

		$this->wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : sys_get_temp_dir() . '/wordpress-tests-lib';

		require_once $this->wp_tests_dir . '/includes/functions.php';

		tests_add_filter( 'muplugins_loaded', [ $this, 'load_plugins' ] );

		if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
			define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $this->plugin_dir . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php' );
		}

		require_once $this->wp_tests_dir . '/includes/bootstrap.php';

		// Install WooCommerce so its tables and runtime (orders, customers,
		// logger) are available, then the engine schema the plugin reads and
		// writes through. Both run once, outside any test transaction, so DDL
		// never breaks rollback isolation.
		if ( class_exists( \WC_Install::class ) ) {
			\WC_Install::install();
		}
		SchemaInstaller::install();

		require_once $this->tests_dir . '/LiteIntegrationTestCase.php';
	}

	/**
	 * Load WooCommerce, then this plugin, the way a real site loads them.
	 *
	 * The plugin file wires the Jetpack Autoloader (which resolves the vendored
	 * engine) and boots the feature modules on `plugins_loaded`, exactly as in
	 * production - no library-style shortcuts.
	 */
	public function load_plugins(): void {
		$woocommerce = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( file_exists( $woocommerce ) ) {
			require_once $woocommerce;
		}

		require_once $this->plugin_dir . '/woocommerce-subscriptions-lite.php';
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return SubscriptionsLiteTestsBootstrap
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

SubscriptionsLiteTestsBootstrap::instance();
