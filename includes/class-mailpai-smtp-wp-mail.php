<?php
/**
 * Route wp_mail through SMTPPai.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Wp_Mail
 */
class Mailpai_Smtp_Wp_Mail {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'pre_wp_mail', array( __CLASS__, 'pre_wp_mail' ), 10, 2 );
	}

	/**
	 * @param null|bool|\WP_Error $short_circuit Short circuit.
	 * @param array               $atts          Mail atts.
	 * @return null|bool|\WP_Error
	 */
	public static function pre_wp_mail( $short_circuit, $atts ) {
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}

		if ( ! is_array( $atts ) ) {
			return null;
		}

		$attachments = isset( $atts['attachments'] ) ? $atts['attachments'] : array();
		if ( ! empty( $attachments ) ) {
			return null;
		}

		$route = Mailpai_Smtp_Context::detect( $atts );
		$conn  = Mailpai_Smtp_Routes::get_connection_id( $route );
		if ( '' === $conn ) {
			return null;
		}

		$to      = isset( $atts['to'] ) ? $atts['to'] : '';
		$subject = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';
		$message = isset( $atts['message'] ) ? (string) $atts['message'] : '';
		$headers = Mailpai_Smtp_Mail_Headers::sanitize( isset( $atts['headers'] ) ? $atts['headers'] : array() );

		$result = Mailpai_Smtp_Mailer::send_for_route(
			$route,
			array(
				'to'      => $to,
				'subject' => $subject,
				'message' => $message,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
