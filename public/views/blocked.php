<?php
/**
 * Protected-page access denied.
 *
 * @package WP_QURL
 */

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Access Denied', 'wp-qurl' ); ?></title>
	<style>
		* { box-sizing: border-box; }
		body {
			display: grid;
			place-items: center;
			min-height: 100vh;
			margin: 0;
			padding: 24px;
			background: #f0f0f1;
			color: #1d2327;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
		}
		main {
			width: min(520px, 100%);
			padding: 40px;
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 8px;
			text-align: center;
		}
		h1 { margin: 0 0 12px; font-size: 1.5rem; }
		p { margin: 0; color: #50575e; line-height: 1.6; }
	</style>
</head>
<body>
	<main>
		<h1><?php esc_html_e( 'Access Denied', 'wp-qurl' ); ?></h1>
		<p><?php esc_html_e( 'This page is private. Use the qURL you were given to open it.', 'wp-qurl' ); ?></p>
	</main>
</body>
</html>
