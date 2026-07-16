<?php
/**
 * Amazon SES Query API without external SDK.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Ses_Api
 */
class Mailpai_Smtp_Ses_Api {

	const API_VERSION = '2010-12-01';

	/**
	 * Amazon SES API regions (see AWS General Reference — SES endpoints).
	 *
	 * @return string[]
	 */
	public static function regions() {
		$regions = array(
			// US East & West.
			'us-east-1',
			'us-east-2',
			'us-west-1',
			'us-west-2',
			// Canada.
			'ca-central-1',
			'ca-west-1',
			// South America.
			'sa-east-1',
			// Europe.
			'eu-west-1',
			'eu-west-2',
			'eu-west-3',
			'eu-central-1',
			'eu-central-2',
			'eu-north-1',
			'eu-south-1',
			// Middle East, Africa & Israel.
			'me-south-1',
			'me-central-1',
			'af-south-1',
			'il-central-1',
			// Asia Pacific.
			'ap-south-1',
			'ap-south-2',
			'ap-northeast-1',
			'ap-northeast-2',
			'ap-northeast-3',
			'ap-southeast-1',
			'ap-southeast-2',
			'ap-southeast-3',
			'ap-southeast-5',
			// AWS GovCloud.
			'us-gov-east-1',
			'us-gov-west-1',
		);

		/**
		 * Filter Amazon SES regions shown in connection setup.
		 *
		 * @param string[] $regions Region codes.
		 */
		return apply_filters( 'mailpai_smtp_ses_regions', $regions );
	}

	/**
	 * @param string $region Region.
	 * @return bool
	 */
	public static function is_region_allowed( $region ) {
		return in_array( $region, self::regions(), true );
	}

	/**
	 * @param string $region Region.
	 * @return string
	 */
	public static function console_url( $region ) {
		$r = self::is_region_allowed( $region ) ? $region : 'us-east-1';
		return 'https://' . $r . '.console.aws.amazon.com/ses/home?region=' . rawurlencode( $r );
	}

	/**
	 * @param string $region     Region.
	 * @param string $access_key Access key.
	 * @param string $secret_key Secret.
	 * @param array  $params     Query params.
	 * @return array|\WP_Error
	 */
	public static function request( $region, $access_key, $secret_key, array $params ) {
		if ( ! self::is_region_allowed( $region ) ) {
			return new WP_Error( 'mailpai_smtp_ses_region', __( 'Unsupported AWS region.', 'smtp-pai' ) );
		}

		$params['Version'] = self::API_VERSION;
		$body              = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		$host              = 'email.' . $region . '.amazonaws.com';
		$url               = 'https://' . $host . '/';

		list( $auth_headers ) = Mailpai_Smtp_Aws_Sigv4::signed_headers( 'POST', $host, '/', '', $body, $access_key, $secret_key, $region, 'ses' );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array_merge(
					array(
						'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
						'Host'         => $host,
					),
					$auth_headers
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$xml  = wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			$err = self::extract_error_message( $xml );
			return new WP_Error( 'mailpai_smtp_ses_http', '' !== $err ? $err : __( 'Amazon SES request failed.', 'smtp-pai' ) );
		}

		$err = self::extract_error_message( $xml );
		if ( '' !== $err ) {
			return new WP_Error( 'mailpai_smtp_ses', $err );
		}

		return array( 'body' => $xml, 'code' => $code );
	}

	/**
	 * @param string $xml Response XML.
	 * @return string
	 */
	private static function extract_error_message( $xml ) {
		if ( ! is_string( $xml ) || false === strpos( $xml, '<Error>' ) ) {
			return '';
		}
		$code = '';
		$msg  = '';
		if ( preg_match( '/<Code>([^<]+)<\/Code>/', $xml, $m ) ) {
			$code = trim( $m[1] );
		}
		if ( preg_match( '/<Message>([^<]+)<\/Message>/', $xml, $m2 ) ) {
			$msg = trim( $m2[1] );
		}
		$line = trim( $code . ( '' !== $code && '' !== $msg ? ': ' : '' ) . $msg );
		return '' !== $line ? $line : __( 'Amazon SES returned an error.', 'smtp-pai' );
	}

