<?php
/**
 * Create qURL form view.
 *
 * @package WP_QURL
 *
 * @var array $settings   Plugin settings.
 * @var bool  $configured Whether API is configured.
 * @var string $prefill_target_url Optional target URL from query string.
 */

defined( 'ABSPATH' ) || exit;

$prefill_target_url = $prefill_target_url ?? '';
$button_attrs        = $configured ? array() : array( 'disabled' => 'disabled' );
?>
<div class="wrap wp-qurl-wrap">
	<h1><?php esc_html_e( 'Create qURL', 'wp-qurl' ); ?></h1>

	<?php if ( ! $configured ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					esc_html__( 'Configure your LayerV API key in %s before creating qURLs.', 'wp-qurl' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wp-qurl-settings' ) ) . '">' . esc_html__( 'Settings', 'wp-qurl' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="wp-qurl-card">
		<p class="description">
			<?php esc_html_e( 'Create a secure, time-limited access link to a protected URL using the LayerV qURL API. The portal link is shown once after creation — store it safely.', 'wp-qurl' ); ?>
		</p>

		<form method="post" action="">
			<?php wp_nonce_field( 'wp_qurl_create' ); ?>
			<input type="hidden" name="wp_qurl_action" value="create_qurl" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="target_url"><?php esc_html_e( 'Target URL', 'wp-qurl' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="url" name="target_url" id="target_url" class="regular-text" required
							value="<?php echo esc_attr( $prefill_target_url ); ?>"
							placeholder="https://internal.example.com/dashboard" <?php disabled( ! $configured ); ?> />
						<p class="description"><?php esc_html_e( 'The HTTPS URL to protect. Must be a valid HTTPS URL.', 'wp-qurl' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="label"><?php esc_html_e( 'Label', 'wp-qurl' ); ?></label>
					</th>
					<td>
						<input type="text" name="label" id="label" class="regular-text" maxlength="500"
							placeholder="<?php esc_attr_e( 'e.g. Contractor access for Alice', 'wp-qurl' ); ?>" <?php disabled( ! $configured ); ?> />
						<p class="description"><?php esc_html_e( 'Human-readable label identifying who this qURL is for.', 'wp-qurl' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="description"><?php esc_html_e( 'Description', 'wp-qurl' ); ?></label>
					</th>
					<td>
						<textarea name="description" id="description" class="large-text" rows="3" <?php disabled( ! $configured ); ?>></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="expires_in"><?php esc_html_e( 'Link Expiration', 'wp-qurl' ); ?></label>
					</th>
					<td>
						<select name="expires_in" id="expires_in" <?php disabled( ! $configured ); ?>>
							<?php
							$expires_options = array( '1h', '6h', '12h', '24h', '2d', '3d', '7d', '14d', '30d' );
							$default_expires = $settings['default_expires_in'];
							foreach ( $expires_options as $opt ) :
								?>
								<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $default_expires, $opt ); ?>>
									<?php echo esc_html( $opt ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'How long the access link remains valid before it expires.', 'wp-qurl' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="session_duration"><?php esc_html_e( 'Session Duration', 'wp-qurl' ); ?></label>
					</th>
					<td>
						<select name="session_duration" id="session_duration" <?php disabled( ! $configured ); ?>>
							<?php
							$session_options = array( '5m', '15m', '30m', '1h', '2h', '4h', '8h', '24h' );
							$default_session = $settings['default_session_duration'];
							foreach ( $session_options as $opt ) :
								?>
								<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $default_session, $opt ); ?>>
									<?php echo esc_html( $opt ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'How long access lasts after the recipient opens the link.', 'wp-qurl' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'One-Time Use', 'wp-qurl' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="one_time_use" value="1"
								<?php checked( $settings['default_one_time_use'] ); ?> <?php disabled( ! $configured ); ?> />
							<?php esc_html_e( 'Expire after a single use', 'wp-qurl' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="max_sessions"><?php esc_html_e( 'Max Concurrent Sessions', 'wp-qurl' ); ?></label>
					</th>
					<td>
						<input type="number" name="max_sessions" id="max_sessions" min="0" max="1000"
							value="<?php echo esc_attr( $settings['default_max_sessions'] ); ?>" <?php disabled( ! $configured ); ?> />
						<p class="description"><?php esc_html_e( '0 = unlimited. Limits concurrent visitors per qURL token.', 'wp-qurl' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Create qURL', 'wp-qurl' ), 'primary', 'submit', true, $button_attrs ); ?>
		</form>
	</div>
</div>
