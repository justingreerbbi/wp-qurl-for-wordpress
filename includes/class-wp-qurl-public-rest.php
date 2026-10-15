<?php
/**
 * Public REST API for frontend qURL minting.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Registers public-facing REST routes for logged-in users and visitors.
 */
class Public_Rest {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'wp-qurl/v1';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/mint',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'mint_qurl' ),
					'permission_callback' => array( $this, 'can_mint' ),
					'args'                => $this->mint_args_schema(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/mint/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'mint_status' ),
					'permission_callback' => array( $this, 'can_mint' ),
					'args'                => array(
						'endpoint' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_endpoint' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check for mint endpoints.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return true|\WP_Error
	 */
	public function can_mint( $request ) {
		if ( ! Settings::is_configured() ) {
			return new \WP_Error(
				'wp_qurl_not_configured',
				__( 'qURL is not configured on this site.', 'wp-qurl' ),
				array( 'status' => 503 )
			);
		}

		$endpoint = $request->get_param( 'endpoint' );
		if ( ! is_string( $endpoint ) || '' === trim( $endpoint ) ) {
			return new \WP_Error(
				'wp_qurl_invalid_endpoint',
				__( 'Endpoint is required.', 'wp-qurl' ),
				array( 'status' => 400 )
			);
		}

		if ( is_user_logged_in() ) {
			if ( ! Capabilities::current_user_can_mint_self() ) {
				return new \WP_Error(
					'rest_forbidden',
					__( 'You are not allowed to mint qURLs.', 'wp-qurl' ),
					array( 'status' => 403 )
				);
			}

			return true;
		}

		if ( ! Blocks::endpoint_allows_visitors( $endpoint ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to mint qURLs.', 'wp-qurl' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * POST /mint — create a one-time self-service qURL.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function mint_qurl( $request ) {
		$endpoint = $request->get_param( 'endpoint' );
		$target   = Service::resolve_endpoint( $endpoint );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$is_guest  = ! is_user_logged_in();
		$user_id   = get_current_user_id();
		$guest_key = '';
		$meta      = array(
			'source'   => 'frontend_block',
			'endpoint' => $endpoint,
		);

		if ( $is_guest ) {
			$guest_key           = Service::ensure_guest_key();
			$meta['guest_mint']  = true;
			$meta['guest_key']   = $guest_key;
			$existing            = Service::get_guest_active_token( $guest_key, $target );
		} else {
			$existing = Service::get_user_active_token( $user_id, $target );
		}

		$service = new Service();
		$args    = array(
			'target_url' => $target,
			'created_by' => $is_guest ? 0 : $user_id,
			'meta'       => $meta,
		);

		if ( $existing ) {
			$path_id = $existing->crid ? $existing->crid : $existing->resource_id;
			$result  = $path_id
				? $service->mint_for_resource( $path_id, $args, Service::CONTEXT_FRONTEND_BLOCK )
				: $service->mint( $args, Service::CONTEXT_FRONTEND_BLOCK );
		} else {
			$result = $service->mint( $args, Service::CONTEXT_FRONTEND_BLOCK );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success'    => true,
				'existing'   => false,
				'qurl_link'  => $result['qurl_link'] ?? '',
				'expires_at' => $result['expires_at'] ?? '',
				'qurl_id'    => $result['qurl_id'] ?? '',
			)
		);
	}

	/**
	 * GET /mint/status — check if the requester has an active mint for endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function mint_status( $request ) {
		$endpoint = $request->get_param( 'endpoint' );
		$target   = Service::resolve_endpoint( $endpoint );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		if ( is_user_logged_in() ) {
			$existing = Service::get_user_active_token( get_current_user_id(), $target );
		} else {
			$guest_key = Service::get_guest_key();
			$existing  = $guest_key ? Service::get_guest_active_token( $guest_key, $target ) : null;
		}

		if ( ! $existing ) {
			return rest_ensure_response(
				array(
					'has_active' => false,
				)
			);
		}

		return rest_ensure_response(
			array(
				'has_active'  => true,
				'expires_at'  => $existing->expires_at,
				'qurl_id'     => $existing->qurl_id,
			)
		);
	}

	/**
	 * Schema for POST /mint parameters.
	 *
	 * @return array
	 */
	private function mint_args_schema() {
		return array(
			'endpoint' => array(
				'required'          => true,
				'type'              => 'string',
				'description'       => __( 'Relative path or absolute HTTPS URL to protect.', 'wp-qurl' ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_endpoint' ),
			),
		);
	}

	/**
	 * Validate an endpoint REST parameter before sanitization.
	 *
	 * @param mixed $value Endpoint value.
	 * @return bool
	 */
	public function validate_endpoint( $value ) {
		return is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 2048;
	}
}
