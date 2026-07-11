<?php
/**
 * Database schema and queries for local qURL storage.
 *
 * Portal links (qurl_link) are ephemeral — the LayerV API returns them only once.
 * This class persists links and access events created through WordPress.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Manages custom database tables.
 */
class Database {

	/**
	 * Create or upgrade tables when the plugin schema version changes.
	 */
	public static function maybe_upgrade() {
		$installed_version = get_option( 'wp_qurl_db_version', '0' );

		if ( version_compare( $installed_version, WP_QURL_DB_VERSION, '<' ) ) {
			Settings::migrate_from_db_version( $installed_version );
			self::create_tables();
		}
	}

	/**
	 * Get the tokens table name.
	 *
	 * @global \wpdb $wpdb WordPress database object.
	 * @return string
	 */
	public static function tokens_table() {
		global $wpdb;
		return $wpdb->prefix . 'qurl_tokens';
	}

	/**
	 * Get the access log table name.
	 *
	 * @global \wpdb $wpdb WordPress database object.
	 * @return string
	 */
	public static function access_log_table() {
		global $wpdb;
		return $wpdb->prefix . 'qurl_access_log';
	}

	/**
	 * Get the browser handoffs table name.
	 *
	 * @return string
	 */
	public static function handoffs_table() {
		global $wpdb;
		return $wpdb->prefix . 'qurl_handoffs';
	}

	/**
	 * Get the browser grants table name.
	 *
	 * @return string
	 */
	public static function grants_table() {
		global $wpdb;
		return $wpdb->prefix . 'qurl_grants';
	}

	/**
	 * Create or upgrade custom tables.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$tokens_table    = self::tokens_table();
		$log_table       = self::access_log_table();
		$handoffs_table  = self::handoffs_table();
		$grants_table    = self::grants_table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_tokens = "CREATE TABLE {$tokens_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			resource_id varchar(32) NOT NULL,
			qurl_id varchar(32) NOT NULL DEFAULT '',
			target_url text NOT NULL,
			qurl_link text NOT NULL,
			label varchar(500) NOT NULL DEFAULT '',
			description text NOT NULL,
			expires_at datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			meta longtext,
			PRIMARY KEY  (id),
			KEY resource_id (resource_id),
			KEY qurl_id (qurl_id),
			KEY created_by (created_by),
			KEY status (status)
		) {$charset_collate};";

		$sql_log = "CREATE TABLE {$log_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			resource_id varchar(32) NOT NULL DEFAULT '',
			qurl_id varchar(32) NOT NULL DEFAULT '',
			event_type varchar(64) NOT NULL,
			src_ip varchar(45) NOT NULL DEFAULT '',
			user_agent text NOT NULL,
			session_id varchar(64) NOT NULL DEFAULT '',
			delivery_id varchar(64) DEFAULT NULL,
			payload longtext,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY resource_id (resource_id),
			KEY qurl_id (qurl_id),
			KEY event_type (event_type),
			UNIQUE KEY delivery_id (delivery_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_handoffs = "CREATE TABLE {$handoffs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_id bigint(20) unsigned NOT NULL DEFAULT 0,
			token_hash char(64) NOT NULL,
			resource_id varchar(32) NOT NULL DEFAULT '',
			qurl_id varchar(32) NOT NULL DEFAULT '',
			page_id bigint(20) unsigned NOT NULL,
			target_url text NOT NULL,
			expires_at datetime DEFAULT NULL,
			session_duration int(10) unsigned NOT NULL DEFAULT 3600,
			max_uses int(10) unsigned NOT NULL DEFAULT 1,
			claimed_uses int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY token_id (token_id),
			KEY resource_id (resource_id),
			KEY qurl_id (qurl_id),
			KEY page_status (page_id,status)
		) {$charset_collate};";

		$sql_grants = "CREATE TABLE {$grants_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			handoff_id bigint(20) unsigned NOT NULL DEFAULT 0,
			page_id bigint(20) unsigned NOT NULL DEFAULT 0,
			resource_id varchar(32) NOT NULL DEFAULT '',
			qurl_id varchar(32) NOT NULL DEFAULT '',
			session_id varchar(64) NOT NULL DEFAULT '',
			delivery_id varchar(64) NOT NULL DEFAULT '',
			src_ip varchar(45) NOT NULL DEFAULT '',
			user_agent_hash char(64) NOT NULL DEFAULT '',
			access_count bigint(20) unsigned NOT NULL DEFAULT 0,
			consumed tinyint(1) NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			claimed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY delivery_id (delivery_id),
			KEY handoff_status (handoff_id,status),
			KEY page_status (page_id,status),
			KEY resource_id (resource_id),
			KEY qurl_id (qurl_id),
			KEY session_id (session_id),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		dbDelta( $sql_tokens );
		dbDelta( $sql_log );
		dbDelta( $sql_handoffs );
		dbDelta( $sql_grants );

		if ( self::grant_schema_is_ready() ) {
			update_option( 'wp_qurl_db_version', WP_QURL_DB_VERSION );
		}
	}

	/**
	 * Verify security-critical grant tables before marking migration complete.
	 *
	 * @return bool
	 */
	private static function grant_schema_is_ready() {
		global $wpdb;

		foreach ( array( self::handoffs_table(), self::grants_table() ) as $table ) {
			$found = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
			);
			if ( $found !== $table ) {
				return false;
			}
		}

