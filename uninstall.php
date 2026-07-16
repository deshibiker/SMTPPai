<?php
/**
 * Uninstall SMTPPai.
 *
 * @package Mailpai_Smtp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Uninstall script locals.

$settings = get_option( 'mailpai_smtp_settings', array() );
if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
	return;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-mailpai-smtp-autoload.php';
Mailpai_Smtp_Autoload::register();
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mailpai-smtp-schema.php';

Mailpai_Smtp_Schema::drop_tables();

foreach (
	array(
		'mailpai_smtp_connections',
		'mailpai_smtp_connection_meta',
		'mailpai_smtp_routes',
		'mailpai_smtp_backup',
		'mailpai_smtp_settings',
		'mailpai_smtp_db_version',
		'mailpai_smtp_alerts_retired',
	) as $opt
) {
	delete_option( $opt );
}

delete_transient( 'mailpai_smtp_log_stats_today' );
delete_transient( 'mailpai_smtp_conflicts' );
