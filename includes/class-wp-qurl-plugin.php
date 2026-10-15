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
		add_action( 'template_redirect', array( 'WP_QURL\Grant', 'maybe_handle_admit_path' ), 0 );

		( new Protector() )->init();

		add_action( 'init', array( $this, 'maybe_sync_webhook_url' ), 20 );
	}

	/**
	 * Point LayerV at the non-REST receiver after this upgrade.
	 */
	public function maybe_sync_webhook_url() {
		if ( '1.5.2' !== get_option( 'wp_qurl_webhook_endpoint', '' ) ) {
			if ( ! Settings::is_configured() || ! Settings::is_webhook_registered() ) {
				update_option( 'wp_qurl_webhook_endpoint', '1.5.2' );
			} else {
				$result = ( new Webhook_Manager() )->sync_url();
				if ( ! empty( $result['success'] ) ) {
					update_option( 'wp_qurl_webhook_endpoint', '1.5.2' );
				}
			}
		}

		$this->maybe_sync_webhook_events();
	}

	/**
	 * Keep LayerV subscribed to the events the access log needs.
	 */
	public function maybe_sync_webhook_events() {
		if ( '1.5.19' === get_option( 'wp_qurl_webhook_events_sync', '' ) ) {
			return;
		}

		if ( ! Settings::is_configured() || ! Settings::is_webhook_registered() ) {
			update_option( 'wp_qurl_webhook_events_sync', '1.5.19' );
			return;
		}

		$current = Settings::get_webhook_events();
		$missing = array_diff( Webhook_Manager::DEFAULT_EVENTS, $current );
		if ( empty( $missing ) ) {
			update_option( 'wp_qurl_webhook_events_sync', '1.5.19' );
			return;
		}

		$merged = array_values( array_unique( array_merge( $current, Webhook_Manager::DEFAULT_EVENTS ) ) );
		$result = ( new Webhook_Manager() )->update_events( $merged );
		if ( ! empty( $result['success'] ) ) {
			update_option( 'wp_qurl_webhook_events_sync', '1.5.19' );
		}
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