		$required_handoff_columns = array( 'token_hash', 'resource_id', 'qurl_id', 'page_id', 'target_url', 'expires_at', 'session_duration', 'max_uses', 'claimed_uses', 'status' );
		$required_grant_columns   = array( 'handoff_id', 'page_id', 'resource_id', 'qurl_id', 'delivery_id', 'src_ip', 'user_agent_hash', 'access_count', 'consumed', 'expires_at', 'status', 'claimed_at' );

		foreach ( $required_handoff_columns as $column ) {
			if ( $column !== $wpdb->get_var( 'SHOW COLUMNS FROM ' . self::handoffs_table() . " LIKE '" . esc_sql( $column ) . "'" ) ) {
				return false;
			}
		}
		foreach ( $required_grant_columns as $column ) {
			if ( $column !== $wpdb->get_var( 'SHOW COLUMNS FROM ' . self::grants_table() . " LIKE '" . esc_sql( $column ) . "'" ) ) {
				return false;
			}
		}

		$delivery_column = $wpdb->get_var( 'SHOW COLUMNS FROM ' . self::access_log_table() . " LIKE 'delivery_id'" );
		$log_index       = $wpdb->get_var( 'SHOW INDEX FROM ' . self::access_log_table() . " WHERE Key_name = 'delivery_id' AND Non_unique = 0", 2 );
		$delivery_index  = $wpdb->get_var( 'SHOW INDEX FROM ' . self::grants_table() . " WHERE Key_name = 'delivery_id' AND Non_unique = 0", 2 );
		return 'delivery_id' === $delivery_column
			&& 'delivery_id' === $log_index
			&& 'delivery_id' === $delivery_index;
	}

	/**
	 * Store a handoff generated before a protected qURL is returned.
	 *
	 * @param array $data Handoff values, including the raw token.
	 * @return int|false
	 */
	public static function insert_handoff( array $data ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::handoffs_table(),
			array(
				'token_id'         => absint( $data['token_id'] ?? 0 ),
				'token_hash'       => hash( 'sha256', (string) ( $data['token'] ?? '' ) ),
				'resource_id'      => sanitize_text_field( $data['resource_id'] ?? '' ),
				'qurl_id'          => sanitize_text_field( $data['qurl_id'] ?? '' ),
				'page_id'          => absint( $data['page_id'] ?? 0 ),
				'target_url'       => esc_url_raw( $data['target_url'] ?? '' ),
				'expires_at'       => $data['expires_at'] ?? null,
				'session_duration' => max( 1, absint( $data['session_duration'] ?? HOUR_IN_SECONDS ) ),
				'max_uses'         => max( 1, absint( $data['max_uses'] ?? 1 ) ),
				'claimed_uses'     => 0,
				'status'           => 'active',
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Attach the local token row to a handoff after token persistence.
	 *
	 * @param int $handoff_id Handoff ID.
	 * @param int $token_id   Token ID.
	 * @return bool
	 */
	public static function attach_handoff_token( $handoff_id, $token_id ) {
		global $wpdb;
		return false !== $wpdb->update(
			self::handoffs_table(),
			array( 'token_id' => absint( $token_id ) ),
			array( 'id' => absint( $handoff_id ) ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Remove an unissued handoff after local mint persistence fails.
	 *
	 * @param int $handoff_id Handoff ID.
	 */
	public static function delete_handoff( $handoff_id ) {
		global $wpdb;
		$wpdb->delete(
			self::handoffs_table(),
			array( 'id' => absint( $handoff_id ) ),
			array( '%d' )
		);
	}

	/**
	 * Create an active page grant from an authenticated access webhook.
	 *
	 * @param array  $event_data Webhook event data.
	 * @param string $delivery_id Unique delivery ID.
	 * @return int|false
	 */
	public static function create_grant_from_access( array $event_data, $delivery_id ) {
		global $wpdb;

		$qurl_id     = sanitize_text_field( $event_data['qurl_id'] ?? '' );
		$resource_id = sanitize_text_field( $event_data['resource_id'] ?? '' );
		$delivery_id = sanitize_text_field( $delivery_id );

		if ( '' === $qurl_id || '' === $resource_id || '' === $delivery_id ) {
			return false;
		}

		if ( self::grant_delivery_exists( $delivery_id ) ) {
			return false;
		}

		$token = self::get_token_by_ids( $resource_id, $qurl_id );
		if ( ! $token ) {
			return false;
		}

		$meta    = is_array( $token->meta ) ? $token->meta : array();
		$page_id = absint( $meta['page_id'] ?? 0 );
		if ( ! $page_id ) {
			$page_id = Service::resolve_protected_page_id( $token->target_url );
		}
		if ( ! $page_id ) {
			return false;
		}

		$session_duration = absint( $meta['session_duration'] ?? 0 );
		if ( $session_duration < 1 ) {
			$session_duration = Service::duration_to_seconds( Settings::get( 'default_session_duration', '1h' ) );
		}

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + max( 1, $session_duration ) );
		if ( ! empty( $token->expires_at ) && strtotime( $expires_at . ' UTC' ) > strtotime( $token->expires_at . ' UTC' ) ) {
			$expires_at = $token->expires_at;
		}

		$inserted = $wpdb->insert(
			self::grants_table(),
			array(
				'handoff_id'      => 0,
				'page_id'         => $page_id,
				'resource_id'     => $resource_id,
				'qurl_id'         => $qurl_id,
				'session_id'      => sanitize_text_field( $event_data['session_id'] ?? '' ),
				'delivery_id'     => $delivery_id,
				'src_ip'          => sanitize_text_field( $event_data['src_ip'] ?? '' ),
				'user_agent_hash' => ! empty( $event_data['user_agent'] ) ? hash( 'sha256', sanitize_textarea_field( (string) $event_data['user_agent'] ) ) : '',
				'access_count'    => absint( $event_data['access_count'] ?? 0 ),
				'consumed'        => ! empty( $event_data['consumed'] ) ? 1 : 0,
				'expires_at'      => $expires_at,
				'status'          => 'active',
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Create an active page grant from a matching LayerV session.
	 *
	 * @param int    $page_id     Protected page ID.
	 * @param string $resource_id Resource ID.
	 * @param array  $session     LayerV session payload.
	 * @return object|null
	 */
	public static function create_grant_from_session( $page_id, $resource_id, array $session ) {
		global $wpdb;

		$page_id     = absint( $page_id );
		$resource_id = sanitize_text_field( $resource_id );
		$qurl_id     = sanitize_text_field( $session['qurl_id'] ?? '' );
		$session_id  = sanitize_text_field( $session['session_id'] ?? '' );

		if ( ! $page_id || '' === $resource_id ) {
			return null;
		}

		$token            = self::get_token_by_ids( $resource_id, $qurl_id );
		$meta             = ( $token && is_array( $token->meta ) ) ? $token->meta : array();
		$session_duration = absint( $meta['session_duration'] ?? 0 );
		if ( $session_duration < 1 ) {
			$session_duration = Service::duration_to_seconds( Settings::get( 'default_session_duration', '1h' ) );
		}

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + max( 1, $session_duration ) );
		if ( $token && ! empty( $token->expires_at ) && strtotime( $expires_at . ' UTC' ) > strtotime( $token->expires_at . ' UTC' ) ) {
			$expires_at = $token->expires_at;
		}

		$delivery_id = 'session:' . ( '' !== $session_id ? $session_id : wp_generate_uuid4() );
		if ( self::grant_delivery_exists( $delivery_id ) ) {
			return self::get_active_page_grant_by_delivery( $delivery_id, $page_id );
		}

		$user_agent = sanitize_textarea_field( (string) ( $session['user_agent'] ?? '' ) );
		$inserted   = $wpdb->insert(
			self::grants_table(),
			array(
				'handoff_id'      => 0,
				'page_id'         => $page_id,
				'resource_id'     => $resource_id,
				'qurl_id'         => $qurl_id,
				'session_id'      => $session_id,
				'delivery_id'     => $delivery_id,
				'src_ip'          => sanitize_text_field( $session['src_ip'] ?? '' ),
				'user_agent_hash' => '' !== $user_agent ? hash( 'sha256', $user_agent ) : '',
				'access_count'    => 0,
				'consumed'        => 0,
				'expires_at'      => $expires_at,
				'status'          => 'active',
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return null;
		}

		return self::get_active_page_grant( (int) $wpdb->insert_id, $page_id );
	}

	/**
	 * Look up a local token by LayerV IDs.
	 *
	 * @param string $resource_id Resource ID.
	 * @param string $qurl_id     Optional qURL ID.
	 * @return object|null
	 */
	public static function get_token_by_ids( $resource_id, $qurl_id = '' ) {
		global $wpdb;

		$resource_id = sanitize_text_field( $resource_id );
		$qurl_id     = sanitize_text_field( $qurl_id );
		if ( '' === $resource_id ) {
			return null;
		}

		if ( '' !== $qurl_id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . self::tokens_table() . ' WHERE resource_id = %s AND qurl_id = %s ORDER BY id DESC LIMIT 1',
					$resource_id,
					$qurl_id
				)
			);
			if ( $row ) {
				return self::hydrate_token( $row );
			}
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tokens_table() . ' WHERE resource_id = %s ORDER BY id DESC LIMIT 1',
				$resource_id
			)
		);

		return $row ? self::hydrate_token( $row ) : null;
	}

	/**
	 * Check for an active protected-page handoff keyed by exact LayerV IDs.
	 *
	 * @param string $qurl_id     qURL display ID.
	 * @param string $resource_id Resource ID.
	 * @return bool
	 */
	public static function has_active_handoff_for_qurl( $qurl_id, $resource_id ) {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::handoffs_table() . " WHERE qurl_id = %s AND resource_id = %s AND status = 'active' LIMIT 1",
				sanitize_text_field( $qurl_id ),
				sanitize_text_field( $resource_id )
			)
		);

		return ! empty( $found );
	}

	/**
	 * Check whether an access delivery already created a browser grant.
	 *
	 * @param string $delivery_id LayerV delivery ID.
	 * @return bool
	 */
	public static function grant_delivery_exists( $delivery_id ) {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::grants_table() . ' WHERE delivery_id = %s LIMIT 1',
				sanitize_text_field( $delivery_id )
			)
		);

		return ! empty( $found );
	}

	/**
	 * Atomically claim the next ready grant for a raw handoff token.
	 *
	 * @param string $raw_token  Raw browser handoff token.
	 * @param string $user_agent Browser user agent.
	 * @param string $client_ip  Browser IP address.
	 * @return object|null Joined grant and handoff data.
	 */
	public static function claim_handoff_grant( $raw_token, $user_agent = '', $client_ip = '' ) {
		global $wpdb;

		$token_hash = hash( 'sha256', (string) $raw_token );
		$wpdb->query( 'START TRANSACTION' );
		$handoff = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::handoffs_table() . " WHERE token_hash = %s AND status = 'active' AND claimed_uses < max_uses AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) LIMIT 1 FOR UPDATE",
				$token_hash
			)
		);

		if ( ! $handoff || (int) $handoff->claimed_uses >= (int) $handoff->max_uses ) {
			$wpdb->query( 'ROLLBACK' );
			return null;
		}

		$user_agent_hash = '' !== $user_agent ? hash( 'sha256', $user_agent ) : '';
		$grant           = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::grants_table() . " WHERE handoff_id = %d AND status = 'ready' AND expires_at > UTC_TIMESTAMP() ORDER BY id ASC LIMIT 1 FOR UPDATE",
				(int) $handoff->id
			)
		);

		if ( ! $grant ) {
			$wpdb->query( 'ROLLBACK' );
			return null;
		}

		$bind_user_agent = (bool) apply_filters( 'wp_qurl_bind_handoff_user_agent', false );
		if ( $bind_user_agent && $grant->user_agent_hash && ! hash_equals( $grant->user_agent_hash, $user_agent_hash ) ) {
			$wpdb->query( 'ROLLBACK' );
			return null;
		}

		$bind_ip = (bool) apply_filters( 'wp_qurl_bind_handoff_ip', false );
		if ( $bind_ip && $grant->src_ip && $client_ip && $grant->src_ip !== $client_ip ) {
			$wpdb->query( 'ROLLBACK' );
			return null;
		}

		$reserved = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::handoffs_table() . ' SET claimed_uses = claimed_uses + 1 WHERE id = %d AND claimed_uses < max_uses',
				(int) $handoff->id
			)
		);

		if ( 1 !== (int) $reserved ) {
			$wpdb->query( 'ROLLBACK' );
			return null;
		}

		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::grants_table() . " SET status = 'claimed', claimed_at = UTC_TIMESTAMP() WHERE id = %d AND status = 'ready'",
				(int) $grant->id
			)
		);
		if ( 1 !== (int) $claimed ) {
			$wpdb->query( 'ROLLBACK' );
			return null;
		}

		$grant->page_id     = (int) $handoff->page_id;
		$grant->target_url  = $handoff->target_url;
		$grant->resource_id = $handoff->resource_id;
		$grant->qurl_id     = $handoff->qurl_id;
		$wpdb->query( 'COMMIT' );
		return $grant;
	}

	/**
	 * Release a claim when WordPress could not issue its page cookie.
	 *
	 * @param int $grant_id Grant ID.
	 * @return bool
	 */
	public static function release_handoff_grant( $grant_id ) {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );
		$grant = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::grants_table() . " WHERE id = %d AND status = 'claimed' LIMIT 1 FOR UPDATE",
				absint( $grant_id )
			)
		);

		if ( ! $grant ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		$released = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::grants_table() . " SET status = 'ready', claimed_at = NULL WHERE id = %d AND status = 'claimed'",
				(int) $grant->id
			)
		);
		$restored = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::handoffs_table() . ' SET claimed_uses = GREATEST(0, claimed_uses - 1) WHERE id = %d',
				(int) $grant->handoff_id
			)
		);

		if ( 1 !== (int) $released || 1 !== (int) $restored ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		$wpdb->query( 'COMMIT' );
		return true;
	}

	/**
	 * Find a handoff by its raw browser token (legacy redirect lookup).
	 *
	 * @param string $raw_token Raw handoff token.
	 * @return object|null
	 */
	public static function get_handoff_by_token( $raw_token ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::handoffs_table() . ' WHERE token_hash = %s LIMIT 1',
				hash( 'sha256', (string) $raw_token )
			)
		);
	}

	/**
	 * Find an active handoff by its raw browser token.
	 *
	 * @param string $raw_token Raw handoff token.
	 * @return object|null
	 */
	public static function get_active_handoff( $raw_token ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::handoffs_table() . " WHERE token_hash = %s AND status = 'active' AND claimed_uses < max_uses AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) LIMIT 1",
				hash( 'sha256', (string) $raw_token )
			)
		);
	}

	/**
	 * Check whether a stored handoff can still accept an access event.
	 *
	 * @param int $handoff_id Handoff ID.
	 * @return bool
	 */
	public static function is_handoff_available( $handoff_id ) {
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::handoffs_table() . " WHERE id = %d AND status = 'active' AND claimed_uses < max_uses AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) LIMIT 1",
				absint( $handoff_id )
			)
		);
		return ! empty( $found );
	}

	/**
	 * Get an active page grant by ID.
	 *
	 * @param int $grant_id Grant ID.
	 * @param int $page_id  Page ID.
	 * @return object|null
	 */
	public static function get_active_page_grant( $grant_id, $page_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::grants_table() . " WHERE id = %d AND page_id = %d AND status IN ('active','claimed') AND expires_at > UTC_TIMESTAMP() LIMIT 1",
				absint( $grant_id ),
				absint( $page_id )
			)
		);
	}

	/**
	 * Get an active page grant by delivery ID.
	 *
	 * @param string $delivery_id Delivery ID.
	 * @param int    $page_id     Page ID.
	 * @return object|null
	 */
	public static function get_active_page_grant_by_delivery( $delivery_id, $page_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::grants_table() . " WHERE delivery_id = %s AND page_id = %d AND status IN ('active','claimed') AND expires_at > UTC_TIMESTAMP() LIMIT 1",
				sanitize_text_field( $delivery_id ),
				absint( $page_id )
			)
		);
	}

	/**
	 * Find an active page grant matching visitor IP and/or user agent.
	 *
	 * @param int    $page_id    Page ID.
	 * @param string $client_ip  Visitor IP.
	 * @param string $user_agent Visitor user agent.
	 * @return object|null
	 */
	public static function find_matching_page_grant( $page_id, $client_ip = '', $user_agent = '' ) {
		global $wpdb;

		$page_id = absint( $page_id );
		if ( ! $page_id ) {
			return null;
		}

		$grants = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::grants_table() . " WHERE page_id = %d AND status IN ('active','claimed') AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 50",
				$page_id
			)
		);

		if ( empty( $grants ) ) {
			return null;
		}

		foreach ( $grants as $grant ) {
			if ( self::visitor_matches_identity( $grant->src_ip ?? '', $grant->user_agent_hash ?? '', $client_ip, $user_agent ) ) {
				return $grant;
			}
		}

		return null;
	}

	/**
	 * Whether a visitor matches stored IP and/or user-agent identity.
	 *
	 * When a stored field is present it must match. At least one stored
	 * identity field must be present and match.
	 *
	 * @param string $stored_ip         Stored source IP.
	 * @param string $stored_ua_hash    Stored user-agent hash.
	 * @param string $client_ip         Visitor IP.
	 * @param string $user_agent        Visitor user agent.
	 * @return bool
	 */
	public static function visitor_matches_identity( $stored_ip, $stored_ua_hash, $client_ip, $user_agent ) {
		$stored_ip      = sanitize_text_field( (string) $stored_ip );
		$stored_ua_hash = sanitize_text_field( (string) $stored_ua_hash );
		$client_ip      = sanitize_text_field( (string) $client_ip );
		$user_agent     = sanitize_textarea_field( (string) $user_agent );
		$client_ua_hash = '' !== $user_agent ? hash( 'sha256', $user_agent ) : '';

		$has_ip = '' !== $stored_ip;
		$has_ua = '' !== $stored_ua_hash;

		if ( ! $has_ip && ! $has_ua ) {
			return false;
		}

		if ( $has_ip && ( '' === $client_ip || $stored_ip !== $client_ip ) ) {
			return false;
		}

		if ( $has_ua && ( '' === $client_ua_hash || ! hash_equals( $stored_ua_hash, $client_ua_hash ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a LayerV session matches the visitor IP and/or user agent.
	 *
	 * @param array  $session    LayerV session.
	 * @param string $client_ip  Visitor IP.
	 * @param string $user_agent Visitor user agent.
	 * @return bool
	 */
	public static function session_matches_visitor( array $session, $client_ip, $user_agent ) {
		$ua = sanitize_textarea_field( (string) ( $session['user_agent'] ?? '' ) );
		return self::visitor_matches_identity(
			$session['src_ip'] ?? '',
			'' !== $ua ? hash( 'sha256', $ua ) : '',
			$client_ip,
			$user_agent
		);
	}

	/**
	 * Revoke handoffs and grants for a LayerV token or resource.
	 *
	 * @param string $resource_id Resource ID.
	 * @param string $qurl_id     Optional qURL ID.
	 */
	public static function revoke_handoff_grants( $resource_id, $qurl_id = '' ) {
		global $wpdb;

		$resource_id = sanitize_text_field( $resource_id );
		$qurl_id     = sanitize_text_field( $qurl_id );

		$where = '' !== $qurl_id
			? $wpdb->prepare( 'qurl_id = %s', $qurl_id )
			: $wpdb->prepare( 'resource_id = %s', $resource_id );

		$handoff_ids = $wpdb->get_col( 'SELECT id FROM ' . self::handoffs_table() . " WHERE {$where}" );
		if ( ! empty( $handoff_ids ) ) {
			$ids = implode( ',', array_map( 'absint', $handoff_ids ) );
			$wpdb->query( 'UPDATE ' . self::handoffs_table() . " SET status = 'revoked' WHERE id IN ({$ids})" );
			$wpdb->query( 'UPDATE ' . self::grants_table() . " SET status = 'revoked' WHERE handoff_id IN ({$ids}) AND status <> 'revoked'" );
		}

		if ( '' !== $qurl_id ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::grants_table() . " SET status = 'revoked' WHERE qurl_id = %s AND status <> 'revoked'",
					$qurl_id
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::grants_table() . " SET status = 'revoked' WHERE resource_id = %s AND status <> 'revoked'",
					$resource_id
				)
			);
		}
	}

	/**
	 * Insert a locally stored qURL token record.
	 *
	 * @param array $data Token data.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function insert_token( array $data ) {
		global $wpdb;

		$defaults = array(
			'resource_id' => '',
			'qurl_id'     => '',
			'target_url'  => '',
			'qurl_link'   => '',
			'label'       => '',
			'description' => '',
			'expires_at'  => null,
			'status'      => 'active',
			'created_by'  => get_current_user_id(),
			'meta'        => null,
		);

		$row = wp_parse_args( $data, $defaults );

		if ( is_array( $row['meta'] ) ) {
			$row['meta'] = wp_json_encode( $row['meta'] );
		}

		// Encrypt the portal link at rest.
		$row['qurl_link'] = Crypto::encrypt( $row['qurl_link'] );

		$inserted = $wpdb->insert(
			self::tokens_table(),
			array(
				'resource_id' => sanitize_text_field( $row['resource_id'] ),
				'qurl_id'     => sanitize_text_field( $row['qurl_id'] ),
				'target_url'  => esc_url_raw( $row['target_url'] ),
				'qurl_link'   => $row['qurl_link'],
				'label'       => sanitize_text_field( $row['label'] ),
				'description' => sanitize_textarea_field( $row['description'] ),
				'expires_at'  => $row['expires_at'],
				'status'      => sanitize_key( $row['status'] ),
				'created_by'  => absint( $row['created_by'] ),
				'meta'        => $row['meta'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update a token record.
	 *
	 * @param int   $id   Token row ID.
	 * @param array $data Fields to update.
	 * @return bool
	 */
	public static function update_token( $id, array $data ) {
		global $wpdb;

		$allowed = array( 'status', 'label', 'description', 'expires_at', 'qurl_id', 'meta' );
		$update  = array();
		$formats = array();

		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			if ( 'meta' === $key && is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}
			$update[ $key ] = $value;
			$formats[]      = '%s';
		}

		if ( empty( $update ) ) {
			return false;
		}

		return (bool) $wpdb->update(
			self::tokens_table(),
			$update,
			array( 'id' => absint( $id ) ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Mark active local tokens as revoked for a resource and optional qURL ID.
	 *
	 * @param string $resource_id LayerV resource ID.
	 * @param string $qurl_id     Optional qURL display ID.
	 * @return int Number of rows updated.
	 */
	public static function revoke_active_tokens( $resource_id, $qurl_id = '' ) {
		global $wpdb;

		if ( '' === $resource_id ) {
			return 0;
		}

		if ( '' !== $qurl_id ) {
			return (int) $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::tokens_table() . " SET status = 'revoked' WHERE resource_id = %s AND qurl_id = %s AND status = 'active'",
					sanitize_text_field( $resource_id ),
					sanitize_text_field( $qurl_id )
				)
			);
		}

		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::tokens_table() . " SET status = 'revoked' WHERE resource_id = %s AND status = 'active'",
				sanitize_text_field( $resource_id )
			)
		);
	}

	/**
	 * Get a single token by ID.
	 *
	 * @param int $id Token row ID.
	 * @return object|null
	 */
	public static function get_token( $id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tokens_table() . ' WHERE id = %d',
				absint( $id )
			)
		);

		return self::hydrate_token( $row );
	}

	/**
	 * Get the newest active token for a user and exact target URL.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $target_url Normalized target URL.
	 * @return object|null
	 */
	public static function get_user_active_token_for_target( $user_id, $target_url ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tokens_table() . ' WHERE created_by = %d AND status = %s AND target_url = %s ORDER BY created_at DESC LIMIT 1',
				absint( $user_id ),
				'active',
				esc_url_raw( $target_url )
			)
		);

		return self::hydrate_token( $row );
	}

	/**
	 * Get the newest active visitor token for a guest key and target URL.
	 *
	 * @param string $guest_key  Anonymous visitor identifier.
	 * @param string $target_url Normalized target URL.
	 * @return object|null
	 */
	public static function get_guest_active_token_for_target( $guest_key, $target_url ) {
		global $wpdb;

		if ( '' === $guest_key ) {
			return null;
		}

		$like = '%' . $wpdb->esc_like( '"guest_key":"' . $guest_key . '"' ) . '%';
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tokens_table() . ' WHERE created_by = %d AND status = %s AND target_url = %s AND meta LIKE %s ORDER BY created_at DESC LIMIT 1',
				0,
				'active',
				esc_url_raw( $target_url ),
				$like
			)
		);

		return self::hydrate_token( $row );
	}

	/**
	 * Check whether a user has a locally stored token for a resource.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $resource_id LayerV resource ID.
	 * @return bool
	 */
	public static function user_owns_resource( $user_id, $resource_id ) {
		global $wpdb;

		if ( ! $user_id || '' === $resource_id ) {
			return false;
		}

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::tokens_table() . ' WHERE created_by = %d AND resource_id = %s LIMIT 1',
				absint( $user_id ),
				sanitize_text_field( $resource_id )
			)
		);

		return ! empty( $found );
	}

	/**
	 * Get unique resource IDs associated with a user.
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public static function get_user_resource_ids( $user_id ) {
		global $wpdb;

		if ( ! $user_id ) {
			return array();
		}

		$resource_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT resource_id FROM ' . self::tokens_table() . ' WHERE created_by = %d AND resource_id <> %s',
				absint( $user_id ),
				''
			)
		);

		return array_values( array_unique( array_map( 'sanitize_text_field', $resource_ids ) ) );
	}

	/**
	 * Get distinct LayerV resource IDs for tokens targeting a URL.
	 *
	 * @param string $target_url Page or resource URL.
	 * @return string[]
	 */
	public static function get_resource_ids_for_target( $target_url ) {
		global $wpdb;

		$target_url = Service::normalize_target_url( $target_url );
		if ( '' === $target_url ) {
			return array();
		}

		$resource_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT resource_id FROM ' . self::tokens_table() . ' WHERE target_url = %s AND resource_id <> %s',
				esc_url_raw( $target_url ),
				''
			)
		);

		return array_values( array_filter( array_map( 'sanitize_text_field', $resource_ids ) ) );
	}

	/**
	 * Get tokens with optional filters.
	 *
	 * @param array $args Query arguments.
	 * @return array{items: array, total: int}
	 */
	public static function get_tokens( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'resource_id' => '',
			'created_by'  => 0,
			'status'      => '',
			'search'      => '',
			'per_page'    => 20,
			'page'        => 1,
			'orderby'     => 'created_at',
			'order'       => 'DESC',
		);

		$args   = wp_parse_args( $args, $defaults );
		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['resource_id'] ) ) {
			$where[]  = 'resource_id = %s';
			$values[] = $args['resource_id'];
		}

		if ( ! empty( $args['created_by'] ) ) {
			$where[]  = 'created_by = %d';
			$values[] = absint( $args['created_by'] );
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(target_url LIKE %s OR label LIKE %s OR description LIKE %s OR resource_id LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$allowed_orderby = array( 'created_at', 'expires_at', 'target_url', 'status' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 100, absint( $args['per_page'] ) ) );
		$page     = max( 1, absint( $args['page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql = 'SELECT COUNT(*) FROM ' . self::tokens_table() . " WHERE {$where_sql}";
		$total     = empty( $values )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );

		$query_sql  = 'SELECT * FROM ' . self::tokens_table() . " WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$query_vals = array_merge( $values, array( $per_page, $offset ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_vals ) );

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::hydrate_token( $row );
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Group tokens by target URL for dashboard display.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function get_grouped_by_target( array $args = array() ) {
		$result = self::get_tokens( $args );
		$groups = array();

		foreach ( $result['items'] as $token ) {
			$url = $token->target_url;
			if ( ! isset( $groups[ $url ] ) ) {
				$groups[ $url ] = array(
					'target_url'  => $url,
					'resource_id' => $token->resource_id,
					'tokens'      => array(),
				);
			}
			$groups[ $url ]['tokens'][] = $token;
		}

		return array_values( $groups );
	}

	/**
	 * Insert an access log entry.
	 *
	 * @param array $data Log entry data.
	 * @return int|false
	 */
	public static function insert_access_log( array $data ) {
		global $wpdb;

		$defaults = array(
			'resource_id' => '',
			'qurl_id'     => '',
			'event_type'  => '',
			'src_ip'      => '',
			'user_agent'  => '',
			'session_id'  => '',
			'delivery_id' => null,
			'payload'     => null,
		);

		$row = wp_parse_args( $data, $defaults );

		if ( is_array( $row['payload'] ) ) {
			$row['payload'] = wp_json_encode( $row['payload'] );
		}

		$event_type = Webhook_Manager::sanitize_events( array( $row['event_type'] ) );
		$event_type = isset( $event_type[0] ) ? $event_type[0] : sanitize_text_field( $row['event_type'] );

		$inserted = $wpdb->insert(
			self::access_log_table(),
			array(
				'resource_id' => sanitize_text_field( $row['resource_id'] ),
				'qurl_id'     => sanitize_text_field( $row['qurl_id'] ),
				'event_type'  => $event_type,
				'src_ip'      => sanitize_text_field( $row['src_ip'] ),
				'user_agent'  => sanitize_textarea_field( $row['user_agent'] ),
				'session_id'  => sanitize_text_field( $row['session_id'] ),
				'delivery_id' => empty( $row['delivery_id'] ) ? null : sanitize_text_field( $row['delivery_id'] ),
				'payload'     => $row['payload'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Check whether a webhook delivery was already logged.
	 *
	 * @param string $delivery_id LayerV delivery ID (wd_…).
	 * @return bool
	 */
	public static function delivery_exists( $delivery_id ) {
		global $wpdb;

		if ( '' === $delivery_id ) {
			return false;
		}

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::access_log_table() . ' WHERE delivery_id = %s LIMIT 1',
				sanitize_text_field( $delivery_id )
			)
		);

		return ! empty( $found );
	}

	/**
	 * Delete expired transient access state and trim old audit events.
	 */
	public static function cleanup_expired() {
		global $wpdb;

		$wpdb->query( 'DELETE FROM ' . self::grants_table() . ' WHERE expires_at <= UTC_TIMESTAMP()' );
		$wpdb->query(
			'DELETE h FROM ' . self::handoffs_table() . ' h LEFT JOIN ' . self::grants_table() . " g ON g.handoff_id = h.id WHERE g.id IS NULL AND (h.expires_at <= UTC_TIMESTAMP() OR h.status <> 'active')"
		);
		$wpdb->query( 'UPDATE ' . self::tokens_table() . " SET status = 'expired' WHERE status = 'active' AND expires_at <= UTC_TIMESTAMP()" );
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::tokens_table() . " WHERE status <> 'active' AND created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) )
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::access_log_table() . ' WHERE created_at < %s',
				gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) )
			)
		);
	}

	/**
	 * Get access log entries.
	 *
	 * @param array $args Query arguments.
	 * @return array{items: array, total: int}
	 */
	public static function get_access_log( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'resource_id'  => '',
			'resource_ids' => null,
			'qurl_id'      => '',
			'event_type'   => '',
			'per_page'     => 50,
			'page'         => 1,
		);

		$args   = wp_parse_args( $args, $defaults );
		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['resource_id'] ) ) {
			$where[]  = 'resource_id = %s';
			$values[] = $args['resource_id'];
		} elseif ( is_array( $args['resource_ids'] ) ) {
			$resource_ids = array_values( array_filter( array_map( 'sanitize_text_field', $args['resource_ids'] ) ) );

			if ( empty( $resource_ids ) ) {
				$where[] = '0=1';
			} else {
				$placeholders = implode( ', ', array_fill( 0, count( $resource_ids ), '%s' ) );
				$where[]      = "resource_id IN ({$placeholders})";
				$values       = array_merge( $values, $resource_ids );
			}
		}

		if ( ! empty( $args['qurl_id'] ) ) {
			$where[]  = 'qurl_id = %s';
			$values[] = $args['qurl_id'];
		}

		if ( ! empty( $args['event_type'] ) ) {
			$where[]  = 'event_type = %s';
			$values[] = $args['event_type'];
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 100, absint( $args['per_page'] ) ) );
		$page      = max( 1, absint( $args['page'] ) );
		$offset    = ( $page - 1 ) * $per_page;

		$count_sql = 'SELECT COUNT(*) FROM ' . self::access_log_table() . " WHERE {$where_sql}";
		$total     = empty( $values )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );

		$query_sql  = 'SELECT * FROM ' . self::access_log_table() . " WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$query_vals = array_merge( $values, array( $per_page, $offset ) );

		$items = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_vals ) );

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Decrypt qurl_link and decode meta on a token row.
	 *
	 * @param object|null $row Database row.
	 * @return object|null
	 */
	private static function hydrate_token( $row ) {
		if ( ! $row ) {
			return null;
		}

		$row->qurl_link = Crypto::decrypt( $row->qurl_link );
		if ( ! empty( $row->meta ) ) {
			$row->meta = json_decode( $row->meta, true );
		}

		return $row;
	}
}
