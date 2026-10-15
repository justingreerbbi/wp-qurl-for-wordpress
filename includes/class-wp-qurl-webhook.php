<?php
/**
 * LayerV webhook receiver.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies, logs, and applies LayerV lifecycle events.
 *
 * The primary receiver is a front-end query URL so "Disable REST API"
 * and similar plugins cannot block LayerV deliveries.
 */
class Webhook {

	/**
	 * Query argument on the public webhook URL.
	 */
	const QUERY_ARG = 'wp_qurl_hook';

	/**
	 * Singleton.
	 *
	 * @var Webhook|null
	 */
	private static $instance;

	/**
	 * Get the singleton.
	 *
	 * @return Webhook
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function init() {
		add_action( 'init', array( $this, 'maybe_handle_front_webhook' ), 0 );
		add_action( 'admin_post_nopriv_wp_qurl_hook', array( $this, 'handle_admin_post' ) );
		add_action( 'admin_post_wp_qurl_hook', array( $this, 'handle_admin_post' ) );
		add_filter( 'rest_authentication_errors', array( $this, 'allow_rest_webhook' ), 21 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Accept signed POSTs on ?wp_qurl_hook={token} without using REST.
	 */
	public function maybe_handle_front_webhook() {
		if ( empty( $_GET[ self::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$token    = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$expected = Settings::get( 'webhook_token', '' );
		if ( ! $expected || ! hash_equals( $expected, $token ) ) {
			status_header( 403 );
			nocache_headers();
			wp_send_json( array( 'error' => 'forbidden' ), 403 );
		}

		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
		if ( 'GET' === $method || 'HEAD' === $method ) {
			nocache_headers();
			wp_send_json(
				array(
					'ok'       => true,
					'receiver' => 'wp-qurl',
				),
				200
			);
		}

		if ( 'POST' !== $method ) {
			status_header( 405 );
			nocache_headers();
			wp_send_json( array( 'error' => 'method_not_allowed' ), 405 );
		}

		$this->respond_from_result(
			$this->process_body(
				(string) file_get_contents( 'php://input' ),
				$this->request_header( 'QURL-Signature' ),
				$this->request_header( 'QURL-Event' ),
				$this->request_header( 'QURL-Delivery' )
			)
		);
	}

	/**
	 * Alternate receiver at admin-post.php?action=wp_qurl_hook.
	 */
	public function handle_admin_post() {
		$token    = sanitize_text_field( wp_unslash( $_GET['token'] ?? ( $_POST['token'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$expected = Settings::get( 'webhook_token', '' );
		if ( ! $expected || ! hash_equals( $expected, $token ) ) {
			status_header( 403 );
			nocache_headers();
			wp_send_json( array( 'error' => 'forbidden' ), 403 );
		}

		$this->respond_from_result(
			$this->process_body(
				(string) file_get_contents( 'php://input' ),
				$this->request_header( 'QURL-Signature' ),
				$this->request_header( 'QURL-Event' ),
				$this->request_header( 'QURL-Delivery' )
			)
		);
	}

	/**
	 * Let the REST webhook through when Disable REST API blocks guests.
	 *
	 * @param mixed $access Current REST auth result.
	 * @return mixed
	 */
	public function allow_rest_webhook( $access ) {
		if ( ! $this->is_rest_webhook_request() ) {
			return $access;
		}

		return true;
	}

	/**
	 * Register the secret webhook route (kept as a fallback).
	 */
	public function register_routes() {
		$token = Settings::get( 'webhook_token', '' );
		if ( ! $token ) {
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
	 * REST adapter for one signed LayerV event.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_webhook( $request ) {
		$result = $this->process_body(
			$request->get_body(),
			(string) $request->get_header( 'QURL-Signature' ),
			(string) $request->get_header( 'QURL-Event' ),
			(string) $request->get_header( 'QURL-Delivery' )
		);

		if ( ! empty( $result['error'] ) ) {
			return new \WP_Error( $result['error'], $result['message'], array( 'status' => (int) $result['status'] ) );
		}

		return new \WP_REST_Response( $result['body'], (int) $result['status'] );
	}

	/**
	 * Process a raw signed webhook body.
	 *
	 * @param string $body      Raw JSON body.
	 * @param string $signature QURL-Signature header.
	 * @param string $header    QURL-Event header.
	 * @param string $delivery  QURL-Delivery header.
	 * @return array{status:int,body?:array,error?:string,message?:string}
	 */
	private function process_body( $body, $signature, $header, $delivery ) {
		$secret = Settings::get_webhook_secret();
		$body   = (string) $body;

		if ( ! $secret || ! $this->verify_signature( $body, $signature, $secret ) ) {
			return array(
				'status'  => 401,
				'error'   => 'invalid_signature',
				'message' => __( 'Invalid webhook signature.', 'wp-qurl' ),
			);
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) || empty( $payload['id'] ) || empty( $payload['type'] ) ) {
			return array(
				'status'  => 400,
				'error'   => 'invalid_payload',
				'message' => __( 'Invalid webhook payload.', 'wp-qurl' ),
			);
		}

		$event_id   = sanitize_text_field( $payload['id'] );
		$event_type = sanitize_text_field( $payload['type'] );
		$header     = sanitize_text_field( $header );
		$delivery   = sanitize_text_field( $delivery );

		if ( ! $header ) {
			$header = $event_type;
		}
		if ( ! $delivery ) {
			$delivery = $event_id;
		}

		if ( $header !== $event_type ) {
			return array(
				'status'  => 400,
				'error'   => 'invalid_headers',
				'message' => __( 'Invalid webhook headers.', 'wp-qurl' ),
			);
		}

		$data        = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
		$resource_id = sanitize_text_field( $data['resource_id'] ?? '' );
		$crid        = sanitize_text_field( $data['crid'] ?? '' );
		$qurl_id     = sanitize_text_field( $data['qurl_id'] ?? ( $data['qurl_display_id'] ?? '' ) );

		if ( Database::delivery_exists( $event_id ) ) {
			if ( 'qurl.accessed' === $event_type ) {
				Grant::from_access_event( $data );
			}
			return array(
				'status' => 200,
				'body'   => array(
					'received'  => true,
					'duplicate' => true,
				),
			);
		}

		$revoke_id = $crid ? $crid : $resource_id;
		if ( 'qurl.accessed' === $event_type ) {
			Grant::from_access_event( $data );
			if ( $qurl_id && Database::recent_event( $qurl_id, 'qurl.accessed', 180 ) ) {
				return array(
					'status' => 200,
					'body'   => array(
						'received'  => true,
						'duplicate' => true,
					),
				);
			}
		} elseif ( 'resource.closed' === $event_type ) {
			Database::revoke_tokens( $revoke_id );
			Grant::forget_resource( $revoke_id );
		} elseif ( in_array( $event_type, array( 'qurl.revoked', 'qurl.expired', 'qurl.token_exhausted', 'token.expired' ), true ) ) {
			Database::revoke_tokens( $revoke_id, $qurl_id );
			if ( $qurl_id ) {
				Grant::forget_qurl( $qurl_id );
			} else {
				Grant::forget_resource( $revoke_id );
			}
		}

		$logged = Database::insert_access_log(
			array(
				'event_id'    => $event_id,
				'resource_id' => $resource_id,
				'crid'        => $crid,
				'qurl_id'     => $qurl_id,
				'event_type'  => $event_type,
				'src_ip'      => $data['src_ip'] ?? '',
				'user_agent'  => $data['user_agent'] ?? '',
				'session_id'  => $data['session_id'] ?? '',
				'delivery_id' => $delivery,
				'payload'     => Settings::get( 'enable_webhook_logging', true ) ? $payload : null,
			)
		);

		if ( ! $logged ) {
			return array(
				'status'  => 500,
				'error'   => 'webhook_log_failed',
				'message' => __( 'Webhook event could not be stored.', 'wp-qurl' ),
			);
		}

		do_action( 'wp_qurl_webhook_received', $event_type, $payload );

		return array(
			'status' => 200,
			'body'   => array(
				'received'  => true,
				'duplicate' => false,
			),
		);
	}

	/**
	 * Send JSON for a processed webhook result and exit.
	 *
	 * @param array $result Processor result.
	 */
	private function respond_from_result( array $result ) {
		nocache_headers();
		status_header( (int) $result['status'] );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json(
				array(
					'error'   => $result['error'],
					'message' => $result['message'] ?? '',
				),
				(int) $result['status']
			);
		}

		wp_send_json( $result['body'], (int) $result['status'] );
	}

	/**
	 * Whether the current REST request is this plugin's webhook.
	 *
	 * @return bool
	 */
	private function is_rest_webhook_request() {
		$token = Settings::get( 'webhook_token', '' );
		if ( ! $token ) {
			return false;
		}

		$route = '';
		if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		} elseif ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$route = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		}

		$route    = untrailingslashit( strtolower( $route ) );
		$expected = '/wp-qurl/v1/webhook/' . strtolower( $token );

		return '' !== $route && ( hash_equals( $expected, $route ) || str_ends_with( $route, $expected ) );
	}

	/**
	 * Read a request header from REST or raw PHP.
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	private function request_header( $name ) {
		$suffix = strtoupper( str_replace( '-', '_', $name ) );
		foreach ( array( 'HTTP_' . $suffix, 'REDIRECT_HTTP_' . $suffix ) as $server_key ) {
			if ( ! empty( $_SERVER[ $server_key ] ) ) {
				return sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) );
			}
		}

		foreach ( array( 'getallheaders', 'apache_request_headers' ) as $reader ) {
			if ( ! function_exists( $reader ) ) {
				continue;
			}
			$headers = $reader();
			if ( ! is_array( $headers ) ) {
				continue;
			}
			foreach ( $headers as $key => $value ) {
				if ( 0 === strcasecmp( (string) $key, $name ) ) {
					return sanitize_text_field( (string) $value );
				}
			}
		}

		return '';
	}

	/**
	 * Verify the raw request HMAC.
	 *
	 * @param string $body      Raw body.
	 * @param string $signature Signature header.
	 * @param string $secret    Signing secret.
	 * @return bool
	 */
	private function verify_signature( $body, $signature, $secret ) {
		$signature = (string) $signature;
		if ( str_starts_with( $signature, 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}
		return (bool) preg_match( '/^[a-f0-9]{64}$/i', $signature )
			&& hash_equals( hash_hmac( 'sha256', $body, $secret ), $signature );
	}
}
