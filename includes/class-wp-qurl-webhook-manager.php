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
		'qurl.created',
		'qurl.accessed',
		'qurl.access_denied',
		'qurl.revoked',
		'qurl.expired',
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
				'message' => __( 'Add your API key in Settings first.', 'wp-qurl' ),
			);
		}

		$events = $this->normalize_events( $events ?? Settings::get_webhook_events() );
		$url    = Settings::get_webhook_url();

		if ( empty( $events ) ) {
			return array(
				'success' => false,
				'message' => __( 'Pick at least one event to record.', 'wp-qurl' ),
			);
		}

		if ( ! $this->is_https_url( $url ) ) {
			return array(
				'success' => false,
				'message' => __( 'Your site needs HTTPS for logging to work.', 'wp-qurl' ),
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
				'message' => __( 'LayerV did not finish turning logging on. Try again.', 'wp-qurl' ),
			);
		}

		Settings::save_webhook_registration( $result['webhook_id'], $result['secret'], $events );

		return array(
			'success' => true,
			'message' => __( 'Logging is on.', 'wp-qurl' ),
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
				'message' => __( 'Turn logging on first.', 'wp-qurl' ),
			);
		}

		$events = $this->normalize_events( $events );
		if ( empty( $events ) ) {
			return array(
				'success' => false,
				'message' => __( 'Pick at least one event to record.', 'wp-qurl' ),
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
			'message' => __( 'Saved.', 'wp-qurl' ),
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
				'message' => __( 'Turn logging on first.', 'wp-qurl' ),
			);
		}

		if ( ! $this->is_https_url( $url ) ) {
			return array(
				'success' => false,
				'message' => __( 'Your site needs HTTPS.', 'wp-qurl' ),
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
			'message' => __( 'Synced.', 'wp-qurl' ),
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
				'message' => __( 'Turn logging on first.', 'wp-qurl' ),
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
				'message' => __( 'LayerV did not give us a new secret. Try again.', 'wp-qurl' ),
			);
		}

		Settings::save_webhook_secret( $result['secret'] );

		return array(
			'success' => true,
			'message' => __( 'New secret saved.', 'wp-qurl' ),
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
			if ( is_wp_error( $result ) && ! Service::is_already_gone( $result ) ) {
				return array(
					'success' => false,
					'message' => $result->get_error_message(),
				);
			}
		}

		Settings::clear_webhook_registration();

		return array(
			'success' => true,
			'message' => __( 'Logging is off.', 'wp-qurl' ),
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
			$status['deliveries'] = $this->flatten_collection( $deliveries );
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
				'message' => __( 'Could not connect the existing LayerV logging setup.', 'wp-qurl' ),
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
					__( 'Logging is on, but a new secret could not be saved: %s', 'wp-qurl' ),
					$regen['message']
				),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Logging is on.', 'wp-qurl' ),
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
		$sanitized   = array();
		$event_alias = array(
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
			if ( isset( $event_alias[ $event ] ) ) {
				$event = $event_alias[ $event ];
			}
			if ( preg_match( '/^[a-z0-9_]+\.[a-z0-9_]+$/', $event ) ) {
				$sanitized[] = $event;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Unwrap paginated LayerV list envelopes into a flat item array.
	 *
	 * @param array $data API data.
	 * @return array
	 */
	private function flatten_collection( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}

		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			return array_values( $data );
		}

		foreach ( array( 'items', 'deliveries', 'results', 'data' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				return $this->flatten_collection( $data[ $key ] );
			}
		}

		return array();
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
