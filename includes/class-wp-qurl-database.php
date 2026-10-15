<?php
/**
 * Local qURL token and webhook event storage.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin's database schema and queries.
 */
class Database {

	/**
	 * Upgrade the schema when needed.
	 */
	public static function maybe_upgrade() {
		if ( version_compare( get_option( 'wp_qurl_db_version', '0' ), WP_QURL_DB_VERSION, '<' ) ) {
			self::create_tables();
			self::forget_stored_portal_secrets();
		}
	}

	/**
	 * Tokens table name.
	 *
	 * @return string
	 */
	public static function tokens_table() {
		global $wpdb;
		return $wpdb->prefix . 'qurl_tokens';
	}

	/**
	 * Access log table name.
	 *
	 * @return string
	 */
	public static function access_log_table() {
		global $wpdb;
		return $wpdb->prefix . 'qurl_access_log';
	}

	/**
	 * Local viewing-grant table name.
	 *
	 * @return string
	 */
	public static function grants_table() {
		return Grant::table();
	}

	/**
	 * Create or upgrade plugin tables.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$tokens  = self::tokens_table();
		$log     = self::access_log_table();
		$grants  = self::grants_table();

		dbDelta(
			"CREATE TABLE {$tokens} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				resource_id varchar(255) NOT NULL DEFAULT '',
				crid varchar(255) NOT NULL DEFAULT '',
				qurl_id varchar(32) NOT NULL DEFAULT '',
				target_url text NOT NULL,
				qurl_site varchar(255) NOT NULL DEFAULT '',
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
				KEY crid (crid),
				KEY qurl_id (qurl_id),
				KEY created_by (created_by),
				KEY status (status)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id varchar(64) NOT NULL DEFAULT '',
				resource_id varchar(255) NOT NULL DEFAULT '',
				crid varchar(255) NOT NULL DEFAULT '',
				qurl_id varchar(32) NOT NULL DEFAULT '',
				event_type varchar(64) NOT NULL,
				src_ip varchar(45) NOT NULL DEFAULT '',
				user_agent text NOT NULL,
				session_id varchar(64) NOT NULL DEFAULT '',
				delivery_id varchar(64) NOT NULL DEFAULT '',
				payload longtext,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY event_id (event_id),
				KEY resource_id (resource_id),
				KEY crid (crid),
				KEY qurl_id (qurl_id),
				KEY event_type (event_type),
				KEY created_at (created_at)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$grants} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				page_id bigint(20) unsigned NOT NULL DEFAULT 0,
				target_url text NOT NULL,
				resource_id varchar(255) NOT NULL DEFAULT '',
				crid varchar(255) NOT NULL DEFAULT '',
				qurl_id varchar(32) NOT NULL DEFAULT '',
				src_ip varchar(45) NOT NULL DEFAULT '',
				user_agent text NOT NULL,
				session_id varchar(64) NOT NULL DEFAULT '',
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY page_id (page_id),
				KEY src_ip (src_ip),
				KEY qurl_id (qurl_id),
				KEY resource_id (resource_id),
				KEY crid (crid),
				KEY expires_at (expires_at)
			) {$collate};"
		);

		update_option( 'wp_qurl_db_version', WP_QURL_DB_VERSION );
	}

	/**
	 * Insert a locally minted qURL.
	 *
	 * @param array $data Token data.
	 * @return int|false
	 */
	public static function insert_token( array $data ) {
		global $wpdb;

		$row = wp_parse_args(
			$data,
			array(
				'resource_id' => '',
				'crid'        => '',
				'qurl_id'     => '',
				'target_url'  => '',
				'qurl_site'   => '',
				'qurl_link'   => '',
				'label'       => '',
				'description' => '',
				'expires_at'  => null,
				'status'      => 'active',
				'created_by'  => get_current_user_id(),
				'meta'        => array(),
			)
		);

		$meta = is_array( $row['meta'] ) ? $row['meta'] : array();
		unset( $meta['qurl_site'], $meta['branded_domain'] );

		$inserted = $wpdb->insert(
			self::tokens_table(),
			array(
				'resource_id' => sanitize_text_field( $row['resource_id'] ),
				'crid'        => sanitize_text_field( $row['crid'] ),
				'qurl_id'     => sanitize_text_field( $row['qurl_id'] ),
				'target_url'  => esc_url_raw( $row['target_url'] ),
				'qurl_site'   => '',
				'qurl_link'   => '',
				'label'       => sanitize_text_field( $row['label'] ),
				'description' => sanitize_textarea_field( $row['description'] ),
				'expires_at'  => $row['expires_at'],
				'status'      => sanitize_key( $row['status'] ),
				'created_by'  => absint( $row['created_by'] ),
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'meta'        => wp_json_encode( $meta ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update allowed token fields.
	 *
	 * @param int   $id   Token row ID.
	 * @param array $data Fields to update.
	 * @return bool
	 */
	public static function update_token( $id, array $data ) {
		global $wpdb;

		$allowed = array( 'resource_id', 'crid', 'qurl_id', 'qurl_site', 'label', 'description', 'expires_at', 'status', 'meta' );
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

		return false !== $wpdb->update(
			self::tokens_table(),
			$update,
			array( 'id' => absint( $id ) ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Get one token row.
	 *
	 * @param int $id Local row ID.
	 * @return object|null
	 */
	public static function get_token( $id ) {
		global $wpdb;
		return self::hydrate_token(
			$wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . self::tokens_table() . ' WHERE id = %d', absint( $id ) )
			)
		);
	}

	/**
	 * Find a token by qURL ID.
	 *
	 * @param string $qurl_id qURL ID.
	 * @return object|null
	 */
	public static function get_token_by_qurl_id( $qurl_id ) {
		global $wpdb;

		$qurl_id = sanitize_text_field( $qurl_id );
		if ( ! $qurl_id ) {
			return null;
		}

		return self::hydrate_token(
			$wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . self::tokens_table() . ' WHERE qurl_id = %s ORDER BY id DESC LIMIT 1',
					$qurl_id
				)
			)
		);
	}

	/**
	 * Latest active token for a CRID or resource public key.
	 *
	 * @param string $resource_id CRID or public key.
	 * @return object|null
	 */
	public static function get_latest_active_token_for_resource( $resource_id ) {
		global $wpdb;

		$resource_id = sanitize_text_field( $resource_id );
		if ( ! $resource_id ) {
			return null;
		}

		return self::hydrate_token(
			$wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . self::tokens_table() . " WHERE (crid = %s OR resource_id = %s) AND status = 'active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) ORDER BY id DESC LIMIT 1",
					$resource_id,
					$resource_id
				)
			)
		);
	}

	/**
	 * Canonical resource identifier: CRID when known, otherwise the public key.
	 *
	 * @param string $id Resource public key or CRID.
	 * @return string
	 */
	public static function resource_id( $id ) {
		$id = sanitize_text_field( $id );
		if ( ! $id ) {
			return '';
		}

		global $wpdb;
		$token = self::hydrate_token(
			$wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . self::tokens_table() . ' WHERE crid = %s OR resource_id = %s ORDER BY id DESC LIMIT 1',
					$id,
					$id
				)
			)
		);

