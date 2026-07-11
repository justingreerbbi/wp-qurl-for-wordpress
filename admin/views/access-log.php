<?php
/**
 * Access log view — webhook events and live API sessions.
 *
 * @package WP_QURL
 *
 * @var array $log           Paginated access log from database.
 * @var array $live_sessions Active sessions from API across resources.
 * @var string $filter       Event type filter.
 * @var int   $page          Current page.
 * @var bool  $configured    Whether API is configured.
 */

defined( 'ABSPATH' ) || exit;

$event_types = array(
	''                   => __( 'All Events', 'wp-qurl' ),
	'qurl.accessed'      => __( 'Accessed', 'wp-qurl' ),
	'qurl.access_denied' => __( 'Access Denied', 'wp-qurl' ),
	'qurl.created'       => __( 'Created', 'wp-qurl' ),
	'qurl.revoked'       => __( 'Revoked', 'wp-qurl' ),
);
?>
<div class="wrap wp-qurl-wrap">
	<h1><?php esc_html_e( 'Access Log', 'wp-qurl' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'View access events from LayerV webhooks and active sessions from the API. Register webhooks under qURLs → Webhooks.', 'wp-qurl' ); ?>
	</p>

	<?php if ( $configured && ! empty( $live_sessions ) ) : ?>
		<h2><?php esc_html_e( 'Active Sessions (Live)', 'wp-qurl' ); ?></h2>
		<table class="wp-list-table widefat fixed striped" style="margin-bottom:2em;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Resource', 'wp-qurl' ); ?></th>
					<th><?php esc_html_e( 'Session ID', 'wp-qurl' ); ?></th>
					<th><?php esc_html_e( 'qURL ID', 'wp-qurl' ); ?></th>
					<th><?php esc_html_e( 'Source IP', 'wp-qurl' ); ?></th>
					<th><?php esc_html_e( 'User Agent', 'wp-qurl' ); ?></th>
					<th><?php esc_html_e( 'Last Seen', 'wp-qurl' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $live_sessions as $session ) : ?>
					<tr>
						<td>
							<a href="
							<?php
							echo esc_url(
								add_query_arg(
									array(
										'page'        => 'wp-qurl-resource',
										'resource_id' => $session['_resource_id'],
										'tab'         => 'sessions',
									),
									admin_url( 'admin.php' )
								)
							);
							?>
										">
								<code><?php echo esc_html( $session['_resource_id'] ); ?></code>
							</a>
						</td>
						<td><code><?php echo esc_html( $session['session_id'] ?? '' ); ?></code></td>
						<td><code><?php echo esc_html( $session['qurl_id'] ?? '' ); ?></code></td>
						<td><?php echo esc_html( $session['src_ip'] ?? '' ); ?></td>
						<td class="wp-qurl-ua"><?php echo esc_html( $session['user_agent'] ?? '' ); ?></td>
						<td><?php echo esc_html( \WP_QURL\Service::format_display_datetime( $session['last_seen_at'] ?? '', get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Event History', 'wp-qurl' ); ?></h2>

	<form method="get" class="wp-qurl-filter-form">
		<input type="hidden" name="page" value="wp-qurl-access-log" />
		<select name="event_type" onchange="this.form.submit()">
			<?php foreach ( $event_types as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filter, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</form>

	<?php if ( empty( $log['items'] ) ) : ?>
		<p><?php esc_html_e( 'No events recorded yet.', 'wp-qurl' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'wp-qurl' ); ?></th>
					<th><?php esc_html_e( 'Event', 'wp-qurl' ); ?></th>
					<th><?php esc_html_e( 'Resource ID', 'wp-qurl' ); ?></th>
					<th><?php esc_html_e( 'qURL ID', 'wp-qurl' ); ?></th>
					<th><?php esc_html_e( 'Delivery ID', 'wp-qurl' ); ?></th>
					<th><?php esc_html_e( 'Source IP', 'wp-qurl' ); ?></th>
					<th><?php esc_html_e( 'User Agent', 'wp-qurl' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log['items'] as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( \WP_QURL\Service::format_display_datetime( $entry->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
						<td><code><?php echo esc_html( $entry->event_type ); ?></code></td>
						<td>
							<?php if ( $entry->resource_id ) : ?>
								<a href="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'page'        => 'wp-qurl-resource',
											'resource_id' => $entry->resource_id,
										),
										admin_url( 'admin.php' )
									)
								);
								?>
											">
									<code><?php echo esc_html( $entry->resource_id ); ?></code>
								</a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( ! empty( $entry->qurl_id ) ? $entry->qurl_id : '—' ); ?></code></td>
						<td><code><?php echo esc_html( ! empty( $entry->delivery_id ) ? $entry->delivery_id : '—' ); ?></code></td>
						<td><?php echo esc_html( ! empty( $entry->src_ip ) ? $entry->src_ip : '—' ); ?></td>
						<td class="wp-qurl-ua"><?php echo esc_html( ! empty( $entry->user_agent ) ? $entry->user_agent : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$total_pages = ceil( $log['total'] / 50 );
		if ( $total_pages > 1 ) :
			?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $page,
								'total'   => $total_pages,
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
