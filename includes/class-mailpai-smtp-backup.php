<?php
/**
 * Backup connection failover settings.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Backup
 */
class Mailpai_Smtp_Backup {

	const OPTION = 'mailpai_smtp_backup';

	/** @var array|null */
	private static $cache = null;

	/**
	 * @return array
	 */
	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		self::$cache = wp_parse_args(
			$stored,
			array(
				'enabled'          => false,
				'mode'             => 'global',
				'global_backup_id' => '',
				'map'              => array(),
				'retry_primary'    => false,
			)
		);
		return self::$cache;
	}

	/**
	 * @param array $data Settings.
	 * @return true|\WP_Error
	 */
	public static function save( array $data ) {
		$backup_id = sanitize_key( (string) ( $data['global_backup_id'] ?? '' ) );
		$clean     = array(
			'enabled'          => '' !== $backup_id,
			'mode'             => 'global',
			'global_backup_id' => $backup_id,
			'map'              => array(),
			'retry_primary'    => false,
		);

		update_option( self::OPTION, $clean, false );
		self::$cache = $clean;
		return true;
	}

	/**
	 * @param string $primary_id Primary connection id.
	 * @return string Backup connection id.
	 */
	public static function backup_for( $primary_id ) {
		$cfg = self::get();
		if ( empty( $cfg['enabled'] ) ) {
			return '';
		}

		$primary_id = sanitize_key( (string) $primary_id );
		$global     = sanitize_key( (string) $cfg['global_backup_id'] );
		return ( $global !== $primary_id ) ? $global : '';
	}

	/**
	 * @param \WP_Error $error Error object.
	 * @return bool
	 */
	public static function is_failover_eligible( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		$code    = $error->get_error_code();
		$message = strtolower( $error->get_error_message() );

		if ( in_array( $code, array( 'mailpai_smtp_invalid_to', 'mailpai_smtp_from' ), true ) ) {
			return false;
		}

		$needles = array( 'auth', 'timeout', 'connection', 'rate', 'throttl', '503', '502', '500', '429', 'unable' );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		return in_array( $code, array( 'mailpai_smtp_ses', 'mailpai_smtp_ses_http', 'mailpai_smtp_smtp', 'mailpai_smtp_api', 'http_request_failed' ), true );
	}
}
