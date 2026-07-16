<?php
/**
 * Encrypt / decrypt secrets using OpenSSL.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Crypto
 */
class Mailpai_Smtp_Crypto {

	/**
	 * Whether OpenSSL encryption is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * @param string $payload Stored payload.
	 * @return bool
	 */
	public static function is_encrypted_payload( $payload ) {
		if ( ! is_string( $payload ) || '' === $payload ) {
			return false;
		}

		$raw = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return false;
		}

		return false !== self::decrypt( $payload );
	}

	/**
	 * @param string $plain Plaintext.
	 * @return string|false
	 */
	public static function encrypt( $plain ) {
		if ( ! is_string( $plain ) || '' === $plain ) {
			return false;
		}

		if ( ! self::is_available() ) {
			return false;
		}

		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $enc ) {
			return false;
		}

		return base64_encode( $iv . $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @param string $payload Encrypted payload.
	 * @return string|false
	 */
	public static function decrypt( $payload ) {
		if ( ! is_string( $payload ) || '' === $payload ) {
			return false;
		}

		if ( ! self::is_available() ) {
			return false;
		}

		$raw = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return false;
		}

		$iv    = substr( $raw, 0, 16 );
		$enc   = substr( $raw, 16 );
		$key   = hash( 'sha256', wp_salt( 'auth' ), true );
		$plain = openssl_decrypt( $enc, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return false !== $plain ? $plain : false;
	}
}
