<?php
/**
 * Sanitize outbound mail headers.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Mail_Headers
 */
class Mailpai_Smtp_Mail_Headers {

	/** @var string[] */
	private static $blocked = array(
		'from',
		'sender',
		'return-path',
		'content-type',
		'content-transfer-encoding',
		'mime-version',
	);

	/** @var string[] */
	private static $address_headers = array(
		'reply-to',
		'cc',
		'bcc',
	);

	/**
	 * Normalize and sanitize headers from wp_mail.
	 *
	 * @param mixed $headers Raw headers.
	 * @return array<int,string>
	 */
	public static function sanitize( $headers ) {
		if ( is_string( $headers ) ) {
			$headers = preg_split( '/\r\n|\r|\n/', $headers );
		}
		if ( ! is_array( $headers ) ) {
			return array();
		}

		$out = array();
		foreach ( $headers as $header ) {
			$header = trim( (string) $header );
			if ( '' === $header ) {
				continue;
			}

			if ( preg_match( '/[\r\n]/', $header ) ) {
				continue;
			}

			if ( ! preg_match( '/^([^:]+):\s*(.+)$/', $header, $matches ) ) {
				continue;
			}

			$name  = strtolower( trim( $matches[1] ) );
			$value = trim( $matches[2] );

			if ( in_array( $name, self::$blocked, true ) ) {
				continue;
			}

			if ( in_array( $name, self::$address_headers, true ) ) {
				$value = self::sanitize_address_header( $value );
				if ( '' === $value ) {
					continue;
				}
			} else {
				$value = sanitize_text_field( $value );
				if ( '' === $value ) {
					continue;
				}
			}

			$out[] = ucwords( $name, '-' ) . ': ' . $value;
		}

		return $out;
	}

	/**
	 * @param string $value Header value.
	 * @return string
	 */
	private static function sanitize_address_header( $value ) {
		$parts  = preg_split( '/\s*,\s*/', (string) $value );
		$emails = array();

		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' === $part ) {
				continue;
			}

			if ( preg_match( '/<([^>]+)>/', $part, $matches ) ) {
				$email = sanitize_email( $matches[1] );
			} else {
				$email = sanitize_email( $part );
			}

			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}

		return implode( ', ', array_unique( $emails ) );
	}
}
