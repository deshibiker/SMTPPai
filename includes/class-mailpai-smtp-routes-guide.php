<?php
/**
 * Specify Connections sidebar guide content.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Routes_Guide
 */
class Mailpai_Smtp_Routes_Guide {

	/**
	 * Accordion sections for the routes page sidebar.
	 *
	 * @return array<int,array{title:string,content:string,open?:bool}>
	 */
	public static function sections() {
		$sections = array(
			array(
				'key'   => 'overview',
				'title' => __( 'Mail routes', 'smtp-pai' ),
				'content' => self::paragraph(
					__( 'Assign each saved connection to the type of mail it should send. Split providers by purpose, or use one connection for everything.', 'smtp-pai' )
				),
				'open'  => true,
			),
			array(
				'key'   => 'use_one',
				'title' => __( 'One for Everything', 'smtp-pai' ),
				'content' => self::paragraph(
					__( 'Choose one saved connection when a single provider handles all mail. WordPress, WooCommerce, and MailPai campaigns all use that connection.', 'smtp-pai' )
				),
			),
			array(
				'key'   => 'separate',
				'title' => __( 'Use Separate Connection (Recommended)', 'smtp-pai' ),
				'content' => self::paragraph(
					__( 'Assign different connections by mail type. Use a transactional provider for site mail and a marketing provider for campaigns when volume or deliverability needs differ.', 'smtp-pai' )
				),
			),
			array(
				'key'   => 'transactional',
				'title' => __( 'Transactional email', 'smtp-pai' ),
				'content' => self::transactional_content(),
			),
		);

		if ( Mailpai_Smtp_Routes::marketing_available() ) {
			$sections[] = array(
				'key'   => 'marketing',
				'title' => __( 'Marketing email', 'smtp-pai' ),
				'content' => self::paragraph(
					__( 'Newsletter and outreach routes are used by MailPai for campaigns. Assign a connection tuned for bulk sending and your campaign From address.', 'smtp-pai' )
				),
			);
		}

		foreach ( $sections as $index => &$section ) {
			unset( $section['key'] );
		}
		unset( $section );

		/**
		 * Filter Specify Connections sidebar guide sections.
		 *
		 * @param array $sections Accordion sections.
		 */
		return apply_filters( 'mailpai_smtp_routes_guide', $sections );
	}

	/**
	 * @return string
	 */
	private static function transactional_content() {
		$lines = array(
			__( 'WordPress emails covers core site mail such as password resets and admin notifications.', 'smtp-pai' ),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$lines[] = __( 'WooCommerce emails covers order confirmations, shipping updates, and other store notifications.', 'smtp-pai' );
		}

		$lines[] = __( 'Pick the connection that should send each type of site mail.', 'smtp-pai' );

		$html = '';
		foreach ( $lines as $line ) {
			$html .= self::paragraph( $line );
		}

		return $html;
	}

	/**
	 * @param string $text Paragraph text.
	 * @return string
	 */
	private static function paragraph( $text ) {
		return '<p>' . esc_html( $text ) . '</p>';
	}
}
