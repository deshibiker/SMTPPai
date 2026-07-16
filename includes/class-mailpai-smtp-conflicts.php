<?php
/**
 * Detect conflicting SMTP plugins.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Conflicts
 */
class Mailpai_Smtp_Conflicts {

	const CACHE_KEY = 'mailpai_smtp_conflicts';

	/**
	 * Known SMTP plugin slugs.
	 *
	 * @return string[]
	 */
	public static function known_plugins() {
		return array(
			'wp-mail-smtp/wp_mail_smtp.php',
			'fluent-smtp/fluent-smtp.php',
			'post-smtp/postman-smtp.php',
			'easy-wp-smtp/easy-wp-smtp.php',
			'smtp-mailer/main.php',
			'gmail-smtp/main.php',
			'wp-smtp/wp-smtp.php',
		);
	}

	/**
	 * Init admin notices.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'flush_cache' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'flush_cache' ) );
	}

	/**
	 * Clear cached conflict scan.
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * @return array<int,array{slug:string,name:string}>
	 */
	public static function active_conflicts() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$out = array();
		foreach ( self::known_plugins() as $slug ) {
			if ( is_plugin_active( $slug ) ) {
				$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $slug, false, false );
				$out[] = array(
					'slug' => $slug,
					'name' => ! empty( $data['Name'] ) ? (string) $data['Name'] : $slug,
				);
			}
		}

		set_transient( self::CACHE_KEY, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * @return bool
	 */
	public static function has_conflict() {
		return ! empty( self::active_conflicts() );
	}

	/**
	 * Admin notice for conflicts.
	 */
	public static function admin_notice() {
		if ( ! Mailpai_Smtp_Capabilities::current_user_can_manage() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( Mailpai_Smtp_Urls::menu_slug() !== $page && ! self::has_conflict() ) {
			return;
		}

		$conflicts = self::active_conflicts();
		if ( empty( $conflicts ) ) {
			return;
		}

		$names = wp_list_pluck( $conflicts, 'name' );
		echo '<div class="notice notice-warning"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: plugin names */
				__( 'Another email plugin is active (%s). Deactivate it to avoid conflicts with SMTPPai.', 'smtp-pai' ),
				implode( ', ', $names )
			)
		);
		echo ' <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Open Plugins', 'smtp-pai' ) . '</a>';
		echo '</p></div>';
	}
}
