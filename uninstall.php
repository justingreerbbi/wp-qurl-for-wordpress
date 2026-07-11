<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package WP_QURL
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

wp_clear_scheduled_hook( 'wp_qurl_cleanup' );

// Remove custom tables.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'qurl_tokens' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'qurl_access_log' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'qurl_grants' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'qurl_handoffs' );

// Remove options.
delete_option( 'wp_qurl_settings' );
delete_option( 'wp_qurl_db_version' );
delete_post_meta_by_key( '_wp_qurl_protected' );

$transient_names = array(
	'_transient_wp_qurl_visitor_endpoints',
	'_transient_timeout_wp_qurl_visitor_endpoints',
);
$rate_value      = $wpdb->esc_like( '_transient_wp_qurl_mint_rate_' ) . '%';
$rate_timeout    = $wpdb->esc_like( '_transient_timeout_wp_qurl_mint_rate_' ) . '%';

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s) OR option_name LIKE %s OR option_name LIKE %s",
		$transient_names[0],
		$transient_names[1],
		$rate_value,
		$rate_timeout
	)
);

// Remove capabilities from roles.
$roles = array_keys( wp_roles()->roles );
$caps  = array( 'manage_qurls', 'create_qurls', 'mint_qurl_self' );

foreach ( $roles as $role_slug ) {
	$qurl_role = get_role( $role_slug );
	if ( $qurl_role ) {
		foreach ( $caps as $cap ) {
			$qurl_role->remove_cap( $cap );
		}
	}
}
