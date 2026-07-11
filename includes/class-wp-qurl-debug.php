<?php
/**
 * Diagnostic helpers for protected-page access troubleshooting.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Collects request, LayerV, and local state for the blocked-page debug panel.
 */
class Debug {

	/**
	 * Whether the blocked-page debug panel is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) Settings::get( 'enable_debug_mode', false );
	}

	/**
	 * Build a diagnostic snapshot for a blocked or failed handoff response.
	 *
	 * @param int    $post_id      Protected page ID, or 0 when unknown.
	 * @param string $block_reason Block reason code.
	 * @return array
	 */
	public static function collect_block_context( $post_id, $block_reason ) {
		$post_id = absint( $post_id );
		$api     = new Api_Client();

		$resource_ids = $post_id ? Page_Meta::get_protection_resource_ids( $post_id ) : array();

		$client_ip    = Protector::get_client_ip();
		$remote_addr  = ! empty( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$request_uri  = ! empty( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
		$query_string = ! empty( $_SERVER['QUERY_STRING'] )
			? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) )
			: '';

		$handoff_token = ! empty( $_GET['wp_qurl_handoff'] )
			? sanitize_text_field( wp_unslash( $_GET['wp_qurl_handoff'] ) )
			: '';

		$context = array(
			'generated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			'block_reason'     => sanitize_key( (string) $block_reason ),
			'plugin_version'   => WP_QURL_VERSION,
			'db_version'       => get_option( 'wp_qurl_db_version', '0' ),
			'page'             => self::collect_page_context( $post_id ),
			'protection'       => array(
				'resource_ids'           => $resource_ids,
				'webhook_registered'     => Settings::is_webhook_registered(),
				'webhook_has_secret'     => Settings::has_webhook_secret(),
				'page_handoff_ready'     => Settings::is_page_handoff_ready(),
				'api_configured'         => Settings::is_configured(),
				'reverse_proxy_detected' => Protector::detects_reverse_proxy(),
			),
			'request'          => array(
				'method'       => ! empty( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
				'request_uri'  => $request_uri,
				'query_string' => $query_string,
				'is_ssl'       => is_ssl(),
				'client_ip'    => $client_ip,
				'remote_addr'  => $remote_addr,
				'ip_candidates'=> self::collect_ip_candidates(),
				'referer'      => ! empty( $_SERVER['HTTP_REFERER'] )
					? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) )
					: '',
				'user_agent'   => ! empty( $_SERVER['HTTP_USER_AGENT'] )
					? sanitize_textarea_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
					: '',
				'handoff_token_present' => '' !== $handoff_token,
				'handoff_token_preview' => self::preview_secret( $handoff_token ),
				'get_params'   => self::collect_get_params(),
			),
			'headers'          => self::collect_request_headers(),
			'qurl_headers'     => self::collect_qurl_headers(),
			'cookies'          => self::collect_qurl_cookies( $post_id ),
			'access_checks'    => self::collect_access_checks( $post_id, $handoff_token ),
			'layer_v_sessions' => self::collect_layer_v_sessions( $api, $resource_ids ),
			'local_records'    => self::collect_local_records( $post_id, $resource_ids, $handoff_token ),
			'recent_access_log'=> self::collect_recent_access_log( $resource_ids, $client_ip ),
		);

