<?php
/**
 * Main plugin singleton.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates plugin subsystems.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize hooks and services.
	 */
	public function init() {
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_action( 'init', array( 'WP_QURL\Database', 'maybe_upgrade' ), 1 );
		add_action( 'init', array( $this, 'ensure_cleanup_scheduled' ), 2 );
		add_action( 'wp_qurl_cleanup', array( 'WP_QURL\Database', 'cleanup_expired' ) );

		Capabilities::register();

		( new Blocks() )->init();
		( new Public_Rest() )->init();

		if ( is_admin() ) {
			Admin::instance()->init();
			Page_Meta::init();
		}

		Webhook::instance()->init();

		( new Handoff() )->init();
		( new Protector() )->init();
	}

	/**
	 * Load plugin translations after WordPress has selected the locale.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wp-qurl', false, dirname( plugin_basename( WP_QURL_FILE ) ) . '/languages' );
	}

	/**
	 * Ensure existing installations receive the cleanup schedule after upgrade.
	 */
	public function ensure_cleanup_scheduled() {
		if ( ! wp_next_scheduled( 'wp_qurl_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wp_qurl_cleanup' );
		}
	}

	/**
	 * Prevent direct instantiation.
	 */
	private function __construct() {}
}
