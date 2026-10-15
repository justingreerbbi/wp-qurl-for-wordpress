<?php
/**
 * WordPress-side access grants for qURL-protected pages.
 *
 * LayerV verifies the recipient. This site cannot change server firewall
 * rules, so WordPress records that verification and decides whether the
 * current request may see the page.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Issues, matches, and expires local viewing grants.
 */
class Grant {

	/**
	 * Cookie name for a confirmed page grant.
	 */
	const COOKIE = 'wp_qurl_access';

	/**
	 * Query flag set by the blocked-page retry after a LayerV redirect.
	 */
	const RETRY_QUERY = 'wp_qurl';

	/**
	 * Legacy query flag kept so an admit token in ?wp_qurl_admit= still unlocks.
	 */
	const ADMIT_QUERY = 'wp_qurl_admit';

	/**
	 * Query flag the denied page sets from document.referrer.
	 */
	const VIA_QUERY = 'wp_qurl_via';

	/**
	 * Front-door path LayerV redirects to after verification.
	 *
	 * Query strings on URL-type targets are stripped by LayerV, so the
	 * token lives in the path instead.
	 */
	const ADMIT_PATH = 'qurl-in';

	/**
	 * How long a just-issued grant may be claimed without an IP match.
	 */
	const PENDING_WINDOW = 180;

	/**
	 * Grants table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'qurl_grants';
	}

	/**
	 * Whether the current request may view a protected page.
	 *
	 * @param int $page_id Page ID.
	 * @return bool
	 */
	public static function allows( $page_id ) {
		$page_id = absint( $page_id );
		if ( ! $page_id ) {
			return false;
		}

		if ( self::has_cookie( $page_id ) ) {
			return true;
		}

		return self::claim_layerv_arrival( $page_id );
	}

	/**
	 * Whether this request already holds a signed grant cookie for the page.
	 *
	 * Used by REST, search, and other non-permalink surfaces that must not
	 * treat a spoofable Referer as authorization.
	 *
	 * @param int $page_id Page ID.
	 * @return bool
	 */
	public static function has_cookie( $page_id ) {
		return self::cookie_allows( $page_id );
	}

	/**
	 * Record a grant from a LayerV qurl.accessed webhook.
	 *
	 * @param array $data Webhook data object.
	 * @return int|false
	 */
	public static function from_access_event( array $data ) {
		$qurl_id = sanitize_text_field( $data['qurl_id'] ?? ( $data['qurl_display_id'] ?? '' ) );
		$src_ip  = self::normalize_ip( $data['src_ip'] ?? '' );

		$token = $qurl_id ? Database::get_token_by_qurl_id( $qurl_id ) : null;
		if ( ! $token ) {
			$resource_id = sanitize_text_field( $data['crid'] ?? ( $data['resource_id'] ?? '' ) );
			$token       = $resource_id ? Database::get_latest_active_token_for_resource( $resource_id ) : null;
		}

		if ( ! $token || 'active' !== $token->status ) {
			return false;
		}

		if ( ! $qurl_id ) {
			$qurl_id = $token->qurl_id;
		}

		$page_id = absint( $token->meta['page_id'] ?? 0 );
		if ( ! $page_id ) {
			$page_id = Service::resolve_page_id( $token->target_url );
		}
		if ( ! $page_id || ! Page_Meta::is_protected( $page_id ) ) {
			$page_id = Service::resolve_protected_page_id( $token->target_url );
		}
		if ( ! $page_id ) {
			return false;
		}

		$seconds = absint( $token->meta['session_duration'] ?? 0 );
		if ( $seconds < 1 ) {
			$seconds = HOUR_IN_SECONDS;
		}

		$grant_id = self::issue(
			array(
				'page_id'     => $page_id,
				'target_url'  => $token->target_url,
				'resource_id' => $token->resource_id,
				'crid'        => $token->crid,
				'qurl_id'     => $qurl_id,
				'src_ip'      => $src_ip,
				'user_agent'  => $data['user_agent'] ?? '',
				'session_id'  => $data['session_id'] ?? '',
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + $seconds ),
			)
		);

