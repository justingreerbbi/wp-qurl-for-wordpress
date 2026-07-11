<?php
/**
 * Plugin settings storage and retrieval.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Manages wp_qurl_settings option.
 */
class Settings {

	/**
	 * Option name in wp_options.
	 */
	const OPTION_KEY = 'wp_qurl_settings';

	/**
	 * Set default settings on first activation.
	 */
	public static function maybe_set_defaults() {
		if ( false !== get_option( self::OPTION_KEY, false ) ) {
			return;
		}

		update_option(
			self::OPTION_KEY,
			array(
				'api_base_url'            => WP_QURL_DEFAULT_API_BASE,
				'api_key'                 => '',
				'default_expires_in'      => '24h',
				'default_session_duration'=> '1h',
				'default_one_time_use'    => false,
				'default_max_sessions'    => 0,
				'enable_webhook_logging'  => true,
				'webhook_secret'          => '',
				'webhook_token'           => wp_generate_password( 24, false ),
				'layerv_webhook_id'       => '',
				'webhook_events'          => Webhook_Manager::DEFAULT_EVENTS,
				'frontend_block_expires_in'       => '24h',
				'frontend_block_session_duration' => '1h',
				'frontend_mint_daily_limit'       => 10,
				'frontend_allowed_hosts'          => '',
				'frontend_mint_roles'             => array( 'subscriber', 'contributor' ),
				'enable_debug_mode'               => false,
			)
		);
	}

