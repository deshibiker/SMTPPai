<?php
/**
 * Plugin Name:       SMTPPai
 * Plugin URI:        https://wordpress.org/plugins/smtp-pai/
 * Description:       Free WordPress SMTP with native API mailers, unlimited connections, per-route routing, backup failover, and an email log.
 * Version:           1.0.1
 * Requires at least: 6.2
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            Dewan Shahedur Rahman
 * Author URI:        https://mailpai.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smtp-pai
 * Domain Path:       /languages
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

define( 'MAILPAI_SMTP_VERSION', '1.0.1' );
define( 'MAILPAI_SMTP_PLUGIN_FILE', __FILE__ );
define( 'MAILPAI_SMTP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAILPAI_SMTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MAILPAI_SMTP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'MAILPAI_SMTP_BRAND_NAME', 'SMTPPai' );
/** wp-admin ?page= slug — keep stable for OAuth redirect URIs and bookmarks. */
define( 'MAILPAI_SMTP_MENU_SLUG', 'mailpai-smtp' );
/** Plugin Check / gettext text domain (folder name may differ on wp.org). */
define( 'MAILPAI_SMTP_TEXT_DOMAIN', 'smtp-pai' );
/** WordPress.org plugin directory slug (Plugin URI only). */
define( 'MAILPAI_SMTP_ORG_SLUG', 'smtp-pai' );

require_once MAILPAI_SMTP_PLUGIN_DIR . 'includes/class-mailpai-smtp-autoload.php';

Mailpai_Smtp_Autoload::register();

register_activation_hook( __FILE__, array( 'Mailpai_Smtp_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Mailpai_Smtp_Activator', 'deactivate' ) );

/**
 * @return Mailpai_Smtp_Plugin
 */
function mailpai_smtp() {
	return Mailpai_Smtp_Plugin::instance();
}

/**
 * Whether SMTPPai is active.
 *
 * @return bool
 */
function mailpai_smtp_is_active() {
	return class_exists( 'Mailpai_Smtp_Plugin' );
}

/**
 * All connections keyed by id.
 *
 * @return array<string,array>
 */
function mailpai_smtp_get_connections() {
	return Mailpai_Smtp_Connection_Store::get_all();
}

/**
 * One connection record.
 *
 * @param string $id Connection id.
 * @return array|null
 */
function mailpai_smtp_get_connection( $id ) {
	return Mailpai_Smtp_Connection_Store::get( $id );
}

/**
 * Send via a connection.
 *
 * @param string $connection_id Connection id.
 * @param array  $args          to, subject, message, headers, route.
 * @return true|\WP_Error
 */
function mailpai_smtp_send( $connection_id, array $args ) {
	return Mailpai_Smtp_Mailer::send_via_connection( $connection_id, $args );
}

/**
 * Connection id assigned to a mail route.
 *
 * @param string $route Route slug.
 * @return string
 */
function mailpai_smtp_get_route_connection( $route ) {
	return Mailpai_Smtp_Routes::get_connection_id( $route );
}

/**
 * Whether a mail route has an enabled connection assigned.
 *
 * @param string $route Route slug (newsletter, outreach, wordpress, woocommerce).
 * @return bool
 */
function mailpai_smtp_is_route_ready( $route ) {
	return Mailpai_Smtp_Integration::is_route_ready( $route );
}

/**
 * Send via the connection assigned to a mail route (with backup failover).
 *
 * @param string $route Route slug.
 * @param array  $args  to, subject, message, headers.
 * @return true|\WP_Error
 */
function mailpai_smtp_send_for_route( $route, array $args ) {
	return Mailpai_Smtp_Mailer::send_for_route( $route, $args );
}

mailpai_smtp();
