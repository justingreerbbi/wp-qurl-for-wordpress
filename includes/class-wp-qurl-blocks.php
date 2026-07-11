<?php
/**
 * Gutenberg block registration for self-service qURL minting.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the qURL Mint block.
 */
class Blocks {

	/**
	 * Block name.
	 */
	const BLOCK_NAME = 'wp-qurl/mint';

	/**
	 * Transient key for visitor-enabled endpoint cache.
	 */
	const VISITOR_ENDPOINTS_TRANSIENT = 'wp_qurl_visitor_endpoints';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'save_post', array( __CLASS__, 'flush_visitor_endpoint_cache' ) );
		add_action( 'delete_post', array( __CLASS__, 'flush_visitor_endpoint_cache' ) );
	}

	/**
	 * Register block type and assets.
	 */
	public function register_block() {
		$dir = WP_QURL_PATH . 'blocks/qurl-mint';

		self::register_assets();

		register_block_type(
			$dir,
			array(
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Server-side render for the mint block.
	 *
	 * @param array     $attributes Block attributes.
	 * @param string    $content    Inner content (unused).
	 * @param \WP_Block $block      Block instance.
	 * @return string
	 */
	public function render_block( $attributes, $content, $block ) {
		$endpoint        = isset( $attributes['endpoint'] ) ? sanitize_text_field( $attributes['endpoint'] ) : '';
		$allow_visitors  = ! empty( $attributes['allowVisitors'] );
		$button_text     = isset( $attributes['buttonText'] ) ? sanitize_text_field( $attributes['buttonText'] ) : __( 'Generate Access Link', 'wp-qurl' );
		$success_text    = isset( $attributes['successMessage'] ) ? sanitize_text_field( $attributes['successMessage'] ) : __( 'Your one-time access link:', 'wp-qurl' );
		$login_text      = isset( $attributes['loginMessage'] ) ? sanitize_text_field( $attributes['loginMessage'] ) : __( 'Log in to generate your personal access link.', 'wp-qurl' );

		if ( '' === $endpoint ) {
			return '<div class="wp-qurl-mint wp-qurl-mint--misconfigured"><p>' . esc_html__( 'Configure an endpoint in the block settings.', 'wp-qurl' ) . '</p></div>';
		}

		if ( ! Settings::is_configured() ) {
			return '<div class="wp-qurl-mint wp-qurl-mint--misconfigured"><p>' . esc_html__( 'qURL is not configured on this site.', 'wp-qurl' ) . '</p></div>';
		}

		if ( ! is_user_logged_in() ) {
			if ( ! $allow_visitors ) {
				$login_url = wp_login_url( get_permalink() );
				return '<div class="wp-qurl-mint wp-qurl-mint--logged-out"><p>' . esc_html( $login_text ) . '</p><p><a class="button" href="' . esc_url( $login_url ) . '">' . esc_html__( 'Log in', 'wp-qurl' ) . '</a></p></div>';
			}
		} elseif ( ! Capabilities::current_user_can_mint_self() ) {
			return '<div class="wp-qurl-mint wp-qurl-mint--forbidden"><p>' . esc_html__( 'You do not have permission to generate qURL links.', 'wp-qurl' ) . '</p></div>';
		}

		return self::render_mint_markup( $endpoint, $button_text, $success_text );
	}

	/**
	 * Render the interactive mint block markup.
	 *
	 * @param string $endpoint     Block endpoint attribute.
	 * @param string $button_text  Button label.
	 * @param string $success_text Success message.
	 * @return string
	 */
	private static function render_mint_markup( $endpoint, $button_text, $success_text ) {
		self::localize_view_script();

		$wrapper_attrs = get_block_wrapper_attributes(
			array(
				'class'                => 'wp-qurl-mint',
				'data-endpoint'        => esc_attr( $endpoint ),
				'data-button-text'     => esc_attr( $button_text ),
				'data-success-message' => esc_attr( $success_text ),
			)
		);

		ob_start();
		?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<button type="button" class="wp-qurl-mint__button button button-primary">
				<?php echo esc_html( $button_text ); ?>
			</button>
			<div class="wp-qurl-mint__status" aria-live="polite"></div>
			<div class="wp-qurl-mint__result" hidden>
				<p class="wp-qurl-mint__success-label"><?php echo esc_html( $success_text ); ?></p>
				<input type="text" class="wp-qurl-mint__link" readonly />
				<button type="button" class="wp-qurl-mint__copy button button-secondary"><?php esc_html_e( 'Copy Link', 'wp-qurl' ); ?></button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Whether a published block allows visitor minting for an endpoint.
	 *
	 * @param string $endpoint Raw endpoint from block attributes or REST request.
	 * @return bool
	 */
	public static function endpoint_allows_visitors( $endpoint ) {
		$endpoint = trim( sanitize_text_field( (string) $endpoint ) );
		if ( '' === $endpoint ) {
			return false;
		}

		$allowed = get_transient( self::VISITOR_ENDPOINTS_TRANSIENT );
		if ( false === $allowed ) {
			$allowed = self::build_visitor_endpoint_index();
			set_transient( self::VISITOR_ENDPOINTS_TRANSIENT, $allowed, HOUR_IN_SECONDS );
		}

		if ( ! is_array( $allowed ) ) {
			return false;
		}

		$needle = self::normalize_endpoint_key( $endpoint );

		return in_array( $needle, $allowed, true );
	}

	/**
	 * Clear cached visitor endpoint index.
	 *
	 * @param int $post_id Post ID (unused).
	 */
	public static function flush_visitor_endpoint_cache( $post_id = 0 ) {
		delete_transient( self::VISITOR_ENDPOINTS_TRANSIENT );
	}

	/**
	 * Build a list of endpoints with visitor minting enabled.
	 *
	 * @return string[]
	 */
	private static function build_visitor_endpoint_index() {
		global $wpdb;

		$endpoints = array();
		$post_ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_content LIKE %s",
				'publish',
				'%' . $wpdb->esc_like( 'wp-qurl/mint' ) . '%'
			)
		);

		foreach ( (array) $post_ids as $post_id ) {
			$content = get_post_field( 'post_content', (int) $post_id );
			if ( ! is_string( $content ) || '' === $content ) {
				continue;
			}

			foreach ( self::collect_visitor_endpoints_from_blocks( parse_blocks( $content ) ) as $endpoint ) {
				$endpoints[] = $endpoint;
			}
		}

		/**
		 * Filter visitor-enabled endpoints discovered from published content.
		 *
		 * @param string[] $endpoints Normalized endpoint keys.
		 */
		$endpoints = apply_filters( 'wp_qurl_visitor_endpoints', $endpoints );

		return array_values( array_unique( $endpoints ) );
	}

	/**
	 * Recursively collect visitor-enabled endpoints from parsed blocks.
	 *
	 * @param array $blocks Parsed block list.
	 * @return string[]
	 */
	private static function collect_visitor_endpoints_from_blocks( array $blocks ) {
		$endpoints = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( self::BLOCK_NAME === ( $block['blockName'] ?? '' ) ) {
				$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				if ( empty( $attrs['allowVisitors'] ) ) {
					continue;
				}

				$endpoint = isset( $attrs['endpoint'] ) ? trim( (string) $attrs['endpoint'] ) : '';
				if ( '' !== $endpoint ) {
					$endpoints[] = self::normalize_endpoint_key( $endpoint );
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$endpoints = array_merge(
					$endpoints,
					self::collect_visitor_endpoints_from_blocks( $block['innerBlocks'] )
				);
			}
		}

		return $endpoints;
	}

	/**
	 * Normalize an endpoint value for comparison.
	 *
	 * @param string $endpoint Raw endpoint attribute.
	 * @return string
	 */
	private static function normalize_endpoint_key( $endpoint ) {
		$endpoint = trim( sanitize_text_field( (string) $endpoint ) );

		if ( preg_match( '#^https?://#i', $endpoint ) ) {
			return untrailingslashit( esc_url_raw( $endpoint ) );
		}

		return '/' . ltrim( $endpoint, '/' );
	}

	/**
	 * Register block editor and view assets.
	 */
	private static function register_assets() {
		wp_register_script(
			'wp-qurl-block-editor',
			WP_QURL_URL . 'blocks/qurl-mint/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			WP_QURL_VERSION,
			true
		);

		wp_register_script(
			'wp-qurl-block-view',
			WP_QURL_URL . 'blocks/qurl-mint/view.js',
			array(),
			WP_QURL_VERSION,
			true
		);

		wp_register_style(
			'wp-qurl-block-view',
			WP_QURL_URL . 'blocks/qurl-mint/style.css',
			array(),
			WP_QURL_VERSION
		);
	}

	/**
	 * Add request-specific data to the frontend script.
	 */
	private static function localize_view_script() {
		static $localized = false;

		if ( $localized ) {
			return;
		}

		wp_localize_script(
			'wp-qurl-block-view',
			'wpQurlBlock',
			array(
				'restUrl' => rest_url( Public_Rest::REST_NAMESPACE ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'generating' => __( 'Generating…', 'wp-qurl' ),
					'error'      => __( 'Could not generate link. Please try again.', 'wp-qurl' ),
					'copied'     => __( 'Copied!', 'wp-qurl' ),
					'copyFail'   => __( 'Copy failed.', 'wp-qurl' ),
				),
			)
		);

		$localized = true;
	}
}