		if ( ! $token ) {
			return $id;
		}

		return $token->crid ? $token->crid : $token->resource_id;
	}

	/**
	 * Get active qurl.site hosts for a target URL.
	 *
	 * @param string $target_url Target URL.
	 * @return string[]
	 */
	public static function get_qurl_hosts_for_target( $target_url ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT qurl_site, meta FROM ' . self::tokens_table() . " WHERE target_url = %s AND status = 'active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())",
				esc_url_raw( Service::normalize_target_url( $target_url ) )
			)
		);

		$hosts = array();
		foreach ( (array) $rows as $row ) {
			$site = $row->qurl_site;
			if ( ! $site && $row->meta ) {
				$meta = json_decode( $row->meta, true );
				$site = is_array( $meta ) ? ( $meta['qurl_site'] ?? '' ) : '';
			}
			$host = strtolower( (string) wp_parse_url( $site, PHP_URL_HOST ) );
			if ( $host ) {
				$hosts[] = $host;
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Active tokens minted for a protected page.
	 *
	 * @param int $page_id Page ID.
	 * @return object[]
	 */
	public static function get_active_tokens_for_page( $page_id ) {
		return self::get_tokens_for_page( $page_id, true );
	}

	/**
	 * Tokens minted for a protected page.
	 *
	 * @param int  $page_id     Page ID.
	 * @param bool $active_only Whether to return only unexpired active tokens.
	 * @return object[]
	 */
	public static function get_tokens_for_page( $page_id, $active_only = false ) {
		global $wpdb;

		$page_id = absint( $page_id );
		if ( ! $page_id ) {
			return array();
		}

		$permalink = get_permalink( $page_id );
		$urls      = array();
		if ( $permalink ) {
			$normalized = Service::normalize_target_url( $permalink );
			$urls[]     = $normalized;
			$urls[]     = untrailingslashit( $normalized );
			$urls[]     = trailingslashit( $normalized );
		}
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );

		$where  = array();
		$values = array();
		if ( $active_only ) {
			$where[] = "status = 'active'";
			$where[] = '(expires_at IS NULL OR expires_at > UTC_TIMESTAMP())';
		}

		$url_sql = '';
		if ( $urls ) {
			$slots   = implode( ',', array_fill( 0, count( $urls ), '%s' ) );
			$url_sql = "target_url IN ({$slots})";
			$values  = array_merge( $values, $urls );
		}

		$page_like_a = '%' . $wpdb->esc_like( '"page_id":' . $page_id . ',' ) . '%';
		$page_like_b = '%' . $wpdb->esc_like( '"page_id":' . $page_id . '}' ) . '%';
		$meta_sql    = '(meta LIKE %s OR meta LIKE %s)';
		$values[]    = $page_like_a;
		$values[]    = $page_like_b;

		if ( $url_sql ) {
			$where[] = "({$url_sql} OR {$meta_sql})";
		} else {
			$where[] = $meta_sql;
		}

		$sql  = 'SELECT * FROM ' . self::tokens_table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 50';
		$rows = $values
			? $wpdb->get_results( $wpdb->prepare( $sql, $values ) )
			: $wpdb->get_results( $sql );

		return array_values( array_filter( array_map( array( __CLASS__, 'hydrate_token' ), (array) $rows ) ) );
	}

	/**
	 * Drop stored portal URLs and qURL site hosts from local token rows.
	 *
	 * Portal links are one-shot secrets and must not be re-displayed.
	 */
	public static function forget_stored_portal_secrets() {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . self::tokens_table() . " SET qurl_link = '', qurl_site = ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Mark local links revoked.
	 *
	 * @param string $resource_id Resource ID or CRID.
	 * @param string $qurl_id     Optional qURL ID.
	 * @return int
	 */
	public static function revoke_tokens( $resource_id, $qurl_id = '' ) {
		global $wpdb;

		if ( $qurl_id ) {
			return (int) $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::tokens_table() . " SET status = 'revoked' WHERE qurl_id = %s AND status = 'active'",
					sanitize_text_field( $qurl_id )
				)
			);
		}

		$resource_id = sanitize_text_field( $resource_id );
		if ( ! $resource_id ) {
			return 0;
		}

		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::tokens_table() . " SET status = 'revoked' WHERE (resource_id = %s OR crid = %s) AND status = 'active'",
				$resource_id,
				$resource_id
			)
		);
	}

	/**
	 * Determine whether a user owns a local resource.
	 *
	 * @param int    $user_id User ID.
	 * @param string $id      Resource ID or CRID.
	 * @return bool
	 */
	public static function user_owns_resource( $user_id, $id ) {
		global $wpdb;
		$id = sanitize_text_field( $id );

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::tokens_table() . ' WHERE created_by = %d AND (resource_id = %s OR crid = %s) LIMIT 1',
				absint( $user_id ),
				$id,
				$id
			)
		);
	}

	/**
	 * Resource IDs owned by a user.
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public static function get_user_resource_ids( $user_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT resource_id, crid FROM ' . self::tokens_table() . ' WHERE created_by = %d',
				absint( $user_id )
			)
		);

		$ids = array();
		foreach ( (array) $rows as $row ) {
			$ids[] = $row->crid ? $row->crid : $row->resource_id;
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Get the newest active token for a user and target.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $target_url Target URL.
	 * @return object|null
	 */
	public static function get_user_active_token_for_target( $user_id, $target_url ) {
		global $wpdb;
		return self::hydrate_token(
			$wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . self::tokens_table() . " WHERE created_by = %d AND target_url = %s AND status = 'active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) ORDER BY id DESC LIMIT 1",
					absint( $user_id ),
					esc_url_raw( $target_url )
				)
			)
		);
	}

	/**
	 * Get the newest active guest token.
	 *
	 * @param string $guest_key  Guest key.
	 * @param string $target_url Target URL.
	 * @return object|null
	 */
	public static function get_guest_active_token_for_target( $guest_key, $target_url ) {
		global $wpdb;
		if ( ! $guest_key ) {
			return null;
		}

		return self::hydrate_token(
			$wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . self::tokens_table() . " WHERE created_by = 0 AND target_url = %s AND status = 'active' AND meta LIKE %s AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) ORDER BY id DESC LIMIT 1",
					esc_url_raw( $target_url ),
					'%' . $wpdb->esc_like( '"guest_key":"' . $guest_key . '"' ) . '%'
				)
			)
		);
	}

	/**
	 * Query local tokens.
	 *
	 * @param array $args Query arguments.
	 * @return array{items: array, total: int}
	 */
	public static function get_tokens( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'resource_id' => '',
				'created_by'  => 0,
				'status'      => '',
				'search'      => '',
				'per_page'    => 20,
				'page'        => 1,
				'orderby'     => 'created_at',
				'order'       => 'DESC',
			)
		);

		$where  = array( '1=1' );
		$values = array();

		if ( $args['resource_id'] ) {
			$where[]  = '(resource_id = %s OR crid = %s)';
			$values[] = $args['resource_id'];
			$values[] = $args['resource_id'];
		}
		if ( $args['created_by'] ) {
			$where[]  = 'created_by = %d';
			$values[] = absint( $args['created_by'] );
		}
		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_key( $args['status'] );
		}
		if ( $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(target_url LIKE %s OR label LIKE %s OR resource_id LIKE %s OR crid LIKE %s OR qurl_id LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = 'SELECT COUNT(*) FROM ' . self::tokens_table() . " WHERE {$where_sql}";
		$total     = $values ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) ) : (int) $wpdb->get_var( $count_sql );

		$allowed_orderby = array( 'created_at', 'expires_at', 'target_url', 'status' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$per_page        = max( 1, min( 1000, absint( $args['per_page'] ) ) );
		$offset          = ( max( 1, absint( $args['page'] ) ) - 1 ) * $per_page;
		$query           = 'SELECT * FROM ' . self::tokens_table() . " WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$rows            = $wpdb->get_results( $wpdb->prepare( $query, array_merge( $values, array( $per_page, $offset ) ) ) );

		return array(
			'items' => array_map( array( __CLASS__, 'hydrate_token' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Group tokens by resource, not merely target URL.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function get_grouped_by_target( array $args = array() ) {
		$result = self::get_tokens( $args );
		$groups = array();

		foreach ( $result['items'] as $token ) {
			$key = $token->crid ? $token->crid : $token->resource_id;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'target_url'  => $token->target_url,
					'resource_id' => $token->resource_id,
					'crid'        => $token->crid,
					'tokens'      => array(),
				);
			}
			$groups[ $key ]['tokens'][] = $token;
		}

		return array_values( $groups );
	}

	/**
	 * Insert one verified webhook event.
	 *
	 * @param array $data Event data.
	 * @return int|false
	 */
	public static function insert_access_log( array $data ) {
		global $wpdb;

		$row = wp_parse_args(
			$data,
			array(
				'event_id'    => '',
				'resource_id' => '',
				'crid'        => '',
				'qurl_id'     => '',
				'event_type'  => '',
				'src_ip'      => '',
				'user_agent'  => '',
				'session_id'  => '',
				'delivery_id' => '',
				'payload'     => null,
			)
		);

		$payload = is_array( $row['payload'] ) ? wp_json_encode( $row['payload'] ) : $row['payload'];
		$ok      = $wpdb->insert(
			self::access_log_table(),
			array(
				'event_id'    => sanitize_text_field( $row['event_id'] ),
				'resource_id' => sanitize_text_field( $row['resource_id'] ),
				'crid'        => sanitize_text_field( $row['crid'] ),
				'qurl_id'     => sanitize_text_field( $row['qurl_id'] ),
				'event_type'  => sanitize_text_field( $row['event_type'] ),
				'src_ip'      => sanitize_text_field( $row['src_ip'] ),
				'user_agent'  => sanitize_textarea_field( $row['user_agent'] ),
				'session_id'  => sanitize_text_field( $row['session_id'] ),
				'delivery_id' => sanitize_text_field( $row['delivery_id'] ),
				'payload'     => $payload,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Record that a protected page was unlocked after a LayerV arrival.
	 *
	 * URL-type LayerV resources often never emit qurl.accessed. WordPress
	 * logs the origin unlock so the access log matches real visits.
	 *
	 * @param array $data Resource identifiers and request metadata.
	 * @return int|true|false Insert ID, true when a recent duplicate was skipped, or false on failure.
	 */
	public static function log_page_access( array $data ) {
		$qurl_id = sanitize_text_field( $data['qurl_id'] ?? '' );
		if ( $qurl_id && self::recent_event( $qurl_id, 'qurl.accessed', 180 ) ) {
			return true;
		}

		return self::insert_access_log(
			array(
				'event_id'    => 'og_' . bin2hex( random_bytes( 12 ) ),
				'resource_id' => $data['resource_id'] ?? '',
				'crid'        => $data['crid'] ?? '',
				'qurl_id'     => $qurl_id,
				'event_type'  => 'qurl.accessed',
				'src_ip'      => $data['src_ip'] ?? '',
				'user_agent'  => $data['user_agent'] ?? '',
				'session_id'  => $data['session_id'] ?? '',
				'delivery_id' => '',
				'payload'     => array(
					'source' => 'origin_unlock',
				),
			)
		);
	}

	/**
	 * Whether an event of this type was stored for a qURL recently.
	 *
	 * @param string $qurl_id qURL ID.
	 * @param string $type    Event type.
	 * @param int    $window  Seconds.
	 * @return bool
	 */
	public static function recent_event( $qurl_id, $type, $window = 180 ) {
		global $wpdb;

		$qurl_id = sanitize_text_field( $qurl_id );
		$type    = sanitize_text_field( $type );
		$window  = max( 1, absint( $window ) );
		if ( ! $qurl_id || ! $type ) {
			return false;
		}

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::access_log_table() . ' WHERE qurl_id = %s AND event_type = %s AND created_at >= %s LIMIT 1',
				$qurl_id,
				$type,
				gmdate( 'Y-m-d H:i:s', time() - $window )
			)
		);
	}

	/**
	 * Check whether an event was already processed.
	 *
	 * @param string $event_id Event ID.
	 * @return bool
	 */
	public static function delivery_exists( $event_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::access_log_table() . ' WHERE event_id = %s LIMIT 1',
				sanitize_text_field( $event_id )
			)
		);
	}

	/**
	 * Query the webhook log.
	 *
	 * @param array $args Query arguments.
	 * @return array{items: array, total: int}
	 */
	public static function get_access_log( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'resource_id'  => '',
				'resource_ids' => null,
				'qurl_id'      => '',
				'event_type'   => '',
				'per_page'     => 50,
				'page'         => 1,
			)
		);

		$where  = array( '1=1' );
		$values = array();
		if ( $args['resource_id'] ) {
			$where[]  = '(resource_id = %s OR crid = %s)';
			$values[] = $args['resource_id'];
			$values[] = $args['resource_id'];
		} elseif ( is_array( $args['resource_ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'sanitize_text_field', $args['resource_ids'] ) ) );
			if ( ! $ids ) {
				$where[] = '0=1';
			} else {
				$slots    = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
				$where[]  = "(resource_id IN ({$slots}) OR crid IN ({$slots}))";
				$values   = array_merge( $values, $ids, $ids );
			}
		}
		if ( $args['qurl_id'] ) {
			$where[]  = 'qurl_id = %s';
			$values[] = $args['qurl_id'];
		}
		if ( $args['event_type'] ) {
			$where[]  = 'event_type = %s';
			$values[] = $args['event_type'];
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = 'SELECT COUNT(*) FROM ' . self::access_log_table() . " WHERE {$where_sql}";
		$total     = $values ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) ) : (int) $wpdb->get_var( $count_sql );
		$per_page  = max( 1, min( 100, absint( $args['per_page'] ) ) );
		$offset    = ( max( 1, absint( $args['page'] ) ) - 1 ) * $per_page;
		$query     = 'SELECT * FROM ' . self::access_log_table() . " WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$items     = $wpdb->get_results( $wpdb->prepare( $query, array_merge( $values, array( $per_page, $offset ) ) ) );

		return array(
			'items' => (array) $items,
			'total' => $total,
		);
	}

	/**
	 * Expire local tokens and trim old records.
	 */
	public static function cleanup_expired() {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . self::tokens_table() . " SET status = 'expired' WHERE status = 'active' AND expires_at <= UTC_TIMESTAMP()" );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::tokens_table() . " WHERE status <> 'active' AND created_at < %s", $cutoff ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::access_log_table() . ' WHERE created_at < %s', $cutoff ) );
		Grant::cleanup();
	}

	/**
	 * Decode stored token values.
	 *
	 * @param object|null $row Row.
	 * @return object|null
	 */
	public static function hydrate_token( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row->qurl_link = '';
		$row->qurl_site = '';
		$row->meta      = $row->meta ? json_decode( $row->meta, true ) : array();
		if ( is_array( $row->meta ) ) {
			unset( $row->meta['qurl_site'], $row->meta['branded_domain'] );
		} else {
			$row->meta = array();
		}
		return $row;
	}
}
