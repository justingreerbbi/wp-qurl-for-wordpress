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
	 * Mint a qURL and store a local record (the qURL itself is not persisted).
	 *
	 * @param array  $args    Mint arguments (target_url required).
	 * @param string $context Mint context constant.
	 * @return array|\WP_Error Success data or error.
	 */
	public function mint( array $args, $context = self::CONTEXT_ADMIN ) {
		if ( ! Settings::is_configured() ) {
			return new \WP_Error(
				'wp_qurl_not_configured',
				__( 'Add your API key in Settings first.', 'wp-qurl' ),
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

		$page_id = self::resolve_protected_page_id( $args['target_url'] );

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

		return $this->store_mint_result( $result, $args, $context, $page_id );
	}

	/**
	 * Mint an additional qURL for an existing resource.
	 *
	 * Uses POST /v1/resources/{id}/qurls (recommended for multi-link remint).
	 *
	 * @param string $resource_id CRID or resource public key.
	 * @param array  $args        Mint arguments (target_url required for local storage).
	 * @param string $context     Mint context constant.
	 * @return array|\WP_Error
	 */
	public function mint_for_resource( $resource_id, array $args, $context = self::CONTEXT_ADMIN ) {
		if ( ! Settings::is_configured() ) {
			return new \WP_Error(
				'wp_qurl_not_configured',
				__( 'Add your API key in Settings first.', 'wp-qurl' ),
			);
		}

		$path_id = Database::resource_id( $resource_id );
		if ( '' === $path_id ) {
			return new \WP_Error(
				'wp_qurl_invalid_resource',
				__( 'Resource ID is required.', 'wp-qurl' )
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

		$page_id = self::resolve_protected_page_id( $args['target_url'] );

		$body = array(
			'expires_in'       => $args['expires_in'],
			'session_duration' => $args['session_duration'],
			'one_time_use'     => (bool) $args['one_time_use'],
			'max_sessions'     => (int) $args['max_sessions'],
		);

		if ( ! empty( $args['label'] ) ) {
			$body['label'] = $args['label'];
		}

		$result = $this->api->create_resource_qurl( $path_id, $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['crid'] ) ) {
			$result['crid'] = $path_id;
		}

		return $this->store_mint_result( $result, $args, $context, $page_id );
	}

	/**
	 * Persist a LayerV mint response as a local token row.
	 *
	 * @param array  $result  LayerV create/mint response data.
	 * @param array  $args    Mint arguments used.
	 * @param string $context Mint context.
	 * @param int    $page_id Protected page ID when applicable.
	 * @return array|\WP_Error
	 */
	private function store_mint_result( array $result, array $args, $context, $page_id = 0 ) {
		$token_id = Database::insert_token(
			array(
				'resource_id' => $result['resource_id'] ?? '',
				'crid'        => $result['crid'] ?? '',
				'qurl_id'     => $result['qurl_id'] ?? '',
				'target_url'  => $args['target_url'],
				'qurl_site'   => '',
				'qurl_link'   => '',
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
				__( 'The qURL was created but could not be saved here. Try again.', 'wp-qurl' )
			);
		}

		$response = array(
			'token_id'       => $token_id,
			'resource_id'    => $result['resource_id'] ?? '',
			'crid'           => $result['crid'] ?? '',
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
		$path_id = Database::resource_id( $result['crid'] ?? ( $result['resource_id'] ?? '' ) );
		$qurl_id = sanitize_text_field( $result['qurl_id'] ?? '' );

		if ( $path_id && $qurl_id ) {
			$this->api->revoke_qurl_token( $path_id, $qurl_id );
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
				__( 'Choose a page first.', 'wp-qurl' )
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
				__( 'That page URL is not valid.', 'wp-qurl' )
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
				__( 'That page is not allowed.', 'wp-qurl' )
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
				$defaults['label']      = __( 'Visitor qURL', 'wp-qurl' );
				$defaults['meta']       = array(
					'source'     => 'frontend_block',
					'guest_mint' => true,
				);
			} else {
				$defaults['label'] = $user->exists()
					? sprintf(
						/* translators: 1: user display name, 2: user ID */
						__( 'qURL for %s', 'wp-qurl' ),
						$user->display_name
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
				__( 'Enter a page URL that starts with https://.', 'wp-qurl' )
			);
		}

		if ( self::CONTEXT_FRONTEND_BLOCK === $context ) {
			$is_guest = ! empty( $args['meta']['guest_mint'] );

			if ( $is_guest ) {
				if ( ! Blocks::endpoint_allows_visitors( $args['meta']['endpoint'] ?? '' ) ) {
					return new \WP_Error(
						'wp_qurl_forbidden',
						__( 'Visitors cannot make a link for this page.', 'wp-qurl' ),
						array( 'status' => 403 )
					);
				}
			} elseif ( ! Capabilities::current_user_can_mint_self() ) {
				return new \WP_Error(
					'wp_qurl_forbidden',
					__( 'You cannot make a qURL.', 'wp-qurl' ),
					array( 'status' => 403 )
				);
			}

			if ( ! self::is_endpoint_allowed( $args['target_url'] ) ) {
				return new \WP_Error(
					'wp_qurl_endpoint_forbidden',
					__( 'That page is not allowed.', 'wp-qurl' ),
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
		$page_id = self::resolve_page_id( $target_url );
		if ( ! $page_id || ! Page_Meta::is_protected( $page_id ) ) {
			return 0;
		}

		return (int) $page_id;
	}

	/**
	 * Resolve a target URL to a WordPress page ID.
	 *
	 * @param string $target_url Target URL.
	 * @return int
	 */
	public static function resolve_page_id( $target_url ) {
		$admit_page = Page_Meta::page_id_from_admit_url( $target_url );
		if ( $admit_page ) {
			return $admit_page;
		}

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
		$path       = $parts['path'] ?? '/';
		$candidates = array_unique(
			array(
				$clean_url . $path,
				untrailingslashit( $clean_url . $path ),
				trailingslashit( $clean_url . $path ),
			)
		);

		foreach ( $candidates as $candidate ) {
			$page_id = url_to_postid( $candidate );
			if ( $page_id && 'page' === get_post_type( $page_id ) ) {
				return (int) $page_id;
			}
		}

		$slug = trim( (string) $path, '/' );
		if ( '' !== $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && 'page' === $page->post_type ) {
				return (int) $page->ID;
			}
		}

		return 0;
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
	 * Parse a date/time value from LayerV or local UTC storage.
	 *
	 * Naive MySQL datetimes are treated as UTC because the plugin stores
	 * timestamps that way. Values with an offset or Z suffix keep that zone.
	 *
	 * @param mixed $value API or MySQL date/time value.
	 * @return int|null Unix timestamp or null when invalid.
	 */
	public static function parse_api_datetime( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			$ts = (int) $value;
			if ( $ts > 9999999999 ) {
				$ts = (int) floor( $ts / 1000 );
			}
			return $ts > 0 ? $ts : null;
		}

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/^\d{10,13}$/', $value ) ) {
			$ts = (int) $value;
			if ( $ts > 9999999999 ) {
				$ts = (int) floor( $ts / 1000 );
			}
			return $ts > 0 ? $ts : null;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value ) ) {
			$normalized = str_replace( 'T', ' ', $value );
			$dt         = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $normalized, new \DateTimeZone( 'UTC' ) );
			$errors     = \DateTimeImmutable::getLastErrors();
			if ( $dt && ( false === $errors || ( empty( $errors['warning_count'] ) && empty( $errors['error_count'] ) ) ) ) {
				return $dt->getTimestamp();
			}
		}

		try {
			return ( new \DateTimeImmutable( $value ) )->getTimestamp();
		} catch ( \Exception $e ) {
			$timestamp = strtotime( $value );
			return false === $timestamp ? null : $timestamp;
		}
	}

	/**
	 * Combined WordPress date and time format from Settings → General.
	 *
	 * @return string
	 */
	public static function site_datetime_format() {
		return trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
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
	 * Format a stored or API date/time in the site timezone.
	 *
	 * @param mixed  $value    API or MySQL date/time value.
	 * @param string $format   WordPress date format. Empty uses site date + time.
	 * @param string $fallback Value returned when the date is invalid.
	 * @return string
	 */
	public static function format_display_datetime( $value, $format = '', $fallback = '—' ) {
		$timestamp = self::parse_api_datetime( $value );
		if ( null === $timestamp ) {
			return $fallback;
		}

		if ( '' === $format ) {
			$format = self::site_datetime_format();
		}

		$formatted = wp_date( $format, $timestamp );
		return is_string( $formatted ) && '' !== $formatted ? $formatted : $fallback;
	}

	/**
	 * Human label for a LayerV event type.
	 *
	 * @param string $type Event type such as qurl.created.
	 * @return string
	 */
	public static function format_event_type( $type ) {
		$type   = sanitize_text_field( (string) $type );
		$labels = array(
			'qurl.created'          => __( 'Minted', 'wp-qurl' ),
			'qurl.accessed'         => __( 'Accessed', 'wp-qurl' ),
			'qurl.access_denied'    => __( 'Access denied', 'wp-qurl' ),
			'qurl.updated'          => __( 'Updated', 'wp-qurl' ),
			'qurl.expired'          => __( 'Expired', 'wp-qurl' ),
			'qurl.revoked'          => __( 'Revoked', 'wp-qurl' ),
			'qurl.token_exhausted'  => __( 'Used up', 'wp-qurl' ),
			'resource.closed'       => __( 'Closed', 'wp-qurl' ),
			'token.expired'         => __( 'Expired', 'wp-qurl' ),
		);

		return $labels[ $type ] ?? $type;
	}

	/**
	 * Format a LayerV quota percentage for display.
	 *
	 * GET /v1/quota already returns a percent (4.5 means 4.5%), not a 0–1 fraction.
	 *
	 * @param mixed $value API percent field.
	 * @return string
	 */
	public static function format_quota_percent( $value ) {
		if ( ! is_numeric( $value ) ) {
			return '';
		}

		return number_format_i18n( (float) $value, 1 ) . '%';
	}

	/**
	 * Format used/max quota counts. -1 means unlimited.
	 *
	 * @param mixed $used Current usage.
	 * @param mixed $max  Plan cap.
	 * @return string
	 */
	public static function format_quota_count( $used, $max = null ) {
		if ( ! is_numeric( $used ) ) {
			return '—';
		}

		$used_label = number_format_i18n( (int) $used );
		if ( ! is_numeric( $max ) ) {
			return $used_label;
		}

		$max = (int) $max;
		if ( $max < 0 ) {
			/* translators: %s: current usage count */
			return sprintf( __( '%s (unlimited)', 'wp-qurl' ), $used_label );
		}

		return $used_label . ' / ' . number_format_i18n( $max );
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
				__( 'Daily limit reached. Try again tomorrow.', 'wp-qurl' ),
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

		$remote_addr = ! empty( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';
		return 'guest_' . md5( $remote_addr );
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
	 * Treat already-inactive LayerV responses as success.
	 *
	 * DELETE 503 means LayerV saved the revoke and is still clearing caches.
	 *
	 * @param \WP_Error $error API error.
	 * @return bool
	 */
	public static function is_already_gone( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data       = $error->get_error_data();
		$status     = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		$error_code = is_array( $data ) ? (string) ( $data['error_code'] ?? '' ) : '';

		if ( in_array( $status, array( 404, 409, 410, 503 ), true ) ) {
			return true;
		}

		if ( in_array( $error_code, array( 'not_found', 'resource_not_found', 'qurl_not_found', 'already_revoked', 'resource_closed', 'gone', 'service_unavailable' ), true ) ) {
			return true;
		}

		return (bool) preg_match( '/not active|already revoked|already inactive|no longer active|not found|has been revoked|was revoked|does not support revocation/i', $error->get_error_message() );
	}

	/**
	 * Candidate LayerV path IDs: CRID first, public key as fallback.
	 *
	 * @param string $crid        CRID.
	 * @param string $resource_id Public key.
	 * @return string[]
	 */
	private static function path_ids( $crid, $resource_id ) {
		$ids = array();
		foreach ( array( $crid, $resource_id ) as $id ) {
			$id = sanitize_text_field( $id );
			if ( $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Call LayerV with each stored ID until one succeeds or is already gone.
	 *
	 * @param callable $callback Receives one path ID.
	 * @param array    $ids      Candidate IDs.
	 * @return true|\WP_Error
	 */
	private static function with_path_ids( callable $callback, array $ids ) {
		$last = new \WP_Error( 'wp_qurl_invalid_resource', __( 'Resource ID is required.', 'wp-qurl' ) );

		foreach ( $ids as $id ) {
			$result = $callback( $id );
			if ( ! is_wp_error( $result ) || self::is_already_gone( $result ) ) {
				return true;
			}

			$last   = $result;
			$data   = $result->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
			if ( ! in_array( $status, array( 400, 404 ), true ) ) {
				return $result;
			}
		}

		return $last;
	}

	/**
	 * Revoke one local qURL after LayerV accepts the delete.
	 *
	 * @param int $local_id Local token row ID.
	 * @return true|\WP_Error
	 */
	public static function revoke_token( $local_id ) {
		$token = Database::get_token( absint( $local_id ) );
		if ( ! $token ) {
			return new \WP_Error( 'wp_qurl_not_found', __( 'No qURL found to revoke.', 'wp-qurl' ) );
		}

		$ids    = self::path_ids( $token->crid, $token->resource_id );
		$api    = new Api_Client();
		$result = self::with_path_ids(
			static function ( $id ) use ( $api, $token ) {
				return $api->revoke_qurl_token( $id, $token->qurl_id );
			},
			$ids
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Database::revoke_tokens( $ids[0] ?? '', $token->qurl_id );
		Grant::forget_qurl( $token->qurl_id );
		return true;
	}

	/**
	 * Revoke a resource and every local link for it.
	 *
	 * @param string $resource_id LayerV CRID or public key.
	 * @return true|\WP_Error
	 */
	public static function revoke_resource( $resource_id ) {
		$resolved = Database::resource_id( $resource_id );
		$ids      = self::path_ids( $resolved, $resource_id );
		if ( ! $ids ) {
			return new \WP_Error( 'wp_qurl_invalid_resource', __( 'Resource ID is required.', 'wp-qurl' ) );
		}

		$api    = new Api_Client();
		$result = self::with_path_ids(
			static function ( $id ) use ( $api ) {
				return $api->delete_resource( $id );
			},
			$ids
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $ids as $id ) {
			Database::revoke_tokens( $id );
			Grant::forget_resource( $id );
		}
		return true;
	}

	/**
	 * Extend one qURL's expiration.
	 *
	 * @param int    $local_id  Local token row ID.
	 * @param string $extend_by Duration such as 24h or 7d.
	 * @return true|\WP_Error
	 */
	public static function extend_token( $local_id, $extend_by ) {
		$token     = Database::get_token( absint( $local_id ) );
		$extend_by = sanitize_text_field( $extend_by );
		if ( ! $token ) {
			return new \WP_Error( 'wp_qurl_not_found', __( 'No qURL found to extend.', 'wp-qurl' ) );
		}
		if ( ! preg_match( '/^\d+[smhdw]$/', $extend_by ) ) {
			return new \WP_Error( 'wp_qurl_invalid_duration', __( 'Extension duration is invalid.', 'wp-qurl' ) );
		}

		$ids = self::path_ids( $token->crid, $token->resource_id );
		$api = new Api_Client();
		$result = null;
		foreach ( $ids as $id ) {
			$result = $api->update_qurl_token( $id, $token->qurl_id, array( 'extend_by' => $extend_by ) );
			if ( ! is_wp_error( $result ) ) {
				break;
			}
			$data   = $result->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
			if ( ! in_array( $status, array( 400, 404 ), true ) ) {
				return $result;
			}
		}
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return is_wp_error( $result ) ? $result : new \WP_Error( 'wp_qurl_not_found', __( 'No qURL found to extend.', 'wp-qurl' ) );
		}

		$expires_at = self::format_storage_datetime( $result['expires_at'] ?? '' );
		if ( $expires_at ) {
			Database::update_token(
				$token->id,
				array(
					'expires_at' => $expires_at,
					'status'     => 'active',
				)
			);
		}

		return true;
	}
}
