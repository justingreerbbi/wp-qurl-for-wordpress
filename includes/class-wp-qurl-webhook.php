<?php
/**
 * REST API endpoint for LayerV webhook events.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the webhook REST route.
 */
class Webhook {

	/**
	 * Singleton instance.
	 *
	 * @var Webhook|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Webhook
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register REST routes.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the webhook route.
	 */
	public function register_routes() {
		$token = Settings::get( 'webhook_token', '' );
		if ( '' === $token ) {
			Settings::get_webhook_url();
			$token = Settings::get( 'webhook_token', '' );
		}

		if ( '' === $token ) {
			return;
		}

		register_rest_route(
			Public_Rest::REST_NAMESPACE,
			'/webhook/' . preg_quote( $token, '/' ),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle incoming webhook payload.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_webhook( $request ) {
		$secret = Settings::get_webhook_secret();
		if ( '' === $secret ) {
			return new \WP_Error(
				'webhook_not_configured',
				__( 'Webhook signing secret is not configured. Register the webhook in qURLs → Webhooks.', 'wp-qurl' ),
				array( 'status' => 503 )
			);
		}

		$body      = $request->get_body();
		$signature = $request->get_header( 'QURL-Signature' );

		if ( ! $this->verify_signature( $body, $signature, $secret ) ) {
			return new \WP_Error(
				'invalid_signature',
				__( 'Invalid webhook signature.', 'wp-qurl' ),
				array( 'status' => 401 )
			);
		}

		$payload = json_decode( $body, true );
		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return new \WP_Error(
				'invalid_payload',
				__( 'Empty webhook payload.', 'wp-qurl' ),
				array( 'status' => 400 )
			);
		}

		$delivery_id  = sanitize_text_field( $request->get_header( 'QURL-Delivery' ) );
		$event_header = sanitize_text_field( $request->get_header( 'QURL-Event' ) );
		if ( '' === $delivery_id || '' === $event_header ) {
			return new \WP_Error(
				'missing_webhook_headers',
				__( 'LayerV webhook delivery headers are required.', 'wp-qurl' ),
				array( 'status' => 400 )
			);
		}

		$payload_type = sanitize_text_field( $payload['type'] ?? '' );
		if ( '' === $payload_type || $payload_type !== $event_header ) {
			return new \WP_Error(
				'webhook_event_mismatch',
				__( 'LayerV webhook event header and payload do not match.', 'wp-qurl' ),
				array( 'status' => 400 )
			);
		}

		$normalized_events = Webhook_Manager::sanitize_events( array( $event_header ) );
		$event_type        = $normalized_events[0] ?? $event_header;
		if ( $event_type !== $event_header ) {
			return new \WP_Error(
				'invalid_webhook_event',
				__( 'LayerV webhook event type is invalid.', 'wp-qurl' ),
				array( 'status' => 400 )
			);
		}

		$is_duplicate = Database::delivery_exists( $delivery_id );
		if ( $is_duplicate ) {
			return new \WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}

		$event_data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : $payload;
		$resource_id = sanitize_text_field( $event_data['resource_id'] ?? '' );
		$qurl_id     = sanitize_text_field( $event_data['qurl_id'] ?? '' );
		$src_ip      = sanitize_text_field( $event_data['src_ip'] ?? '' );
		$user_agent  = sanitize_textarea_field( $event_data['user_agent'] ?? '' );

		if ( 'qurl.accessed' === $event_type && ( '' === $resource_id || '' === $qurl_id ) ) {
			return new \WP_Error(
				'invalid_access_event',
				__( 'LayerV access events require qURL and resource identifiers.', 'wp-qurl' ),
				array( 'status' => 400 )
			);
		}

		if ( 'qurl.accessed' === $event_type ) {
			Database::create_grant_from_access( $event_data, $delivery_id );
		}

		$logged = Database::insert_access_log(
			array(
				'resource_id' => $resource_id,
				'qurl_id'     => $qurl_id,
				'event_type'  => $event_type,
				'src_ip'      => $src_ip,
				'user_agent'  => $user_agent,
				'delivery_id' => $delivery_id,
				'payload'     => Settings::get( 'enable_webhook_logging', true ) ? $payload : null,
			)
		);
		if ( ! $logged && Database::delivery_exists( $delivery_id ) ) {
			return new \WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}
		if ( ! $logged ) {
			return new \WP_Error(
				'webhook_log_failed',
				__( 'The LayerV webhook delivery could not be recorded.', 'wp-qurl' ),
				array( 'status' => 500 )
			);
		}

		Service::sync_local_token_from_webhook( $event_type, $event_data );

		if ( in_array( $event_type, array( 'qurl.revoked', 'qurl.expired', 'token.expired', 'qurl.token_exhausted', 'resource.closed' ), true ) ) {
			Database::revoke_page_grants( $resource_id, $qurl_id );
		}

		/**
		 * Fires after a LayerV webhook event is logged.
		 *
		 * @param string $event_type Event type (e.g. qurl.accessed).
		 * @param array  $payload    Full webhook payload.
		 */
		do_action( 'wp_qurl_webhook_received', $event_type, $payload );

		return new \WP_REST_Response(
			array(
				'received'  => true,
				'duplicate' => false,
			),
			200
		);
	}

	/**
	 * Verify HMAC-SHA256 webhook signature.
	 *
	 * @param string $body      Raw request body.
	 * @param string $signature Signature from QURL-Signature header.
	 * @param string $secret    Webhook secret.
	 * @return bool
	 */
	private function verify_signature( $body, $signature, $secret ) {
		if ( empty( $signature ) ) {
			return false;
		}

		if ( 0 === strpos( $signature, 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}

		if ( ! preg_match( '/^[a-f0-9]{64}$/i', $signature ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $body, $secret );

		return hash_equals( $expected, $signature );
	}
}
