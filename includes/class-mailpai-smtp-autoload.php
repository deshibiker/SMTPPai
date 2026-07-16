<?php
/**
 * Autoload Mailpai_Smtp_* classes.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Autoload
 */
class Mailpai_Smtp_Autoload {

	/**
	 * Register autoloader.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * @param string $class Class name.
	 */
	public static function autoload( $class ) {
		if ( 0 !== strpos( $class, 'Mailpai_Smtp_' ) ) {
			return;
		}

		$relative = str_replace( '_', '-', strtolower( substr( $class, 13 ) ) );
		$paths    = array(
			MAILPAI_SMTP_PLUGIN_DIR . 'includes/class-mailpai-smtp-' . $relative . '.php',
			MAILPAI_SMTP_PLUGIN_DIR . 'includes/admin/class-mailpai-smtp-' . $relative . '.php',
		);

		foreach ( $paths as $path ) {
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
