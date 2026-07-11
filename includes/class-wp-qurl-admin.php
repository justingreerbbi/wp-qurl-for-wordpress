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
			__( 'Create qURL', 'wp-qurl' ),
			__( 'Create qURL', 'wp-qurl' ),
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
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
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
			case 'terminate_sessions':
				$this->action_terminate_sessions();
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

		$type = $result['success'] ? 'success' : 'error';
		$key  = $result['success'] ? 'connection_ok' : 'connection_fail';

		set_transient(
			'wp_qurl_admin_notice',
			array(
				'type'    => $type,
				'message' => $result['message'],
			),
			30
		);

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

		set_transient(
			'wp_qurl_admin_notice',
			array(
				'type'    => $result['success'] ? 'success' : 'error',
				'message' => $result['message'],
			),
			30
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	/**
	 * Create a new qURL via the LayerV API.
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
			set_transient(
				'wp_qurl_admin_notice',
				array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				),
				30
			);
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-create' ) );
			exit;
		}

		$redirect = add_query_arg(
			array(
				'page'        => self::MENU_SLUG . '-resource',
				'resource_id' => rawurlencode( $result['resource_id'] ?? '' ),
				'created'     => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Mint a new access link for an existing resource.
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

		$body = array();
		if ( ! empty( $_POST['expires_in'] ) ) {
			$body['expires_in'] = sanitize_text_field( wp_unslash( $_POST['expires_in'] ) );
		}
		if ( ! empty( $_POST['label'] ) ) {
			$body['label'] = sanitize_text_field( wp_unslash( $_POST['label'] ) );
		}

		$stored_by_service = false;
		if ( Service::resolve_protected_page_id( $target_url ) ) {
			$result            = ( new Service() )->mint(
				array(
					'target_url' => $target_url,
					'expires_in' => $body['expires_in'] ?? Settings::get( 'default_expires_in', '24h' ),
					'label'      => $body['label'] ?? '',
				),
				Service::CONTEXT_ADMIN
			);
			$stored_by_service = ! is_wp_error( $result );
			if ( $stored_by_service && ! empty( $result['resource_id'] ) ) {
				$resource_id = $result['resource_id'];
			}
		} else {
			$result = $this->api->mint_link( $resource_id, $body );
		}

		if ( is_wp_error( $result ) ) {
			set_transient(
				'wp_qurl_admin_notice',
				array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				),
				30
			);
		} elseif ( ! $stored_by_service ) {
			$token_id = Database::insert_token(
				array(
					'resource_id' => $resource_id,
					'qurl_id'     => $result['qurl_id'] ?? '',
					'target_url'  => $target_url,
					'qurl_link'   => $result['qurl_link'] ?? '',
					'label'       => $result['label'] ?? ( $body['label'] ?? '' ),
					'expires_at'  => Service::format_storage_datetime( $result['expires_at'] ?? '' ),
					'status'      => 'active',
					'meta'        => array(
						'type'           => $result['type'] ?? 'url',
						'qurl_site'      => $result['qurl_site'] ?? '',
						'branded_domain' => $result['branded_domain'] ?? '',
					),
				)
			);

			if ( ! $token_id ) {
				if ( ! empty( $result['qurl_id'] ) ) {
					$this->api->revoke_qurl_token( $resource_id, $result['qurl_id'] );
				}
				set_transient(
					'wp_qurl_admin_notice',
					array(
						'type'    => 'error',
						'message' => __( 'The qURL was minted but could not be stored and was revoked.', 'wp-qurl' ),
					),
					30
				);
			} else {
				set_transient(
					'wp_qurl_admin_notice',
					array(
						'type'    => 'success',
						'message' => __( 'New access link minted successfully.', 'wp-qurl' ),
					),
					30
				);
			}
		} else {
			set_transient(
				'wp_qurl_admin_notice',
				array(
					'type'    => 'success',
					'message' => __( 'New protected-page access link created.', 'wp-qurl' ),
				),
				30
			);
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
		$result      = Service::admin_revoke_resource( $resource_id );

		if ( is_wp_error( $result ) ) {
			set_transient(
				'wp_qurl_admin_notice',
				array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				),
				30
			);
		} else {
			$this->store_revoke_notice(
				$result,
				__( 'Resource revoked successfully.', 'wp-qurl' )
			);
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
		$qurl_id     = sanitize_text_field( wp_unslash( $_POST['qurl_id'] ?? '' ) );
		$local_id    = absint( $_POST['local_id'] ?? 0 );

		if ( ! $this->current_user_can_access_resource( $resource_id ) ) {
			wp_die( esc_html__( 'You do not have permission to access this resource.', 'wp-qurl' ), '', array( 'response' => 403 ) );
		}

		if ( $local_id ) {
			$local_token = Database::get_token( $local_id );
			$owns_token  = $local_token
				&& $resource_id === $local_token->resource_id
				&& (
					Capabilities::current_user_can_manage()
					|| get_current_user_id() === (int) $local_token->created_by
				);

			if ( ! $owns_token ) {
				wp_die( esc_html__( 'The selected qURL token is invalid.', 'wp-qurl' ), '', array( 'response' => 403 ) );
			}

			if ( '' === $qurl_id ) {
				$qurl_id = $local_token->qurl_id;
			}
		}

		$result = Service::admin_revoke_token( $resource_id, $qurl_id, $local_id );

		if ( is_wp_error( $result ) ) {
			set_transient(
				'wp_qurl_admin_notice',
				array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				),
				30
			);
		} else {
			$this->store_revoke_notice(
				$result,
				__( 'qURL token revoked.', 'wp-qurl' )
			);
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
	 * Terminate all active sessions for a resource.
	 */
	private function action_terminate_sessions() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'Unauthorized.', 'wp-qurl' ) );
		}

		check_admin_referer( 'wp_qurl_terminate_sessions' );

		$resource_id = sanitize_text_field( wp_unslash( $_POST['resource_id'] ?? '' ) );
		$result      = $this->api->terminate_all_sessions( $resource_id );

		if ( is_wp_error( $result ) ) {
			set_transient(
				'wp_qurl_admin_notice',
				array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				),
				30
			);
		} else {
			$count = isset( $result['terminated'] ) ? (int) $result['terminated'] : 0;
			Database::revoke_handoff_grants( $resource_id );
			set_transient(
				'wp_qurl_admin_notice',
				array(
					'type'    => 'success',
					'message' => sprintf(
						/* translators: %d: number of sessions terminated */
						_n( '%d session terminated.', '%d sessions terminated.', $count, 'wp-qurl' ),
						$count
					),
				),
				30
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG . '-resource',
					'resource_id' => rawurlencode( $resource_id ),
					'tab'         => 'sessions',
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
		$message  = $messages[ $key ] ?? $key;

		set_transient(
			'wp_qurl_admin_notice',
			array(
				'type'    => $type,
				'message' => $message,
			),
			30
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Store an admin notice for a revoke action.
	 *
	 * WordPress is treated as authoritative: local revocation succeeds even when
	 * LayerV returns an error such as "qURL not active".
	 *
	 * @param array  $result          Revoke result from Service.
	 * @param string $success_message Base success message.
	 */
	private function store_revoke_notice( array $result, $success_message ) {
		$type    = 'success';
		$message = $success_message;

		if ( empty( $result['local_revoked'] ) ) {
			$type    = 'warning';
			$message = __( 'No active local qURL records were found. Remote status was checked when possible.', 'wp-qurl' );
		} elseif ( ! empty( $result['remote_synced'] ) ) {
			$message = $success_message;
		} elseif ( ! empty( $result['benign_remote_error'] ) ) {
			$message = sprintf(
				/* translators: %s: base success message */
				__( '%s LayerV reported this link was already inactive.', 'wp-qurl' ),
				$success_message
			);
		} elseif ( ! empty( $result['remote_error'] ) ) {
			$type    = 'warning';
			$message = sprintf(
				/* translators: 1: base success message, 2: LayerV error message */
				__( '%1$s LayerV could not be updated: %2$s', 'wp-qurl' ),
				$success_message,
				$result['remote_error']
			);
		}

		set_transient(
			'wp_qurl_admin_notice',
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

		$notice = get_transient( 'wp_qurl_admin_notice' );
		if ( ! $notice ) {
			return;
		}

		delete_transient( 'wp_qurl_admin_notice' );

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
			'settings_saved'  => __( 'Settings saved.', 'wp-qurl' ),
			'invalid_url'     => __( 'Please enter a valid HTTPS target URL.', 'wp-qurl' ),
			'connection_ok'   => __( 'API connection successful.', 'wp-qurl' ),
			'connection_fail' => __( 'API connection failed.', 'wp-qurl' ),
		);
	}

	/**
	 * Render the dashboard page.
	 */
	public function render_dashboard() {
		if ( ! Capabilities::current_user_can_create() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-qurl' ) );
		}

		$api_resources = array();
		$api_error     = '';
		$resource_ids  = array();

		if ( Settings::is_configured() ) {
			$params = array(
				'limit' => 50,
				'sort'  => 'created_at:desc',
			);
			if ( ! Capabilities::current_user_can_manage() ) {
				$resource_ids = Database::get_user_resource_ids( get_current_user_id() );
			}

			$response = $this->api->list_qurls( $params );
			if ( is_wp_error( $response ) ) {
				$api_error = $response->get_error_message();
			} else {
				$api_resources = $response;
				if ( ! Capabilities::current_user_can_manage() ) {
					$api_resources = array_values(
						array_filter(
							(array) $api_resources,
							static function ( $resource_data ) use ( $resource_ids ) {
								return is_array( $resource_data )
									&& isset( $resource_data['resource_id'] )
									&& in_array( $resource_data['resource_id'], $resource_ids, true );
							}
						)
					);
				}
			}
		}

		$local_args = array( 'per_page' => 1000 );
		if ( ! Capabilities::current_user_can_manage() ) {
			$local_args['created_by'] = get_current_user_id();
		}
		$local_groups   = Database::get_grouped_by_target( $local_args );
		$status_filter  = $this->get_dashboard_status_filter();
		$dashboard_rows = $this->build_dashboard_rows(
			is_array( $api_resources ) ? $api_resources : array(),
			$local_groups
		);
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
				'api_error'       => $api_error,
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

		if ( empty( $resource_id ) ) {
			wp_die( esc_html__( 'Resource ID is required.', 'wp-qurl' ) );
		}

		if ( ! $this->current_user_can_access_resource( $resource_id ) ) {
			wp_die( esc_html__( 'You do not have permission to access this resource.', 'wp-qurl' ), '', array( 'response' => 403 ) );
		}

		$resource  = null;
		$qurls     = array();
		$sessions  = array();
		$api_error = '';

		if ( Settings::is_configured() ) {
			$resource = $this->api->get_qurl( $resource_id );
			if ( is_wp_error( $resource ) ) {
				$api_error = $resource->get_error_message();
			}

			$qurls_response = $this->api->list_resource_qurls( $resource_id, array( 'limit' => 100 ) );
			if ( ! is_wp_error( $qurls_response ) ) {
				$qurls = $qurls_response;
			}

			$sessions_response = $this->api->list_sessions( $resource_id );
			if ( ! is_wp_error( $sessions_response ) ) {
				$sessions = $sessions_response;
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
				'qurls'        => is_array( $qurls ) ? $qurls : array(),
				'sessions'     => is_array( $sessions ) ? $sessions : array(),
				'local_tokens' => $local_tokens['items'],
				'access_log'   => $access_log['items'],
				'tab'          => $tab,
				'api_error'    => $api_error,
				'just_created' => ! empty( $_GET['created'] ),
			)
		);
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

		// Also fetch live sessions from API for all known resources.
		$live_sessions = array();
		if ( Settings::is_configured() ) {
			$local_args = array( 'per_page' => 500 );
			if ( ! Capabilities::current_user_can_manage() ) {
				$local_args['created_by'] = get_current_user_id();
			}
			$local = Database::get_tokens( $local_args );
			$seen  = array();
			foreach ( $local['items'] as $token ) {
				if ( empty( $token->resource_id ) || isset( $seen[ $token->resource_id ] ) ) {
					continue;
				}
				$seen[ $token->resource_id ] = true;
				$sessions                    = $this->api->list_sessions( $token->resource_id );
				if ( ! is_wp_error( $sessions ) && is_array( $sessions ) ) {
					foreach ( $sessions as $session ) {
						$session['_resource_id'] = $token->resource_id;
						$live_sessions[]         = $session;
					}
				}
			}
		}

		$this->render_view(
			'access-log',
			array(
				'log'           => $log,
				'live_sessions' => $live_sessions,
				'filter'        => $filter,
				'page'          => $page,
				'configured'    => Settings::is_configured(),
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
		return array( '', 'active', 'revoked', 'expired', 'consumed' );
	}

	/**
	 * Human-readable labels for dashboard status filters.
	 *
	 * @return array<string, string>
	 */
	private function get_dashboard_status_labels() {
		return array(
			''         => __( 'All', 'wp-qurl' ),
			'active'   => __( 'Active', 'wp-qurl' ),
			'revoked'  => __( 'Revoked', 'wp-qurl' ),
			'expired'  => __( 'Expired', 'wp-qurl' ),
			'consumed' => __( 'Consumed', 'wp-qurl' ),
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
	 * Merge API resources with locally stored tokens for dashboard display.
	 *
	 * @param array $api_resources Resources from LayerV.
	 * @param array $local_groups  Local token groups.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_dashboard_rows( array $api_resources, array $local_groups ) {
		$rows                      = array();
		$local_targets_by_resource = array();

		foreach ( $local_groups as $group ) {
			foreach ( $group['tokens'] as $group_token ) {
				if ( ! empty( $group_token->resource_id ) ) {
					$local_targets_by_resource[ $group_token->resource_id ] = $group_token->target_url;
				}
			}
		}

		foreach ( $api_resources as $res ) {
			if ( ! is_array( $res ) ) {
				continue;
			}

			$resource_id = $res['resource_id'] ?? ( $res['target_url'] ?? '' );
			if ( isset( $local_targets_by_resource[ $resource_id ] ) ) {
				$res['target_url'] = $local_targets_by_resource[ $resource_id ];
			}

			$rows[ $resource_id ] = $res;
		}

		foreach ( $local_groups as $group ) {
			foreach ( $group['tokens'] as $group_token ) {
				$resource_id = $group_token->resource_id;
				if ( ! isset( $rows[ $resource_id ] ) ) {
					$rows[ $resource_id ] = array(
						'resource_id' => $resource_id,
						'target_url'  => $group_token->target_url,
						'status'      => $group_token->status,
						'qurl_count'  => 1,
					);
				} else {
					$rows[ $resource_id ]['target_url'] = $group_token->target_url;
					if ( empty( $rows[ $resource_id ]['status'] ) ) {
						$rows[ $resource_id ]['status'] = $group_token->status;
					}
				}
			}
		}

		return array_values( $rows );
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

		foreach ( array( 'active', 'revoked', 'expired', 'consumed' ) as $status ) {
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
