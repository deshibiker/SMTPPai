<?php
/**
 * Homepage setup wizard sidebar content.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Setup_Guide
 */
class Mailpai_Smtp_Setup_Guide {

	/**
	 * Accordion sections for the dashboard setup guide.
	 *
	 * @param int $connection_count Saved connections count.
	 * @return array<int,array{title:string,content:string,open?:bool}>
	 */
	public static function sections( $connection_count = 0 ) {
		$connection_count = max( 0, (int) $connection_count );
		$active           = self::active_step( $connection_count );

		$sections = array(
			array(
				'key'     => 'add_connection',
				'title'   => __( 'Add connection', 'smtp-pai' ),
				'content' => self::paragraph(
					__( 'Connect your SMTP or email API provider, enter sender details, and save the connection. Test delivery from the dashboard when you are ready.', 'smtp-pai' )
				) . self::action_link(
					'dashboard',
					__( 'Add a connection', 'smtp-pai' ),
					array( 'wizard' => '1' )
				),
			),
			array(
				'key'     => 'specify_connections',
				'title'   => __( 'Specify connections', 'smtp-pai' ),
				'content' => self::paragraph(
					__( 'Choose which connection sends WordPress, WooCommerce, newsletter, and outreach mail, or use one connection for everything.', 'smtp-pai' )
				) . self::action_link(
					'routes',
					__( 'Open Specify Connections', 'smtp-pai' )
				),
			),
			array(
				'key'     => 'backup',
				'title'   => __( 'Backup', 'smtp-pai' ),
				'content' => self::paragraph(
					__( 'Set a fallback connection so mail still sends if your primary provider fails.', 'smtp-pai' )
				) . self::action_link(
					'backup',
					__( 'Configure backup', 'smtp-pai' )
				),
			),
			array(
				'key'     => 'email_log',
				'title'   => __( 'Email log', 'smtp-pai' ),
				'content' => self::paragraph(
					__( 'Review sent and failed messages, filter by date or status, and inspect delivery details when troubleshooting.', 'smtp-pai' )
				) . self::action_link(
					'log',
					__( 'View email log', 'smtp-pai' )
				),
			),
		);

		foreach ( $sections as $index => &$section ) {
			if ( $section['key'] === $active ) {
				$section['open'] = true;
			}
			unset( $section['key'] );
		}
		unset( $section );

		return $sections;
	}

	/**
	 * @param int $connection_count Saved connections count.
	 * @return string Step key slug.
	 */
	private static function active_step( $connection_count ) {
		if ( $connection_count < 1 ) {
			return 'add_connection';
		}
		if ( ! self::routes_configured() ) {
			return 'specify_connections';
		}
		return 'add_connection';
	}

	/**
	 * @return bool
	 */
	private static function routes_configured() {
		return Mailpai_Smtp_Routes::is_configured();
	}

	/**
	 * @param string $text Paragraph text.
	 * @return string
	 */
	private static function paragraph( $text ) {
		return '<p>' . esc_html( $text ) . '</p>';
	}

	/**
	 * @param string $tab   Admin tab slug.
	 * @param string $label Link label.
	 * @param array  $args  Optional query args.
	 * @return string
	 */
	private static function action_link( $tab, $label, $args = array() ) {
		return sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( Mailpai_Smtp_Urls::tab( $tab, $args ) ),
			esc_html( $label )
		);
	}
}