		if ( $grant_id ) {
			self::purge_page_cache( $page_id );
		}

		return $grant_id;
	}

	/**
	 * Reject leftover /qurl-in/ URLs. Authorization happens on the permalink.
	 */
	public static function maybe_handle_admit_path() {
		if ( ! self::is_admit_path_request() ) {
			return;
		}

		status_header( 403 );
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		wp_die(
			esc_html__( 'This page is private. Use the qURL you were given to open it.', 'wp-qurl' ),
			esc_html__( 'Access Denied', 'wp-qurl' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Unlock the real permalink after LayerV verified the visitor.
	 *
	 * @param int $page_id Page ID.
	 * @return bool
	 */
	private static function claim_layerv_arrival( $page_id ) {
		$page_id = absint( $page_id );
		if ( ! $page_id || ! Database::get_active_tokens_for_page( $page_id ) ) {
			return false;
		}

		if ( self::is_typed_origin_navigation() ) {
			return false;
		}

		if ( ! self::is_layerv_referer_host( self::referer_host() ) ) {
			return false;
		}

		$sec = strtolower( (string) ( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) );
		if ( 'cross-site' !== $sec ) {
			return false;
		}

		return self::complete_admit( $page_id, false );
	}

	/**
	 * Address-bar / bookmark GET against this origin.
	 *
	 * @return bool
	 */
	private static function is_typed_origin_navigation() {
		$site = strtolower( (string) ( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) );
		$mode = strtolower( (string) ( $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '' ) );
		$dest = strtolower( (string) ( $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '' ) );

		return 'none' === $site && ( '' === $mode || 'navigate' === $mode ) && ( '' === $dest || 'document' === $dest );
	}

	/**
	 * Hostname of the HTTP Referer, if present.
	 *
	 * @return string
	 */
	private static function referer_host() {
		$raw  = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$host = (string) wp_parse_url( esc_url_raw( $raw ), PHP_URL_HOST );
		return strtolower( $host );
	}

	/**
	 * Whether a hostname is a LayerV portal/proxy host.
	 *
	 * @param string $host Hostname.
	 * @return bool
	 */
	private static function is_layerv_referer_host( $host ) {
		$host = strtolower( (string) $host );
		if ( '' === $host ) {
			return false;
		}

		return 'qurl.link' === $host
			|| 'www.qurl.link' === $host
			|| 'qurl.site' === $host
			|| str_ends_with( $host, '.qurl.site' );
	}

	/**
	 * Whether this request is a leftover /qurl-in/ URL.
	 *
	 * @return bool
	 */
	private static function is_admit_path_request() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '' === $path ) {
			return false;
		}

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( $home_path && '/' !== $home_path && 0 === strpos( $path, untrailingslashit( $home_path ) ) ) {
			$path = substr( $path, strlen( untrailingslashit( $home_path ) ) );
			if ( '' === $path ) {
				$path = '/';
			}
		}

		return (bool) preg_match( '#^/' . preg_quote( self::ADMIT_PATH, '#' ) . '/#', $path );
	}

	/**
	 * Issue a grant and cookie after LayerV arrival.
	 *
	 * @param int  $page_id Page ID.
	 * @param bool $purge   Whether to purge page caches on this request.
	 * @return bool
	 */
	private static function complete_admit( $page_id, $purge = true ) {
		$page_id = absint( $page_id );
		if ( ! $page_id ) {
			return false;
		}

		$ips     = self::client_ips();
		$seconds = HOUR_IN_SECONDS;
		$token   = Database::get_active_tokens_for_page( $page_id );
		$token   = $token ? $token[0] : null;
		if ( $token ) {
			$seconds = absint( $token->meta['session_duration'] ?? 0 );
			if ( $seconds < 1 ) {
				$seconds = HOUR_IN_SECONDS;
			}
		}

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $seconds );
		self::issue(
			array(
				'page_id'     => $page_id,
				'target_url'  => $token ? $token->target_url : get_permalink( $page_id ),
				'resource_id' => $token ? $token->resource_id : '',
				'crid'        => $token ? $token->crid : '',
				'qurl_id'     => $token ? $token->qurl_id : '',
				'src_ip'      => $ips ? $ips[0] : '',
				'user_agent'  => self::request_user_agent(),
				'expires_at'  => $expires_at,
			)
		);
		self::set_cookie( $page_id, $expires_at );
		if ( $token && ! empty( $token->qurl_id ) ) {
			Database::log_page_access(
				array(
					'resource_id' => $token->resource_id,
					'crid'        => $token->crid,
					'qurl_id'     => $token->qurl_id,
					'src_ip'      => $ips ? $ips[0] : '',
					'user_agent'  => self::request_user_agent(),
				)
			);
		}
		if ( $purge ) {
			self::purge_page_cache( $page_id );
		}

		return true;
	}

	/**
	 * Insert a grant row.
	 *
	 * @param array $data Grant fields.
	 * @return int|false
	 */
	public static function issue( array $data ) {
		global $wpdb;

		$row = wp_parse_args(
			$data,
			array(
				'page_id'     => 0,
				'target_url'  => '',
				'resource_id' => '',
				'crid'        => '',
				'qurl_id'     => '',
				'src_ip'      => '',
				'user_agent'  => '',
				'session_id'  => '',
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			)
		);

		$ok = $wpdb->insert(
			self::table(),
			array(
				'page_id'     => absint( $row['page_id'] ),
				'target_url'  => esc_url_raw( $row['target_url'] ),
				'resource_id' => sanitize_text_field( $row['resource_id'] ),
				'crid'        => sanitize_text_field( $row['crid'] ),
				'qurl_id'     => sanitize_text_field( $row['qurl_id'] ),
				'src_ip'      => self::normalize_ip( $row['src_ip'] ),
				'user_agent'  => sanitize_textarea_field( $row['user_agent'] ),
				'session_id'  => sanitize_text_field( $row['session_id'] ),
				'expires_at'  => $row['expires_at'],
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Forget grants for one qURL.
	 *
	 * @param string $qurl_id qURL ID.
	 */
	public static function forget_qurl( $qurl_id ) {
		global $wpdb;
		$qurl_id = sanitize_text_field( $qurl_id );
		if ( ! $qurl_id ) {
			return;
		}
		$wpdb->delete( self::table(), array( 'qurl_id' => $qurl_id ), array( '%s' ) );
	}

	/**
	 * Forget grants for a resource.
	 *
	 * @param string $resource_id CRID or public key.
	 */
	public static function forget_resource( $resource_id ) {
		global $wpdb;
		$resource_id = sanitize_text_field( $resource_id );
		if ( ! $resource_id ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE resource_id = %s OR crid = %s',
				$resource_id,
				$resource_id
			)
		);
	}

	/**
	 * Drop expired grants.
	 */
	public static function cleanup() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE expires_at <= UTC_TIMESTAMP()' );
	}

	/**
	 * Match a stored grant to this request by client IP.
	 *
	 * @param int      $page_id Page ID.
	 * @param string[] $ips     Candidate client IPs.
	 * @return object|null
	 */
	private static function match_ip( $page_id, array $ips ) {
		global $wpdb;

		$ips = array_values( array_filter( $ips ) );
		if ( ! $ips ) {
			return null;
		}

		$slots = implode( ',', array_fill( 0, count( $ips ), '%s' ) );
		$sql   = 'SELECT * FROM ' . self::table() . " WHERE page_id = %d AND expires_at > UTC_TIMESTAMP() AND src_ip IN ({$slots}) ORDER BY id DESC LIMIT 1";
		$args  = array_merge( array( absint( $page_id ) ), $ips );

		return $wpdb->get_row( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Match a recent grant by browser user-agent when IPs differ behind a WAF.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $ua      Request user-agent.
	 * @return object|null
	 */
	private static function match_user_agent( $page_id, $ua ) {
		global $wpdb;

		$ua = self::normalize_user_agent( $ua );
		if ( '' === $ua ) {
			return null;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE page_id = %d AND expires_at > UTC_TIMESTAMP() AND created_at >= %s ORDER BY id DESC LIMIT 10',
				absint( $page_id ),
				gmdate( 'Y-m-d H:i:s', time() - ( 10 * MINUTE_IN_SECONDS ) )
			)
		);

		foreach ( (array) $rows as $row ) {
			$stored = self::normalize_user_agent( $row->user_agent ?? '' );
			if ( '' !== $stored && hash_equals( $stored, $ua ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Claim a grant issued in the last few seconds after LayerV verification.
	 *
	 * Used when Sucuri/LiteSpeed hide the visitor IP and the first origin hit
	 * happens before headers can be matched. Requires qURL provenance.
	 *
	 * @param int $page_id Page ID.
	 * @return object|null
	 */
	private static function match_pending( $page_id ) {
		global $wpdb;

		if ( ! self::has_qurl_provenance() ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE page_id = %d AND expires_at > UTC_TIMESTAMP() AND created_at >= %s ORDER BY id DESC LIMIT 1',
				absint( $page_id ),
				gmdate( 'Y-m-d H:i:s', time() - self::PENDING_WINDOW )
			)
		);
	}

	/**
	 * Allow when LayerV is actually proxying with a qurl.site Host header.
	 *
	 * @param int $page_id Page ID.
	 * @return bool
	 */
	private static function is_qurl_site_request( $page_id ) {
		$host = self::request_host();
		if ( ! $host || ! str_ends_with( $host, '.qurl.site' ) ) {
			return false;
		}

		$permalink = get_permalink( $page_id );
		if ( ! $permalink ) {
			return false;
		}

		return in_array(
			$host,
			Database::get_qurl_hosts_for_target( Service::normalize_target_url( $permalink ) ),
			true
		);
	}

	/**
	 * Whether this request looks like a LayerV qURL arrival.
	 *
	 * @return bool
	 */
	public static function has_qurl_provenance() {
		if ( isset( $_GET[ self::RETRY_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		$referer = ! empty( $_SERVER['HTTP_REFERER'] )
			? strtolower( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) )
			: '';
		if ( $referer ) {
			$host = (string) wp_parse_url( $referer, PHP_URL_HOST );
			if ( self::is_qurl_host( $host ) ) {
				return true;
			}
		}

		$origin = ! empty( $_SERVER['HTTP_ORIGIN'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) )
			: '';
		if ( $origin ) {
			$host = (string) wp_parse_url( $origin, PHP_URL_HOST );
			if ( self::is_qurl_host( $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Hosts that belong to the LayerV qURL portal.
	 *
	 * @param string $host Hostname.
	 * @return bool
	 */
	private static function is_qurl_host( $host ) {
		$host = strtolower( (string) $host );
		if ( '' === $host ) {
			return false;
		}

		return in_array( $host, array( 'qurl.link', 'qurl.site', 'layerv.ai', 'relay.layerv.ai' ), true )
			|| str_ends_with( $host, '.qurl.site' )
			|| str_ends_with( $host, '.qurl.link' )
			|| str_ends_with( $host, '.layerv.ai' );
	}

	/**
	 * Request host without port.
	 *
	 * @return string
	 */
	private static function request_host() {
		$host = ! empty( $_SERVER['HTTP_HOST'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) )
			: '';
		return preg_replace( '/:\d+$/', '', $host );
	}

	/**
	 * Client IP addresses for this request, including WAF forwarded headers.
	 *
	 * @return string[]
	 */
	public static function client_ips() {
		$keys = array(
			'REMOTE_ADDR',
			'HTTP_CF_CONNECTING_IP',
			'HTTP_TRUE_CLIENT_IP',
			'HTTP_X_SUCURI_CLIENTIP',
			'HTTP_X_SUCURI_CLIENT_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_CLIENT_IP',
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_FORWARDED',
		);

		$ips = array();
		foreach ( $keys as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			if ( 'HTTP_FORWARDED' === $key && preg_match_all( '/for="?\[?([^\s,;\]"]+)/i', $raw, $matches ) ) {
				foreach ( $matches[1] as $ip ) {
					$ips[] = self::normalize_ip( $ip );
				}
				continue;
			}
			foreach ( preg_split( '/\s*,\s*/', $raw ) as $part ) {
				$part  = preg_replace( '/^for=/i', '', $part );
				$ips[] = self::normalize_ip( $part );
			}
		}

		return array_values( array_unique( array_filter( $ips ) ) );
	}

	/**
	 * Current request user-agent.
	 *
	 * @return string
	 */
	public static function request_user_agent() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}
		return sanitize_textarea_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
	}

	/**
	 * Normalize an IP address for comparison.
	 *
	 * @param string $ip Raw IP.
	 * @return string
	 */
	public static function normalize_ip( $ip ) {
		$ip = strtolower( trim( (string) $ip, " \t\n\r\0\x0B\"[]" ) );
		if ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $matches ) ) {
			$ip = $matches[1];
		}
		if ( str_starts_with( $ip, '::ffff:' ) ) {
			$ip = substr( $ip, 7 );
		}
		$valid = filter_var( $ip, FILTER_VALIDATE_IP );
		return $valid ? $valid : '';
	}

	/**
	 * Normalize a user-agent string for comparison.
	 *
	 * @param string $ua Raw user-agent.
	 * @return string
	 */
	public static function normalize_user_agent( $ua ) {
		$ua = strtolower( trim( (string) $ua ) );
		$ua = preg_replace( '/\s+/', ' ', $ua );
		return is_string( $ua ) ? $ua : '';
	}

	/**
	 * Bind a matched grant to the visitor's current IP for later cookie-less hits.
	 *
	 * @param object $row Grant row.
	 * @param string $ip  Client IP.
	 */
	private static function bind_client( $row, $ip ) {
		global $wpdb;

		$ip = self::normalize_ip( $ip );
		if ( ! $ip || empty( $row->id ) ) {
			return;
		}

		if ( ! empty( $row->src_ip ) && $row->src_ip === $ip ) {
			return;
		}

		$wpdb->update(
			self::table(),
			array( 'src_ip' => $ip ),
			array( 'id' => absint( $row->id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Drop LiteSpeed/object cache for a page after a grant is issued.
	 *
	 * @param int $page_id Page ID.
	 */
	public static function purge_page_cache( $page_id ) {
		$page_id = absint( $page_id );
		if ( ! $page_id ) {
			return;
		}

		clean_post_cache( $page_id );
		do_action( 'litespeed_purge_post', $page_id );

		$url = get_permalink( $page_id );
		if ( $url ) {
			do_action( 'litespeed_purge_url', $url );
			do_action( 'litespeed_purge_url', untrailingslashit( $url ) );
		}
	}

	/**
	 * Whether the signed access cookie covers this page.
	 *
	 * @param int $page_id Page ID.
	 * @return bool
	 */
	private static function cookie_allows( $page_id ) {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return false;
		}

		$raw   = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		$parts = explode( '|', $raw );
		if ( 3 !== count( $parts ) ) {
			return false;
		}

		list( $cookie_page, $expires, $hmac ) = $parts;
		if ( ! hash_equals( self::sign( $cookie_page . '|' . $expires ), $hmac ) ) {
			return false;
		}

		return absint( $cookie_page ) === absint( $page_id ) && absint( $expires ) > time();
	}

	/**
	 * Store a short-lived signed cookie after a grant matches.
	 *
	 * @param int    $page_id    Page ID.
	 * @param string $expires_at MySQL UTC expiry.
	 */
	private static function set_cookie( $page_id, $expires_at ) {
		if ( headers_sent() ) {
			return;
		}

		$expires = Service::parse_api_datetime( $expires_at );
		if ( ! $expires || $expires <= time() ) {
			$expires = time() + HOUR_IN_SECONDS;
		}

		$value = absint( $page_id ) . '|' . $expires . '|' . self::sign( absint( $page_id ) . '|' . $expires );

		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		$_COOKIE[ self::COOKIE ] = $value;
	}

	/**
	 * HMAC for cookie payloads.
	 *
	 * @param string $value Unsigned payload.
	 * @return string
	 */
	private static function sign( $value ) {
		return hash_hmac( 'sha256', $value, AUTH_KEY . SECURE_AUTH_KEY . 'wp-qurl-grant' );
	}
}
