<?php
/**
 * Settings view — API credentials and defaults.
 *
 * @package WP_QURL
 *
 * @var array $settings   Plugin settings.
 * @var bool  $configured Whether API is configured.
 * @var array|null $quota Quota data from API.
 * @var string $quota_error API error when fetching quota.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap wp-qurl-wrap">
	<h1><?php esc_html_e( 'qURL Settings', 'wp-qurl' ); ?></h1>

	<?php if ( $configured ) : ?>
		<h2><?php esc_html_e( 'Your Plan', 'wp-qurl' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Your plan usage.', 'wp-qurl' ); ?></p>

		<?php if ( $quota_error ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $quota_error ); ?></p></div>
		<?php elseif ( $quota ) : ?>
			<div class="wp-qurl-quota-card">
				<?php if ( ! empty( $quota['plan'] ) ) : ?>
					<p>
						<strong><?php esc_html_e( 'Plan:', 'wp-qurl' ); ?></strong>
						<?php echo esc_html( ucfirst( (string) $quota['plan'] ) ); ?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $quota['period_start'] ) || ! empty( $quota['period_end'] ) ) : ?>
					<p>
						<strong><?php esc_html_e( 'Billing period:', 'wp-qurl' ); ?></strong>
						<?php
						echo esc_html(
							\WP_QURL\Service::format_display_datetime( $quota['period_start'] ?? '', get_option( 'date_format' ) )
							. ' – '
							. \WP_QURL\Service::format_display_datetime( $quota['period_end'] ?? '', get_option( 'date_format' ) )
						);
						?>
					</p>
				<?php endif; ?>

				<?php
				$usage       = ( isset( $quota['usage'] ) && is_array( $quota['usage'] ) ) ? $quota['usage'] : array();
				$limits      = ( isset( $quota['rate_limits'] ) && is_array( $quota['rate_limits'] ) ) ? $quota['rate_limits'] : array();
				$active_used = $usage['active_resources'] ?? ( $usage['active_qurls'] ?? null );
				$active_max  = $limits['max_active_resources'] ?? ( $limits['max_active_qurls'] ?? null );
				$active_pct  = $usage['active_resources_percent'] ?? ( $usage['active_qurls_percent'] ?? null );
				?>
				<?php if ( $usage ) : ?>
					<table class="widefat striped" style="margin-top:1em;">
						<tbody>
							<?php if ( isset( $usage['qurls_created'] ) ) : ?>
								<tr>
									<th scope="row"><?php esc_html_e( 'qURLs minted', 'wp-qurl' ); ?></th>
									<td><?php echo esc_html( \WP_QURL\Service::format_quota_count( $usage['qurls_created'], $limits['max_qurls'] ?? null ) ); ?></td>
								</tr>
							<?php endif; ?>
							<?php if ( null !== $active_used ) : ?>
								<tr>
									<th scope="row"><?php esc_html_e( 'Protected pages in use', 'wp-qurl' ); ?></th>
									<td><?php echo esc_html( \WP_QURL\Service::format_quota_count( $active_used, $active_max ) ); ?></td>
								</tr>
							<?php endif; ?>
							<?php if ( is_numeric( $active_pct ) ) : ?>
								<tr>
									<th scope="row"><?php esc_html_e( 'Plan usage', 'wp-qurl' ); ?></th>
									<td><?php echo esc_html( \WP_QURL\Service::format_quota_percent( $active_pct ) ); ?></td>
								</tr>
							<?php endif; ?>
							<?php if ( isset( $usage['total_accesses'] ) ) : ?>
								<tr>
									<th scope="row"><?php esc_html_e( 'Total opens', 'wp-qurl' ); ?></th>
									<td><?php echo esc_html( \WP_QURL\Service::format_quota_count( $usage['total_accesses'] ) ); ?></td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
				<?php endif; ?>

			</div>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Usage details are not available right now.', 'wp-qurl' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

		<form method="post" action="" autocomplete="off">
		<?php wp_nonce_field( 'wp_qurl_save_settings' ); ?>
		<input type="hidden" name="wp_qurl_action" value="save_settings" />
		<input type="text" name="wp_qurl_username" value="" autocomplete="username" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;height:0;width:0;opacity:0;" />

		<h2><?php esc_html_e( 'LayerV Key', 'wp-qurl' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: %s: LayerV API documentation URL */
				esc_html__( 'Get a key from your LayerV account. Help: %s', 'wp-qurl' ),
				'<a href="https://layerv.ai/docs/" target="_blank" rel="noopener">layerv.ai/docs</a>'
			);
			?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="api_base_url"><?php esc_html_e( 'LayerV address', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="url" name="api_base_url" id="api_base_url" class="regular-text"
						value="<?php echo esc_attr( $settings['api_base_url'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Leave this unless LayerV told you to change it.', 'wp-qurl' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wp_qurl_api_key"><?php esc_html_e( 'API Key', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="password" name="wp_qurl_api_key" id="wp_qurl_api_key" class="regular-text"
						autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other"
						readonly onfocus="this.removeAttribute('readonly');"
						placeholder="<?php echo $configured ? esc_attr__( 'Leave blank to keep the current key', 'wp-qurl' ) : esc_attr__( 'Paste your LayerV API key', 'wp-qurl' ); ?>" />
					<p class="description">
						<?php esc_html_e( 'Paste your key here. Leave blank to keep the one already saved.', 'wp-qurl' ); ?>
					</p>
					<?php if ( $configured ) : ?>
						<p class="description" style="color:green;">&#10003; <?php esc_html_e( 'A key is saved.', 'wp-qurl' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'New qURL defaults', 'wp-qurl' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Starting values when you make a new qURL. You can still change them each time.', 'wp-qurl' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="default_expires_in"><?php esc_html_e( 'Link expiration', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="text" name="default_expires_in" id="default_expires_in" class="regular-text"
						value="<?php echo esc_attr( $settings['default_expires_in'] ); ?>" placeholder="24h" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="default_session_duration"><?php esc_html_e( 'Viewing time', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="text" name="default_session_duration" id="default_session_duration" class="regular-text"
						value="<?php echo esc_attr( $settings['default_session_duration'] ); ?>" placeholder="1h" />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Default One-Time Use', 'wp-qurl' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="default_one_time_use" value="1"
							<?php checked( $settings['default_one_time_use'] ); ?> />
						<?php esc_html_e( 'New links work only once, unless you change it', 'wp-qurl' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="default_max_sessions"><?php esc_html_e( 'People at once', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="number" name="default_max_sessions" id="default_max_sessions" min="0" max="1000"
						value="<?php echo esc_attr( $settings['default_max_sessions'] ); ?>" />
					<p class="description"><?php esc_html_e( '0 means no limit.', 'wp-qurl' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Page Block', 'wp-qurl' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Settings for the Mint qURL block you can add to a page.', 'wp-qurl' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="frontend_block_expires_in"><?php esc_html_e( 'Link expiration', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="text" name="frontend_block_expires_in" id="frontend_block_expires_in" class="regular-text"
						value="<?php echo esc_attr( $settings['frontend_block_expires_in'] ); ?>" placeholder="24h" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="frontend_block_session_duration"><?php esc_html_e( 'Viewing time', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="text" name="frontend_block_session_duration" id="frontend_block_session_duration" class="regular-text"
						value="<?php echo esc_attr( $settings['frontend_block_session_duration'] ); ?>" placeholder="1h" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="frontend_mint_daily_limit"><?php esc_html_e( 'Daily limit', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="number" name="frontend_mint_daily_limit" id="frontend_mint_daily_limit" min="0" max="1000"
						value="<?php echo esc_attr( $settings['frontend_mint_daily_limit'] ); ?>" />
					<p class="description"><?php esc_html_e( 'How many links someone can make each day. 0 means no limit.', 'wp-qurl' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="frontend_allowed_hosts"><?php esc_html_e( 'Other sites', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<textarea name="frontend_allowed_hosts" id="frontend_allowed_hosts" class="large-text" rows="3"><?php echo esc_textarea( $settings['frontend_allowed_hosts'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Extra sites the block can make links for. One per line.', 'wp-qurl' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Who can make links', 'wp-qurl' ); ?></th>
				<td>
					<?php
					$mint_roles = \WP_QURL\Settings::get_frontend_mint_roles();
					foreach ( wp_roles()->roles as $role_slug => $role_data ) :
						?>
						<label style="display:block;margin-bottom:4px;">
							<input type="checkbox" name="frontend_mint_roles[]" value="<?php echo esc_attr( $role_slug ); ?>"
								<?php checked( in_array( $role_slug, $mint_roles, true ) ); ?> />
							<?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Admins, editors, and authors can always make links.', 'wp-qurl' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Visit history', 'wp-qurl' ); ?></h2>
		<p class="description">
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %s: Link to the Webhooks administration page. */
					__( 'Turn logging on from the %s page. You usually do not need extra details.', 'wp-qurl' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wp-qurl-webhooks' ) ) . '">' . esc_html__( 'Webhooks', 'wp-qurl' ) . '</a>'
				)
			);
			?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Extra details', 'wp-qurl' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="enable_webhook_logging" value="1"
							<?php checked( $settings['enable_webhook_logging'] ); ?> />
						<?php esc_html_e( 'Keep extra event details in the log', 'wp-qurl' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Logging password', 'wp-qurl' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="regenerate_webhook_token" value="1" />
						<?php esc_html_e( 'Make a new webhook password (sync Webhooks after you save)', 'wp-qurl' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'wp-qurl' ) ); ?>
	</form>

	<form method="post" class="wp-qurl-test-form">
		<?php wp_nonce_field( 'wp_qurl_test_connection' ); ?>
		<input type="hidden" name="wp_qurl_action" value="test_connection" />
		<?php submit_button( __( 'Test key', 'wp-qurl' ), 'secondary', 'submit', false ); ?>
		<p class="description"><?php esc_html_e( 'Checks that your API key works.', 'wp-qurl' ); ?></p>
	</form>
</div>
