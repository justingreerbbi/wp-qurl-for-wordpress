<?php
/**
 * Resource detail view — qURLs and access log for one resource.
 *
 * @package WP_QURL
 *
 * @var string $resource_id  LayerV resource ID.
 * @var array|null $resource Resource data from API.
 * @var array  $local_tokens Locally stored qURL records.
 * @var array  $access_log   Webhook-logged access events.
 * @var string $tab          Active tab slug.
 * @var string $api_error    API error message.
 * @var bool   $just_created Whether a qURL was just minted.
 * @var string $portal_link  One-time portal URL, empty after this view.
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
$portal_link    = isset( $portal_link ) ? (string) $portal_link : '';
?>
<div class="wrap wp-qurl-wrap">
	<h1><?php esc_html_e( 'Protected page', 'wp-qurl' ); ?></h1>

	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-qurl' ) ); ?>">&larr; <?php esc_html_e( 'Back', 'wp-qurl' ); ?></a>
	</p>

	<?php if ( $api_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $api_error ); ?></p></div>
	<?php endif; ?>

	<?php if ( $portal_link ) : ?>
		<div class="notice notice-success">
			<p><strong><?php esc_html_e( 'Copy this link now. It will not show again after you leave this page.', 'wp-qurl' ); ?></strong></p>
			<p>
				<input type="text" readonly class="wp-qurl-link-input large-text" value="<?php echo esc_attr( $portal_link ); ?>" onclick="this.select();" />
			</p>
			<p>
				<button type="button" class="button button-primary wp-qurl-copy" data-copy="<?php echo esc_attr( $portal_link ); ?>">
					<?php esc_html_e( 'Copy qURL', 'wp-qurl' ); ?>
				</button>
				<a class="button" href="<?php echo esc_url( $portal_link ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Open qURL', 'wp-qurl' ); ?>
				</a>
			</p>
		</div>
	<?php elseif ( ! empty( $just_created ) ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'That link was already shown and cannot be shown again. Make a new qURL if you need another copy.', 'wp-qurl' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $display_target ) : ?>
		<div class="wp-qurl-resource-meta">
			<p>
				<strong><?php esc_html_e( 'Page:', 'wp-qurl' ); ?></strong>
				<?php echo esc_html( $display_target ); ?>
			</p>
			<?php if ( ! empty( $resource['description'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Description:', 'wp-qurl' ); ?></strong> <?php echo esc_html( $resource['description'] ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper wp-qurl-tabs">
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'qurls', $detail_base ) ); ?>"
			class="nav-tab <?php echo 'qurls' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'qURLs', 'wp-qurl' ); ?>
		</a>
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'access-log', $detail_base ) ); ?>"
			class="nav-tab <?php echo 'access-log' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Access Log', 'wp-qurl' ); ?>
		</a>
	</nav>

	<?php if ( 'access-log' === $tab ) : ?>
		<div class="wp-qurl-tab-panel">
			<?php if ( empty( $access_log ) ) : ?>
				<p><?php esc_html_e( 'Nothing logged yet. Turn on logging under qURLs → Webhooks.', 'wp-qurl' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Event', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'qURL', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Time', 'wp-qurl' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $access_log as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( \WP_QURL\Service::format_event_type( $entry->event_type ) ); ?></td>
								<td><?php echo esc_html( $entry->qurl_id ); ?></td>
								<td><?php echo esc_html( \WP_QURL\Service::format_display_datetime( $entry->created_at ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

	<?php else : /* qurls tab */ ?>
		<div class="wp-qurl-tab-panel">
			<div class="wp-qurl-actions" style="margin-bottom:1.5em;">
				<h2><?php esc_html_e( 'Mint qURL', 'wp-qurl' ); ?></h2>
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
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Mint qURL', 'wp-qurl' ); ?></button>
				</form>
			</div>

			<h2><?php esc_html_e( 'Minted qURLs', 'wp-qurl' ); ?></h2>
			<?php if ( empty( $local_tokens ) ) : ?>
				<p class="description"><?php esc_html_e( 'No qURLs for this page yet.', 'wp-qurl' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'qURL', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Expires', 'wp-qurl' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wp-qurl' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $local_tokens as $token ) : ?>
							<tr>
								<td><?php echo esc_html( ! empty( $token->label ) ? $token->label : '—' ); ?></td>
								<td><?php echo esc_html( $token->qurl_id ); ?></td>
								<td><?php echo esc_html( ucfirst( $token->status ) ); ?></td>
								<td>
									<?php
									echo $token->expires_at
										? esc_html( \WP_QURL\Service::format_display_datetime( $token->expires_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) )
										: '—';
									?>
								</td>
								<td>
									<?php if ( 'active' === $token->status ) : ?>
										<form method="post" style="display:inline-block;margin-right:0.5em;" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this qURL?', 'wp-qurl' ) ); ?>');">
											<?php wp_nonce_field( 'wp_qurl_revoke_token' ); ?>
											<input type="hidden" name="wp_qurl_action" value="revoke_token" />
											<input type="hidden" name="resource_id" value="<?php echo esc_attr( $resource_id ); ?>" />
											<input type="hidden" name="qurl_id" value="<?php echo esc_attr( $token->qurl_id ); ?>" />
											<input type="hidden" name="local_id" value="<?php echo esc_attr( $token->id ); ?>" />
											<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Revoke', 'wp-qurl' ); ?></button>
										</form>
										<form method="post" style="display:inline-flex;align-items:center;gap:0.35em;">
											<?php wp_nonce_field( 'wp_qurl_extend_token' ); ?>
											<input type="hidden" name="wp_qurl_action" value="extend_token" />
											<input type="hidden" name="resource_id" value="<?php echo esc_attr( $resource_id ); ?>" />
											<input type="hidden" name="qurl_id" value="<?php echo esc_attr( $token->qurl_id ); ?>" />
											<input type="hidden" name="local_id" value="<?php echo esc_attr( $token->id ); ?>" />
											<select name="extend_by">
												<option value="1h">+1h</option>
												<option value="24h" selected>+24h</option>
												<option value="7d">+7d</option>
											</select>
											<button type="submit" class="button button-small"><?php esc_html_e( 'Extend', 'wp-qurl' ); ?></button>
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

			<?php if ( \WP_QURL\Capabilities::current_user_can_manage() ) : ?>
				<div class="wp-qurl-danger-zone" style="margin-top:3em;">
					<h2><?php esc_html_e( 'Remove', 'wp-qurl' ); ?></h2>
					<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Turn off this page’s qURLs? This cannot be undone.', 'wp-qurl' ) ); ?>');">
						<?php wp_nonce_field( 'wp_qurl_revoke_resource' ); ?>
						<input type="hidden" name="wp_qurl_action" value="revoke_resource" />
						<input type="hidden" name="resource_id" value="<?php echo esc_attr( $resource_id ); ?>" />
						<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Revoke all qURLs', 'wp-qurl' ); ?></button>
					</form>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
