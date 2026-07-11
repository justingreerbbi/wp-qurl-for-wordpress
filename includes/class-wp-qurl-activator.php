<?php
/**
 * Plugin activation and deactivation routines.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation lifecycle.
 */
class Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		Database::create_tables();
		Settings::maybe_set_defaults();

		// Grant capabilities to default roles.
		Capabilities::assign_role_caps();

		if ( ! wp_next_scheduled( 'wp_qurl_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wp_qurl_cleanup' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'wp_qurl_cleanup' );
		flush_rewrite_rules();
	}
}
