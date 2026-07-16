<?php
/**
 * Detect mail context for routing.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Context
 */
class Mailpai_Smtp_Context {

	/** @var string|null */
	private static $detected = null;

	/**
	 * @param array $atts wp_mail atts.
	 * @return string Route slug.
	 */
	public static function detect( array $atts = array() ) {
		if ( null !== self::$detected ) {
			return apply_filters( 'mailpai_smtp_mail_context', self::$detected, $atts );
		}

		self::$detected = 'wordpress';

		if ( self::is_woocommerce_context() ) {
			self::$detected = 'woocommerce';
		}

		return apply_filters( 'mailpai_smtp_mail_context', self::$detected, $atts );
	}

	/**
	 * @return bool
	 */
	public static function is_woocommerce_context() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		if ( doing_action( 'woocommerce_email' ) || doing_action( 'woocommerce_email_header' ) || doing_action( 'woocommerce_email_footer' ) ) {
			return true;
		}

		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return false;
		}

		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		foreach ( $trace as $frame ) {
			$class = isset( $frame['class'] ) ? (string) $frame['class'] : '';
			if ( 0 === strpos( $class, 'WC_Email' ) || false !== strpos( $class, 'WooCommerce' ) ) {
				return true;
			}
		}

		return false;
	}
}
