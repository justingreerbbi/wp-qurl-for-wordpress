<?php
/**
 * Front-end gate for qURL-protected pages.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks the public permalink unless WordPress has a local access grant.
 *
 * LayerV authenticates the recipient. This host cannot change server
 * firewall rules, so the plugin records that access and unlocks the page.
 */
class Protector {

	/**
	 * Register the page gate and content-leak filters.
	 */
	public function init() {
		add_action( 'wp', array( $this, 'maybe_block_page' ), 1 );
		add_action( 'pre_get_posts', array( $this, 'exclude_from_public_queries' ), 9 );
		add_filter( 'the_content', array( $this, 'filter_content' ), 1 );
		add_filter( 'the_excerpt', array( $this, 'filter_excerpt' ), 1 );
		add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), 1 );
		add_filter( 'rest_pre_dispatch', array( $this, 'filter_rest_pre_dispatch' ), 10, 3 );
		add_filter( 'rest_page_query', array( $this, 'filter_rest_page_query' ), 10, 2 );
		add_filter( 'rest_prepare_page', array( $this, 'filter_rest_prepare_page' ), 10, 3 );
		add_filter( 'rest_post_search_query', array( $this, 'filter_rest_search_query' ), 10, 2 );
		add_filter( 'oembed_request_post_id', array( $this, 'filter_oembed_post_id' ), 10, 2 );
		add_filter( 'oembed_response_data', array( $this, 'filter_oembed_response' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filter_wp_sitemaps' ), 10, 2 );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( $this, 'exclude_ids_from_sitemap' ) );
		add_filter( 'rank_math/sitemap/exclude_post_ids', array( $this, 'exclude_ids_from_sitemap' ) );
	}

	/**
	 * Deny direct-origin access to a protected page.
	 */
	public function maybe_block_page() {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$page_id = get_queried_object_id();
		if ( ! Page_Meta::is_protected( $page_id ) ) {
			return;
		}

		$this->private_page_headers();

		$allowed = $this->user_can_bypass( $page_id ) || Grant::allows( $page_id );
		if ( $allowed ) {
			return;
		}

		$this->render_blocked( $page_id );
	}

	/**
	 * Keep protected pages out of search, feeds, archives, and other lists.
	 *
	 * Singular permalink queries are left intact so they can 403 instead of 404.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function exclude_from_public_queries( $query ) {
		if ( ! $query instanceof \WP_Query ) {
			return;
		}

		if ( $this->is_privileged_query_context() ) {
			return;
		}

		if ( $query->is_singular() ) {
			return;
		}

		$exclude = $this->hidden_page_ids();
		if ( ! $exclude ) {
			return;
		}

		$existing = $query->get( 'post__not_in' );
		$existing = is_array( $existing ) ? $existing : array();
		$query->set( 'post__not_in', array_values( array_unique( array_merge( $existing, $exclude ) ) ) );
	}

	/**
	 * Strip protected body HTML outside an authorized view.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_content( $content ) {
		$post_id = get_the_ID();
		if ( ! $this->must_hide_post( $post_id ) ) {
			return $content;
		}

		return '';
	}

	/**
	 * Strip protected excerpts from loops and search cards.
	 *
	 * @param string $excerpt Excerpt.
	 * @return string
	 */
	public function filter_excerpt( $excerpt ) {
		$post_id = get_the_ID();
		if ( ! $this->must_hide_post( $post_id ) ) {
			return $excerpt;
		}

		return '';
	}

	/**
	 * Block core REST fetches of a protected page.
	 *
	 * Disable REST API may already 401 guests. This still covers logged-in
	 * users who are not the author, and sites without that plugin.
	 *
	 * @param mixed            $result Dispatch result.
	 * @param \WP_REST_Server  $server REST server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function filter_rest_pre_dispatch( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result ) {
			return $result;
		}

		if ( ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		$route = (string) $request->get_route();
		if ( ! preg_match( '#^/wp/v2/pages/(?P<id>\d+)(?:/|$)#', $route, $matches ) ) {
			return $result;
		}

		$page_id = absint( $matches['id'] );
		if ( ! $this->must_hide_post( $page_id ) ) {
			return $result;
		}

		return new \WP_Error(
			'wp_qurl_protected',
			__( 'This page is private. Use the qURL you were given to open it.', 'wp-qurl' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Hide protected pages from REST page collections.
	 *
	 * @param array            $args    WP_Query args.
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function filter_rest_page_query( $args, $request ) {
		unset( $request );
		return $this->append_hidden_not_in( $args );
	}

	/**
	 * Empty REST payload if a collection still includes a protected page.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Page.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_REST_Response
	 */
	public function filter_rest_prepare_page( $response, $post, $request ) {
		unset( $request );

		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post ) {
			return $response;
		}

		if ( ! $this->must_hide_post( $post->ID ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$data['content'] = array(
			'raw'       => '',
			'rendered'  => '',
			'protected' => true,
		);
		$data['excerpt'] = array(
			'raw'       => '',
			'rendered'  => '',
			'protected' => true,
		);
		if ( isset( $data['guid'] ) && is_array( $data['guid'] ) ) {
			$data['guid']['rendered'] = '';
		}
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Hide protected pages from the core REST search handler.
	 *
	 * @param array            $args    Query args.
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function filter_rest_search_query( $args, $request ) {
		unset( $request );
		return $this->append_hidden_not_in( $args );
	}

	/**
	 * Refuse oEmbed discovery for a protected permalink.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Requested URL.
	 * @return int
	 */
	public function filter_oembed_post_id( $post_id, $url ) {
		unset( $url );
		$post_id = absint( $post_id );
		if ( $post_id && $this->must_hide_post( $post_id ) ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * Strip oEmbed body if a protected page still resolved.
	 *
	 * @param array    $data oEmbed data.
	 * @param \WP_Post $post Post.
	 * @return array|false
	 */
	public function filter_oembed_response( $data, $post ) {
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		if ( $post_id && $this->must_hide_post( $post_id ) ) {
			return false;
		}

		return $data;
	}

	/**
	 * Hide protected pages from the core sitemap.
	 *
	 * @param array  $args      Query args.
	 * @param string $post_type Post type.
	 * @return array
	 */
	public function filter_wp_sitemaps( $args, $post_type ) {
		if ( 'page' !== $post_type ) {
			return $args;
		}

		return $this->append_hidden_not_in( $args );
	}

	/**
	 * Hide protected pages from Yoast / Rank Math sitemaps.
	 *
	 * @param int[] $ids Already excluded IDs.
	 * @return int[]
	 */
	public function exclude_ids_from_sitemap( $ids ) {
		$ids = is_array( $ids ) ? $ids : array();
		return array_values( array_unique( array_merge( $ids, $this->hidden_page_ids() ) ) );
	}

	/**
	 * Permit the logged-in page author, and qURL admins, to preview the origin page.
	 *
	 * @param int $page_id Page ID.
	 * @return bool
	 */
	private function user_can_bypass( $page_id ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( Capabilities::current_user_can_manage() ) {
			return true;
		}

		return (int) get_post_field( 'post_author', $page_id ) === get_current_user_id();
	}

	/**
	 * Whether protected body may be shown on this request.
	 *
	 * LayerV Referer is not accepted here. That check is only for the
	 * permalink HTML load in maybe_block_page().
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function must_hide_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || ! Page_Meta::is_protected( $post_id ) ) {
			return false;
		}

		if ( $this->user_can_bypass( $post_id ) || Grant::has_cookie( $post_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Protected page IDs the current user may not see in lists.
	 *
	 * @return int[]
	 */
	private function hidden_page_ids() {
		$hidden = array();
		foreach ( Page_Meta::protected_page_ids() as $page_id ) {
			if ( $this->must_hide_post( $page_id ) ) {
				$hidden[] = $page_id;
			}
		}

		return $hidden;
	}

	/**
	 * Merge hidden page IDs into a query args array.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	private function append_hidden_not_in( array $args ) {
		$exclude = $this->hidden_page_ids();
		if ( ! $exclude ) {
			return $args;
		}

		$existing = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
		$args['post__not_in'] = array_values( array_unique( array_merge( $existing, $exclude ) ) );
		return $args;
	}

	/**
	 * Admin screens (not REST) may list protected pages for editors.
	 *
	 * @return bool
	 */
	private function is_privileged_query_context() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		return is_admin();
	}

	/**
	 * Disable caching for every protected response.
	 */
	private function private_page_headers() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'LSCACHE_NO_CACHE' ) ) {
			define( 'LSCACHE_NO_CACHE', true );
		}

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
			header( 'Pragma: no-cache', true );
			header( 'Expires: 0', true );
			header( 'X-LiteSpeed-Cache-Control: no-cache', true );
			header( 'Vary: Cookie, Referer', false );
		}

		do_action( 'litespeed_control_set_nocache', 'wp-qurl-protected' );
	}

	/**
	 * Render the access-denied template.
	 *
	 * @param int $page_id Page ID.
	 */
	private function render_blocked( $page_id ) {
		status_header( 403 );
		nocache_headers();

		$template = WP_QURL_PATH . 'public/views/blocked.php';
		if ( is_readable( $template ) ) {
			$page_title = get_the_title( $page_id );
			include $template;
			exit;
		}

		wp_die(
			esc_html__( 'This page is private. Use the qURL you were given to open it.', 'wp-qurl' ),
			esc_html__( 'Access Denied', 'wp-qurl' ),
			array( 'response' => 403 )
		);
	}
}
