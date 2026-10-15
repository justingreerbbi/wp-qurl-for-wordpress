<?php
/**
 * Dashboard view — lists protected URLs and their qURLs.
 *
 * @package WP_QURL
 *
 * @var array  $rows            Protected URL rows for the dashboard table.
 * @var string $status_filter   Active status filter.
 * @var array  $status_counts   Row counts by status.
 * @var array  $status_labels   Human-readable status filter labels.
 * @var int    $total_row_count Total rows before filtering.
 * @var bool   $configured      Whether API key is set.
 */

defined( 'ABSPATH' ) || exit;

$dashboard_base = admin_url( 'admin.php?page=wp-qurl' );
?>
<div class="wrap wp-qurl-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'qURLs', 'wp-qurl' ); ?></h1>
	<?php if ( $configured ) : ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-qurl-create' ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Mint qURL', 'wp-qurl' ); ?>
		</a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<?php if ( ! $configured ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL */
					esc_html__( 'Add your API key in %s to get started.', 'wp-qurl' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wp-qurl-settings' ) ) . '">' . esc_html__( 'Settings', 'wp-qurl' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Protected Resources', 'wp-qurl' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Resources you have protected. Links only show when you create them.', 'wp-qurl' ); ?>
	</p>

	<?php if ( $total_row_count > 0 ) : ?>
		<ul class="subsubsub wp-qurl-status-filters">
			<?php
			$filter_links = array();
			foreach ( $status_labels as $status_key => $status_label ) {
				$count      = isset( $status_counts[ $status_key ] ) ? (int) $status_counts[ $status_key ] : 0;
				$filter_url = add_query_arg( 'status', $status_key, $dashboard_base );
				$class      = $status_filter === $status_key ? 'current' : '';
				$filter_links[] = sprintf(
					'<li><a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a></li>',
					esc_url( $filter_url ),
					esc_attr( $class ),
					esc_html( $status_label ),
					$count
				);
			}
			echo implode( " <span aria-hidden='true'>|</span> ", $filter_links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</ul>
		<div class="clear"></div>
	<?php endif; ?>

	<?php if ( 0 === $total_row_count ) : ?>
		<div class="wp-qurl-empty-state">
			<p><?php esc_html_e( 'Nothing here yet. Make a qURL to get started.', 'wp-qurl' ); ?></p>
			<?php if ( $configured ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wp-qurl-create' ) ); ?>">
					<?php esc_html_e( 'Mint qURL', 'wp-qurl' ); ?>
				</a>
			<?php endif; ?>
		</div>
	<?php elseif ( empty( $rows ) ) : ?>
		<div class="wp-qurl-empty-state">
			<p>
				<?php
				if ( '' !== $status_filter && isset( $status_labels[ $status_filter ] ) ) {
					printf(
						/* translators: %s: status label */
						esc_html__( 'Nothing matches “%s”.', 'wp-qurl' ),
						esc_html( $status_labels[ $status_filter ] )
					);
				} else {
					esc_html_e( 'Nothing matches this filter.', 'wp-qurl' );
				}
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( add_query_arg( 'status', '', $dashboard_base ) ); ?>">
					<?php esc_html_e( 'Show all', 'wp-qurl' ); ?>
				</a>
			</p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped wp-qurl-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Page', 'wp-qurl' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'wp-qurl' ); ?></th>
					<th scope="col"><?php esc_html_e( 'qURLs', 'wp-qurl' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Expires', 'wp-qurl' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'wp-qurl' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $res ) : ?>
					<?php
					$path_id = $res['path_id'] ?? '';
					$detail_url = add_query_arg(
						array(
							'page'        => 'wp-qurl-resource',
							'resource_id' => $path_id,
						),
						admin_url( 'admin.php' )
					);
					?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( $detail_url ); ?>">
									<?php echo esc_html( $res['target_url'] ?? '—' ); ?>
								</a>
							</strong>
							<?php if ( ! empty( $res['description'] ) ) : ?>
								<br><span class="description"><?php echo esc_html( $res['description'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<span class="wp-qurl-status wp-qurl-status--<?php echo esc_attr( $res['status'] ?? 'unknown' ); ?>">
								<?php echo esc_html( ucfirst( $res['status'] ?? 'unknown' ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $res['qurl_count'] ?? '—' ); ?></td>
						<td>
							<?php
							if ( ! empty( $res['expires_at'] ) ) {
								echo esc_html( \WP_QURL\Service::format_display_datetime( $res['expires_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
							} else {
								echo '—';
							}
							?>
						</td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( $detail_url ); ?>">
								<?php esc_html_e( 'View', 'wp-qurl' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
