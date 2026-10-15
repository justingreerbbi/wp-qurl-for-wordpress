<?php
/**
 * WordPress admin interface for qURL management.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Registers admin menus, handles form actions, and renders pages.
 */
class Admin {

	/**
	 * Menu slug prefix.
	 */
	const MENU_SLUG = 'wp-qurl';

	/**
	 * User meta key for the persisted dashboard status filter.
	 */
	const DASHBOARD_STATUS_FILTER_META = 'wp_qurl_dashboard_status_filter';

	/**
	 * Transient prefix for a one-time minted portal URL.
	 */
	const PORTAL_FLASH_PREFIX = 'wp_qurl_portal_flash_';

	/**
	 * Singleton instance.
	 *
	 * @var Admin|null
	 */
	private static $instance = null;

	/**
	 * API client instance.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Get singleton instance.
	 *
	 * @return Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->api = new Api_Client();
	}

	/**
	 * Register admin hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Register admin menu pages.
	 */
	public function register_menus() {
		if ( ! Capabilities::current_user_can_create() ) {
			return;
		}

		$cap = Capabilities::current_user_can_manage() ? Capabilities::MANAGE : Capabilities::CREATE;

		add_menu_page(
			__( 'qURLs', 'wp-qurl' ),
			__( 'qURLs', 'wp-qurl' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-admin-links',
			30
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'wp-qurl' ),
			__( 'Dashboard', 'wp-qurl' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Mint qURL', 'wp-qurl' ),
			__( 'Mint qURL', 'wp-qurl' ),
			Capabilities::CREATE,
			self::MENU_SLUG . '-create',
			array( $this, 'render_create' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Access Log', 'wp-qurl' ),
			__( 'Access Log', 'wp-qurl' ),
			$cap,
			self::MENU_SLUG . '-access-log',
			array( $this, 'render_access_log' )
		);

		if ( Capabilities::current_user_can_manage() ) {
			add_submenu_page(
				self::MENU_SLUG,
				__( 'Webhooks', 'wp-qurl' ),
				__( 'Webhooks', 'wp-qurl' ),
				Capabilities::MANAGE,
				self::MENU_SLUG . '-webhooks',
				array( $this, 'render_webhooks' )
			);

			add_submenu_page(
				self::MENU_SLUG,
				__( 'Settings', 'wp-qurl' ),
				__( 'Settings', 'wp-qurl' ),
				Capabilities::MANAGE,
				self::MENU_SLUG . '-settings',
				array( $this, 'render_settings' )
			);
		}

		// Hidden page for resource detail (linked from dashboard).
		add_submenu_page(
			null,
			__( 'Resource Detail', 'wp-qurl' ),
			__( 'Resource Detail', 'wp-qurl' ),
			$cap,
			self::MENU_SLUG . '-resource',
			array( $this, 'render_resource_detail' )
		);
	}

	/**
	 * Enqueue admin CSS and JS on plugin pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$is_plugin = false !== strpos( (string) $hook, self::MENU_SLUG );
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : (string) get_post_type(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_page_editor = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && 'page' === $post_type;
		if ( ! $is_plugin && ! $is_page_editor ) {
			return;
		}

		wp_enqueue_style(
			'wp-qurl-admin',
			WP_QURL_URL . 'admin/css/admin.css',
			array(),
			WP_QURL_VERSION
		);

		wp_enqueue_script(
			'wp-qurl-admin',
			WP_QURL_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			WP_QURL_VERSION,
			true
		);

		wp_localize_script(
			'wp-qurl-admin',
			'wpQurlAdmin',
			array(
				'copySuccess' => __( 'Copied to clipboard!', 'wp-qurl' ),
				'copyFail'    => __( 'Failed to copy.', 'wp-qurl' ),
			)
		);
	}

	/**
	 * Handle POST form actions before page render.
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['wp_qurl_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['wp_qurl_action'] ) );

		switch ( $action ) {
			case 'save_settings':
				$this->action_save_settings();
				break;
			case 'create_qurl':
				$this->action_create_qurl();
				break;
			case 'mint_link':
				$this->action_mint_link();
				break;
			case 'revoke_resource':
				$this->action_revoke_resource();
				break;
			case 'revoke_token':
				$this->action_revoke_token();
				break;
			case 'extend_token':
				$this->action_extend_token();
				break;
			case 'test_connection':
				$this->action_test_connection();
				break;
			case 'register_webhook':
				$this->action_register_webhook();
				break;
			case 'update_webhook_events':
				$this->action_update_webhook_events();
				break;
			case 'sync_webhook_url':
				$this->action_sync_webhook_url();
				break;
			case 'regenerate_webhook_secret':
				$this->action_regenerate_webhook_secret();
				break;
			case 'unregister_webhook':
				$this->action_unregister_webhook();
				break;
		}
	}

	/**
	 * Save plugin settings.
	 */
	private function action_save_settings() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'Unauthorized.', 'wp-qurl' ) );
		}

		check_admin_referer( 'wp_qurl_save_settings' );

		Settings::save( wp_unslash( $_POST ) );

		$this->redirect_with_notice(
			admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' ),
			'settings_saved',
			'success'
		);
	}

	/**
	 * Test API connection from settings page.
	 */
	private function action_test_connection() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'Unauthorized.', 'wp-qurl' ) );
		}

		check_admin_referer( 'wp_qurl_test_connection' );

		$result = $this->api->test_connection();
		$this->notice( $result['success'] ? 'success' : 'error', $result['message'] );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' ) );
		exit;
	}

	/**
	 * Register webhook with LayerV.
	 */
	private function action_register_webhook() {
		$this->handle_webhook_manager_action(
			'wp_qurl_register_webhook',
			function () {
				$events  = isset( $_POST['webhook_events'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['webhook_events'] ) ) : null;
				$manager = new Webhook_Manager();
				return $manager->register( $events );
			},
			self::MENU_SLUG . '-webhooks'
		);
	}

	/**
	 * Update webhook event subscriptions.
	 */
	private function action_update_webhook_events() {
		$this->handle_webhook_manager_action(
			'wp_qurl_update_webhook_events',
			function () {
				$events  = isset( $_POST['webhook_events'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['webhook_events'] ) ) : array();
				$manager = new Webhook_Manager();
				return $manager->update_events( $events );
			},
			self::MENU_SLUG . '-webhooks'
		);
	}

	/**
	 * Sync webhook URL with LayerV.
	 */
	private function action_sync_webhook_url() {
		$this->handle_webhook_manager_action(
			'wp_qurl_sync_webhook_url',
			function () {
				$manager = new Webhook_Manager();
				return $manager->sync_url();
			},
			self::MENU_SLUG . '-webhooks'
		);
	}

	/**
	 * Regenerate webhook signing secret.
	 */
	private function action_regenerate_webhook_secret() {
		$this->handle_webhook_manager_action(
			'wp_qurl_regenerate_webhook_secret',
			function () {
				$manager = new Webhook_Manager();
				return $manager->regenerate_secret();
			},
			self::MENU_SLUG . '-webhooks'
		);
	}

	/**
	 * Unregister webhook from LayerV.
	 */
	private function action_unregister_webhook() {
		$this->handle_webhook_manager_action(
			'wp_qurl_unregister_webhook',
			function () {
				$manager = new Webhook_Manager();
				return $manager->unregister();
			},
			self::MENU_SLUG . '-webhooks'
		);
	}

	/**
	 * Run a webhook manager action and redirect with notice.
	 *
	 * @param string   $nonce_action Nonce action name.
	 * @param callable $callback     Returns array{success:bool,message:string}.
	 * @param string   $page         Admin page slug.
	 */
	private function handle_webhook_manager_action( $nonce_action, $callback, $page ) {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'Unauthorized.', 'wp-qurl' ) );
		}

		check_admin_referer( $nonce_action );

		$result = call_user_func( $callback );
		$this->notice( $result['success'] ? 'success' : 'error', $result['message'] );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	/**
	 * Mint a new qURL via the LayerV API.
	 */
	private function action_create_qurl() {
		if ( ! Capabilities::current_user_can_create() ) {
			wp_die( esc_html__( 'Unauthorized.', 'wp-qurl' ) );
		}

		check_admin_referer( 'wp_qurl_create' );

		$target_url = esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) );
		if (
			empty( $target_url )
			|| ! wp_http_validate_url( $target_url )
			|| 'https' !== strtolower( (string) wp_parse_url( $target_url, PHP_URL_SCHEME ) )
		) {
			$this->redirect_with_notice(
				admin_url( 'admin.php?page=' . self::MENU_SLUG . '-create' ),
				'invalid_url',
				'error'
			);
		}

		$body = array(
			'target_url'       => $target_url,
			'expires_in'       => sanitize_text_field( wp_unslash( $_POST['expires_in'] ?? Settings::get( 'default_expires_in' ) ) ),
			'session_duration' => sanitize_text_field( wp_unslash( $_POST['session_duration'] ?? Settings::get( 'default_session_duration' ) ) ),
			'one_time_use'     => ! empty( $_POST['one_time_use'] ),
			'max_sessions'     => absint( $_POST['max_sessions'] ?? Settings::get( 'default_max_sessions' ) ),
			'label'            => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
			'description'      => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
		);

		$service = new Service();
		$result  = $service->mint( $body, Service::CONTEXT_ADMIN );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-create' ) );
			exit;
		}

		$path_id = Database::resource_id( $result['crid'] ?? ( $result['resource_id'] ?? '' ) );
		self::stash_portal_link( $result['qurl_link'] ?? '' );

		$redirect = add_query_arg(
			array(
				'page'        => self::MENU_SLUG . '-resource',
				'resource_id' => rawurlencode( $path_id ),
				'created'     => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Mint a new qURL for an existing resource.
	 *
	 * Uses POST /v1/resources/{id}/qurls so additional links share the same CRID.
	 */
	private function action_mint_link() {
		if ( ! Capabilities::current_user_can_create() ) {
			wp_die( esc_html__( 'Unauthorized.', 'wp-qurl' ) );
		}

		check_admin_referer( 'wp_qurl_mint_link' );

		$resource_id = sanitize_text_field( wp_unslash( $_POST['resource_id'] ?? '' ) );
		$target_url  = esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) );

		if ( ! $this->current_user_can_access_resource( $resource_id ) ) {
			wp_die( esc_html__( 'You do not have permission to access this resource.', 'wp-qurl' ), '', array( 'response' => 403 ) );
		}

		$args = array(
			'target_url' => $target_url,
		);
		if ( ! empty( $_POST['expires_in'] ) ) {
			$args['expires_in'] = sanitize_text_field( wp_unslash( $_POST['expires_in'] ) );
		}
		if ( ! empty( $_POST['label'] ) ) {
			$args['label'] = sanitize_text_field( wp_unslash( $_POST['label'] ) );
		}

		$result = ( new Service() )->mint_for_resource( $resource_id, $args, Service::CONTEXT_ADMIN );

		$redirect_args = array(
			'page'        => self::MENU_SLUG . '-resource',
			'resource_id' => rawurlencode( $resource_id ),
		);

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
		} else {
			$path_id = Database::resource_id( $result['crid'] ?? ( $result['resource_id'] ?? $resource_id ) );
			if ( $path_id ) {
				$redirect_args['resource_id'] = rawurlencode( $path_id );
			}
			self::stash_portal_link( $result['qurl_link'] ?? '' );
			$redirect_args['created'] = '1';
			$this->notice( 'success', __( 'Your qURL is ready. Copy it now — it will not be shown again.', 'wp-qurl' ) );
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Extend an existing qURL's expiration.
	 */
	private function action_extend_token() {
		if ( ! Capabilities::current_user_can_create() ) {
			wp_die( esc_html__( 'Unauthorized.', 'wp-qurl' ) );
		}

		check_admin_referer( 'wp_qurl_extend_token' );

		$resource_id = sanitize_text_field( wp_unslash( $_POST['resource_id'] ?? '' ) );
		$local_id    = absint( $_POST['local_id'] ?? 0 );
		$extend_by   = sanitize_text_field( wp_unslash( $_POST['extend_by'] ?? '24h' ) );

		if ( ! $this->current_user_can_access_resource( $resource_id ) ) {
			wp_die( esc_html__( 'You do not have permission to access this resource.', 'wp-qurl' ), '', array( 'response' => 403 ) );
		}

		$result = Service::extend_token( $local_id, $extend_by );
		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
		} else {
			$this->notice( 'success', __( 'Link expiration updated.', 'wp-qurl' ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG . '-resource',
					'resource_id' => rawurlencode( $resource_id ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Revoke an entire resource.
	 */
	private function action_revoke_resource() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'Unauthorized.', 'wp-qurl' ) );
		}

		check_admin_referer( 'wp_qurl_revoke_resource' );

		$resource_id = sanitize_text_field( wp_unslash( $_POST['resource_id'] ?? '' ) );
		$result      = Service::revoke_resource( $resource_id );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
		} else {
			$this->notice( 'success', __( 'All qURLs for this page were revoked.', 'wp-qurl' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/**
	 * Revoke a single qURL token.
	 */
	private function action_revoke_token() {
		if ( ! Capabilities::current_user_can_create() ) {
			wp_die( esc_html__( 'Unauthorized.', 'wp-qurl' ) );
		}

		check_admin_referer( 'wp_qurl_revoke_token' );

		$resource_id = sanitize_text_field( wp_unslash( $_POST['resource_id'] ?? '' ) );
		$local_id    = absint( $_POST['local_id'] ?? 0 );
		$token       = Database::get_token( $local_id );

		if ( ! $this->current_user_can_access_resource( $resource_id ) ) {
			wp_die( esc_html__( 'You do not have permission to access this resource.', 'wp-qurl' ), '', array( 'response' => 403 ) );
		}

		$owns_token = $token
			&& (
				Capabilities::current_user_can_manage()
				|| get_current_user_id() === (int) $token->created_by
			);
		if ( ! $owns_token ) {
			wp_die( esc_html__( 'The selected qURL is invalid.', 'wp-qurl' ), '', array( 'response' => 403 ) );
		}

		$result = Service::revoke_token( $local_id );
		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
		} else {
			$this->notice( 'success', __( 'qURL revoked.', 'wp-qurl' ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG . '-resource',
					'resource_id' => rawurlencode( $resource_id ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Redirect with a notice stored in a transient.
	 *
	 * @param string $url     Redirect URL.
	 * @param string $key     Notice message key.
	 * @param string $type    Notice type (success|error|warning).
	 */
	private function redirect_with_notice( $url, $key, $type ) {
		$messages = $this->get_notice_messages();
		$this->notice( $type, $messages[ $key ] ?? $key );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Store a one-shot admin notice.
	 *
	 * @param string $type    success|error|warning.
	 * @param string $message Message.
	 */
	private function notice( $type, $message ) {
		set_transient(
			'wp_qurl_admin_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			30
		);
	}

	/**
	 * Check whether the current user may access a LayerV resource.
	 *
	 * Managers may access all resources. Other users may access only resources
	 * represented by one of their locally stored qURL tokens.
	 *
	 * @param string $resource_id LayerV resource ID.
	 * @return bool
	 */
	private function current_user_can_access_resource( $resource_id ) {
		if ( Capabilities::current_user_can_manage() ) {
			return true;
		}

		return Database::user_owns_resource( get_current_user_id(), $resource_id );
	}

	/**
	 * Render admin notices from transients.
	 */
	public function render_notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, self::MENU_SLUG ) ) {
			return;
		}

		$key    = 'wp_qurl_admin_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}

		delete_transient( $key );

		$class = 'notice notice-' . esc_attr( $notice['type'] );
		printf(
			'<div class="%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Get predefined notice messages.
	 *
	 * @return array
	 */
	private function get_notice_messages() {
		return array(
			'settings_saved' => __( 'Settings saved.', 'wp-qurl' ),
			'invalid_url'    => __( 'Enter a page URL that starts with https://.', 'wp-qurl' ),
		);
	}

	/**
	 * Render the dashboard page.
	 */
	public function render_dashboard() {
		if ( ! Capabilities::current_user_can_create() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-qurl' ) );
		}

		$local_args = array( 'per_page' => 1000 );
		if ( ! Capabilities::current_user_can_manage() ) {
			$local_args['created_by'] = get_current_user_id();
		}
		$local_groups   = Database::get_grouped_by_target( $local_args );
		$status_filter  = $this->get_dashboard_status_filter();
		$dashboard_rows = $this->build_dashboard_rows( $local_groups );
		$status_counts  = $this->count_dashboard_statuses( $dashboard_rows );
		$filtered_rows  = $this->filter_dashboard_rows_by_status( $dashboard_rows, $status_filter );

		$this->render_view(
			'dashboard',
			array(
				'rows'            => $filtered_rows,
				'status_filter'   => $status_filter,
				'status_counts'   => $status_counts,
				'status_labels'   => $this->get_dashboard_status_labels(),
				'total_row_count' => count( $dashboard_rows ),
				'configured'      => Settings::is_configured(),
			)
		);
	}

	/**
	 * Render resource detail page (accessed via query arg on dashboard).
	 */
	public function render_resource_detail() {
		if ( ! Capabilities::current_user_can_create() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-qurl' ), '', array( 'response' => 403 ) );
		}

		$resource_id = sanitize_text_field( wp_unslash( $_GET['resource_id'] ?? '' ) );
		$tab         = sanitize_key( wp_unslash( $_GET['tab'] ?? 'qurls' ) );
		if ( 'sessions' === $tab ) {
			$tab = 'qurls';
		}

		if ( empty( $resource_id ) ) {
			wp_die( esc_html__( 'Resource ID is required.', 'wp-qurl' ) );
		}

		if ( ! $this->current_user_can_access_resource( $resource_id ) ) {
			wp_die( esc_html__( 'You do not have permission to access this resource.', 'wp-qurl' ), '', array( 'response' => 403 ) );
		}

		$resource  = null;
		$api_error = '';
		$path_id   = Database::resource_id( $resource_id );

		if ( Settings::is_configured() ) {
			$resource = $this->api->get_resource( $path_id );
			if ( is_wp_error( $resource ) ) {
				if ( ! Service::is_already_gone( $resource ) ) {
					$api_error = $resource->get_error_message();
				}
				$resource = null;
			}
		}

		$local_args = array(
			'resource_id' => $resource_id,
			'per_page'    => 100,
		);
		if ( ! Capabilities::current_user_can_manage() ) {
			$local_args['created_by'] = get_current_user_id();
		}
		$local_tokens = Database::get_tokens( $local_args );

		$access_log = Database::get_access_log(
			array(
				'resource_id' => $resource_id,
				'per_page'    => 50,
			)
		);

		$this->render_view(
			'resource-detail',
			array(
				'resource_id'  => $resource_id,
				'resource'     => is_array( $resource ) ? $resource : null,
				'local_tokens' => $local_tokens['items'],
				'access_log'   => $access_log['items'],
				'tab'          => $tab,
				'api_error'    => $api_error,
				'just_created' => ! empty( $_GET['created'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'portal_link'  => self::take_portal_link(),
			)
		);
	}

	/**
	 * Hold a minted portal URL until the next admin screen for this user.
	 *
	 * @param string $link Portal URL.
	 */
	public static function stash_portal_link( $link ) {
		$link = esc_url_raw( (string) $link );
		if ( '' === $link || ! is_user_logged_in() ) {
			return;
		}

		set_transient( self::PORTAL_FLASH_PREFIX . get_current_user_id(), $link, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Read and delete the one-time portal URL for this user.
	 *
	 * @return string
	 */
	public static function take_portal_link() {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$key  = self::PORTAL_FLASH_PREFIX . get_current_user_id();
		$link = get_transient( $key );
		delete_transient( $key );

		return is_string( $link ) ? $link : '';
	}

	/**
	 * Render create qURL page.
	 */
	public function render_create() {
		if ( ! Capabilities::current_user_can_create() ) {
			wp_die( esc_html__( 'You do not have permission to create qURLs.', 'wp-qurl' ) );
		}

		$this->render_view(
			'create',
			array(
				'settings'           => Settings::get_all(),
				'configured'         => Settings::is_configured(),
				'prefill_target_url' => isset( $_GET['target_url'] ) ? esc_url_raw( wp_unslash( $_GET['target_url'] ) ) : '',
			)
		);
	}

	/**
	 * Render access log page.
	 */
	public function render_access_log() {
		if ( ! Capabilities::current_user_can_create() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-qurl' ) );
		}

		$page       = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$raw_filter = sanitize_text_field( wp_unslash( $_GET['event_type'] ?? '' ) );
		$filters    = Webhook_Manager::sanitize_events( array( $raw_filter ) );
		$filter     = isset( $filters[0] ) ? $filters[0] : '';

		$log_args = array(
			'event_type' => $filter,
			'per_page'   => 50,
			'page'       => $page,
		);

		if ( ! Capabilities::current_user_can_manage() ) {
			$log_args['resource_ids'] = Database::get_user_resource_ids( get_current_user_id() );
		}

		$log = Database::get_access_log( $log_args );

		$this->render_view(
			'access-log',
			array(
				'log'        => $log,
				'filter'     => $filter,
				'page'       => $page,
				'configured' => Settings::is_configured(),
			)
		);
	}

	/**
	 * Render webhooks management page.
	 */
	public function render_webhooks() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage webhooks.', 'wp-qurl' ) );
		}

		$manager = new Webhook_Manager();

		$this->render_view(
			'webhooks',
			array(
				'configured'     => Settings::is_configured(),
				'webhook_url'    => Settings::get_webhook_url(),
				'status'         => $manager->get_status(),
				'event_types'    => $manager->get_available_event_types(),
				'default_events' => Webhook_Manager::DEFAULT_EVENTS,
			)
		);
	}

	/**
	 * Render settings page.
	 */
	public function render_settings() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage settings.', 'wp-qurl' ) );
		}

		$quota       = null;
		$quota_error = '';
		if ( Settings::is_configured() ) {
			$quota_response = $this->api->get_quota();
			if ( is_wp_error( $quota_response ) ) {
				$quota_error = $quota_response->get_error_message();
			} else {
				$quota = $quota_response;
			}
		}

		$this->render_view(
			'settings',
			array(
				'settings'    => Settings::get_all(),
				'configured'  => Settings::is_configured(),
				'quota'       => $quota,
				'quota_error' => $quota_error,
			)
		);
	}

	/**
	 * Allowed dashboard status filter values.
	 *
	 * @return string[]
	 */
	private function get_dashboard_status_filters() {
		return array( '', 'active', 'revoked', 'expired' );
	}

	/**
	 * Human-readable labels for dashboard status filters.
	 *
	 * @return array<string, string>
	 */
	private function get_dashboard_status_labels() {
		return array(
			''        => __( 'All', 'wp-qurl' ),
			'active'  => __( 'Active', 'wp-qurl' ),
			'revoked' => __( 'Revoked', 'wp-qurl' ),
			'expired' => __( 'Expired', 'wp-qurl' ),
		);
	}

	/**
	 * Read the persisted dashboard status filter for the current user.
	 *
	 * @return string
	 */
	private function get_dashboard_status_filter() {
		$allowed = $this->get_dashboard_status_filters();

		if ( isset( $_GET['status'] ) ) {
			$status = sanitize_key( wp_unslash( $_GET['status'] ) );
			if ( in_array( $status, $allowed, true ) ) {
				update_user_meta( get_current_user_id(), self::DASHBOARD_STATUS_FILTER_META, $status );
				return $status;
			}
		}

		$saved = get_user_meta( get_current_user_id(), self::DASHBOARD_STATUS_FILTER_META, true );
		return in_array( $saved, $allowed, true ) ? $saved : '';
	}

	/**
	 * Build dashboard rows from locally stored tokens.
	 *
	 * @param array $local_groups Local token groups.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_dashboard_rows( array $local_groups ) {
		$rows = array();

		foreach ( $local_groups as $group ) {
			$path_id = sanitize_text_field( $group['crid'] ? $group['crid'] : ( $group['resource_id'] ?? '' ) );
			if ( ! $path_id ) {
				continue;
			}

			$status     = 'revoked';
			$expires_at = '';
			$active     = 0;
			foreach ( $group['tokens'] as $token ) {
				if ( 'active' === $token->status ) {
					$status = 'active';
					++$active;
					if ( $token->expires_at && ( ! $expires_at || $token->expires_at < $expires_at ) ) {
						$expires_at = $token->expires_at;
					}
				} elseif ( 'expired' === $token->status && 'active' !== $status ) {
					$status = 'expired';
				}
			}

			$rows[] = array(
				'path_id'     => $path_id,
				'target_url'  => $group['target_url'],
				'status'      => $status,
				'qurl_count'  => $active ? $active : count( $group['tokens'] ),
				'expires_at'  => $expires_at,
			);
		}

		return $rows;
	}

	/**
	 * Count dashboard rows by normalized status.
	 *
	 * @param array $rows Dashboard rows.
	 * @return array<string, int>
	 */
	private function count_dashboard_statuses( array $rows ) {
		$counts = array(
			'' => count( $rows ),
		);

		foreach ( array( 'active', 'revoked', 'expired' ) as $status ) {
			$counts[ $status ] = 0;
		}

		foreach ( $rows as $row ) {
			$status = sanitize_key( $row['status'] ?? 'unknown' );
			if ( ! isset( $counts[ $status ] ) ) {
				$counts[ $status ] = 0;
			}
			++$counts[ $status ];
		}

		return $counts;
	}

	/**
	 * Filter dashboard rows by status.
	 *
	 * @param array  $rows   Dashboard rows.
	 * @param string $status Status filter.
	 * @return array
	 */
	private function filter_dashboard_rows_by_status( array $rows, $status ) {
		if ( '' === $status ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $status ) {
					return sanitize_key( $row['status'] ?? 'unknown' ) === $status;
				}
			)
		);
	}

	/**
	 * Load and render a view template.
	 *
	 * @param string $view View filename without extension.
	 * @param array  $vars Variables to extract into the view scope.
	 */
	private function render_view( $view, array $vars = array() ) {
		$file = WP_QURL_PATH . 'admin/views/' . $view . '.php';

		if ( ! is_readable( $file ) ) {
			wp_die( esc_html__( 'View not found.', 'wp-qurl' ) );
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Controlled template vars.
		extract( $vars, EXTR_SKIP );
		include $file;
	}
}
