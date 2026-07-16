<?php
/**
 * Capability helpers.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Capabilities
 */
class Mailpai_Smtp_Capabilities {

	/**
	 * @return string
	 */
	public static function manage_cap() {
		return apply_filters( 'mailpai_smtp_manage_capability', 'manage_options' );
	}

	/**
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::manage_cap() );
	}
}