	/**
	 * @param string $from_name  From name.
	 * @param string $from_email From email.
	 * @param string $to         To.
	 * @param string $subject    Subject.
	 * @param string $html          HTML body.
	 * @param array  $headers       Extra headers.
	 * @param array  $inline_images Inline CID attachments.
	 * @return string
	 */
	public static function build_raw_mime( $from_name, $from_email, $to, $subject, $html, array $headers = array(), array $inline_images = array() ) {
		$from_name = trim( (string) $from_name );
		$enc_name  = $from_name;
		if ( preg_match( '/[^\x20-\x7E]/', $from_name ) ) {
			$enc_name = '=?UTF-8?B?' . base64_encode( $from_name ) . '?='; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} elseif ( '' !== $from_name && ( false !== strpos( $from_name, '"' ) || false !== strpos( $from_name, '\\' ) ) ) {
			$enc_name = '"' . addcslashes( $from_name, '\\"' ) . '"';
		}

		$from_line = '' !== $enc_name ? ( $enc_name . ' <' . $from_email . '>' ) : $from_email;
		$sub_enc   = '=?UTF-8?B?' . base64_encode( (string) $subject ) . '?='; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$lines   = array();
		$lines[] = 'From: ' . $from_line;
		$lines[] = 'To: ' . $to;
		$lines[] = 'Subject: ' . $sub_enc;
		$reserved = array(
			'from',
			'to',
			'subject',
			'content-type',
			'content-transfer-encoding',
			'mime-version',
		);
		foreach ( Mailpai_Smtp_Mail_Headers::sanitize( $headers ) as $h ) {
			$h = trim( (string) $h );
			if ( '' === $h || ! preg_match( '/^([^:]+):/', $h, $matches ) ) {
				continue;
			}
			if ( in_array( strtolower( trim( $matches[1] ) ), $reserved, true ) ) {
				continue;
			}
			$lines[] = $h;
		}
		$lines[] = 'MIME-Version: 1.0';

		if ( empty( $inline_images ) ) {
			$lines[] = 'Content-Type: text/html; charset=UTF-8';
			$lines[] = 'Content-Transfer-Encoding: base64';
			$lines[] = '';
			$lines[] = rtrim( chunk_split( base64_encode( $html ), 76, "\r\n" ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} else {
			$boundary = 'mailpai_' . wp_hash( (string) microtime( true ) );
			$lines[]  = 'Content-Type: multipart/related; boundary="' . $boundary . '"';
			$lines[]  = '';
			$lines[]  = '--' . $boundary;
			$lines[]  = 'Content-Type: text/html; charset=UTF-8';
			$lines[]  = 'Content-Transfer-Encoding: base64';
			$lines[]  = '';
			$lines[]  = rtrim( chunk_split( base64_encode( $html ), 76, "\r\n" ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

			foreach ( $inline_images as $img ) {
				if ( empty( $img['path'] ) || empty( $img['cid'] ) || ! is_readable( (string) $img['path'] ) ) {
					continue;
				}
				$mime_type = ! empty( $img['mime'] ) ? (string) $img['mime'] : 'application/octet-stream';
				$filename  = ! empty( $img['filename'] ) ? (string) $img['filename'] : basename( (string) $img['path'] );
				$raw       = file_get_contents( (string) $img['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local media bytes for MIME inline part.
				if ( false === $raw ) {
					continue;
				}
				$lines[] = '--' . $boundary;
				$lines[] = 'Content-Type: ' . $mime_type;
				$lines[] = 'Content-Transfer-Encoding: base64';
				$lines[] = 'Content-ID: <' . (string) $img['cid'] . '>';
				$lines[] = 'Content-Disposition: inline; filename="' . str_replace( '"', '', $filename ) . '"';
				$lines[] = '';
				$lines[] = rtrim( chunk_split( base64_encode( $raw ), 76, "\r\n" ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}

			$lines[] = '--' . $boundary . '--';
		}

		return implode( "\r\n", $lines );
	}

	/**
	 * @param array $response SES API response.
	 * @return string
	 */
	public static function format_success_status( array $response ) {
		$code = (int) ( $response['code'] ?? 0 );
		$body = (string) ( $response['body'] ?? '' );
		$lines = array( 'HTTP ' . $code );

		$message_id = '';
		if ( preg_match( '/<MessageId>([^<]+)<\/MessageId>/', $body, $matches ) ) {
			$message_id = trim( $matches[1] );
		}
		if ( '' !== $message_id ) {
			$lines[] = 'MessageId: ' . $message_id;
		}

		return sanitize_textarea_field( implode( "\n", $lines ) );
	}

	/**
	 * @param string $region     Region.
	 * @param string $access_key Key.
	 * @param string $secret_key Secret.
	 * @param string $source     From email.
	 * @param string $to         Recipient.
	 * @param string $mime       Raw MIME.
	 * @return array|\WP_Error
	 */
	public static function send_raw_email( $region, $access_key, $secret_key, $source, $to, $mime ) {
		$params = array(
			'Action'                => 'SendRawEmail',
			'Source'                => $source,
			'Destinations.member.1' => $to,
			'RawMessage.Data'       => base64_encode( $mime ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);

		$res = self::request( $region, $access_key, $secret_key, $params );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $res;
	}

	/**
	 * @param string $region     Region.
	 * @param string $access_key Key.
	 * @param string $secret_key Secret.
	 * @param string $identity   Domain or email.
	 * @return array|\WP_Error Keys: verification_status (Success|Pending|Failed|NotStarted), dkim_tokens string[].
	 */
	public static function get_identity_attributes( $region, $access_key, $secret_key, $identity ) {
		$identity = trim( (string) $identity );
		if ( '' === $identity ) {
			return new WP_Error( 'mailpai_smtp_ses_identity', __( 'Identity is empty.', 'smtp-pai' ) );
		}

		$v = self::request(
			$region,
			$access_key,
			$secret_key,
			array(
				'Action'              => 'GetIdentityVerificationAttributes',
				'Identities.member.1' => $identity,
			)
		);
		if ( is_wp_error( $v ) ) {
			return $v;
		}

		$status = 'NotStarted';
		if ( preg_match( '/<VerificationStatus>([^<]+)<\/VerificationStatus>/', $v['body'], $vm ) ) {
			$status = trim( $vm[1] );
		}

		$d = self::request(
			$region,
			$access_key,
			$secret_key,
			array(
				'Action'              => 'GetIdentityDkimAttributes',
				'Identities.member.1' => $identity,
			)
		);
		if ( is_wp_error( $d ) ) {
			return $d;
		}

		$tokens = array();
		if ( preg_match( '#<DkimTokens>(.*?)</DkimTokens>#s', $d['body'], $blk ) && ! empty( $blk[1] ) && preg_match_all( '/<member>([a-zA-Z0-9]+)<\/member>/', $blk[1], $tm ) ) {
			$tokens = $tm[1];
		}

		return array(
			'verification_status' => $status,
			'dkim_tokens'         => $tokens,
		);
	}

	/**
	 * Build expected DKIM CNAME rows for SES domain identities.
	 *
	 * @param string $domain Domain (no @).
	 * @param array  $tokens Token strings from API.
	 * @return array<int,array{name:string,type:string,value:string}>
	 */
	public static function dkim_records_for_domain( $domain, array $tokens ) {
		$domain = strtolower( trim( $domain ) );
		$rows   = array();
		foreach ( $tokens as $t ) {
			$t = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $t );
			if ( '' === $t ) {
				continue;
			}
			$rows[] = array(
				'name'  => $t . '._domainkey.' . $domain,
				'type'  => 'CNAME',
				'value' => $t . '.dkim.amazonses.com',
			);
		}
		return $rows;
	}

	/**
	 * DNS verification for domain: DKIM CNAMEs, SPF hint, DMARC presence.
	 *
	 * @param string $domain        Lowercase domain.
	 * @param array  $expected_dkim Rows name,type,value from dkim_records_for_domain.
	 * @return array{rows:array<int,array{key:string,label:string,status:string,message:string}>}
	 */
	public static function dns_check_domain( $domain, array $expected_dkim ) {
		$domain = strtolower( trim( $domain ) );
		$rows   = array();

		foreach ( $expected_dkim as $rec ) {
			$name = $rec['name'] ?? '';
			$want = strtolower( (string) ( $rec['value'] ?? '' ) );
			if ( '' === $name || '' === $want ) {
				continue;
			}
			$found = self::dns_find_cname_target( $name );
			if ( null === $found ) {
				$rows[] = array(
					'key'     => 'dkim:' . $name,
					'label'   => 'DKIM',
					'status'  => 'pending',
					'message' => __( 'Not found yet (DNS may still be updating).', 'smtp-pai' ),
				);
			} elseif ( $found === $want || false !== strpos( $found, 'dkim.amazonses.com' ) ) {
				$rows[] = array(
					'key'     => 'dkim:' . $name,
					'label'   => 'DKIM',
					'status'  => 'pass',
					'message' => __( 'Record looks correct.', 'smtp-pai' ),
				);
			} else {
				$rows[] = array(
					'key'     => 'dkim:' . $name,
					'label'   => 'DKIM',
					'status'  => 'fail',
					'message' => __( 'Points to a different host than SES expects.', 'smtp-pai' ),
				);
			}
		}

		$spf = self::dns_collect_txt( $domain );
		if ( self::spf_includes_amazon_ses( $spf ) ) {
			$rows[] = array(
				'key'     => 'spf',
				'label'   => 'SPF',
				'status'  => 'pass',
				'message' => __( 'TXT includes Amazon SES.', 'smtp-pai' ),
			);
		} elseif ( ! empty( $spf ) ) {
			$rows[] = array(
				'key'     => 'spf',
				'label'   => 'SPF',
				'status'  => 'warn',
				'message' => __( 'SPF exists but does not include include:amazonses.com', 'smtp-pai' ),
			);
		} else {
			$rows[] = array(
				'key'     => 'spf',
				'label'   => 'SPF',
				'status'  => 'warn',
				'message' => __( 'No SPF TXT found on this domain.', 'smtp-pai' ),
			);
		}

		$dmarc = self::dns_collect_txt( '_dmarc.' . $domain );
		if ( ! empty( $dmarc ) && false !== strpos( strtolower( implode( ' ', $dmarc ) ), 'v=dmarc1' ) ) {
			$rows[] = array(
				'key'     => 'dmarc',
				'label'   => 'DMARC',
				'status'  => 'pass',
				'message' => __( 'DMARC record found.', 'smtp-pai' ),
			);
		} else {
			$rows[] = array(
				'key'     => 'dmarc',
				'label'   => 'DMARC',
				'status'  => 'warn',
				'message' => __( 'No DMARC TXT at _dmarc (recommended for deliverability).', 'smtp-pai' ),
			);
		}

		return array( 'rows' => $rows );
	}

	/**
	 * @param string $name Hostname to query.
	 * @return string|null Lowercase target, empty string if none, null if lookup failed.
	 */
	private static function dns_find_cname_target( $name ) {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return null;
		}
		$recs = @dns_get_record( $name, DNS_CNAME ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $recs || ! is_array( $recs ) ) {
			return null;
		}
		foreach ( $recs as $r ) {
			if ( isset( $r['target'] ) ) {
				return strtolower( rtrim( (string) $r['target'], '.' ) );
			}
		}
		return '';
	}

	/**
	 * @param string $host Host / zone name.
	 * @return string[]
	 */
	private static function dns_collect_txt( $host ) {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return array();
		}
		$recs = @dns_get_record( $host, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $recs || ! is_array( $recs ) ) {
			return array();
		}
		$out = array();
		foreach ( $recs as $r ) {
			if ( isset( $r['txt'] ) ) {
				$out[] = (string) $r['txt'];
			}
		}
		return $out;
	}

	/**
	 * @param array $txt_records TXT rdata strings.
	 * @return bool
	 */
	private static function spf_includes_amazon_ses( array $txt_records ) {
		foreach ( $txt_records as $t ) {
			$l = strtolower( $t );
			if ( false !== strpos( $l, 'v=spf1' ) && false !== strpos( $l, 'include:amazonses.com' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $email Email address.
	 * @return string Domain part or empty.
	 */
	public static function domain_from_email( $email ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return '';
		}
		$parts = explode( '@', $email, 2 );
		return isset( $parts[1] ) ? strtolower( $parts[1] ) : '';
	}

	/**
	 * Load SES identity + DKIM rows for the connection screen.
	 *
	 * @param string $region     Region.
	 * @param string $access_key Access key.
	 * @param string $secret_key Secret key.
	 * @param string $from_email From address (verified in SES).
	 * @return array|\WP_Error Keys: api_identity, verification_status, dkim_tokens, records (rows), domain_for_checks.
	 */
	public static function load_dns_snapshot( $region, $access_key, $secret_key, $from_email ) {
		$from   = sanitize_email( $from_email );
		$domain = self::domain_from_email( $from );
		if ( '' === $domain ) {
			return new WP_Error( 'mailpai_smtp_ses_from', __( 'Set a valid From email first.', 'smtp-pai' ) );
		}

		$candidates = array_unique(
			array_filter(
				array(
					$domain,
					$from,
				)
			)
		);

		$last = null;
		foreach ( $candidates as $identity ) {
			$attr = self::get_identity_attributes( $region, $access_key, $secret_key, $identity );
			if ( ! is_wp_error( $attr ) ) {
				$records = self::dkim_records_for_domain( $domain, $attr['dkim_tokens'] );
				return array(
					'api_identity'        => $identity,
					'verification_status' => $attr['verification_status'],
					'dkim_tokens'         => $attr['dkim_tokens'],
					'records'             => $records,
					'domain_for_checks'   => $domain,
				);
			}
			$last = $attr;
		}

		return $last ? $last : new WP_Error( 'mailpai_smtp_ses_dns', __( 'Could not read identity from SES.', 'smtp-pai' ) );
	}
}
