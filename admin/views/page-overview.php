<?php
/**
 * Page editor overview of minted qURLs for a protected page.
 *
 * @package WP_QURL
 *
 * @var array $resources Grouped token data.
 */

defined( 'ABSPATH' ) || exit;

$resources = isset( $resources ) && is_array( $resources ) ? $resources : array();
$can_open  = \WP_QURL\Capabilities::current_user_can_create();
?>
<div class="wp-qurl-page-overview">
	<?php if ( empty( $resources ) ) : ?>
		<p class="description"><?php esc_html_e( 'No qURLs for this page yet.', 'wp-qurl' ); ?></p>
	<?php else : ?>
		<?php $index = 0; ?>
		<?php foreach ( $resources as $group ) : ?>
			<?php
			++$index;
			$path_id    = $group['path_id'] ?? '';
			$tokens     = $group['tokens'] ?? array();
			$detail_url = $can_open && $path_id && 0 !== strpos( (string) $path_id, 'local-' )
				? add_query_arg(
					array(
						'page'        => 'wp-qurl-resource',
						'resource_id' => $path_id,
					),
					admin_url( 'admin.php' )
				)
				: '';
			?>
			<div class="wp-qurl-page-overview__resource">
				<h4>
					<?php
					printf(
						/* translators: %d: resource index on this page */
						esc_html__( 'Link set %d', 'wp-qurl' ),
						(int) $index
					);
					?>
					<?php if ( $detail_url ) : ?>
						<a class="button button-small" href="<?php echo esc_url( $detail_url ); ?>">
							<?php esc_html_e( 'Manage', 'wp-qurl' ); ?>
						</a>
					<?php endif; ?>
				</h4>

				<h5><?php esc_html_e( 'Minted qURLs', 'wp-qurl' ); ?></h5>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'qURL', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Expires', 'wp-qurl' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tokens as $token ) : ?>
							<tr>
								<td><?php echo esc_html( ! empty( $token->label ) ? $token->label : '—' ); ?></td>
								<td><?php echo esc_html( $token->qurl_id ); ?></td>
								<td><?php echo esc_html( ucfirst( (string) $token->status ) ); ?></td>
								<td>
									<?php
									echo ! empty( $token->expires_at )
										? esc_html( \WP_QURL\Service::format_display_datetime( $token->expires_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) )
										: '—';
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
