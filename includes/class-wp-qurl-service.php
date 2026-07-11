<?php
/**
 * Core qURL minting service shared by admin and public APIs.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Creates qURLs via LayerV and persists local token records.
 */
class Service {

	/**
	 * Mint context: WordPress admin UI.
	 */
	const CONTEXT_ADMIN = 'admin';

	/**
	 * Mint context: frontend block self-service.
	 */
	const CONTEXT_FRONTEND_BLOCK = 'frontend_block';

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->api = new Api_Client();
	}

	/**
	 * Create a qURL and store the portal link locally.
	 *
	 * @param array  $args    Mint arguments (target_url required).
	 * @param string $context Mint context constant.
	 * @return array|\WP_Error Success data or error.
	 */
	public function mint( array $args, $context = self::CONTEXT_ADMIN ) {
		if ( ! Settings::is_configured() ) {
			return new \WP_Error(
				'wp_qurl_not_configured',
				__( 'LayerV API is not configured.', 'wp-qurl' )
			);
		}

		$args               = $this->prepare_args( $args, $context );
		$args['target_url'] = self::normalize_target_url( $args['target_url'] );

		/**
		 * Filter mint arguments before validation and API call.
		 *
		 * @param array  $args    Prepared mint arguments.
		 * @param string $context Mint context.
		 */
		$args = apply_filters( 'wp_qurl_mint_args', $args, $context );

		$validation = $this->validate_args( $args, $context );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		/**
		 * Short-circuit minting.
		 *
		 * @param null|\WP_Error|array $preempt  Return non-null to bypass mint.
		 * @param array                $args     Mint arguments.
		 * @param string               $context  Mint context.
		 */
		$preempt = apply_filters( 'wp_qurl_pre_mint', null, $args, $context );
		if ( null !== $preempt ) {
			return $preempt;
		}

		$clean_target = $args['target_url'];
		$page_id      = self::resolve_protected_page_id( $clean_target );
		if ( $page_id ) {
			$canonical_target = get_permalink( $page_id );
			if ( $canonical_target ) {
				$clean_target       = self::normalize_target_url( $canonical_target );
				$args['target_url'] = $clean_target;
			}
		}

		$body = array(
			'type'             => 'url',
			'target_url'       => $args['target_url'],
			'expires_in'       => $args['expires_in'],
			'session_duration' => $args['session_duration'],
			'one_time_use'     => (bool) $args['one_time_use'],
			'max_sessions'     => (int) $args['max_sessions'],
		);

		if ( ! empty( $args['label'] ) ) {
			$body['label'] = $args['label'];
		}

		$result = $this->api->create_qurl( $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$token_id = Database::insert_token(
			array(
				'resource_id' => $result['resource_id'] ?? '',
				'qurl_id'     => $result['qurl_id'] ?? '',
				'target_url'  => $args['target_url'],
				'qurl_link'   => $result['qurl_link'] ?? '',
				'label'       => $result['label'] ?? $args['label'],
				'description' => $args['description'],
				'expires_at'  => self::format_storage_datetime( $result['expires_at'] ?? '' ),
				'status'      => 'active',
				'created_by'  => (int) $args['created_by'],
				'meta'        => array_merge(
					(array) $args['meta'],
					array(
						'context'          => $context,
						'type'             => $result['type'] ?? 'url',
						'qurl_site'        => $result['qurl_site'] ?? '',
						'branded_domain'   => $result['branded_domain'] ?? '',
						'session_duration' => self::duration_to_seconds( $args['session_duration'] ),
						'page_id'          => $page_id ? (int) $page_id : 0,
					)
				),
			)
		);

		if ( ! $token_id ) {
			$this->revoke_failed_mint( $result );
			return new \WP_Error(
				'wp_qurl_store_failed',
				__( 'qURL was created but could not be saved locally.', 'wp-qurl' )
			);
		}

		$response = array(
			'token_id'       => $token_id,
			'resource_id'    => $result['resource_id'] ?? '',
			'qurl_id'        => $result['qurl_id'] ?? '',
			'qurl_link'      => $result['qurl_link'] ?? '',
			'qurl_site'      => $result['qurl_site'] ?? '',
			'branded_domain' => $result['branded_domain'] ?? '',
			'target_url'     => $args['target_url'],
			'expires_at'     => $result['expires_at'] ?? '',
			'label'          => $result['label'] ?? $args['label'],
			'type'           => $result['type'] ?? 'url',
			'one_time_use'   => (bool) $args['one_time_use'],
		);

		/**
		 * Fires after a qURL is minted and stored.
		 *
		 * @param array  $response Mint response data.
		 * @param array  $args     Mint arguments used.
		 * @param string $context  Mint context.
		 */
		do_action( 'wp_qurl_minted', $response, $args, $context );

		if ( self::CONTEXT_FRONTEND_BLOCK === $context ) {
			self::record_rate_limit( self::get_rate_limit_actor( $args ), $args['target_url'] );
		}

		return $response;
	}

	/**
	 * Revoke only the token created by a mint whose local persistence failed.
	 *
	 * @param array $result LayerV create response data.
	 */
	private function revoke_failed_mint( array $result ) {
		$resource_id = sanitize_text_field( $result['resource_id'] ?? '' );
		$qurl_id     = sanitize_text_field( $result['qurl_id'] ?? '' );

		if ( '' !== $resource_id && '' !== $qurl_id ) {
			$this->api->revoke_qurl_token( $resource_id, $qurl_id );
		}
	}

	/**
	 * Resolve and validate a block endpoint into a target URL.
	 *
	 * @param string $endpoint Relative path or absolute HTTPS URL.
	 * @return string|\WP_Error
	 */
	public static function resolve_endpoint( $endpoint ) {
		$endpoint = trim( (string) $endpoint );
		if ( '' === $endpoint ) {
			return new \WP_Error(
				'wp_qurl_invalid_endpoint',
				__( 'Endpoint is required.', 'wp-qurl' )
			);
		}

		if ( preg_match( '#^https?://#i', $endpoint ) ) {
			$url = esc_url_raw( $endpoint );
		} else {
			$path = '/' . ltrim( $endpoint, '/' );
			$url  = home_url( $path );
		}

		if ( ! wp_http_validate_url( $url ) ) {
			return new \WP_Error(
				'wp_qurl_invalid_endpoint',
				__( 'Endpoint must resolve to a valid URL.', 'wp-qurl' )
			);
		}

		/**
		 * Filter the resolved endpoint URL before minting.
		 *
		 * @param string $url      Resolved URL.
		 * @param string $endpoint Raw endpoint from block/settings.
		 */
		$url = apply_filters( 'wp_qurl_resolve_endpoint', $url, $endpoint );

		if ( ! self::is_endpoint_allowed( $url ) ) {
			return new \WP_Error(
				'wp_qurl_endpoint_forbidden',
				__( 'This endpoint is not allowed for qURL minting.', 'wp-qurl' )
			);
		}

		return self::normalize_target_url( $url );
	}

	/**
	 * Check whether an endpoint URL is permitted for frontend minting.
	 *
	 * @param string $url Resolved target URL.
	 * @return bool
	 */
	public static function is_endpoint_allowed( $url ) {
		$allowed_hosts = Settings::get_frontend_allowed_hosts();
		$host          = wp_parse_url( $url, PHP_URL_HOST );
		$site_host     = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( empty( $host ) ) {
			return false;
		}

		$hosts = array_unique( array_filter( array_merge( array( $site_host ), $allowed_hosts ) ) );

		/**
		 * Filter allowed hosts for frontend qURL minting.
		 *
		 * @param array  $hosts Allowed hostnames.
		 * @param string $url   Target URL being checked.
		 */
		$hosts = apply_filters( 'wp_qurl_allowed_mint_hosts', $hosts, $url );

		if ( ! in_array( $host, $hosts, true ) ) {
			return false;
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( 'https' !== strtolower( (string) $scheme ) ) {
			return false;
		}

		/**
		 * Final allow/deny for a mint endpoint URL.
		 *
		 * @param bool   $allowed Default true if host/scheme checks passed.
		 * @param string $url     Target URL.
		 */
		return (bool) apply_filters( 'wp_qurl_is_endpoint_allowed', true, $url );
	}

	/**
	 * Prepare arguments with context-specific defaults.
	 *
	 * @param array  $args    Input arguments.
	 * @param string $context Mint context.
	 * @return array
	 */
	private function prepare_args( array $args, $context ) {
		$user = wp_get_current_user();

		$defaults = array(
			'target_url'       => '',
			'expires_in'       => Settings::get( 'default_expires_in', '24h' ),
			'session_duration' => Settings::get( 'default_session_duration', '1h' ),
			'one_time_use'     => Settings::get( 'default_one_time_use', false ),
			'max_sessions'     => Settings::get( 'default_max_sessions', 0 ),
			'label'            => '',
			'description'      => '',
			'created_by'       => get_current_user_id(),
			'meta'             => array(),
		);

		if ( self::CONTEXT_FRONTEND_BLOCK === $context ) {
			$defaults['expires_in']       = Settings::get( 'frontend_block_expires_in', '24h' );
			$defaults['session_duration'] = Settings::get( 'frontend_block_session_duration', '1h' );
			$defaults['one_time_use']     = true;
			$defaults['max_sessions']     = 1;

			if ( ! empty( $args['meta']['guest_mint'] ) ) {
				$defaults['created_by'] = 0;
				$defaults['label']      = __( 'Self-service visitor link', 'wp-qurl' );
				$defaults['meta']       = array(
					'source'     => 'frontend_block',
					'guest_mint' => true,
				);
			} else {
				$defaults['label'] = $user->exists()
					? sprintf(
						/* translators: 1: user display name, 2: user ID */
						__( 'Self-service link for %1$s (user #%2$d)', 'wp-qurl' ),
						$user->display_name,
						$user->ID
					)
					: '';
				$defaults['meta'] = array(
					'source' => 'frontend_block',
				);
			}
		}

		return wp_parse_args( $args, $defaults );
	}

	/**
	 * Validate mint arguments.
	 *
	 * @param array  $args    Prepared arguments.
	 * @param string $context Mint context.
	 * @return true|\WP_Error
	 */
	private function validate_args( array $args, $context ) {
		if (
			empty( $args['target_url'] )
			|| ! wp_http_validate_url( $args['target_url'] )
			|| 'https' !== strtolower( (string) wp_parse_url( $args['target_url'], PHP_URL_SCHEME ) )
		) {
			return new \WP_Error(
				'wp_qurl_invalid_url',
				__( 'A valid HTTPS target URL is required.', 'wp-qurl' )
			);
		}

		if ( self::CONTEXT_FRONTEND_BLOCK === $context ) {
			$is_guest = ! empty( $args['meta']['guest_mint'] );

			if ( $is_guest ) {
				if ( ! Blocks::endpoint_allows_visitors( $args['meta']['endpoint'] ?? '' ) ) {
					return new \WP_Error(
						'wp_qurl_forbidden',
						__( 'Visitor access is not enabled for this endpoint.', 'wp-qurl' ),
						array( 'status' => 403 )
					);
				}
			} elseif ( ! Capabilities::current_user_can_mint_self() ) {
				return new \WP_Error(
					'wp_qurl_forbidden',
					__( 'You are not allowed to generate qURL links.', 'wp-qurl' ),
					array( 'status' => 403 )
				);
			}

			if ( ! self::is_endpoint_allowed( $args['target_url'] ) ) {
				return new \WP_Error(
					'wp_qurl_endpoint_forbidden',
					__( 'This endpoint is not allowed.', 'wp-qurl' ),
					array( 'status' => 403 )
				);
			}

			$rate = self::check_rate_limit( self::get_rate_limit_actor( $args ), $args['target_url'] );
			if ( is_wp_error( $rate ) ) {
				return $rate;
			}
		}

		return true;
	}

	/**
	 * Resolve a clean target URL to a protected WordPress page.
	 *
	 * @param string $target_url Target URL.
	 * @return int
	 */
	public static function resolve_protected_page_id( $target_url ) {
		$target_url = self::normalize_target_url( $target_url );
		if ( '' === $target_url ) {
			return 0;
		}

		$parts = wp_parse_url( $target_url );
		if ( ! is_array( $parts ) ) {
			return 0;
		}

		$clean_url = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );
		if ( isset( $parts['port'] ) ) {
			$clean_url .= ':' . absint( $parts['port'] );
		}
		$clean_url .= $parts['path'] ?? '/';

		$page_id = url_to_postid( $clean_url );
		if ( ! $page_id || 'page' !== get_post_type( $page_id ) || ! Page_Meta::is_protected( $page_id ) ) {
			return 0;
		}

		return (int) $page_id;
	}

	/**
	 * Normalize a target URL for consistent storage and lookup.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	public static function normalize_target_url( $url ) {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return '';
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['path'] ) || '/' === $parsed['path'] ) {
			return $url;
		}

		return untrailingslashit( $url );
	}

	/**
	 * Convert an API duration such as 30m or 1h into seconds.
	 *
	 * @param string $duration Duration value.
	 * @return int
	 */
	public static function duration_to_seconds( $duration ) {
		if ( ! is_string( $duration ) || ! preg_match( '/^(\d+)([smhd])$/', $duration, $matches ) ) {
			return HOUR_IN_SECONDS;
		}

		$multipliers = array(
			's' => 1,
			'm' => MINUTE_IN_SECONDS,
			'h' => HOUR_IN_SECONDS,
			'd' => DAY_IN_SECONDS,
		);

		return max( 1, (int) $matches[1] * $multipliers[ $matches[2] ] );
	}

	/**
	 * Parse a date/time value received from the LayerV API.
	 *
	 * @param mixed $value API date/time value.
	 * @return int|null Unix timestamp or null when invalid.
	 */
	public static function parse_api_datetime( $value ) {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return null;
		}

		$timestamp = strtotime( (string) $value );

		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * Format an API date/time for database storage.
	 *
	 * @param mixed $value API date/time value.
	 * @return string|null MySQL UTC date/time or null when invalid.
	 */
	public static function format_storage_datetime( $value ) {
		$timestamp = self::parse_api_datetime( $value );

		return null === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Format an API date/time for display in the site's timezone.
	 *
	 * @param mixed  $value    API date/time value.
	 * @param string $format   WordPress date format.
	 * @param string $fallback Value returned when the date is invalid.
	 * @return string
	 */
	public static function format_display_datetime( $value, $format, $fallback = '—' ) {
		$timestamp = self::parse_api_datetime( $value );

		return null === $timestamp ? $fallback : wp_date( $format, $timestamp );
	}

	/**
	 * Enforce per-actor daily mint rate limit for frontend context.
	 *
	 * @param string $actor_id   User ID or guest actor identifier.
	 * @param string $target_url Target URL.
	 * @return true|\WP_Error
	 */
	public static function check_rate_limit( $actor_id, $target_url ) {
		$limit = (int) Settings::get( 'frontend_mint_daily_limit', 10 );
		if ( $limit <= 0 ) {
			return true;
		}

		$key   = self::rate_limit_key( $actor_id, $target_url );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new \WP_Error(
				'wp_qurl_rate_limit',
				__( 'Daily qURL generation limit reached for this endpoint.', 'wp-qurl' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Record a successful frontend mint against the daily rate limit.
	 *
	 * @param string $actor_id   User ID or guest actor identifier.
	 * @param string $target_url Target URL.
	 */
	public static function record_rate_limit( $actor_id, $target_url ) {
		$limit = (int) Settings::get( 'frontend_mint_daily_limit', 10 );
		if ( $limit <= 0 ) {
			return;
		}

		$key   = self::rate_limit_key( $actor_id, $target_url );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, DAY_IN_SECONDS );
	}

	/**
	 * Build a transient key for rate limiting.
	 *
	 * @param string $actor_id   User ID or guest actor identifier.
	 * @param string $target_url Target URL.
	 * @return string
	 */
	private static function rate_limit_key( $actor_id, $target_url ) {
		$actor_id = sanitize_key( (string) $actor_id );

		return 'wp_qurl_mint_rate_' . $actor_id . '_' . md5( self::normalize_target_url( $target_url ) ) . '_' . gmdate( 'Ymd' );
	}

	/**
	 * Resolve the rate-limit actor for a mint request.
	 *
	 * @param array $args Prepared mint arguments.
	 * @return string
	 */
	public static function get_rate_limit_actor( array $args ) {
		if ( ! empty( $args['created_by'] ) ) {
			return (string) (int) $args['created_by'];
		}

		if ( ! empty( $args['meta']['guest_key'] ) ) {
			return 'guest_' . sanitize_key( (string) $args['meta']['guest_key'] );
		}

		return 'guest_' . md5( Protector::get_client_ip() );
	}

	/**
	 * Cookie name used to identify anonymous visitors.
	 */
	const GUEST_COOKIE_NAME = 'wp_qurl_guest';

	/**
	 * Get the current visitor guest key from cookie.
	 *
	 * @return string
	 */
	public static function get_guest_key() {
		if ( empty( $_COOKIE[ self::GUEST_COOKIE_NAME ] ) ) {
			return '';
		}

		$key = sanitize_text_field( wp_unslash( $_COOKIE[ self::GUEST_COOKIE_NAME ] ) );

		return preg_match( '/^[a-f0-9]{32}$/', $key ) ? $key : '';
	}

	/**
	 * Ensure an anonymous visitor has a persistent guest key cookie.
	 *
	 * @return string
	 */
	public static function ensure_guest_key() {
		$key = self::get_guest_key();
		if ( '' !== $key ) {
			return $key;
		}

		$key = bin2hex( random_bytes( 16 ) );

		if ( ! headers_sent() ) {
			setcookie(
				self::GUEST_COOKIE_NAME,
				$key,
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		$_COOKIE[ self::GUEST_COOKIE_NAME ] = $key;

		return $key;
	}

	/**
	 * Get an active token for a user and endpoint (for re-display).
	 *
	 * @param int    $user_id    User ID.
	 * @param string $target_url Target URL.
	 * @return object|null
	 */
	public static function get_user_active_token( $user_id, $target_url ) {
		$token = Database::get_user_active_token_for_target( $user_id, self::normalize_target_url( $target_url ) );
		if ( ! $token ) {
			return null;
		}

		$meta = is_array( $token->meta ) ? $token->meta : array();
		if ( isset( $meta['context'] ) && self::CONTEXT_FRONTEND_BLOCK !== $meta['context'] ) {
			return null;
		}
		if ( ! empty( $token->expires_at ) ) {
			$expires_at = self::parse_api_datetime( $token->expires_at );
			if ( null === $expires_at || $expires_at < time() ) {
				return null;
			}
		}

		return $token;
	}

	/**
	 * Get an active visitor token for a guest key and endpoint.
	 *
	 * @param string $guest_key  Anonymous visitor identifier.
	 * @param string $target_url Target URL.
	 * @return object|null
	 */
	public static function get_guest_active_token( $guest_key, $target_url ) {
		$token = Database::get_guest_active_token_for_target( $guest_key, self::normalize_target_url( $target_url ) );
		if ( ! $token ) {
			return null;
		}

		$meta = is_array( $token->meta ) ? $token->meta : array();
		if ( empty( $meta['guest_mint'] ) ) {
			return null;
		}

		if ( ! empty( $token->expires_at ) ) {
			$expires_at = self::parse_api_datetime( $token->expires_at );
			if ( null === $expires_at || $expires_at < time() ) {
				return null;
			}
		}

		return $token;
	}

	/**
	 * Whether a LayerV revoke error means the remote token is already inactive.
	 *
	 * @param \WP_Error $error API error.
	 * @return bool
	 */
	public static function is_remote_revoke_benign( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

		if ( in_array( $status, array( 404, 409, 410 ), true ) ) {
			return true;
		}

		$message = strtolower( $error->get_error_message() );
		$needles = array(
			'not active',
			'already revoked',
			'already inactive',
			'no longer active',
			'not found',
			'has been revoked',
			'was revoked',
		);

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Revoke a token from the admin UI, keeping WordPress authoritative.
	 *
	 * Local records are always revoked when present. LayerV is updated when
	 * possible, but benign "already inactive" responses are treated as synced.
	 *
	 * @param string $resource_id LayerV resource ID.
	 * @param string $qurl_id     qURL display ID.
	 * @param int    $local_id    Local token row ID.
	 * @return array|\WP_Error
	 */
	public static function admin_revoke_token( $resource_id, $qurl_id = '', $local_id = 0 ) {
		$local_id    = absint( $local_id );
		$resource_id = sanitize_text_field( $resource_id );
		$qurl_id     = sanitize_text_field( $qurl_id );
		$local_token = $local_id ? Database::get_token( $local_id ) : null;

		if ( $local_token ) {
			if ( '' === $resource_id ) {
				$resource_id = $local_token->resource_id;
			}
			if ( '' === $qurl_id ) {
				$qurl_id = $local_token->qurl_id;
			}
		}

		if ( ! $local_token && ( '' === $resource_id || '' === $qurl_id ) ) {
			return new \WP_Error(
				'wp_qurl_not_found',
				__( 'No qURL token found to revoke.', 'wp-qurl' )
			);
		}

		$local_revoked = false;

		if ( $local_id && $local_token ) {
			if ( 'revoked' !== $local_token->status ) {
				Database::update_token( $local_id, array( 'status' => 'revoked' ) );
			}
			$local_revoked = true;
		}

		if ( '' !== $resource_id ) {
			$updated = Database::revoke_active_tokens( $resource_id, $qurl_id );
			Database::revoke_page_grants( $resource_id, $qurl_id );
			if ( $updated > 0 ) {
				$local_revoked = true;
			}
		}

		$remote_synced       = false;
		$remote_error        = '';
		$benign_remote_error = false;
		$remote_attempted    = false;

		if ( '' !== $resource_id && '' !== $qurl_id ) {
			$remote_attempted = true;
			$api              = new Api_Client();
			$remote_result    = $api->revoke_qurl_token( $resource_id, $qurl_id );

			if ( is_wp_error( $remote_result ) ) {
				$remote_error        = $remote_result->get_error_message();
				$benign_remote_error = self::is_remote_revoke_benign( $remote_result );
				$remote_synced       = $benign_remote_error;
			} else {
				$remote_synced = true;
			}
		}

		if ( ! $local_revoked && ! $remote_attempted ) {
			return new \WP_Error(
				'wp_qurl_not_found',
				__( 'No qURL token found to revoke.', 'wp-qurl' )
			);
		}

		return array(
			'local_revoked'       => $local_revoked,
			'remote_synced'       => $remote_synced,
			'remote_error'        => $remote_error,
			'benign_remote_error' => $benign_remote_error,
			'remote_attempted'    => $remote_attempted,
		);
	}

	/**
	 * Revoke a resource from the admin UI, keeping WordPress authoritative.
	 *
	 * @param string $resource_id LayerV resource ID.
	 * @return array|\WP_Error
	 */
	public static function admin_revoke_resource( $resource_id ) {
		$resource_id = sanitize_text_field( $resource_id );
		if ( '' === $resource_id ) {
			return new \WP_Error(
				'wp_qurl_invalid_resource',
				__( 'Resource ID is required.', 'wp-qurl' )
			);
		}

		$local_revoked = Database::revoke_active_tokens( $resource_id ) > 0;
		Database::revoke_page_grants( $resource_id );

		$remote_synced       = false;
		$remote_error        = '';
		$benign_remote_error = false;

		$api           = new Api_Client();
		$remote_result = $api->delete_qurl( $resource_id );

		if ( is_wp_error( $remote_result ) ) {
			$remote_error        = $remote_result->get_error_message();
			$benign_remote_error = self::is_remote_revoke_benign( $remote_result );
			$remote_synced       = $benign_remote_error;
		} else {
			$remote_synced = true;
		}

		return array(
			'local_revoked'       => $local_revoked,
			'remote_synced'       => $remote_synced,
			'remote_error'        => $remote_error,
			'benign_remote_error' => $benign_remote_error,
			'remote_attempted'    => true,
		);
	}

	/**
	 * Sync local token state from a LayerV webhook event.
	 *
	 * @param string $event_type Event type.
	 * @param array  $event_data Event payload data.
	 */
	public static function sync_local_token_from_webhook( $event_type, array $event_data ) {
		$resource_id = sanitize_text_field( $event_data['resource_id'] ?? '' );
		$qurl_id     = sanitize_text_field( $event_data['qurl_id'] ?? '' );

		if ( '' === $resource_id ) {
			return;
		}

		$revoke_events = array(
			'qurl.revoked',
			'qurl.expired',
			'token.expired',
			'qurl.token_exhausted',
			'resource.closed',
		);

		/**
		 * Filter webhook events that should mark local qURL tokens revoked.
		 *
		 * @param array  $revoke_events Event types.
		 * @param string $event_type    Current event type.
		 * @param array  $event_data    Event payload data.
		 */
		$revoke_events = apply_filters( 'wp_qurl_webhook_revoke_events', $revoke_events, $event_type, $event_data );

		if ( ! in_array( $event_type, $revoke_events, true ) ) {
			return;
		}

		Database::revoke_active_tokens( $resource_id, $qurl_id );
		Database::revoke_page_grants( $resource_id, $qurl_id );
	}
}
