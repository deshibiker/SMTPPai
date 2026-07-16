<?php
/**
 * Connection status helpers.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Monitor
 */
class Mailpai_Smtp_Monitor {

	/**
	 * @param array $rec Connection.
	 * @return string working|failed|untested|disabled
	 */
	public static function connection_status( array $rec ) {
		if ( empty( $rec['enabled'] ) ) {
			return 'disabled';
		}
		if ( Mailpai_Smtp_Connection_Store::needs_oauth_signin( $rec ) ) {
			return 'untested';
		}
		$st = $rec['last_status'] ?? '';
		if ( 'failed' === $st ) {
			return 'failed';
		}
		if ( 'working' === $st || ! empty( $rec['last_sent_at'] ) ) {
			return 'working';
		}
		return 'untested';
	}
}
