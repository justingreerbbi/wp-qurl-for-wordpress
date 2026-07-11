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
	 * Get LayerV resource IDs minted for a protected page URL.
	 *
	 * @param int $post_id Page ID.
	 * @return string[]
	 */
	public static function get_protection_resource_ids( $post_id ) {
		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return array();
		}

		$resource_ids = Database::get_resource_ids_for_target( Service::normalize_target_url( $permalink ) );

		/**
		 * Filter resource IDs associated with a protected page.
		 *
		 * @param string[] $resource_ids LayerV resource IDs.
		 * @param int      $post_id      Protected page ID.
		 */
		$resource_ids = apply_filters( 'wp_qurl_protection_resource_ids', $resource_ids, $post_id );

		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $resource_ids ) ) ) );
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
			<?php esc_html_e( 'When enabled, visitors must open a qURL access link before viewing this page. Access is unlocked by matching the visitor IP and browser from LayerV.', 'wp-qurl' ); ?>
		</p>

		<?php if ( ! Settings::is_page_handoff_ready() ) : ?>
			<p class="notice notice-warning inline">
				<?php esc_html_e( 'A LayerV webhook is recommended so access events are recorded immediately. Without it, WordPress can still unlock via live LayerV sessions.', 'wp-qurl' ); ?>
			</p>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'LayerV webhook is registered. Create or reissue qURL links for this page from the button below.', 'wp-qurl' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( Capabilities::current_user_can_create() && Settings::is_configured() ) : ?>
			<p>
				<a href="<?php echo esc_url( $create_url ); ?>" class="button button-secondary" target="_blank" rel="noopener">
					<?php esc_html_e( 'Create qURL for this page', 'wp-qurl' ); ?>
				</a>
			</p>
			<p class="description">
				<?php esc_html_e( 'Opens in a new tab with this page pre-filled as the target URL.', 'wp-qurl' ); ?>
			</p>
		<?php endif; ?>
		<?php
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
