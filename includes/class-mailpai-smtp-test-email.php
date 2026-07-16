<?php
/**
 * HTML template for SMTP connection test emails only.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Test_Email
 */
class Mailpai_Smtp_Test_Email {

	/**
	 * @param string $connection_id Connection id.
	 * @return string
	 */
	public static function subject( $connection_id ) {
		return sprintf(
			/* translators: %s: site name */
			__( 'Connection test successful — %s', 'smtp-pai' ),
			get_bloginfo( 'name' )
		);
	}

	/**
	 * @param string $connection_id Connection id.
	 * @param string $to            Recipient email.
	 * @return string
	 */
	public static function html( $connection_id, $to ) {
		$connection_id = sanitize_key( (string) $connection_id );
		$to            = sanitize_email( (string) $to );
		$rec           = Mailpai_Smtp_Connection_Store::get( $connection_id );
		$provider      = Mailpai_Smtp_Provider_Registry::get( is_array( $rec ) ? ( $rec['provider'] ?? '' ) : '' );

		$site_name   = get_bloginfo( 'name' );
		$site_url    = home_url( '/' );
		$sent_at     = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$conn_name   = is_array( $rec ) ? Mailpai_Smtp_Connection_Store::title( $rec ) : '';
		$provider_l  = ! empty( $provider['label'] ) ? (string) $provider['label'] : __( 'Unknown provider', 'smtp-pai' );
		$from_name   = is_array( $rec ) ? sanitize_text_field( $rec['from_name'] ?? $site_name ) : $site_name;
		$from_email  = is_array( $rec ) ? sanitize_email( $rec['from_email'] ?? '' ) : '';
		$transport   = self::transport_summary( $rec, $provider );
		$settings    = Mailpai_Smtp_Urls::tab( 'dashboard', array( 'edit' => $connection_id ) );

		$headline = esc_html__( 'Your email connection is working', 'smtp-pai' );
		$intro    = esc_html__( 'This message confirms that SMTPPai can send mail through your configured connection. Keep this email for your records or delete it — it was sent only to verify delivery.', 'smtp-pai' );

		$rows = array(
			array( __( 'Website', 'smtp-pai' ), self::link( $site_name, $site_url ) ),
			array( __( 'Connection', 'smtp-pai' ), esc_html( $conn_name ) ),
			array( __( 'Provider', 'smtp-pai' ), esc_html( $provider_l ) ),
			array( __( 'Transport', 'smtp-pai' ), esc_html( $transport ) ),
			array( __( 'From', 'smtp-pai' ), esc_html( $from_name ) . ( $from_email ? ' <' . esc_html( $from_email ) . '>' : '' ) ),
			array( __( 'Delivered to', 'smtp-pai' ), esc_html( $to ) ),
			array( __( 'Sent at', 'smtp-pai' ), esc_html( $sent_at ) ),
		);

		$details = self::details_table( $rows );
		$cta     = esc_html__( 'View connection settings', 'smtp-pai' );
		$footer  = esc_html__( 'Sent by SMTPPai — connection test only.', 'smtp-pai' );

		return '<!DOCTYPE html>
<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>' . esc_html( self::subject( $connection_id ) ) . '</title>
</head>
<body style="margin:0;padding:0;background-color:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen-Sans,Ubuntu,Cantarell,\'Helvetica Neue\',sans-serif;color:#1d2327;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f0f0f1;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:560px;background-color:#ffffff;border:1px solid #dcdcde;border-radius:4px;overflow:hidden;">
<tr>
<td style="background-color:#1d2327;padding:24px 28px;">
<p style="margin:0 0 6px;font-size:12px;line-height:1.4;letter-spacing:0.04em;text-transform:uppercase;color:#a7aaad;">SMTPPai</p>
<h1 style="margin:0;font-size:22px;line-height:1.35;font-weight:600;color:#ffffff;">' . $headline . '</h1>
</td>
</tr>
<tr>
<td style="padding:28px;">
<p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#50575e;">' . $intro . '</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;border:1px solid #dcdcde;border-radius:4px;border-collapse:separate;">
' . $details . '
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0">
<tr>
<td style="border-radius:4px;background-color:#2271b1;">
<a href="' . esc_url( $settings ) . '" style="display:inline-block;padding:12px 20px;font-size:14px;line-height:1.4;font-weight:600;color:#ffffff;text-decoration:none;">' . $cta . '</a>
</td>
</tr>
</table>
</td>
</tr>
<tr>
<td style="padding:16px 28px 24px;border-top:1px solid #f0f0f1;">
<p style="margin:0;font-size:12px;line-height:1.5;color:#787c82;">' . $footer . '</p>
</td>
</tr>
</table>
</td></tr>
</table>
</body>
</html>';
	}

