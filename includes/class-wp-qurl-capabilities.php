<?php
/**
 * Custom capabilities for qURL management.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and assigns plugin capabilities.
 */
class Capabilities {

	/**
	 * Capability for full qURL management.
	 */
	const MANAGE = 'manage_qurls';

	/**
	 * Capability for creating qURLs.
	 */
	const CREATE = 'create_qurls';

	/**
	 * Capability for frontend self-service minting.
	 */
	const MINT_SELF = 'mint_qurl_self';

	/**
	 * Register capabilities on admin init (idempotent).
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'assign_role_caps' ) );
	}

	/**
	 * Assign capabilities to WordPress roles.
	 */
	public static function assign_role_caps() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::MANAGE );
			$admin->add_cap( self::CREATE );
			$admin->add_cap( self::MINT_SELF );
		}

		foreach ( array( 'editor', 'author' ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( $role ) {
				$role->add_cap( self::CREATE );
				$role->add_cap( self::MINT_SELF );
			}
		}

		foreach ( Settings::get_frontend_mint_roles() as $role_slug ) {
			$role = get_role( $role_slug );
			if ( $role ) {
				$role->add_cap( self::MINT_SELF );
			}
		}
	}

	/**
	 * Check whether the current user can manage all qURLs.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::MANAGE );
	}

	/**
	 * Check whether the current user can create qURLs.
	 *
	 * @return bool
	 */
	public static function current_user_can_create() {
		return current_user_can( self::CREATE ) || current_user_can( self::MANAGE );
	}

	/**
	 * Check whether the current user can mint a self-service qURL on the frontend.
	 *
	 * @return bool
	 */
	public static function current_user_can_mint_self() {
		$allowed = current_user_can( self::MINT_SELF );

		/**
		 * Filter whether the current user may mint a frontend qURL.
		 *
		 * @param bool $allowed Default capability check result.
		 */
		return (bool) apply_filters( 'wp_qurl_user_can_mint_self', $allowed );
	}
}
