<?php
/**
 * Plugin Name:       WP qURL
 * Plugin URI:        https://layerv.ai/docs/
 * Description:       Create and manage LayerV qURL secure access links from the WordPress admin.
 * Version:           1.2.3
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Author:            Justin Greer
 * Author URI:        https://justin-greer.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-qurl
 * Domain Path:       /languages
 *
 * @package WP_QURL
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version.
 */
define( 'WP_QURL_VERSION', '1.2.3' );

/**
 * Independent database schema version.
 */
define( 'WP_QURL_DB_VERSION', '1.2.3' );

/**
 * Plugin root file path.
 */
define( 'WP_QURL_FILE', __FILE__ );

/**
 * Plugin directory path.
 */
define( 'WP_QURL_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 */
define( 'WP_QURL_URL', plugin_dir_url( __FILE__ ) );

/**
 * Default LayerV API base URL.
 *
 * @see https://layerv.ai/docs/
 */
define( 'WP_QURL_DEFAULT_API_BASE', 'https://api.layerv.ai' );

/**
 * Autoload plugin classes.
 *
 * @param string $class_name Fully qualified class name.
 */
function wp_qurl_autoload( $class_name ) {
	$prefix = 'WP_QURL\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative = strtolower( str_replace( '_', '-', substr( $class_name, strlen( $prefix ) ) ) );
	$file     = WP_QURL_PATH . 'includes/class-wp-qurl-' . $relative . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}

spl_autoload_register( 'wp_qurl_autoload' );

/**
 * Activate the plugin.
 */
function wp_qurl_activate() {
	WP_QURL\Activator::activate();
}

/**
 * Deactivate the plugin.
 */
function wp_qurl_deactivate() {
	WP_QURL\Activator::deactivate();
}

register_activation_hook( __FILE__, 'wp_qurl_activate' );
register_deactivation_hook( __FILE__, 'wp_qurl_deactivate' );

/**
 * Bootstrap the plugin after WordPress loads.
 */
function wp_qurl_init() {
	$plugin = WP_QURL\Plugin::instance();
	$plugin->init();
}

add_action( 'plugins_loaded', 'wp_qurl_init' );