		/**
		 * Filter the blocked-page debug payload before it is rendered.
		 *
		 * @param array  $context      Debug context.
		 * @param int    $post_id      Protected page ID.
		 * @param string $block_reason Block reason code.
		 */
		return apply_filters( 'wp_qurl_block_debug_context', $context, $post_id, $block_reason );
	}

	/**
	 * Collect candidate client IPs from each available request source.
	 *
	 * @return array
	 */
	private static function collect_ip_candidates() {
		$remote_addr = ! empty( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$real_ip = ! empty( $_SERVER['HTTP_X_REAL_IP'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) )
			: '';
		$forwarded_for = ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
			: '';
		$cf_ip = ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) )
			: '';

		$forwarded_first = '';
		if ( '' !== $forwarded_for ) {
			$parts           = explode( ',', $forwarded_for );
			$forwarded_first = trim( $parts[0] );
		}

		return array(
			'selected_client_ip'     => Protector::get_client_ip(),
			'remote_addr'            => $remote_addr,
			'x_real_ip'              => $real_ip,
			'x_forwarded_for'        => $forwarded_for,
			'x_forwarded_first'      => $forwarded_first,
			'cf_connecting_ip'       => $cf_ip,
			'reverse_proxy_detected' => Protector::detects_reverse_proxy(),
		);
	}

	/**
	 * Collect page metadata for debug output.
	 *
	 * @param int $post_id Page ID.
	 * @return array
	 */
	private static function collect_page_context( $post_id ) {
		if ( ! $post_id ) {
			return array(
				'id'          => 0,
				'title'       => '',
				'permalink'   => '',
				'is_protected'=> false,
			);
		}

		return array(
			'id'           => $post_id,
			'title'        => get_the_title( $post_id ),
			'permalink'    => get_permalink( $post_id ),
			'is_protected' => Page_Meta::is_protected( $post_id ),
		);
	}

	/**
	 * Collect non-sensitive query parameters.
	 *
	 * @return array
	 */
	private static function collect_get_params() {
		$params = array();

		foreach ( $_GET as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$params[ $key ] = '[array]';
				continue;
			}

			$scalar = sanitize_text_field( wp_unslash( (string) $value ) );
			if ( 'wp_qurl_handoff' === $key ) {
				$params[ $key ] = self::preview_secret( $scalar );
			} else {
				$params[ $key ] = $scalar;
			}
		}

		return $params;
	}

	/**
	 * Collect inbound request headers relevant to proxy and qURL access.
	 *
	 * @return array
	 */
	private static function collect_request_headers() {
		$headers = array(
			'REMOTE_ADDR'    => ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'REQUEST_METHOD' => ! empty( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
			'REQUEST_URI'    => ! empty( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			'QUERY_STRING'   => ! empty( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '',
			'SERVER_PROTOCOL'=> ! empty( $_SERVER['SERVER_PROTOCOL'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) ) : '',
		);

		foreach ( $_SERVER as $key => $value ) {
			if ( 0 !== strpos( $key, 'HTTP_' ) || ! is_scalar( $value ) ) {
				continue;
			}

			$headers[ $key ] = self::maybe_redact_header( $key, (string) $value );
		}

		ksort( $headers );

		return $headers;
	}

	/**
	 * Collect headers that appear to originate from LayerV or qURL proxying.
	 *
	 * @return array
	 */
	private static function collect_qurl_headers() {
		$patterns = array( 'QURL', 'X_QURL', 'X-QURL', 'LAYER', 'X-LAYER' );
		$found    = array();

		foreach ( $_SERVER as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$normalized = strtoupper( str_replace( '-', '_', (string) $key ) );
			foreach ( $patterns as $pattern ) {
				if ( false !== strpos( $normalized, $pattern ) ) {
					$found[ $key ] = self::maybe_redact_header( $key, (string) $value );
					break;
				}
			}
		}

		if ( empty( $found ) ) {
			$found['_note'] = 'No qURL/LayerV-specific request headers were detected on this request.';
		}

		ksort( $found );

		return $found;
	}

	/**
	 * Redact sensitive inbound header values.
	 *
	 * @param string $key   Header key.
	 * @param string $value Header value.
	 * @return string
	 */
	private static function maybe_redact_header( $key, $value ) {
		$normalized = strtoupper( str_replace( '-', '_', $key ) );

		if ( in_array( $normalized, array( 'HTTP_COOKIE', 'HTTP_AUTHORIZATION', 'HTTP_PROXY_AUTHORIZATION' ), true ) ) {
			return '[redacted — ' . strlen( $value ) . ' bytes]';
		}

		return sanitize_textarea_field( wp_unslash( $value ) );
	}

	/**
	 * Collect qURL-related cookies and decode known grant payloads when possible.
	 *
	 * @param int $post_id Page ID.
	 * @return array
	 */
	private static function collect_qurl_cookies( $post_id ) {
		$cookie_names = array(
			Service::GUEST_COOKIE_NAME,
		);

		if ( $post_id ) {
			$cookie_names[] = 'wp_qurl_page_' . absint( $post_id );
		}

		$cookies = array(
			'present' => array(),
			'decoded' => array(),
		);

		foreach ( $cookie_names as $cookie_name ) {
			if ( empty( $_COOKIE[ $cookie_name ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
			$cookies['present'][ $cookie_name ] = self::preview_secret( $value );

			if ( 0 === strpos( $cookie_name, 'wp_qurl_page_' ) ) {
				$cookies['decoded'][ $cookie_name ] = self::decode_page_cookie_debug( $value );
			}
		}

		if ( empty( $cookies['present'] ) ) {
			$cookies['present']['_note'] = 'No qURL cookies were sent with this request.';
		}

		return $cookies;
	}

	/**
	 * Decode a page-grant cookie for debug display.
	 *
	 * @param string $value Cookie value.
	 * @return array
	 */
	private static function decode_page_cookie_debug( $value ) {
		$parts = explode( '.', $value, 2 );
		if ( 2 !== count( $parts ) ) {
			return array( 'valid' => false, 'reason' => 'Malformed cookie structure.' );
		}

		$padding = strlen( $parts[0] ) % 4;
		$encoded = strtr( $parts[0], '-_', '+/' );
		if ( $padding ) {
			$encoded .= str_repeat( '=', 4 - $padding );
		}

		$decoded = base64_decode( $encoded, true );
		if ( false === $decoded ) {
			return array( 'valid' => false, 'reason' => 'Could not base64-decode cookie payload.' );
		}

		$payload = json_decode( $decoded, true );

		return array(
			'valid'   => is_array( $payload ),
			'payload' => is_array( $payload ) ? $payload : null,
		);
	}

	/**
	 * Summarize which access checks passed or failed.
	 *
	 * @param int    $post_id       Page ID.
	 * @param string $handoff_token Raw handoff token from the request.
	 * @return array
	 */
	private static function collect_access_checks( $post_id, $handoff_token ) {
		$client_ip  = Protector::get_client_ip();
		$user_agent = ! empty( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_textarea_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		$checks = array(
			'logged_in'                => is_user_logged_in(),
			'user_can_manage_qurls'    => Capabilities::current_user_can_manage(),
			'user_can_edit_page'       => $post_id ? current_user_can( 'edit_page', $post_id ) : false,
			'page_grant_cookie_sent'   => $post_id ? ! empty( $_COOKIE[ 'wp_qurl_page_' . absint( $post_id ) ] ) : false,
			'handoff_token_in_request' => '' !== $handoff_token,
			'client_ip'                => $client_ip,
			'local_ip_ua_grant_match'  => false,
		);

		if ( $post_id ) {
			$matched = Database::find_matching_page_grant( $post_id, $client_ip, $user_agent );
			$checks['local_ip_ua_grant_match'] = (bool) $matched;
			if ( $matched ) {
				$checks['matched_grant'] = array(
					'id'          => (int) $matched->id,
					'resource_id' => $matched->resource_id,
					'qurl_id'     => $matched->qurl_id,
					'src_ip'      => $matched->src_ip,
					'expires_at'  => $matched->expires_at,
					'status'      => $matched->status,
				);
			}
		}

		if ( $post_id && ! empty( $_COOKIE[ 'wp_qurl_page_' . absint( $post_id ) ] ) ) {
			$cookie_value = sanitize_text_field( wp_unslash( $_COOKIE[ 'wp_qurl_page_' . absint( $post_id ) ] ) );
			$decoded      = self::decode_page_cookie_debug( $cookie_value );
			if ( ! empty( $decoded['payload']['grant_id'] ) ) {
				$grant = Database::get_active_page_grant(
					absint( $decoded['payload']['grant_id'] ),
					$post_id
				);
				$checks['page_grant_db_match'] = (bool) $grant;
				$checks['page_grant_payload']  = $decoded['payload'];
			} else {
				$checks['page_grant_db_match'] = false;
			}
		}

		if ( '' !== $handoff_token ) {
			$handoff = Database::get_handoff_by_token( $handoff_token );
			$checks['legacy_handoff_found'] = (bool) $handoff;
			if ( $handoff ) {
				$checks['legacy_handoff'] = array(
					'id'          => (int) $handoff->id,
					'resource_id' => $handoff->resource_id,
					'qurl_id'     => $handoff->qurl_id,
					'page_id'     => (int) $handoff->page_id,
					'target_url'  => $handoff->target_url,
					'expires_at'  => $handoff->expires_at,
					'status'      => $handoff->status,
				);
			}
		}

		return $checks;
	}

	/**
	 * Fetch active LayerV sessions for each accepted resource ID.
	 *
	 * @param Api_Client $api          API client.
	 * @param array      $resource_ids Resource IDs.
	 * @return array
	 */
	private static function collect_layer_v_sessions( Api_Client $api, array $resource_ids ) {
		$sessions = array();

		if ( ! Settings::is_configured() ) {
			return array(
				'_note' => 'LayerV API is not configured; session lookup skipped.',
			);
		}

		if ( empty( $resource_ids ) ) {
			return array(
				'_note' => 'No LayerV resource IDs are associated with this page.',
			);
		}

		foreach ( $resource_ids as $resource_id ) {
			$result = $api->list_sessions( $resource_id );
			if ( is_wp_error( $result ) ) {
				$sessions[ $resource_id ] = array(
					'error' => $result->get_error_message(),
				);
				continue;
			}

			$sessions[ $resource_id ] = is_array( $result ) ? $result : array();
		}

		return $sessions;
	}

	/**
	 * Collect local handoff, grant, and token records related to the page.
	 *
	 * @param int    $post_id       Page ID.
	 * @param array  $resource_ids  Resource IDs.
	 * @param string $handoff_token Raw handoff token.
	 * @return array
	 */
	private static function collect_local_records( $post_id, array $resource_ids, $handoff_token ) {
		global $wpdb;

		$records = array(
			'handoffs' => array(),
			'grants'   => array(),
			'tokens'   => array(),
		);

		if ( $post_id ) {
			$records['handoffs'] = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, resource_id, qurl_id, status, claimed_uses, max_uses, expires_at, created_at FROM ' . Database::handoffs_table() . ' WHERE page_id = %d ORDER BY id DESC LIMIT 10',
					$post_id
				),
				ARRAY_A
			);
			$records['grants'] = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, handoff_id, page_id, resource_id, qurl_id, session_id, delivery_id, src_ip, status, expires_at, claimed_at, created_at FROM ' . Database::grants_table() . ' WHERE page_id = %d ORDER BY id DESC LIMIT 10',
					$post_id
				),
				ARRAY_A
			);
		} elseif ( ! empty( $resource_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $resource_ids ), '%s' ) );
			$records['grants'] = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, handoff_id, page_id, resource_id, qurl_id, session_id, delivery_id, src_ip, status, expires_at, claimed_at, created_at FROM ' . Database::grants_table() . " WHERE resource_id IN ({$placeholders}) ORDER BY id DESC LIMIT 10",
					$resource_ids
				),
				ARRAY_A
			);
		}

		if ( $post_id ) {
			$permalink = get_permalink( $post_id );
			if ( $permalink ) {
				$target = Service::normalize_target_url( $permalink );
				$token_result = Database::get_tokens(
					array(
						'search'   => $target,
						'per_page' => 5,
					)
				);
				foreach ( $token_result['items'] as $token ) {
					$records['tokens'][] = array(
						'id'          => (int) $token->id,
						'resource_id' => $token->resource_id,
						'qurl_id'     => $token->qurl_id,
						'target_url'  => $token->target_url,
						'status'      => $token->status,
						'expires_at'  => $token->expires_at,
						'meta'        => is_array( $token->meta ) ? $token->meta : array(),
					);
				}
			}
		}

		return $records;
	}

	/**
	 * Collect recent webhook access-log entries for the page resources.
	 *
	 * @param array  $resource_ids Resource IDs.
	 * @param string $client_ip    Detected client IP.
	 * @return array
	 */
	private static function collect_recent_access_log( array $resource_ids, $client_ip ) {
		if ( empty( $resource_ids ) ) {
			return array(
				'_note' => 'No resource IDs available for access-log lookup.',
			);
		}

		$result = Database::get_access_log(
			array(
				'resource_ids' => $resource_ids,
				'per_page'     => 10,
				'page'         => 1,
			)
		);

		$entries = array();
		foreach ( $result['items'] as $entry ) {
			$payload = null;
			if ( ! empty( $entry->payload ) ) {
				$payload = json_decode( $entry->payload, true );
			}

			$entries[] = array(
				'id'         => (int) $entry->id,
				'event_type' => $entry->event_type,
				'resource_id'=> $entry->resource_id,
				'qurl_id'    => $entry->qurl_id,
				'src_ip'     => $entry->src_ip,
				'session_id' => $entry->session_id,
				'created_at' => $entry->created_at,
				'ip_matches_client' => ( '' !== $client_ip && $entry->src_ip === $client_ip ),
				'payload'    => is_array( $payload ) ? $payload : $entry->payload,
			);
		}

		return array(
			'total'   => (int) $result['total'],
			'entries' => $entries,
		);
	}

	/**
	 * Preview a secret-like value without exposing the full string.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function preview_secret( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		if ( strlen( $value ) <= 12 ) {
			return str_repeat( '*', strlen( $value ) );
		}

		return substr( $value, 0, 6 ) . '…' . substr( $value, -4 ) . ' (' . strlen( $value ) . ' chars)';
	}
}
