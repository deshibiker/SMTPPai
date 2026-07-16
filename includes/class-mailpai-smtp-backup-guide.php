<?php
/**
 * Backup Connection sidebar guide content.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Backup_Guide
 */
class Mailpai_Smtp_Backup_Guide {

	/**
	 * Accordion sections for the backup page sidebar.
	 *
	 * @return array<int,array{title:string,content:string,open?:bool}>
	 */
	public static function sections() {
		$sections = array(
			array(
				'title'   => __( 'Fallback connection', 'smtp-pai' ),
				'content' => self::paragraph(
					__( 'Choose a second connection to use when your primary route fails. Leave None to disable backup.', 'smtp-pai' )
				),
				'open'    => true,
			),
			array(
				'title'   => __( 'When it runs', 'smtp-pai' ),
				'content' => self::paragraph(
					__( 'Backup sending starts automatically after you select a connection and save. Use Test on the dashboard to confirm each connection works.', 'smtp-pai' )
				),
			),
		);

		return apply_filters( 'mailpai_smtp_backup_guide', $sections );
	}

	/**
	 * @param string $text Paragraph text.
	 * @return string
	 */
	private static function paragraph( $text ) {
		return '<p>' . esc_html( $text ) . '</p>';
	}
}
