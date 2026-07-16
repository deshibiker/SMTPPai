<?php
/**
 * Admin URL helpers.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Urls
 */
class Mailpai_Smtp_Urls {

	/**
	 * wp-admin ?page= slug for SMTPPai screens.
	 *
	 * @return string
	 */
	public static function menu_slug() {
		return defined( 'MAILPAI_SMTP_MENU_SLUG' ) ? MAILPAI_SMTP_MENU_SLUG : 'mailpai-smtp';
	}

	/**
	 * Whether a WordPress admin hook suffix or screen id belongs to SMTPPai.
	 *
	 * @param string $hook_or_screen Hook suffix or screen id.
	 * @return bool
	 */
	private static function hook_matches_menu( $hook_or_screen ) {
		$hook_or_screen = (string) $hook_or_screen;
		$slug           = self::menu_slug();

		if ( '' === $hook_or_screen ) {
			return false;
		}

		if ( $hook_or_screen === 'toplevel_page_' . $slug ) {
			return true;
		}

		$suffix = '_page_' . $slug;

		return strlen( $hook_or_screen ) >= strlen( $suffix )
			&& substr( $hook_or_screen, -strlen( $suffix ) ) === $suffix;
	}

	/**
	 * Whether the current or given admin screen belongs to SMTPPai.
	 *
	 * @param string $hook_suffix Optional hook suffix from admin_enqueue_scripts.
	 * @return bool
	 */
	public static function is_admin_screen( $hook_suffix = '' ) {
		$slug = self::menu_slug();

		if ( '' !== (string) $hook_suffix ) {
			return self::hook_matches_menu( $hook_suffix );
		}

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && self::hook_matches_menu( (string) $screen->id ) ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return $slug === $page;
	}

	/**
	 * @param string $tab  Tab slug.
	 * @param array  $args Query args.
	 * @return string
	 */
	public static function tab( $tab, $args = array() ) {
		$args = array_merge( array( 'page' => self::menu_slug(), 'tab' => $tab ), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