	/**
	 * @param array<int,array{0:string,1:string}> $rows Label/value pairs (value may contain safe HTML).
	 * @return string
	 */
	private static function details_table( array $rows ) {
		$html = '';
		foreach ( $rows as $index => $row ) {
			$bg     = 0 === $index % 2 ? '#ffffff' : '#f6f7f7';
			$label  = $row[0];
			$value  = $row[1];
			$html  .= '<tr>
<td width="38%" style="padding:12px 16px;font-size:13px;line-height:1.5;font-weight:600;color:#50575e;background-color:' . $bg . ';border-bottom:1px solid #f0f0f1;vertical-align:top;">' . esc_html( $label ) . '</td>
<td style="padding:12px 16px;font-size:13px;line-height:1.5;color:#1d2327;background-color:' . $bg . ';border-bottom:1px solid #f0f0f1;vertical-align:top;">' . $value . '</td>
</tr>';
		}
		return $html;
	}

	/**
	 * @param array|null $rec      Connection record.
	 * @param array      $provider Provider definition.
	 * @return string
	 */
	private static function transport_summary( $rec, array $provider ) {
		if ( ! is_array( $rec ) ) {
			return '';
		}

		$transport = $provider['transport'] ?? 'smtp';
		if ( 'api' === $transport ) {
			if ( 'amazon_ses' === ( $rec['provider'] ?? '' ) ) {
				$region = sanitize_text_field( $rec['aws_region'] ?? 'us-east-1' );
				return sprintf(
					/* translators: %s: AWS region code */
					__( 'Amazon SES API (%s)', 'smtp-pai' ),
					$region
				);
			}
			return __( 'HTTPS API', 'smtp-pai' );
		}

		$host = trim( (string) ( $rec['host'] ?? '' ) );
		if ( '' === $host && ! empty( $provider['host'] ) ) {
			$host = (string) $provider['host'];
		}
		$port = (int) ( $rec['port'] ?? ( $provider['port'] ?? 587 ) );
		if ( ! empty( $rec['disable_encryption'] ) ) {
			$enc_label = __( 'None', 'smtp-pai' );
		} else {
			$enc = sanitize_key( (string) ( $rec['encryption'] ?? ( $provider['encryption'] ?? 'tls' ) ) );
			$enc_label = 'ssl' === $enc ? 'SSL' : ( 'tls' === $enc ? 'TLS' : strtoupper( $enc ) );
		}

		if ( '' === $host ) {
			return sprintf(
				/* translators: 1: port number, 2: encryption type */
				__( 'SMTP (port %1$d, %2$s)', 'smtp-pai' ),
				$port,
				$enc_label
			);
		}

		return sprintf(
			/* translators: 1: SMTP host, 2: port number, 3: encryption type */
			__( '%1$s:%2$d (%3$s)', 'smtp-pai' ),
			$host,
			$port,
			$enc_label
		);
	}

	/**
	 * @param string $label Link text.
	 * @param string $url   URL.
	 * @return string
	 */
	private static function link( $label, $url ) {
		return '<a href="' . esc_url( $url ) . '" style="color:#2271b1;text-decoration:none;">' . esc_html( $label ) . '</a>';
	}
}
