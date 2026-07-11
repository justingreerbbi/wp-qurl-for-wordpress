<?php
/**
 * Legacy protected-page handoff redirect.
 *
 * Older qURL links pointed LayerV at ?wp_qurl_handoff=TOKEN. New links target
 * the real page directly; this handler only redirects legacy callbacks.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Redirects legacy handoff callbacks to the real protected page.
 */
class Handoff {

	/**
	 * Register the browser callback before the page protector runs.
	 */
	public function init() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect_legacy' ), 0 );
	}

	/**
	 * Redirect a legacy handoff token to its stored target URL.
	 */
	public function maybe_redirect_legacy() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Legacy opaque callback token.
		if ( empty( $_GET['wp_qurl_handoff'] ) ) {
			return;
		}

		$this->private_response_headers();

		$token = sanitize_text_field( wp_unslash( $_GET['wp_qurl_handoff'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified against stored hash.

		if ( ! preg_match( '/^[A-Za-z0-9]{40,128}$/', $token ) ) {
			$this->render_failure( 'invalid' );
		}

		$handoff = Database::get_handoff_by_token( $token );
		if ( ! $handoff || empty( $handoff->target_url ) || 'revoked' === $handoff->status ) {
			$this->render_failure( 'expired' );
		}

		if ( ! empty( $handoff->expires_at ) && strtotime( $handoff->expires_at . ' UTC' ) <= time() ) {
			$this->render_failure( 'expired' );
		}

		if ( ! $this->is_first_party_request() ) {
			$continue_url = add_query_arg(
				'wp_qurl_handoff',
				$token,
				set_url_scheme( home_url( '/' ), 'https' )
			);
			wp_safe_redirect( $continue_url, 302, 'WP qURL' );
			exit;
		}

		wp_safe_redirect( $handoff->target_url, 302, 'WP qURL' );
		exit;
	}

	/**
	 * Check whether the callback is running on the WordPress origin host.
	 *
	 * @return bool
	 */
	private function is_first_party_request() {
		$site_host    = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$request_host = ! empty( $_SERVER['HTTP_HOST'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) )
			: '';
		$request_host = preg_replace( '/:\d+$/', '', $request_host );

		return '' !== $site_host && hash_equals( $site_host, $request_host );
	}

	/**
	 * Mark handoff responses private and prevent referrer leakage.
	 */
	private function private_response_headers() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}

	/**
	 * Render a safe public handoff error.
	 *
	 * @param string $reason Failure reason.
	 */
	private function render_failure( $reason ) {
		status_header( 403 );
		$block_reason  = $reason;
		$page_title    = '';
		$debug_context = Debug::is_enabled() && Capabilities::current_user_can_manage()
			? Debug::collect_block_context( 0, $reason )
			: null;
		$template      = WP_QURL_PATH . 'public/views/blocked.php';

		if ( is_readable( $template ) ) {
			include $template;
			exit;
		}

		wp_die(
			esc_html__( 'This access link is invalid or expired. Please open a new qURL access link.', 'wp-qurl' ),
			esc_html__( 'Access Denied', 'wp-qurl' ),
			array( 'response' => 403 )
		);
	}
}
