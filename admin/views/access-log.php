<?php
/**
 * Access log view — webhook events.
 *
 * @package WP_QURL
 *
 * @var array  $log        Paginated access log from database.
 * @var string $filter     Event type filter.
 * @var int    $page       Current page.
 * @var bool   $configured Whether API is configured.
 */

defined( 'ABSPATH' ) || exit;

$event_types = array(
	''                   => __( 'All Events', 'wp-qurl' ),
	'qurl.accessed'      => __( 'Accessed', 'wp-qurl' ),
	'qurl.access_denied' => __( 'Access Denied', 'wp-qurl' ),
	'qurl.created'       => __( 'Minted', 'wp-qurl' ),
	'qurl.updated'       => __( 'Updated', 'wp-qurl' ),
	'qurl.expired'       => __( 'Expired', 'wp-qurl' ),
	'qurl.revoked'       => __( 'Revoked', 'wp-qurl' ),
	'qurl.token_exhausted' => __( 'Used up', 'wp-qurl' ),
	'resource.closed'    => __( 'Closed', 'wp-qurl' ),
);
?>
<div class="wrap wp-qurl-wrap">
	<h1><?php esc_html_e( 'Access Log', 'wp-qurl' ); ?></h1>

	<p class="description">
		<?php
		printf(
			/* translators: %s: WordPress timezone string */
			esc_html__( 'A history of your qURL links. Times match your site timezone (%s).', 'wp-qurl' ),
			esc_html( wp_timezone_string() )
		);
		?>
	</p>

	<h2><?php esc_html_e( 'History', 'wp-qurl' ); ?></h2>

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
		<p><?php esc_html_e( 'Nothing logged yet.', 'wp-qurl' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'wp-qurl' ); ?></th>
					<th><?php esc_html_e( 'Event', 'wp-qurl' ); ?></th>
					<th><?php esc_html_e( 'qURL', 'wp-qurl' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log['items'] as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( \WP_QURL\Service::format_display_datetime( $entry->created_at ) ); ?></td>
						<td><?php echo esc_html( \WP_QURL\Service::format_event_type( $entry->event_type ) ); ?></td>
						<td><?php echo esc_html( ! empty( $entry->qurl_id ) ? $entry->qurl_id : '—' ); ?></td>
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
