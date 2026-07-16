<?php
/**
 * Main plugin bootstrap.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Plugin
 */
final class Mailpai_Smtp_Plugin {

	/** @var self|null */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'init' ), 5 );
	}

	/**
	 * Wire components.
	 */
	public function init() {
		Mailpai_Smtp_Schema::maybe_upgrade();
		Mailpai_Smtp_Integration::maybe_migrate_mailpai_connections();
		Mailpai_Smtp_Routes::maybe_auto_assign_first_ready_connection();
		Mailpai_Smtp_Log::init();
		Mailpai_Smtp_Log::schedule_cleanup();
		self::retire_alerts();
		Mailpai_Smtp_Wp_Mail::init();
		Mailpai_Smtp_Conflicts::init();

		if ( is_admin() ) {
			Mailpai_Smtp_Admin::instance();
		}
	}

	/**
	 * Remove legacy alert cron and settings after alerts feature was retired.
	 */
	private static function retire_alerts() {
		if ( get_option( 'mailpai_smtp_alerts_retired', false ) ) {
			return;
		}

		wp_clear_scheduled_hook( 'mailpai_smtp_alert_digest' );
		delete_option( 'mailpai_smtp_alert_settings' );
		update_option( 'mailpai_smtp_alerts_retired', 1, false );
	}
}
