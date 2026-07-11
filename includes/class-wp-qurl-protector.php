<?php
/**
 * Front-end access gate for qURL-protected pages.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks unauthenticated qURL access to protected WordPress pages.
 */
class Protector {

	/**
	 * Register front-end hooks.
	 */
	public function init() {
		add_action( 'template_redirect', array( $this, 'maybe_block_page' ), 1 );
	}

	/**
	 * Block protected pages when the visitor lacks a valid page grant.
	 */
	public function maybe_block_page() {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! Page_Meta::is_protected( $post_id ) ) {
			return;
		}

		$this->private_page_headers();

		if ( $this->user_can_bypass( $post_id ) ) {
			return;
		}

		if ( $this->has_valid_page_grant( $post_id ) ) {
			return;
		}

		$this->render_blocked( $post_id, 'denied' );
	}

	/**
	 * Set cache controls for every protected page response, allowed or denied.
	 */
	private function private_page_headers() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'Vary: Cookie', false );
	}

	/**
	 * Check cookie, local IP/UA grant, or live LayerV session match.
	 *
	 * @param int $post_id Page ID.
	 * @return bool
	 */
	private function has_valid_page_grant( $post_id ) {
		if ( $this->has_valid_page_cookie( $post_id ) ) {
			return true;
		}

		$client_ip  = self::get_client_ip();
		$user_agent = ! empty( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_textarea_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		$grant = Database::find_matching_page_grant( $post_id, $client_ip, $user_agent );
		if ( $grant && self::grant_page_access_cookie( $post_id, $grant ) ) {
			return true;
		}

		$grant = $this->match_layer_v_session( $post_id, $client_ip, $user_agent );
		if ( $grant && self::grant_page_access_cookie( $post_id, $grant ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Validate an existing signed page cookie against the local grant row.
	 *
	 * @param int $post_id Page ID.
	 * @return bool
	 */
	private function has_valid_page_cookie( $post_id ) {
		$cookie_name = self::page_cookie_name( $post_id );
		if ( empty( $_COOKIE[ $cookie_name ] ) ) {
			return false;
		}

		$cookie_value = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
		$payload      = self::decode_signed_cookie( $cookie_value );
		if (
			! is_array( $payload )
			|| absint( $payload['page_id'] ?? 0 ) !== absint( $post_id )
			|| absint( $payload['expires_at'] ?? 0 ) <= time()
		) {
			$this->clear_page_cookie( $post_id );
			return false;
		}

		$grant = Database::get_active_page_grant(
			absint( $payload['grant_id'] ?? 0 ),
			$post_id
		);

		if ( ! $grant ) {
			$this->clear_page_cookie( $post_id );
			return false;
		}

		return true;
	}

	/**
	 * Fall back to live LayerV sessions when the webhook grant is delayed.
	 *
	 * @param int    $post_id    Page ID.
	 * @param string $client_ip  Visitor IP.
	 * @param string $user_agent Visitor user agent.
	 * @return object|null
	 */
	private function match_layer_v_session( $post_id, $client_ip, $user_agent ) {
		if ( ! Settings::is_configured() ) {
			return null;
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return null;
		}

		$resource_ids = Database::get_resource_ids_for_target( Service::normalize_target_url( $permalink ) );
		if ( empty( $resource_ids ) ) {
			return null;
		}

		$api = new Api_Client();
		foreach ( $resource_ids as $resource_id ) {
			$sessions = $api->list_sessions( $resource_id );
			if ( is_wp_error( $sessions ) || ! is_array( $sessions ) ) {
				continue;
			}

			foreach ( $sessions as $session ) {
				if ( ! is_array( $session ) ) {
					continue;
				}
				if ( ! Database::session_matches_visitor( $session, $client_ip, $user_agent ) ) {
					continue;
				}

				$grant = Database::create_grant_from_session( $post_id, $resource_id, $session );
				if ( $grant ) {
					return $grant;
				}
			}
		}

		return null;
	}

	/**
	 * Whether the current user may bypass qURL protection.
	 *
	 * @param int $post_id Page ID.
	 * @return bool
	 */
	private function user_can_bypass( $post_id ) {
		if ( Capabilities::current_user_can_manage() ) {
			return true;
		}

		if ( current_user_can( 'edit_page', $post_id ) ) {
			return true;
		}

		if ( is_preview() && current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Detect a reverse-proxy front end using standard forwarding headers.
	 *
	 * @return bool
	 */
	public static function detects_reverse_proxy() {
		$remote_addr = ! empty( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$real_ip     = ! empty( $_SERVER['HTTP_X_REAL_IP'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) )
			: '';
		$forwarded   = ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
			: '';

		$candidate = '';
		if ( $real_ip && filter_var( $real_ip, FILTER_VALIDATE_IP ) ) {
			$candidate = $real_ip;
		} elseif ( $forwarded ) {
			$parts = explode( ',', $forwarded );
			$first = trim( $parts[0] );
			if ( $first && filter_var( $first, FILTER_VALIDATE_IP ) ) {
				$candidate = $first;
			}
		}

		if ( '' === $candidate || '' === $remote_addr || $candidate === $remote_addr ) {
			return false;
		}

		return ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] )
			|| ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			|| ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] )
			|| ! empty( $_SERVER['HTTP_X_FORWARDED_SERVER'] );
	}

	/**
	 * Get the visitor's IP address.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		$ip            = '';
		$trust_proxy   = (bool) apply_filters( 'wp_qurl_trust_proxy_headers', self::detects_reverse_proxy() );
		$cf_ip         = ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) )
			: '';
		$forwarded_for = ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
			: '';
		$real_ip       = ! empty( $_SERVER['HTTP_X_REAL_IP'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) )
			: '';
		$remote_addr   = ! empty( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( $trust_proxy && $cf_ip && filter_var( $cf_ip, FILTER_VALIDATE_IP ) ) {
			$ip = $cf_ip;
		} elseif ( $trust_proxy && $real_ip && filter_var( $real_ip, FILTER_VALIDATE_IP ) ) {
			$ip = $real_ip;
		} elseif ( $trust_proxy && $forwarded_for ) {
			$parts = explode( ',', $forwarded_for );
			$ip    = trim( $parts[0] );
		} elseif ( $remote_addr ) {
			$ip = $remote_addr;
		}

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = '';
		}

		/**
		 * Filter the client IP used for page unlock matching and rate limits.
		 *
		 * @param string $ip Detected client IP.
		 */
		return apply_filters( 'wp_qurl_client_ip', $ip );
	}

	/**
	 * Set a page-specific cookie after a grant is matched.
	 *
	 * @param int    $page_id Page ID.
	 * @param object $grant   Active grant row.
	 * @return bool Whether the cookie header was issued.
	 */
	public static function grant_page_access_cookie( $page_id, $grant ) {
		if ( headers_sent() || ! $grant ) {
			return false;
		}

		$expires_at = Service::parse_api_datetime( $grant->expires_at ?? '' );
		if ( ! $expires_at || $expires_at <= time() ) {
			return false;
		}

		$value = self::encode_signed_cookie(
			array(
				'grant_id'   => absint( $grant->id ?? 0 ),
				'page_id'    => absint( $page_id ),
				'expires_at' => $expires_at,
			)
		);

		$cookie_name = self::page_cookie_name( $page_id );
		$issued      = setcookie(
			$cookie_name,
			$value,
			array(
				'expires'  => $expires_at,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => true,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		if ( ! $issued ) {
			return false;
		}

		$_COOKIE[ $cookie_name ] = $value;
		return true;
	}

	/**
	 * Build the isolated cookie name for a protected page.
	 *
	 * @param int $page_id Page ID.
	 * @return string
	 */
	private static function page_cookie_name( $page_id ) {
		return 'wp_qurl_page_' . absint( $page_id );
	}

	/**
	 * Sign a local cookie payload.
	 *
	 * @param array $payload Cookie data.
	 * @return string
	 */
	private static function encode_signed_cookie( array $payload ) {
		$json      = wp_json_encode( $payload );
		$encoded   = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding, not obfuscation.
		$signature = hash_hmac( 'sha256', $encoded, wp_salt( 'wp_qurl_page_grant' ) );
		return $encoded . '.' . $signature;
	}

	/**
	 * Verify and decode a local signed cookie.
	 *
	 * @param string $value Cookie value.
	 * @return array|null
	 */
	private static function decode_signed_cookie( $value ) {
		$parts = explode( '.', sanitize_text_field( (string) $value ), 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$expected = hash_hmac( 'sha256', $parts[0], wp_salt( 'wp_qurl_page_grant' ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return null;
		}

		$padding = strlen( $parts[0] ) % 4;
		$encoded = strtr( $parts[0], '-_', '+/' );
		if ( $padding ) {
			$encoded .= str_repeat( '=', 4 - $padding );
		}

		$decoded = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the signed URL-safe cookie payload.
		if ( false === $decoded ) {
			return null;
		}

		$payload = json_decode( $decoded, true );
		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Clear only the local grant cookie for one page.
	 *
	 * @param int $post_id Page ID.
	 */
	private function clear_page_cookie( $post_id ) {
		$cookie_name = self::page_cookie_name( $post_id );
		if ( empty( $_COOKIE[ $cookie_name ] ) ) {
			return;
		}

		if ( ! headers_sent() ) {
			setcookie(
				$cookie_name,
				'',
				array(
					'expires'  => time() - YEAR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => true,
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		unset( $_COOKIE[ $cookie_name ] );
	}

	/**
	 * Clear obsolete pre-handoff cookies on deny.
	 */
	private function clear_obsolete_cookies() {
		foreach ( array( 'wp_qurl_access', 'wp_qurl_access_grant' ) as $cookie_name ) {
			if ( empty( $_COOKIE[ $cookie_name ] ) ) {
				continue;
			}

			if ( ! headers_sent() ) {
				setcookie(
					$cookie_name,
					'',
					array(
						'expires'  => time() - YEAR_IN_SECONDS,
						'path'     => COOKIEPATH ? COOKIEPATH : '/',
						'domain'   => COOKIE_DOMAIN,
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}

			unset( $_COOKIE[ $cookie_name ] );
		}
	}

	/**
	 * Render the blocked access page and exit.
	 *
	 * @param int    $post_id Page ID.
	 * @param string $reason  Block reason code.
	 */
	private function render_blocked( $post_id, $reason ) {
		$this->clear_page_cookie( $post_id );
		$this->clear_obsolete_cookies();

		status_header( 403 );
		nocache_headers();

		$template = WP_QURL_PATH . 'public/views/blocked.php';
		if ( is_readable( $template ) ) {
			$block_reason  = $reason;
			$page_title    = get_the_title( $post_id );
			$debug_context = Debug::is_enabled() && Capabilities::current_user_can_manage()
				? Debug::collect_block_context( $post_id, $reason )
				: null;
			include $template;
			exit;
		}

		wp_die(
			esc_html__( 'This page is protected. Please use your qURL access link to view it.', 'wp-qurl' ),
			esc_html__( 'Access Denied', 'wp-qurl' ),
			array( 'response' => 403 )
		);
	}
}
