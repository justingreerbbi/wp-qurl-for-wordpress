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
		<h2><?php esc_html_e( 'Account Quota', 'wp-qurl' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Current LayerV usage and limits for your API key.', 'wp-qurl' ); ?></p>

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

				<?php if ( ! empty( $quota['usage'] ) && is_array( $quota['usage'] ) ) : ?>
					<table class="widefat striped" style="margin-top:1em;">
						<tbody>
							<?php if ( isset( $quota['usage']['qurls_created'] ) ) : ?>
								<tr>
									<th scope="row"><?php esc_html_e( 'qURLs created', 'wp-qurl' ); ?></th>
									<td><?php echo esc_html( (string) $quota['usage']['qurls_created'] ); ?></td>
								</tr>
							<?php endif; ?>
							<?php if ( isset( $quota['usage']['active_qurls'] ) ) : ?>
								<tr>
									<th scope="row"><?php esc_html_e( 'Active qURLs', 'wp-qurl' ); ?></th>
									<td><?php echo esc_html( (string) $quota['usage']['active_qurls'] ); ?></td>
								</tr>
							<?php endif; ?>
							<?php if ( isset( $quota['usage']['active_qurls_percent'] ) ) : ?>
								<tr>
									<th scope="row"><?php esc_html_e( 'Active qURL usage', 'wp-qurl' ); ?></th>
									<td><?php echo esc_html( number_format_i18n( (float) $quota['usage']['active_qurls_percent'] * 100, 1 ) ); ?>%</td>
								</tr>
							<?php endif; ?>
							<?php if ( isset( $quota['usage']['total_accesses'] ) ) : ?>
								<tr>
									<th scope="row"><?php esc_html_e( 'Total accesses', 'wp-qurl' ); ?></th>
									<td><?php echo esc_html( (string) $quota['usage']['total_accesses'] ); ?></td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( ! empty( $quota['rate_limits'] ) && is_array( $quota['rate_limits'] ) ) : ?>
					<details style="margin-top:1em;">
						<summary><?php esc_html_e( 'Rate limits', 'wp-qurl' ); ?></summary>
						<pre class="wp-qurl-quota-raw"><?php echo esc_html( wp_json_encode( $quota['rate_limits'], JSON_PRETTY_PRINT ) ); ?></pre>
					</details>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Quota information is unavailable.', 'wp-qurl' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( 'wp_qurl_save_settings' ); ?>
		<input type="hidden" name="wp_qurl_action" value="save_settings" />

		<h2><?php esc_html_e( 'API Connection', 'wp-qurl' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: %s: LayerV API documentation URL */
				esc_html__( 'Get your API key from the LayerV dashboard. API documentation: %s', 'wp-qurl' ),
				'<a href="https://layerv.ai/docs/" target="_blank" rel="noopener">layerv.ai/docs</a>'
			);
			?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="api_base_url"><?php esc_html_e( 'API Base URL', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="url" name="api_base_url" id="api_base_url" class="regular-text"
						value="<?php echo esc_attr( $settings['api_base_url'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Default: https://api.layerv.ai', 'wp-qurl' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="api_key"><?php esc_html_e( 'API Key', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="password" name="api_key" id="api_key" class="regular-text" autocomplete="new-password"
						placeholder="<?php echo $configured ? esc_attr__( '•••••••• (leave blank to keep current)', 'wp-qurl' ) : esc_attr__( 'Enter your LayerV API key', 'wp-qurl' ); ?>" />
					<p class="description">
						<?php esc_html_e( 'Requires qurl:read and qurl:write scopes. Stored encrypted in the database.', 'wp-qurl' ); ?>
					</p>
					<?php if ( $configured ) : ?>
						<p class="description" style="color:green;">&#10003; <?php esc_html_e( 'API key is configured.', 'wp-qurl' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Default qURL Options', 'wp-qurl' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Defaults applied when creating new qURLs. Authors can override per qURL.', 'wp-qurl' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="default_expires_in"><?php esc_html_e( 'Default Link Expiration', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="text" name="default_expires_in" id="default_expires_in" class="regular-text"
						value="<?php echo esc_attr( $settings['default_expires_in'] ); ?>" placeholder="24h" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="default_session_duration"><?php esc_html_e( 'Default Session Duration', 'wp-qurl' ); ?></label>
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
						<?php esc_html_e( 'Expire after single use by default', 'wp-qurl' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="default_max_sessions"><?php esc_html_e( 'Default Max Sessions', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="number" name="default_max_sessions" id="default_max_sessions" min="0" max="1000"
						value="<?php echo esc_attr( $settings['default_max_sessions'] ); ?>" />
					<p class="description"><?php esc_html_e( '0 = unlimited', 'wp-qurl' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Frontend Block (Self-Service)', 'wp-qurl' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Defaults for the qURL Access Link block. Logged-in users mint one-time links via the public REST API — not the admin UI.', 'wp-qurl' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="frontend_block_expires_in"><?php esc_html_e( 'Block Link Expiration', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="text" name="frontend_block_expires_in" id="frontend_block_expires_in" class="regular-text"
						value="<?php echo esc_attr( $settings['frontend_block_expires_in'] ); ?>" placeholder="24h" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="frontend_block_session_duration"><?php esc_html_e( 'Block Session Duration', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="text" name="frontend_block_session_duration" id="frontend_block_session_duration" class="regular-text"
						value="<?php echo esc_attr( $settings['frontend_block_session_duration'] ); ?>" placeholder="1h" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="frontend_mint_daily_limit"><?php esc_html_e( 'Daily Mint Limit', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<input type="number" name="frontend_mint_daily_limit" id="frontend_mint_daily_limit" min="0" max="1000"
						value="<?php echo esc_attr( $settings['frontend_mint_daily_limit'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Per user, per endpoint, per day. 0 = unlimited.', 'wp-qurl' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="frontend_allowed_hosts"><?php esc_html_e( 'Additional Allowed Hosts', 'wp-qurl' ); ?></label>
				</th>
				<td>
					<textarea name="frontend_allowed_hosts" id="frontend_allowed_hosts" class="large-text" rows="3"><?php echo esc_textarea( $settings['frontend_allowed_hosts'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One hostname per line. The site host is always allowed. HTTPS required.', 'wp-qurl' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Roles Allowed to Mint', 'wp-qurl' ); ?></th>
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
					<p class="description"><?php esc_html_e( 'Administrators, editors, and authors always receive mint access.', 'wp-qurl' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Access Logging', 'wp-qurl' ); ?></h2>
		<p class="description">
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %s: Link to the Webhooks administration page. */
					__( 'Manage LayerV webhooks on the %s page. Delivery metadata is retained for deduplication; detailed payload storage is optional.', 'wp-qurl' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wp-qurl-webhooks' ) ) . '">' . esc_html__( 'Webhooks', 'wp-qurl' ) . '</a>'
				)
			);
			?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Detailed Logging', 'wp-qurl' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="enable_webhook_logging" value="1"
							<?php checked( $settings['enable_webhook_logging'] ); ?> />
						<?php esc_html_e( 'Store full incoming webhook payloads in the access log', 'wp-qurl' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Webhook Token', 'wp-qurl' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="regenerate_webhook_token" value="1" />
						<?php esc_html_e( 'Regenerate receiver URL token on save (sync webhook in LayerV afterward)', 'wp-qurl' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Troubleshooting', 'wp-qurl' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Use debug mode while diagnosing protected-page access issues. Disable it when finished — the blocked page will expose technical request details to anyone who is denied access.', 'wp-qurl' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Debug Mode', 'wp-qurl' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="enable_debug_mode" value="1"
							<?php checked( ! empty( $settings['enable_debug_mode'] ) ); ?> />
						<?php esc_html_e( 'Show diagnostic information on the Access Denied page', 'wp-qurl' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'When enabled, blocked requests display diagnostics only to signed-in qURL administrators. Anonymous visitors never receive debug data.', 'wp-qurl' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'wp-qurl' ) ); ?>
	</form>

	<form method="post" class="wp-qurl-test-form">
		<?php wp_nonce_field( 'wp_qurl_test_connection' ); ?>
		<input type="hidden" name="wp_qurl_action" value="test_connection" />
		<?php submit_button( __( 'Test API Connection', 'wp-qurl' ), 'secondary', 'submit', false ); ?>
		<p class="description"><?php esc_html_e( 'Tests the saved API key against GET /v1/quota.', 'wp-qurl' ); ?></p>
	</form>
</div>