	/**
	 * Get all settings with defaults applied.
	 *
	 * @return array
	 */
	public static function get_all() {
		$defaults = array(
			'api_base_url'             => WP_QURL_DEFAULT_API_BASE,
			'api_key'                  => '',
			'default_expires_in'       => '24h',
			'default_session_duration' => '1h',
			'default_one_time_use'     => false,
			'default_max_sessions'     => 0,
			'enable_webhook_logging'   => true,
			'webhook_secret'           => '',
			'webhook_token'            => '',
			'layerv_webhook_id'        => '',
			'webhook_events'           => Webhook_Manager::DEFAULT_EVENTS,
			'frontend_block_expires_in'       => '24h',
			'frontend_block_session_duration' => '1h',
			'frontend_mint_daily_limit'       => 10,
			'frontend_allowed_hosts'          => '',
			'frontend_mint_roles'             => array( 'subscriber', 'contributor' ),
			'enable_debug_mode'               => false,
		);

		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = wp_parse_args( $stored, $defaults );

		if ( ! is_array( $settings['webhook_events'] ) ) {
			$settings['webhook_events'] = Webhook_Manager::DEFAULT_EVENTS;
		}

		return $settings;
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	/**
	 * Get the decrypted API key.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		$encrypted = self::get( 'api_key', '' );
		if ( '' === $encrypted ) {
			return '';
		}
		return Crypto::decrypt( $encrypted );
	}

	/**
	 * Get the decrypted LayerV webhook signing secret.
	 *
	 * @return string
	 */
	public static function get_webhook_secret() {
		$encrypted = self::get( 'webhook_secret', '' );
		if ( '' === $encrypted ) {
			return '';
		}
		return Crypto::decrypt( $encrypted );
	}

	/**
	 * Whether a LayerV webhook signing secret is stored.
	 *
	 * @return bool
	 */
	public static function has_webhook_secret() {
		return '' !== self::get( 'webhook_secret', '' );
	}

	/**
	 * Get subscribed webhook event types.
	 *
	 * @return array
	 */
	public static function get_webhook_events() {
		$events = self::get( 'webhook_events', Webhook_Manager::DEFAULT_EVENTS );
		if ( ! is_array( $events ) ) {
			return Webhook_Manager::DEFAULT_EVENTS;
		}

		$events = Webhook_Manager::sanitize_events( $events );

		return empty( $events ) ? Webhook_Manager::DEFAULT_EVENTS : $events;
	}

	/**
	 * Whether a LayerV webhook ID is stored locally.
	 *
	 * @return bool
	 */
	public static function is_webhook_registered() {
		return '' !== self::get( 'layerv_webhook_id', '' );
	}

	/**
	 * Whether webhook configuration can authorize protected-page handoffs.
	 *
	 * @return bool
	 */
	public static function is_page_handoff_ready() {
		return self::is_webhook_registered()
			&& self::has_webhook_secret()
			&& in_array( 'qurl.accessed', self::get_webhook_events(), true );
	}

	/**
	 * Save webhook registration from LayerV.
	 *
	 * @param string $webhook_id LayerV webhook ID.
	 * @param string $secret     Signing secret (empty to keep existing).
	 * @param array  $events     Subscribed events.
	 */
	public static function save_webhook_registration( $webhook_id, $secret, array $events ) {
		$settings = self::get_all();

		$settings['layerv_webhook_id'] = sanitize_text_field( $webhook_id );
		$settings['webhook_events']    = Webhook_Manager::sanitize_events( $events );

		if ( '' !== $secret ) {
			$settings['webhook_secret'] = Crypto::encrypt( $secret );
		}

		update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * Save only the webhook signing secret.
	 *
	 * @param string $secret LayerV signing secret.
	 */
	public static function save_webhook_secret( $secret ) {
		$settings                   = self::get_all();
		$settings['webhook_secret'] = Crypto::encrypt( sanitize_text_field( $secret ) );
		update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * Save subscribed webhook events.
	 *
	 * @param array $events Event types.
	 */
	public static function save_webhook_events( array $events ) {
		$settings                   = self::get_all();
		$settings['webhook_events'] = Webhook_Manager::sanitize_events( $events );
		update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * Clear local webhook registration data.
	 */
	public static function clear_webhook_registration() {
		$settings                      = self::get_all();
		$settings['layerv_webhook_id']   = '';
		$settings['webhook_secret']      = '';
		$settings['webhook_events']      = Webhook_Manager::DEFAULT_EVENTS;
		update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * Save settings from admin form.
	 *
	 * @param array $input Raw form input.
	 * @return bool
	 */
	public static function save( array $input ) {
		$current = self::get_all();

		$settings = array(
			'api_base_url'             => esc_url_raw( $input['api_base_url'] ?? $current['api_base_url'] ),
			'default_expires_in'       => sanitize_text_field( $input['default_expires_in'] ?? $current['default_expires_in'] ),
			'default_session_duration' => sanitize_text_field( $input['default_session_duration'] ?? $current['default_session_duration'] ),
			'default_one_time_use'     => ! empty( $input['default_one_time_use'] ),
			'default_max_sessions'     => absint( $input['default_max_sessions'] ?? $current['default_max_sessions'] ),
			'enable_webhook_logging'   => ! empty( $input['enable_webhook_logging'] ),
			'webhook_secret'           => $current['webhook_secret'],
			'webhook_token'            => sanitize_text_field( $current['webhook_token'] ),
			'layerv_webhook_id'        => $current['layerv_webhook_id'],
			'webhook_events'           => $current['webhook_events'],
			'frontend_block_expires_in'       => sanitize_text_field( $input['frontend_block_expires_in'] ?? $current['frontend_block_expires_in'] ),
			'frontend_block_session_duration' => sanitize_text_field( $input['frontend_block_session_duration'] ?? $current['frontend_block_session_duration'] ),
			'frontend_mint_daily_limit'       => absint( $input['frontend_mint_daily_limit'] ?? $current['frontend_mint_daily_limit'] ),
			'frontend_allowed_hosts'          => self::sanitize_hosts_text( $input['frontend_allowed_hosts'] ?? $current['frontend_allowed_hosts'] ),
			'frontend_mint_roles'             => self::sanitize_roles( $input['frontend_mint_roles'] ?? array() ),
			'enable_debug_mode'               => ! empty( $input['enable_debug_mode'] ),
		);

		if ( ! empty( $input['api_key'] ) ) {
			$settings['api_key'] = Crypto::encrypt( sanitize_text_field( $input['api_key'] ) );
		} else {
			$settings['api_key'] = $current['api_key'];
		}

		$token_regenerated = ! empty( $input['regenerate_webhook_token'] );
		if ( $token_regenerated ) {
			$settings['webhook_token'] = wp_generate_password( 24, false );
		}

		$updated = update_option( self::OPTION_KEY, $settings );

		Capabilities::assign_role_caps();

		if ( $token_regenerated && '' !== $settings['layerv_webhook_id'] ) {
			$manager = new Webhook_Manager();
			$manager->sync_url();
		}

		return $updated;
	}

	/**
	 * Get the REST webhook endpoint URL for LayerV configuration.
	 *
	 * @return string
	 */
	public static function get_webhook_url() {
		$token = self::get( 'webhook_token', '' );
		if ( '' === $token ) {
			$settings                    = self::get_all();
			$settings['webhook_token']   = wp_generate_password( 24, false );
			update_option( self::OPTION_KEY, $settings );
			$token = $settings['webhook_token'];
		}
		return rest_url( 'wp-qurl/v1/webhook/' . $token );
	}

	/**
	 * Check whether API credentials are configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::get_api_key();
	}

	/**
	 * Whether blocked-page debug output is enabled.
	 *
	 * @return bool
	 */
	public static function is_debug_mode() {
		return (bool) self::get( 'enable_debug_mode', false );
	}

	/**
	 * Apply option migrations tied to database schema version bumps.
	 *
	 * @param string $from_version Previously installed DB schema version.
	 */
	public static function migrate_from_db_version( $from_version ) {
		$settings = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$changed = false;

		if ( version_compare( $from_version, '1.4.2', '<' ) && ! array_key_exists( 'enable_debug_mode', $settings ) ) {
			$settings['enable_debug_mode'] = false;
			$changed                       = true;
		}

		if ( version_compare( $from_version, '2.0.0', '<' ) ) {
			if ( array_key_exists( 'trust_proxy_headers', $settings ) ) {
				unset( $settings['trust_proxy_headers'] );
				$changed = true;
			}

			delete_post_meta_by_key( '_wp_qurl_resource_id' );
		}

		if ( $changed ) {
			update_option( self::OPTION_KEY, $settings );
		}
	}

	/**
	 * Additional allowed hosts for frontend minting (one hostname per line).
	 *
	 * @return array
	 */
	public static function get_frontend_allowed_hosts() {
		$raw = self::get( 'frontend_allowed_hosts', '' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$hosts = preg_split( '/\r\n|\r|\n/', $raw );
		$hosts = array_map( 'trim', $hosts );
		$hosts = array_filter( $hosts );

		return array_values( array_unique( array_map( 'sanitize_text_field', $hosts ) ) );
	}

	/**
	 * Roles that receive the mint_qurl_self capability.
	 *
	 * @return array
	 */
	public static function get_frontend_mint_roles() {
		$roles = self::get( 'frontend_mint_roles', array( 'subscriber', 'contributor' ) );
		return self::sanitize_roles( $roles );
	}

	/**
	 * Sanitize role slugs from form input.
	 *
	 * @param mixed $roles Role list or comma-separated string.
	 * @return array
	 */
	public static function sanitize_roles( $roles ) {
		if ( is_string( $roles ) ) {
			$roles = preg_split( '/\s*,\s*/', $roles );
		}
		if ( ! is_array( $roles ) ) {
			return array();
		}

		$valid = array_keys( wp_roles()->roles );
		$roles = array_map( 'sanitize_key', $roles );

		return array_values( array_intersect( $roles, $valid ) );
	}

	/**
	 * Sanitize multiline host list for storage.
	 *
	 * @param string $text Raw textarea input.
	 * @return string
	 */
	public static function sanitize_hosts_text( $text ) {
		$hosts = self::get_frontend_allowed_hosts_from_text( (string) $text );
		return implode( "\n", $hosts );
	}

	/**
	 * Parse hostnames from textarea text.
	 *
	 * @param string $text Raw text.
	 * @return array
	 */
	private static function get_frontend_allowed_hosts_from_text( $text ) {
		if ( '' === trim( $text ) ) {
			return array();
		}
		$hosts = preg_split( '/\r\n|\r|\n|,/', $text );
		$hosts = array_map( 'trim', $hosts );
		$hosts = array_filter( $hosts );
		return array_values( array_unique( array_map( 'sanitize_text_field', $hosts ) ) );
	}
}
