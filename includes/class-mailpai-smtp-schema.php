<?php
/**
 * Database schema for email log.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Schema
 */
class Mailpai_Smtp_Schema {

	const DB_VERSION = '1.1.0';

	/**
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'mailpai_smtp_log';
	}

	/**
	 * Create or upgrade tables.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'mailpai_smtp_db_version', '' );
		if ( self::DB_VERSION === $installed ) {
			return;
		}

		self::create_tables();
		update_option( 'mailpai_smtp_db_version', self::DB_VERSION, false );
	}

	/**
	 * Create log table.
	 */
	public static function create_tables() {
		global $wpdb;

		$table   = self::log_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id varchar(32) NOT NULL DEFAULT '',
			primary_connection_id varchar(32) NOT NULL DEFAULT '',
			provider varchar(32) NOT NULL DEFAULT '',
			route varchar(32) NOT NULL DEFAULT '',
			recipient varchar(191) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT 'sent',
			failover tinyint(1) NOT NULL DEFAULT 0,
			error_message text NULL,
			headers longtext NULL,
			body longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY connection_id (connection_id),
			KEY route (route),
			KEY created_at (created_at),
			KEY status_created (status, created_at),
			KEY failover_created (failover, created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop tables on uninstall.
	 */
	public static function drop_tables() {
		global $wpdb;
		$table = self::log_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is plugin-controlled.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
