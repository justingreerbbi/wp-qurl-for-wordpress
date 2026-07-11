<?php
/**
 * Symmetric encryption for sensitive plugin data at rest.
 *
 * Uses WordPress AUTH_KEY and SECURE_AUTH_KEY for key derivation.
 *
 * @package WP_QURL
 */

namespace WP_QURL;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts and decrypts strings using authenticated OpenSSL encryption.
 */
class Crypto {

	/**
	 * Prefix for authenticated encryption values.
	 */
	const VERSION_PREFIX = 'v2:';

	/**
	 * Encrypt a plaintext string.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Versioned, Base64-encoded authenticated payload, or empty string on failure.
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === $plaintext ) {
			return '';
		}

		$key = self::get_key();

		try {
			$iv = random_bytes( 12 );
		} catch ( \Throwable $error ) {
			return '';
		}

		$tag        = '';
		$ciphertext = openssl_encrypt( (string) $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			return '';
		}

		return self::VERSION_PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a ciphertext string.
	 *
	 * @param string $encoded Base64-encoded ciphertext.
	 * @return string Plaintext or original value if decryption fails.
	 */
	public static function decrypt( $encoded ) {
		if ( '' === $encoded ) {
			return '';
		}

		if ( 0 === strpos( $encoded, self::VERSION_PREFIX ) ) {
			return self::decrypt_authenticated( substr( $encoded, strlen( self::VERSION_PREFIX ) ), $encoded );
		}

		return $encoded;
	}

	/**
	 * Decrypt an authenticated AES-256-GCM value.
	 *
	 * @param string $encoded  Base64-encoded payload.
	 * @param string $fallback Original value returned on failure.
	 * @return string
	 */
	private static function decrypt_authenticated( $encoded, $fallback ) {
		$raw = base64_decode( $encoded, true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return $fallback;
		}

		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );
		$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-gcm', self::get_key(), OPENSSL_RAW_DATA, $iv, $tag );

		return false !== $plaintext ? $plaintext : $fallback;
	}

	/**
	 * Derive a 32-byte encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private static function get_key() {
		return hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY . 'wp-qurl', true );
	}
}
