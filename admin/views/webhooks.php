<?php
/**
 * Webhooks management view.
 *
 * @package WP_QURL
 *
 * @var bool   $configured     Whether API key is set.
 * @var string $webhook_url    Local receiver URL.
 * @var array  $status         Combined webhook status from Webhook_Manager.
 * @var array  $event_types    Available event types from LayerV API.
 * @var array  $default_events Default subscribed events.
 */

defined( 'ABSPATH' ) || exit;

$is_registered = ! empty( $status['registered_locally'] );
$remote        = $status['remote'] ?? null;
$subscribed    = $status['events'] ?? $default_events;
$button_attrs  = $configured ? array() : array( 'disabled' => 'disabled' );

// Build a map of event type => description from API.
$event_catalog = array();
foreach ( $event_types as $item ) {
	if ( is_array( $item ) && ! empty( $item['type'] ) ) {
		$event_catalog[ $item['type'] ] = $item['description'] ?? '';
	} elseif ( is_string( $item ) ) {
		$event_catalog[ $item ] = '';
	}
}

// Fallback catalog when API event list is unavailable.
if ( empty( $event_catalog ) ) {
	foreach ( array_merge( $default_events, array( 'qurl.expired', 'qurl.updated', 'token.expired', 'quota.exceeded' ) ) as $type ) {
		$event_catalog[ $type ] = '';
	}
}
?>
<div class="wrap wp-qurl-wrap">
	<h1><?php esc_html_e( 'Webhooks', 'wp-qurl' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Register and manage your LayerV webhook directly from WordPress. Events are delivered to your site and logged under Access Log.', 'wp-qurl' ); ?>
	</p>

	<?php if ( ! $configured ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					esc_html__( 'Configure your API key in %s before managing webhooks.', 'wp-qurl' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wp-qurl-settings' ) ) . '">' . esc_html__( 'Settings', 'wp-qurl' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="wp-qurl-card">
		<h2><?php esc_html_e( 'Status', 'wp-qurl' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Receiver URL', 'wp-qurl' ); ?></th>
				<td>
					<input type="text" readonly class="large-text code wp-qurl-webhook-url" value="<?php echo esc_attr( $webhook_url ); ?>" onclick="this.select();" />
					<?php if ( 0 !== strpos( $webhook_url, 'https://' ) ) : ?>
						<p class="description" style="color:#d63638;">
							<?php esc_html_e( 'Your site must use HTTPS for LayerV to deliver webhooks.', 'wp-qurl' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Registration', 'wp-qurl' ); ?></th>
				<td>
					<?php if ( $is_registered ) : ?>
						<span class="wp-qurl-status wp-qurl-status--active"><?php esc_html_e( 'Registered', 'wp-qurl' ); ?></span>
						<code><?php echo esc_html( $status['webhook_id'] ); ?></code>
					<?php else : ?>
						<span class="wp-qurl-status wp-qurl-status--revoked"><?php esc_html_e( 'Not registered', 'wp-qurl' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Signing secret', 'wp-qurl' ); ?></th>
				<td>
					<?php if ( ! empty( $status['has_secret'] ) ) : ?>
						<span style="color:green;">&#10003; <?php esc_html_e( 'Stored (encrypted)', 'wp-qurl' ); ?></span>
					<?php else : ?>
						<span style="color:#d63638;"><?php esc_html_e( 'Missing — register or regenerate secret', 'wp-qurl' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $remote ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'LayerV status', 'wp-qurl' ); ?></th>
					<td>
						<?php echo esc_html( ucfirst( $remote['status'] ?? 'unknown' ) ); ?>
						<?php if ( isset( $remote['failure_count'] ) ) : ?>
							&nbsp;·&nbsp;
							<?php
							printf(
								/* translators: %d: consecutive failure count */
								esc_html__( '%d consecutive failures', 'wp-qurl' ),
								(int) $remote['failure_count']
							);
							?>
						<?php endif; ?>
						<?php if ( isset( $remote['last_delivery_success'] ) ) : ?>
							&nbsp;·&nbsp;
							<?php echo ! empty( $remote['last_delivery_success'] ) ? esc_html__( 'Last delivery: OK', 'wp-qurl' ) : esc_html__( 'Last delivery: failed', 'wp-qurl' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php elseif ( ! empty( $status['remote_error'] ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'LayerV error', 'wp-qurl' ); ?></th>
					<td><span style="color:#d63638;"><?php echo esc_html( $status['remote_error'] ); ?></span></td>
				</tr>
			<?php endif; ?>
		</table>
	</div>

	<div class="wp-qurl-card">
		<h2><?php esc_html_e( 'Event subscriptions', 'wp-qurl' ); ?></h2>
		<form method="post">
			<?php if ( $is_registered ) : ?>
				<?php wp_nonce_field( 'wp_qurl_update_webhook_events' ); ?>
				<input type="hidden" name="wp_qurl_action" value="update_webhook_events" />
			<?php else : ?>
				<?php wp_nonce_field( 'wp_qurl_register_webhook' ); ?>
				<input type="hidden" name="wp_qurl_action" value="register_webhook" />
			<?php endif; ?>

			<fieldset class="wp-qurl-event-list">
				<?php foreach ( $event_catalog as $type => $description ) : ?>
					<label class="wp-qurl-event-item">
						<input type="checkbox" name="webhook_events[]" value="<?php echo esc_attr( $type ); ?>"
							<?php checked( in_array( $type, $subscribed, true ) ); ?>
							<?php disabled( ! $configured ); ?> />
						<code><?php echo esc_html( $type ); ?></code>
						<?php if ( $description ) : ?>
							<span class="description">— <?php echo esc_html( $description ); ?></span>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<p class="submit">
				<?php if ( $is_registered ) : ?>
					<?php submit_button( __( 'Update Events', 'wp-qurl' ), 'primary', 'submit', false, $button_attrs ); ?>
				<?php else : ?>
					<?php submit_button( __( 'Register Webhook with LayerV', 'wp-qurl' ), 'primary', 'submit', false, $button_attrs ); ?>
				<?php endif; ?>
			</p>
		</form>
	</div>

	<?php if ( $is_registered ) : ?>
		<div class="wp-qurl-card">
			<h2><?php esc_html_e( 'Actions', 'wp-qurl' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Sync the receiver URL after changing the webhook token in Settings. Regenerate the secret if signature verification fails.', 'wp-qurl' ); ?></p>

			<form method="post" style="display:inline-block;margin-right:8px;">
				<?php wp_nonce_field( 'wp_qurl_sync_webhook_url' ); ?>
				<input type="hidden" name="wp_qurl_action" value="sync_webhook_url" />
				<?php submit_button( __( 'Sync URL', 'wp-qurl' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" style="display:inline-block;margin-right:8px;" onsubmit="return confirm('<?php echo esc_js( __( 'Regenerate the webhook secret? LayerV will invalidate the old secret immediately.', 'wp-qurl' ) ); ?>');">
				<?php wp_nonce_field( 'wp_qurl_regenerate_webhook_secret' ); ?>
				<input type="hidden" name="wp_qurl_action" value="regenerate_webhook_secret" />
				<?php submit_button( __( 'Regenerate Secret', 'wp-qurl' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this webhook in LayerV and clear local registration?', 'wp-qurl' ) ); ?>');">
				<?php wp_nonce_field( 'wp_qurl_unregister_webhook' ); ?>
				<input type="hidden" name="wp_qurl_action" value="unregister_webhook" />
				<?php submit_button( __( 'Unregister', 'wp-qurl' ), 'delete', 'submit', false ); ?>
			</form>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $status['deliveries'] ) ) : ?>
		<div class="wp-qurl-card">
			<h2><?php esc_html_e( 'Recent deliveries', 'wp-qurl' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Delivery ID', 'wp-qurl' ); ?></th>
						<th><?php esc_html_e( 'Event', 'wp-qurl' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-qurl' ); ?></th>
						<th><?php esc_html_e( 'HTTP', 'wp-qurl' ); ?></th>
						<th><?php esc_html_e( 'Time', 'wp-qurl' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $status['deliveries'] as $delivery ) : ?>
						<?php if ( ! is_array( $delivery ) ) { continue; } ?>
						<tr>
							<td><code><?php echo esc_html( $delivery['delivery_id'] ?? '' ); ?></code></td>
							<td><code><?php echo esc_html( $delivery['event_type'] ?? ( $delivery['event'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( $delivery['status'] ?? '' ); ?></td>
							<td><?php echo esc_html( $delivery['response_status'] ?? ( $delivery['http_status'] ?? '—' ) ); ?></td>
							<td>
								<?php
								$ts = $delivery['created_at'] ?? '';
								echo esc_html( \WP_QURL\Service::format_display_datetime( $ts, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
