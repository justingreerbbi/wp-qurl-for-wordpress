<?php
/**
 * Blocked access template for qURL-protected pages.
 *
 * @package WP_QURL
 *
 * @var string     $block_reason  Safe public reason code.
 * @var string     $page_title    Protected page title (not exposed as content).
 * @var array|null $debug_context Optional diagnostic payload when debug mode is enabled.
 */

defined( 'ABSPATH' ) || exit;

$site_name     = get_bloginfo( 'name' );
$debug_context = isset( $debug_context ) && is_array( $debug_context ) ? $debug_context : null;
$debug_json    = $debug_context ? wp_json_encode( $debug_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : '';
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>
		<?php
		/* translators: %s: WordPress site name. */
		echo esc_html( sprintf( __( 'Access Denied — %s', 'wp-qurl' ), $site_name ) );
		?>
	</title>
	<style>
		* { box-sizing: border-box; }
		body {
			margin: 0;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			background: #f0f0f1;
			color: #1d2327;
			min-height: 100vh;
			padding: 24px;
		}
		.wp-qurl-blocked-layout {
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			gap: 24px;
			min-height: calc(100vh - 48px);
		}
		.wp-qurl-blocked {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 8px;
			padding: 40px 48px;
			max-width: 520px;
			width: 100%;
			text-align: center;
			box-shadow: 0 1px 3px rgba(0,0,0,.06);
		}
		.wp-qurl-blocked__icon {
			font-size: 48px;
			line-height: 1;
			margin-bottom: 16px;
		}
		.wp-qurl-blocked h1 {
			font-size: 1.5em;
			margin: 0 0 12px;
		}
		.wp-qurl-blocked p {
			color: #50575e;
			line-height: 1.6;
			margin: 0 0 8px;
		}
		.wp-qurl-blocked-debug {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 8px;
			padding: 24px;
			max-width: 960px;
			width: 100%;
			box-shadow: 0 1px 3px rgba(0,0,0,.06);
			text-align: left;
		}
		.wp-qurl-blocked-debug h2 {
			margin: 0 0 8px;
			font-size: 1.15em;
		}
		.wp-qurl-blocked-debug__intro {
			color: #50575e;
			margin: 0 0 16px;
			line-height: 1.5;
		}
		.wp-qurl-blocked-debug pre {
			margin: 0;
			padding: 16px;
			background: #1d2327;
			color: #f0f0f1;
			border-radius: 6px;
			overflow: auto;
			max-height: 70vh;
			font-size: 12px;
			line-height: 1.5;
			white-space: pre-wrap;
			word-break: break-word;
		}
	</style>
</head>
<body>
	<div class="wp-qurl-blocked-layout">
		<div class="wp-qurl-blocked">
			<div class="wp-qurl-blocked__icon" aria-hidden="true">&#128274;</div>
			<h1><?php esc_html_e( 'Access Denied', 'wp-qurl' ); ?></h1>
			<?php if ( 'expired' === $block_reason || 'invalid' === $block_reason ) : ?>
				<p><?php esc_html_e( 'This access link is invalid or expired. Please request a new qURL access link.', 'wp-qurl' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'This page is protected by qURL. You need a valid access link to view it.', 'wp-qurl' ); ?></p>
				<p><?php esc_html_e( 'Open your qURL access link, then return to this page from the same browser and network.', 'wp-qurl' ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( $debug_context && $debug_json ) : ?>
			<section class="wp-qurl-blocked-debug" aria-labelledby="wp-qurl-debug-heading">
				<h2 id="wp-qurl-debug-heading"><?php esc_html_e( 'qURL Debug Information', 'wp-qurl' ); ?></h2>
				<p class="wp-qurl-blocked-debug__intro">
					<?php esc_html_e( 'Administrator-only debug mode is enabled. This panel shows request, LayerV session, and local grant diagnostics.', 'wp-qurl' ); ?>
				</p>
				<pre><?php echo esc_html( $debug_json ); ?></pre>
			</section>
		<?php endif; ?>
	</div>
</body>
</html>
