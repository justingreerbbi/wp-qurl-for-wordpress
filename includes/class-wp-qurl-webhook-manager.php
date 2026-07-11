<?php
/**
 * LayerV webhook registration and synchronization.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Manages webhook lifecycle via the LayerV API.
 */
class Webhook_Manager {

	/**
	 * Default events subscribed for WordPress access logging.
	 */
	const DEFAULT_EVENTS = array(
		'qurl.accessed',
		'qurl.access_denied',
		'qurl.created',
		'qurl.expired',
		'qurl.revoked',
		'token.minted',
		'token.expired',
		'qurl.token_exhausted',
		'resource.closed',
	);

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->api = new Api_Client();
	}

	/**
	 * Register (or link) a webhook pointing at this WordPress site.
	 *
	 * @param array|null $events Event types to subscribe to.
	 * @return array{success: bool, message: string}
	 */
	public function register( $events = null ) {
		if ( ! Settings::is_configured() ) {
			return array(
				'success' => false,
				'message' => __( 'API key is not configured.', 'wp-qurl' ),
			);
		}

		$events = $this->normalize_events( $events ?? Settings::get_webhook_events() );
		$url    = Settings::get_webhook_url();

		if ( empty( $events ) ) {
			return array(
				'success' => false,
				'message' => __( 'Select at least one webhook event.', 'wp-qurl' ),
			);
		}

		if ( ! $this->is_https_url( $url ) ) {
			return array(
				'success' => false,
				'message' => __( 'Webhook URL must use HTTPS. Configure SSL on your site first.', 'wp-qurl' ),
			);
		}

		$existing = $this->find_webhook_for_url( $url );
		if ( $existing ) {
			return $this->link_existing_webhook( $existing, $events );
		}

		$result = $this->api->create_webhook(
			array(
				'url'         => $url,
				'events'      => $events,
				'description' => sprintf(
					/* translators: %s: site URL */
					__( 'WordPress WP qURL plugin — %s', 'wp-qurl' ),
					home_url()
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		if ( empty( $result['webhook_id'] ) || empty( $result['secret'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'LayerV did not return a webhook ID or secret.', 'wp-qurl' ),
			);
		}

		Settings::save_webhook_registration( $result['webhook_id'], $result['secret'], $events );

		return array(
			'success' => true,
			'message' => __( 'Webhook registered with LayerV successfully.', 'wp-qurl' ),
		);
	}

	/**
	 * Update subscribed events on the registered webhook.
	 *
	 * @param array $events Event types.
	 * @return array{success: bool, message: string}
	 */
	public function update_events( array $events ) {
		$webhook_id = Settings::get( 'layerv_webhook_id', '' );
		if ( '' === $webhook_id ) {
			return array(
				'success' => false,
				'message' => __( 'No webhook is registered yet.', 'wp-qurl' ),
			);
		}

		$events = $this->normalize_events( $events );
		if ( empty( $events ) ) {
			return array(
				'success' => false,
				'message' => __( 'Select at least one webhook event.', 'wp-qurl' ),
			);
		}

		$result = $this->api->update_webhook(
			$webhook_id,
			array(
				'events' => $events,
				'status' => 'active',
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		Settings::save_webhook_events( $events );

		return array(
			'success' => true,
			'message' => __( 'Webhook events updated.', 'wp-qurl' ),
		);
	}

	/**
	 * Sync the webhook URL in LayerV after token or site URL changes.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function sync_url() {
		$webhook_id = Settings::get( 'layerv_webhook_id', '' );
		$url        = Settings::get_webhook_url();

		if ( '' === $webhook_id ) {
			return array(
				'success' => false,
				'message' => __( 'No webhook is registered yet.', 'wp-qurl' ),
			);
		}

		if ( ! $this->is_https_url( $url ) ) {
			return array(
				'success' => false,
				'message' => __( 'Webhook URL must use HTTPS.', 'wp-qurl' ),
			);
		}

		$result = $this->api->update_webhook(
			$webhook_id,
			array(
				'url'    => $url,
				'status' => 'active',
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Webhook URL synced with LayerV.', 'wp-qurl' ),
		);
	}

	/**
	 * Regenerate the signing secret in LayerV and store it locally.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function regenerate_secret() {
		$webhook_id = Settings::get( 'layerv_webhook_id', '' );
		if ( '' === $webhook_id ) {
			return array(
				'success' => false,
				'message' => __( 'No webhook is registered yet.', 'wp-qurl' ),
			);
		}

		$result = $this->api->regenerate_webhook_secret( $webhook_id );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		if ( empty( $result['secret'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'LayerV did not return a new webhook secret.', 'wp-qurl' ),
			);
		}

		Settings::save_webhook_secret( $result['secret'] );

		return array(
			'success' => true,
			'message' => __( 'Webhook secret regenerated and saved.', 'wp-qurl' ),
		);
	}

	/**
	 * Delete the webhook in LayerV and clear local registration.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function unregister() {
		$webhook_id = Settings::get( 'layerv_webhook_id', '' );

		if ( '' !== $webhook_id ) {
			$result = $this->api->delete_webhook( $webhook_id );
			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'message' => $result->get_error_message(),
				);
			}
		}

		Settings::clear_webhook_registration();

		return array(
			'success' => true,
			'message' => __( 'Webhook unregistered.', 'wp-qurl' ),
		);
	}

	/**
	 * Get combined local and remote webhook status.
	 *
	 * @return array
	 */
	public function get_status() {
		$status = array(
			'registered_locally' => Settings::is_webhook_registered(),
			'webhook_id'         => Settings::get( 'layerv_webhook_id', '' ),
			'has_secret'         => Settings::has_webhook_secret(),
			'webhook_url'        => Settings::get_webhook_url(),
			'events'             => Settings::get_webhook_events(),
			'remote'             => null,
			'remote_error'       => '',
			'deliveries'         => array(),
		);

		if ( ! Settings::is_configured() || '' === $status['webhook_id'] ) {
			return $status;
		}

		$remote = $this->api->get_webhook( $status['webhook_id'] );
		if ( is_wp_error( $remote ) ) {
			$status['remote_error'] = $remote->get_error_message();
			return $status;
		}

		$status['remote'] = $remote;

		$deliveries = $this->api->list_webhook_deliveries( $status['webhook_id'], array( 'limit' => 10 ) );
		if ( ! is_wp_error( $deliveries ) && is_array( $deliveries ) ) {
			$status['deliveries'] = $deliveries;
		}

		return $status;
	}

	/**
	 * Fetch all available event types from LayerV.
	 *
	 * @return array
	 */
	public function get_available_event_types() {
		if ( ! Settings::is_configured() ) {
			return array();
		}

		$result = $this->api->list_webhook_event_types();
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return array();
		}

		return $result;
	}

	/**
	 * Find a webhook in LayerV matching the given URL.
	 *
	 * @param string $url Webhook receiver URL.
	 * @return array|null
	 */
	public function find_webhook_for_url( $url ) {
		$webhooks = $this->api->list_webhooks( array( 'limit' => 100 ) );
		if ( is_wp_error( $webhooks ) || ! is_array( $webhooks ) ) {
			return null;
		}

		$normalized = $this->normalize_url( $url );
		foreach ( $webhooks as $webhook ) {
			if ( ! is_array( $webhook ) || empty( $webhook['url'] ) ) {
				continue;
			}
			if ( $this->normalize_url( $webhook['url'] ) === $normalized ) {
				return $webhook;
			}
		}

		return null;
	}

	/**
	 * Link an existing LayerV webhook and ensure we have a signing secret.
	 *
	 * @param array $webhook Remote webhook data.
	 * @param array $events    Desired event subscriptions.
	 * @return array{success: bool, message: string}
	 */
	private function link_existing_webhook( array $webhook, array $events ) {
		$webhook_id = $webhook['webhook_id'] ?? '';
		if ( '' === $webhook_id ) {
			return array(
				'success' => false,
				'message' => __( 'Existing webhook is missing an ID.', 'wp-qurl' ),
			);
		}

		$update = $this->api->update_webhook(
			$webhook_id,
			array(
				'events' => $events,
				'status' => 'active',
				'url'    => Settings::get_webhook_url(),
			)
		);

		if ( is_wp_error( $update ) ) {
			return array(
				'success' => false,
				'message' => $update->get_error_message(),
			);
		}

		Settings::save_webhook_registration( $webhook_id, '', $events );

		// LayerV only returns a webhook secret at creation or regeneration.
		// Always regenerate when linking an existing remote webhook so the
		// locally stored secret is guaranteed to match.
		$regen = $this->regenerate_secret();
		if ( ! $regen['success'] ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: API error message. */
					__( 'Webhook was linked, but its signing secret could not be synchronized: %s', 'wp-qurl' ),
					$regen['message']
				),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Existing LayerV webhook linked to this site.', 'wp-qurl' ),
		);
	}

	/**
	 * Normalize and validate event type list.
	 *
	 * @param array $events Raw event list.
	 * @return array
	 */
	private function normalize_events( array $events ) {
		return self::sanitize_events( $events );
	}

	/**
	 * Sanitize webhook event names while preserving the required period.
	 *
	 * WordPress sanitize_key() removes periods, turning "qurl.accessed"
	 * into the invalid event name "qurlaccessed".
	 *
	 * @param array $events Raw event list.
	 * @return array
	 */
	public static function sanitize_events( array $events ) {
		$sanitized  = array();
		$legacy_map = array(
			'qurlcreated'         => 'qurl.created',
			'qurlexpired'         => 'qurl.expired',
			'qurlrevoked'         => 'qurl.revoked',
			'qurlupdated'         => 'qurl.updated',
			'resourceclosed'      => 'resource.closed',
			'qurlaccessed'        => 'qurl.accessed',
			'qurlaccess_denied'   => 'qurl.access_denied',
			'qurltoken_exhausted' => 'qurl.token_exhausted',
			'quotawarning'        => 'quota.warning',
			'quotaexceeded'       => 'quota.exceeded',
			'tokenminted'         => 'token.minted',
			'tokenexpired'        => 'token.expired',
			'domainverified'      => 'domain.verified',
			'domainfailed'        => 'domain.failed',
			'domaindeleted'       => 'domain.deleted',
		);

		foreach ( $events as $event ) {
			if ( ! is_scalar( $event ) ) {
				continue;
			}

			$event = strtolower( sanitize_text_field( (string) $event ) );
			if ( isset( $legacy_map[ $event ] ) ) {
				$event = $legacy_map[ $event ];
			}
			if ( preg_match( '/^[a-z0-9_]+\.[a-z0-9_]+$/', $event ) ) {
				$sanitized[] = $event;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Normalize URL for comparison.
	 *
	 * @param string $url URL to normalize.
	 * @return string
	 */
	private function normalize_url( $url ) {
		return untrailingslashit( strtolower( esc_url_raw( $url ) ) );
	}

	/**
	 * Check whether a URL uses HTTPS.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private function is_https_url( $url ) {
		return 0 === strpos( $url, 'https://' );
	}
}
