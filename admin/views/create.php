<?php
/**
 * Mint qURL form view.
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
	<h1><?php esc_html_e( 'Mint qURL', 'wp-qurl' ); ?></h1>

	<?php if ( ! $configured ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					esc_html__( 'Add your API key in %s first.', 'wp-qurl' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wp-qurl-settings' ) ) . '">' . esc_html__( 'Settings', 'wp-qurl' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="wp-qurl-card">
		<p class="description">
			<?php esc_html_e( 'Make a private link to a page. Copy it when it appears — it only shows once.', 'wp-qurl' ); ?>
		</p>

		<form method="post" action="">
			<?php wp_nonce_field( 'wp_qurl_create' ); ?>
			<input type="hidden" name="wp_qurl_action" value="create_qurl" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="target_url"><?php esc_html_e( 'Page URL', 'wp-qurl' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="url" name="target_url" id="target_url" class="regular-text" required
							value="<?php echo esc_attr( $prefill_target_url ); ?>"
							placeholder="https://internal.example.com/dashboard" <?php disabled( ! $configured ); ?> />
						<p class="description"><?php esc_html_e( 'The page this link should open.', 'wp-qurl' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="label"><?php esc_html_e( 'Label', 'wp-qurl' ); ?></label>
					</th>
					<td>
						<input type="text" name="label" id="label" class="regular-text" maxlength="500"
							placeholder="<?php esc_attr_e( 'e.g. Contractor access for Alice', 'wp-qurl' ); ?>" <?php disabled( ! $configured ); ?> />
						<p class="description"><?php esc_html_e( 'Optional. A note so you remember who this is for.', 'wp-qurl' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="description"><?php esc_html_e( 'Description', 'wp-qurl' ); ?></label>
					</th>
					<td>
						<textarea name="description" id="description" class="large-text" rows="3" <?php disabled( ! $configured ); ?>></textarea>
						<p class="description"><?php esc_html_e( 'Optional. Extra notes for yourself.', 'wp-qurl' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="expires_in"><?php esc_html_e( 'Link expiration', 'wp-qurl' ); ?></label>
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
						<p class="description"><?php esc_html_e( 'How long the link stays usable.', 'wp-qurl' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="session_duration"><?php esc_html_e( 'Viewing time', 'wp-qurl' ); ?></label>
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
						<p class="description"><?php esc_html_e( 'How long they can keep viewing after they open it.', 'wp-qurl' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'One-Time Use', 'wp-qurl' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="one_time_use" value="1"
								<?php checked( $settings['default_one_time_use'] ); ?> <?php disabled( ! $configured ); ?> />
							<?php esc_html_e( 'This link works only once', 'wp-qurl' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="max_sessions"><?php esc_html_e( 'People at once', 'wp-qurl' ); ?></label>
					</th>
					<td>
						<input type="number" name="max_sessions" id="max_sessions" min="0" max="1000"
							value="<?php echo esc_attr( $settings['default_max_sessions'] ); ?>" <?php disabled( ! $configured ); ?> />
						<p class="description"><?php esc_html_e( 'How many people can use it at the same time. 0 means no limit.', 'wp-qurl' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Mint qURL', 'wp-qurl' ), 'primary', 'submit', true, $button_attrs ); ?>
		</form>
	</div>
</div>
