<?php
/**
 * Settings sidebar guide content.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Settings_Guide
 */
class Mailpai_Smtp_Settings_Guide {

	/**
	 * Accordion sections for the settings page sidebar.
	 *
	 * @return array<int,array{title:string,content:string,open?:bool}>
	 */
	public static function sections() {
		$sections = array(
			array(
				'title'   => __( 'Email log', 'smtp-pai' ),
				'content' => self::paragraph(
					__( 'Control how long sent and failed messages stay in the log. Shorter retention keeps the database smaller.', 'smtp-pai' )
				),
				'open'    => true,
			),
			array(
				'title'   => __( 'Store message body', 'smtp-pai' ),
				'content' => self::paragraph(
					__( 'Saving the full HTML body helps with troubleshooting but increases storage and may include personal data. Leave this off unless you need message previews in the log.', 'smtp-pai' )
				),
			),
			array(
				'title'   => __( 'Uninstall cleanup', 'smtp-pai' ),
				'content' => self::paragraph(
					__( 'When enabled, removing the plugin deletes connections, routes, logs, and settings. Turn this on only if you want a clean uninstall.', 'smtp-pai' )
				),
			),
		);

		return apply_filters( 'mailpai_smtp_settings_guide', $sections );
	}

	/**
	 * @param string $text Paragraph text.
	 * @return string
	 */
	private static function paragraph( $text ) {
		return '<p>' . esc_html( $text ) . '</p>';
	}
}
