<?php
/**
 * Resource detail view — qURLs, sessions, and access log for one resource.
 *
 * @package WP_QURL
 *
 * @var string $resource_id  LayerV resource ID.
 * @var array|null $resource Resource data from API.
 * @var array  $qurls        qURL summaries from API.
 * @var array  $sessions     Active sessions from API.
 * @var array  $local_tokens Locally stored portal links.
 * @var array  $access_log   Webhook-logged access events.
 * @var string $tab          Active tab slug.
 * @var string $api_error    API error message.
 * @var bool   $just_created Whether resource was just created.
 */

defined( 'ABSPATH' ) || exit;

$detail_base    = add_query_arg(
	array(
		'page'        => 'wp-qurl-resource',
		'resource_id' => $resource_id,
	),
	admin_url( 'admin.php' )
);
$display_target = ! empty( $local_tokens ) && ! empty( $local_tokens[0]->target_url )
	? $local_tokens[0]->target_url
	: ( $resource['target_url'] ?? '' );
?>
<div class="wrap wp-qurl-wrap">
	<h1>
		<?php esc_html_e( 'Resource Detail', 'wp-qurl' ); ?>
		<code class="wp-qurl-inline-code"><?php echo esc_html( $resource_id ); ?></code>
	</h1>

	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-qurl' ) ); ?>">&larr; <?php esc_html_e( 'Back to Dashboard', 'wp-qurl' ); ?></a>
	</p>

	<?php if ( $api_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $api_error ); ?></p></div>
	<?php endif; ?>

	<?php if ( $just_created && ! empty( $local_tokens ) ) : ?>
		<div class="notice notice-success">
			<p><strong><?php esc_html_e( 'qURL created! Copy the portal link now — it cannot be recovered later.', 'wp-qurl' ); ?></strong></p>
			<?php $newest = $local_tokens[0]; ?>
			<p>
				<input type="text" readonly class="wp-qurl-link-input large-text" value="<?php echo esc_attr( $newest->qurl_link ); ?>" />
				<button type="button" class="button wp-qurl-copy" data-copy="<?php echo esc_attr( $newest->qurl_link ); ?>">
					<?php esc_html_e( 'Copy Portal Link', 'wp-qurl' ); ?>
				</button>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $resource ) : ?>
		<div class="wp-qurl-resource-meta">
			<p>
				<strong><?php esc_html_e( 'Target URL:', 'wp-qurl' ); ?></strong>
				<?php echo esc_html( ! empty( $display_target ) ? $display_target : '—' ); ?>
			</p>
			<?php if ( ! empty( $resource['description'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Description:', 'wp-qurl' ); ?></strong> <?php echo esc_html( $resource['description'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $resource['qurl_site'] ) ) : ?>
				<p><strong><?php esc_html_e( 'qURL Site:', 'wp-qurl' ); ?></strong> <a href="<?php echo esc_url( $resource['qurl_site'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $resource['qurl_site'] ); ?></a></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper wp-qurl-tabs">
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'qurls', $detail_base ) ); ?>"
			class="nav-tab <?php echo 'qurls' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'qURLs', 'wp-qurl' ); ?>
		</a>
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'sessions', $detail_base ) ); ?>"
			class="nav-tab <?php echo 'sessions' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Active Sessions', 'wp-qurl' ); ?>
			<?php if ( count( $sessions ) ) : ?>
				<span class="count">(<?php echo esc_html( count( $sessions ) ); ?>)</span>
			<?php endif; ?>
		</a>
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'access-log', $detail_base ) ); ?>"
			class="nav-tab <?php echo 'access-log' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Access Log', 'wp-qurl' ); ?>
		</a>
	</nav>

	<?php if ( 'sessions' === $tab ) : ?>
		<div class="wp-qurl-tab-panel">
			<?php if ( \WP_QURL\Capabilities::current_user_can_manage() && ! empty( $sessions ) ) : ?>
				<form method="post" style="margin-bottom:1em;" onsubmit="return confirm('<?php echo esc_js( __( 'Terminate all active sessions?', 'wp-qurl' ) ); ?>');">
					<?php wp_nonce_field( 'wp_qurl_terminate_sessions' ); ?>
					<input type="hidden" name="wp_qurl_action" value="terminate_sessions" />
					<input type="hidden" name="resource_id" value="<?php echo esc_attr( $resource_id ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Terminate All Sessions', 'wp-qurl' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( empty( $sessions ) ) : ?>
				<p><?php esc_html_e( 'No active sessions.', 'wp-qurl' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Session ID', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'qURL ID', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Source IP', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'User Agent', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Created', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Last Seen', 'wp-qurl' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sessions as $session ) : ?>
							<tr>
								<td><code><?php echo esc_html( $session['session_id'] ?? '' ); ?></code></td>
								<td><code><?php echo esc_html( $session['qurl_id'] ?? '' ); ?></code></td>
								<td><?php echo esc_html( $session['src_ip'] ?? '' ); ?></td>
								<td class="wp-qurl-ua"><?php echo esc_html( $session['user_agent'] ?? '' ); ?></td>
								<td><?php echo esc_html( \WP_QURL\Service::format_display_datetime( $session['created_at'] ?? '', get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
								<td><?php echo esc_html( \WP_QURL\Service::format_display_datetime( $session['last_seen_at'] ?? '', get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

	<?php elseif ( 'access-log' === $tab ) : ?>
		<div class="wp-qurl-tab-panel">
			<?php if ( empty( $access_log ) ) : ?>
				<p><?php esc_html_e( 'No access events logged yet. Register a webhook under qURLs → Webhooks.', 'wp-qurl' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Event', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'qURL ID', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Delivery ID', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Source IP', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Time', 'wp-qurl' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $access_log as $entry ) : ?>
							<tr>
								<td><code><?php echo esc_html( $entry->event_type ); ?></code></td>
								<td><code><?php echo esc_html( $entry->qurl_id ); ?></code></td>
								<td><code><?php echo esc_html( ! empty( $entry->delivery_id ) ? $entry->delivery_id : '—' ); ?></code></td>
								<td><?php echo esc_html( $entry->src_ip ); ?></td>
								<td><?php echo esc_html( \WP_QURL\Service::format_display_datetime( $entry->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

	<?php else : /* qurls tab */ ?>
		<div class="wp-qurl-tab-panel">
			<div class="wp-qurl-actions" style="margin-bottom:1.5em;">
				<h2><?php esc_html_e( 'Mint New Access Link', 'wp-qurl' ); ?></h2>
				<form method="post" class="wp-qurl-inline-form">
					<?php wp_nonce_field( 'wp_qurl_mint_link' ); ?>
					<input type="hidden" name="wp_qurl_action" value="mint_link" />
					<input type="hidden" name="resource_id" value="<?php echo esc_attr( $resource_id ); ?>" />
					<input type="hidden" name="target_url" value="<?php echo esc_attr( $display_target ); ?>" />
					<input type="text" name="label" placeholder="<?php esc_attr_e( 'Label (optional)', 'wp-qurl' ); ?>" />
					<select name="expires_in">
						<option value="24h">24h</option>
						<option value="7d">7d</option>
						<option value="30d">30d</option>
					</select>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Mint Link', 'wp-qurl' ); ?></button>
				</form>
			</div>

			<h2><?php esc_html_e( 'Stored Portal Links (WordPress)', 'wp-qurl' ); ?></h2>
			<?php if ( empty( $local_tokens ) ) : ?>
				<p class="description"><?php esc_html_e( 'No portal links stored locally for this resource.', 'wp-qurl' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'qURL ID', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Portal Link', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wp-qurl' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $local_tokens as $token ) : ?>
							<tr>
								<td><?php echo esc_html( ! empty( $token->label ) ? $token->label : '—' ); ?></td>
								<td><code><?php echo esc_html( $token->qurl_id ); ?></code></td>
								<td>
									<?php if ( 'active' === $token->status && $token->qurl_link ) : ?>
										<button type="button" class="button button-small wp-qurl-copy" data-copy="<?php echo esc_attr( $token->qurl_link ); ?>">
											<?php esc_html_e( 'Copy', 'wp-qurl' ); ?>
										</button>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( ucfirst( $token->status ) ); ?></td>
								<td>
									<?php if ( 'active' === $token->status ) : ?>
										<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this qURL token?', 'wp-qurl' ) ); ?>');">
											<?php wp_nonce_field( 'wp_qurl_revoke_token' ); ?>
											<input type="hidden" name="wp_qurl_action" value="revoke_token" />
											<input type="hidden" name="resource_id" value="<?php echo esc_attr( $resource_id ); ?>" />
											<input type="hidden" name="qurl_id" value="<?php echo esc_attr( $token->qurl_id ); ?>" />
											<input type="hidden" name="local_id" value="<?php echo esc_attr( $token->id ); ?>" />
											<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Revoke', 'wp-qurl' ); ?></button>
										</form>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'API qURL Tokens', 'wp-qurl' ); ?></h2>
			<?php if ( empty( $qurls ) ) : ?>
				<p class="description"><?php esc_html_e( 'No qURL tokens returned from API.', 'wp-qurl' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'qURL ID', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Label', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'One-Time', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Use Count', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Expires', 'wp-qurl' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $qurls as $q ) : ?>
							<tr>
								<td><code><?php echo esc_html( $q['qurl_id'] ?? '' ); ?></code></td>
								<td><?php echo esc_html( $q['label'] ?? '—' ); ?></td>
								<td>
									<span class="wp-qurl-status wp-qurl-status--<?php echo esc_attr( $q['status'] ?? '' ); ?>">
										<?php echo esc_html( ucfirst( $q['status'] ?? '' ) ); ?>
									</span>
								</td>
								<td><?php echo ! empty( $q['one_time_use'] ) ? esc_html__( 'Yes', 'wp-qurl' ) : esc_html__( 'No', 'wp-qurl' ); ?></td>
								<td><?php echo esc_html( $q['use_count'] ?? 0 ); ?></td>
								<td><?php echo esc_html( \WP_QURL\Service::format_display_datetime( $q['expires_at'] ?? '', get_option( 'date_format' ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( \WP_QURL\Capabilities::current_user_can_manage() ) : ?>
				<div class="wp-qurl-danger-zone" style="margin-top:3em;">
					<h2><?php esc_html_e( 'Danger Zone', 'wp-qurl' ); ?></h2>
					<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this resource and ALL its qURLs? This cannot be undone.', 'wp-qurl' ) ); ?>');">
						<?php wp_nonce_field( 'wp_qurl_revoke_resource' ); ?>
						<input type="hidden" name="wp_qurl_action" value="revoke_resource" />
						<input type="hidden" name="resource_id" value="<?php echo esc_attr( $resource_id ); ?>" />
						<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Revoke Entire Resource', 'wp-qurl' ); ?></button>
					</form>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
