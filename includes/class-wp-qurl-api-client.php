<?php
/**
 * LayerV qURL API HTTP client.
 *
 * Wraps the LayerV qURL Service API (https://layerv.ai/docs/).
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client for LayerV API requests.
 */
class Api_Client {

	/**
	 * Test API connectivity with stored credentials.
	 *
	 * @return array{success: bool, message: string, data?: array}
	 */
	public function test_connection() {
		$response = $this->get( '/v1/quota' );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Your key works.', 'wp-qurl' ),
			'data'    => $response,
		);
	}

	/**
	 * Get resource details by CRID or resource_id.
	 *
	 * Normalizes ResourceDetailData to a flat ResourceData array for callers.
	 *
	 * @param string $id CRID or resource public key.
	 * @return array|\WP_Error
	 */
	public function get_resource( $id ) {
		$data = $this->get( '/v1/resources/' . rawurlencode( $id ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( isset( $data['resource'] ) && is_array( $data['resource'] ) ) {
			return $data['resource'];
		}

		return $data;
	}

	/**
	 * Create a qURL for an existing resource (recommended multi-link path).
	 *
	 * @param string $resource_id CRID or resource public key.
	 * @param array  $body        Request body per CreateQurlForResourceRequest.
	 * @return array|\WP_Error
	 */
	public function create_resource_qurl( $resource_id, array $body = array() ) {
		$allowed = array(
			'expires_in',
			'one_time_use',
			'max_sessions',
			'session_duration',
			'access_policy',
			'label',
			'target_path',
		);

		return $this->post(
			'/v1/resources/' . rawurlencode( $resource_id ) . '/qurls',
			array_intersect_key( $body, array_flip( $allowed ) ),
			true
		);
	}

	/**
	 * Create a resource and its first access link from a target URL.
	 *
	 * @param array $body Request body per CreateQurlRequest schema.
	 * @return array|\WP_Error
	 */
	public function create_qurl( array $body ) {
		$allowed = array(
			'type',
			'target_url',
			'expires_in',
			'one_time_use',
			'max_sessions',
			'session_duration',
			'access_policy',
			'label',
			'custom_domain',
		);

		return $this->post( '/v1/qurls', array_intersect_key( $body, array_flip( $allowed ) ), true );
	}

	/**
	 * Update a specific qURL (extend expiry, label, sessions, etc.).
	 *
	 * @param string $resource_id CRID or resource public key.
	 * @param string $qurl_id     qURL display ID.
	 * @param array  $body        UpdateQurlTokenRequest fields.
	 * @return array|\WP_Error
	 */
	public function update_qurl_token( $resource_id, $qurl_id, array $body ) {
		$allowed = array(
			'extend_by',
			'expires_at',
			'label',
			'access_policy',
			'max_sessions',
			'session_duration',
		);

		return $this->patch(
			'/v1/resources/' . rawurlencode( $resource_id ) . '/qurls/' . rawurlencode( $qurl_id ),
			array_intersect_key( $body, array_flip( $allowed ) ),
			true
		);
	}

	/**
	 * Revoke a resource and all its qURLs.
	 *
	 * @param string $resource_id CRID or resource public key.
	 * @return true|\WP_Error
	 */
	public function delete_resource( $resource_id ) {
		return $this->delete( '/v1/resources/' . rawurlencode( $resource_id ) );
	}

	/**
	 * Revoke a single qURL token.
	 *
	 * @param string $resource_id CRID or resource public key.
	 * @param string $qurl_id     qURL display ID.
	 * @return true|\WP_Error
	 */
	public function revoke_qurl_token( $resource_id, $qurl_id ) {
		return $this->delete(
			'/v1/resources/' . rawurlencode( $resource_id ) . '/qurls/' . rawurlencode( $qurl_id )
		);
	}

	/**
	 * Get quota information.
	 *
	 * @return array|\WP_Error
	 */
	public function get_quota() {
		return $this->get( '/v1/quota' );
	}

	/**
	 * List webhook endpoints.
	 *
	 * @param array $params Query parameters.
	 * @return array|\WP_Error
	 */
	public function list_webhooks( array $params = array() ) {
		return $this->get( '/v1/webhooks', $params );
	}

	/**
	 * Create a webhook endpoint.
	 *
	 * @param array $body Request body.
	 * @return array|\WP_Error
	 */
	public function create_webhook( array $body ) {
		return $this->post( '/v1/webhooks', $body, true );
	}

	/**
	 * Get a single webhook.
	 *
	 * @param string $webhook_id Webhook ID.
	 * @return array|\WP_Error
	 */
	public function get_webhook( $webhook_id ) {
		return $this->get( '/v1/webhooks/' . rawurlencode( $webhook_id ) );
	}

	/**
	 * Update a webhook.
	 *
	 * @param string $webhook_id Webhook ID.
	 * @param array  $body       Update fields.
	 * @return array|\WP_Error
	 */
	public function update_webhook( $webhook_id, array $body ) {
		return $this->patch( '/v1/webhooks/' . rawurlencode( $webhook_id ), $body, true );
	}

	/**
	 * Delete a webhook.
	 *
	 * @param string $webhook_id Webhook ID.
	 * @return true|\WP_Error
	 */
	public function delete_webhook( $webhook_id ) {
		return $this->delete( '/v1/webhooks/' . rawurlencode( $webhook_id ) );
	}

	/**
	 * List available webhook event types.
	 *
	 * @return array|\WP_Error
	 */
	public function list_webhook_event_types() {
		return $this->get( '/v1/webhooks/events' );
	}

	/**
	 * Regenerate a webhook signing secret.
	 *
	 * @param string $webhook_id Webhook ID.
	 * @return array|\WP_Error Response includes new secret.
	 */
	public function regenerate_webhook_secret( $webhook_id ) {
		return $this->post( '/v1/webhooks/' . rawurlencode( $webhook_id ) . '/secret', array(), true );
	}

	/**
	 * List webhook delivery attempts.
	 *
	 * @param string $webhook_id Webhook ID.
	 * @param array  $params     Query parameters.
	 * @return array|\WP_Error
	 */
	public function list_webhook_deliveries( $webhook_id, array $params = array() ) {
		return $this->get( '/v1/webhooks/' . rawurlencode( $webhook_id ) . '/deliveries', $params );
	}

	/**
	 * Perform a GET request.
	 *
	 * @param string $path   API path.
	 * @param array  $params Query parameters.
	 * @return array|\WP_Error Decoded data envelope or error.
	 */
	private function get( $path, array $params = array() ) {
		$url = $this->build_url( $path, $params );
		return $this->request( 'GET', $url );
	}

	/**
	 * Perform a POST request.
	 *
	 * @param string $path             API path.
	 * @param array  $body             JSON body.
	 * @param bool   $use_idempotency  Whether to send Idempotency-Key header.
	 * @return array|\WP_Error
	 */
	private function post( $path, array $body, $use_idempotency = false ) {
		$url     = $this->build_url( $path );
		$headers = array();

		if ( $use_idempotency ) {
			$headers['Idempotency-Key'] = wp_generate_uuid4();
		}

		return $this->request( 'POST', $url, $body, $headers );
	}

	/**
	 * Perform a PATCH request.
	 *
	 * @param string $path             API path.
	 * @param array  $body             JSON body.
	 * @param bool   $use_idempotency  Whether to send Idempotency-Key header.
	 * @return array|\WP_Error
	 */
	private function patch( $path, array $body, $use_idempotency = false ) {
		$url     = $this->build_url( $path );
		$headers = array();

		if ( $use_idempotency ) {
			$headers['Idempotency-Key'] = wp_generate_uuid4();
		}

		return $this->request( 'PATCH', $url, $body, $headers );
	}

	/**
	 * Perform a DELETE request.
	 *
	 * @param string $path            API path.
	 * @param bool   $return_body     Return decoded body on success (for terminate-all).
	 * @return true|array|\WP_Error
	 */
	private function delete( $path, $return_body = false ) {
		$url      = $this->build_url( $path );
		$response = $this->request( 'DELETE', $url, null, array(), true );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $return_body ? $response : true;
	}

	/**
	 * Execute an HTTP request against the LayerV API.
	 *
	 * @param string     $method      HTTP method.
	 * @param string     $url         Full URL.
	 * @param array|null $body        JSON body for POST/PATCH.
	 * @param array      $extra_headers Additional headers.
	 * @param bool       $allow_empty   Allow empty success body (204).
	 * @return array|\WP_Error
	 */
	private function request( $method, $url, $body = null, array $extra_headers = array(), $allow_empty = false ) {
		$api_key = Settings::get_api_key();
		if ( '' === $api_key ) {
			return new \WP_Error(
				'wp_qurl_not_configured',
				__( 'Add your API key in Settings first.', 'wp-qurl' )
			);
		}

		$headers = array_merge(
			array(
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'WP-qURL/' . WP_QURL_VERSION . '; ' . home_url(),
			),
			$extra_headers
		);

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 30,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( 429 === $code ) {
			$retry = wp_remote_retrieve_header( $response, 'retry-after' );
			return new \WP_Error(
				'wp_qurl_rate_limit',
				sprintf(
					/* translators: %s: seconds until retry */
					__( 'Rate limit exceeded. Retry after %s seconds.', 'wp-qurl' ),
					$retry ? $retry : '?'
				),
				array(
					'status'      => 429,
					'retry_after' => $retry,
				)
			);
		}

		if ( 204 === $code || 304 === $code ) {
			return array();
		}

		if ( $allow_empty && 200 === $code && ( '' === $raw || null === $decoded ) ) {
			return array();
		}

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $decoded ) && isset( $decoded['data'] ) ? $decoded['data'] : $decoded;
		}

		$error  = is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ? $decoded['error'] : array();
		$detail = $error['detail'] ?? ( $error['title'] ?? '' );
		if ( '' === $detail ) {
			$detail = sprintf(
				/* translators: %d: HTTP status code */
				__( 'API request failed with status %d.', 'wp-qurl' ),
				$code
			);
		}

		return new \WP_Error(
			'wp_qurl_api_error',
			$detail,
			array(
				'status'      => $code,
				'error_code'  => sanitize_key( $error['code'] ?? '' ),
				'retry_after' => wp_remote_retrieve_header( $response, 'retry-after' ),
				'body'        => $decoded,
			)
		);
	}

	/**
	 * Build a full API URL.
	 *
	 * @param string $path   API path.
	 * @param array  $params Query parameters.
	 * @return string
	 */
	private function build_url( $path, array $params = array() ) {
		$base = untrailingslashit( Settings::get( 'api_base_url', WP_QURL_DEFAULT_API_BASE ) );
		$url  = $base . $path;

		if ( ! empty( $params ) ) {
			$url = add_query_arg( array_filter( $params ), $url );
		}

		return $url;
	}
}
