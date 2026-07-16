<?php
/**
 * Activation and deactivation.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Activator
 */
class Mailpai_Smtp_Activator {

	/**
	 * Activate plugin.
	 */
	public static function activate() {
		Mailpai_Smtp_Schema::create_tables();
		update_option( 'mailpai_smtp_db_version', Mailpai_Smtp_Schema::DB_VERSION, false );

		if ( false === get_option( 'mailpai_smtp_settings', false ) ) {
			update_option(
				'mailpai_smtp_settings',
				array(
					'log_retention_days' => 14,
					'log_body'           => false,
					'routing_enabled'    => true,
				),
				false
			);
		}

		if ( false === get_option( 'mailpai_smtp_routes', false ) ) {
			update_option( 'mailpai_smtp_routes', Mailpai_Smtp_Routes::defaults(), false );
		}

		if ( false === get_option( 'mailpai_smtp_backup', false ) ) {
			update_option(
				'mailpai_smtp_backup',
				array(
					'enabled'          => false,
					'mode'             => 'global',
					'global_backup_id' => '',
					'map'              => array(),
					'retry_primary'    => false,
				),
				false
			);
		}

		Mailpai_Smtp_Log::schedule_cleanup();
		wp_clear_scheduled_hook( 'mailpai_smtp_alert_digest' );
		delete_option( 'mailpai_smtp_alert_settings' );
	}

	/**
	 * Deactivate plugin.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'mailpai_smtp_alert_digest' );
		wp_clear_scheduled_hook( 'mailpai_smtp_log_cleanup' );
	}
}
