<?php
/**
 * Page editor meta box for qURL protection settings.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and saves per-page qURL protection metadata.
 */
class Page_Meta {

	/**
	 * Post meta key: protection enabled.
	 */
	const META_PROTECTED = '_wp_qurl_protected';

	/**
	 * Post meta key: admit token appended to the LayerV target URL.
	 */
	const META_ADMIT = '_wp_qurl_admit';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_page', array( __CLASS__, 'save_meta' ), 10, 2 );
	}

	/**
	 * Check whether a page is qURL-protected.
	 *
	 * @param int $post_id Page ID.
	 * @return bool
	 */
	public static function is_protected( $post_id ) {
		return '1' === get_post_meta( $post_id, self::META_PROTECTED, true );
	}

	/**
	 * IDs of published pages marked qURL-protected.
	 *
	 * @return int[]
	 */
	public static function protected_page_ids() {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}

		global $wpdb;

		// Direct SQL so this cannot recurse through pre_get_posts.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND pm.meta_value = %s
				AND p.post_type = 'page' AND p.post_status = 'publish'
				LIMIT 100",
				self::META_PROTECTED,
				'1'
			)
		);

		$ids = array_values( array_filter( array_map( 'absint', (array) $found ) ) );
		return $ids;
	}

	/**
	 * Stable admit token for a protected page.
	 *
	 * LayerV URL resources redirect the browser to target_url after
	 * verification and do not create origin sessions. WordPress therefore
	 * puts this token on the LayerV target so a successful qURL visit is
	 * distinguishable from a direct hit.
	 *
	 * @param int $post_id Page ID.
	 * @return string
	 */
	public static function ensure_admit_token( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return '';
		}

		$stored = (string) get_post_meta( $post_id, self::META_ADMIT, true );
		if ( preg_match( '/^[A-Za-z0-9]{24,64}$/', $stored ) ) {
			return $stored;
		}

		$token = wp_generate_password( 32, false, false );
		update_post_meta( $post_id, self::META_ADMIT, $token );
		return $token;
	}

	/**
	 * Replace the admit token so a leaked /qurl-in/ URL stops working.
	 *
	 * @param int $post_id Page ID.
	 * @return string
	 */
	public static function rotate_admit_token( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return '';
		}

		delete_post_meta( $post_id, self::META_ADMIT );
		return self::ensure_admit_token( $post_id );
	}

	/**
	 * LayerV target URL that includes the admit token.
	 *
	 * Query strings are stripped on URL-type LayerV redirects, so the
	 * token is a path segment instead of a query argument.
	 *
	 * @param int $post_id Page ID.
	 * @return string
	 */
	public static function admit_target_url( $post_id ) {
		$token = self::ensure_admit_token( $post_id );
		if ( ! $token ) {
			return '';
		}

		return untrailingslashit( home_url( '/' . Grant::ADMIT_PATH . '/' . $token ) );
	}

	/**
	 * Find a protected page whose admit token matches.
	 *
	 * @param string $token Admit token.
	 * @return int
	 */
	public static function page_id_for_admit_token( $token ) {
		$token = sanitize_text_field( $token );
		if ( ! preg_match( '/^[A-Za-z0-9]{24,64}$/', $token ) ) {
			return 0;
		}

		$found = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'meta_key'               => self::META_ADMIT,
				'meta_value'             => $token,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$page_id = absint( $found[0] ?? 0 );
		if ( ! $page_id ) {
			return 0;
		}

		$stored = (string) get_post_meta( $page_id, self::META_ADMIT, true );
		if ( ! $stored || ! hash_equals( $stored, $token ) ) {
			return 0;
		}

		return Page_Meta::is_protected( $page_id ) ? $page_id : 0;
	}

	/**
	 * Resolve a LayerV target URL that uses the admit path (or legacy query).
	 *
	 * @param string $url Target URL.
	 * @return int
	 */
	public static function page_id_from_admit_url( $url ) {
		$token = self::token_from_admit_url( $url );
		return $token ? self::page_id_for_admit_token( $token ) : 0;
	}

	/**
	 * Extract an admit token from a target URL.
	 *
	 * @param string $url Target URL.
	 * @return string
	 */
	public static function token_from_admit_url( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#/' . preg_quote( Grant::ADMIT_PATH, '#' ) . '/([A-Za-z0-9]{24,64})/?$#', $path, $matches ) ) {
			return $matches[1];
		}

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' === $query ) {
			return '';
		}

		wp_parse_str( $query, $vars );
		$provided = sanitize_text_field( $vars[ Grant::ADMIT_QUERY ] ?? '' );
		return preg_match( '/^[A-Za-z0-9]{24,64}$/', $provided ) ? $provided : '';
	}

	/**
	 * Register the meta box on the page edit screen.
	 */
	public static function add_meta_box() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}

		add_meta_box(
			'wp-qurl-protection',
			__( 'qURL Protection', 'wp-qurl' ),
			array( __CLASS__, 'render_meta_box' ),
			'page',
			'side',
			'high'
		);

		add_meta_box(
			'wp-qurl-activity',
			__( 'qURLs for this page', 'wp-qurl' ),
			array( __CLASS__, 'render_activity_meta_box' ),
			'page',
			'normal',
			'default'
		);
	}

	/**
	 * Render the meta box HTML.
	 *
	 * @param \WP_Post $post Current page.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'wp_qurl_page_meta', 'wp_qurl_page_meta_nonce' );

		$protected  = self::is_protected( $post->ID );
		$permalink  = get_permalink( $post->ID );
		$create_url = add_query_arg(
			array(
				'page'       => 'wp-qurl-create',
				'target_url' => rawurlencode( $permalink ),
			),
			admin_url( 'admin.php' )
		);
		?>
		<p>
			<label>
				<input type="checkbox" name="wp_qurl_protected" value="1" <?php checked( $protected ); ?> />
				<?php esc_html_e( 'Protect this page with qURL', 'wp-qurl' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'Hides this page. Only you (when logged in) or someone with a qURL can see it.', 'wp-qurl' ); ?>
		</p>

		<?php if ( Capabilities::current_user_can_create() && Settings::is_configured() ) : ?>
			<p>
				<a href="<?php echo esc_url( $create_url ); ?>" class="button button-secondary" target="_blank" rel="noopener">
					<?php esc_html_e( 'Mint qURL for this page', 'wp-qurl' ); ?>
				</a>
			</p>
			<p class="description">
				<?php esc_html_e( 'Opens a new tab ready to make a link for this page. Copy the link when you see it — it only shows once.', 'wp-qurl' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Overview of resources and minted qURLs for this page.
	 *
	 * @param \WP_Post $post Current page.
	 */
	public static function render_activity_meta_box( $post ) {
		if ( ! self::is_protected( $post->ID ) ) {
			echo '<p class="description">' . esc_html__( 'Turn on protection above to see links for this page.', 'wp-qurl' ) . '</p>';
			return;
		}

		$tokens    = Database::get_tokens_for_page( $post->ID, false );
		$resources = array();

		foreach ( $tokens as $token ) {
			$path_id = $token->crid ? $token->crid : $token->resource_id;
			if ( ! $path_id ) {
				$path_id = 'local-' . (int) $token->id;
			}
			if ( ! isset( $resources[ $path_id ] ) ) {
				$resources[ $path_id ] = array(
					'path_id' => $path_id,
					'tokens'  => array(),
				);
			}
			$resources[ $path_id ]['tokens'][] = $token;
		}

		$template = WP_QURL_PATH . 'admin/views/page-overview.php';
		if ( is_readable( $template ) ) {
			include $template;
		}
	}

	/**
	 * Save meta box values on page save.
	 *
	 * @param int      $post_id Page ID.
	 * @param \WP_Post $post    Page object.
	 */
	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['wp_qurl_page_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_qurl_page_meta_nonce'] ) ), 'wp_qurl_page_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_page', $post_id ) ) {
			return;
		}

		$protected = ! empty( $_POST['wp_qurl_protected'] );

		if ( $protected ) {
			update_post_meta( $post_id, self::META_PROTECTED, '1' );
		} else {
			delete_post_meta( $post_id, self::META_PROTECTED );
		}
	}
}
