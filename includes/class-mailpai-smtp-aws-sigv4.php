<?php
/**
 * AWS Signature Version 4 signing for regional HTTPS requests (e.g. Amazon SES query API).
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Aws_Sigv4
 */
class Mailpai_Smtp_Aws_Sigv4 {

	/**
	 * Build Authorization header and return headers array for wp_remote_*.
	 *
	 * @param string $method       HTTP method.
	 * @param string $host        Request host (e.g. email.us-east-1.amazonaws.com).
	 * @param string $path        URL path (usually /).
	 * @param string $query       Canonical query string (often empty).
	 * @param string $body        Exact request body bytes.
	 * @param string $access_key  AWS access key ID.
	 * @param string $secret_key  AWS secret access key.
	 * @param string $region      AWS region.
	 * @param string $service     AWS service id (ses).
	 * @return array{0:array<string,string>,1:string} Signed headers and amz_date (ISO8601).
	 */
	public static function signed_headers( $method, $host, $path, $query, $body, $access_key, $secret_key, $region, $service ) {
		$amz_date    = gmdate( 'Ymd\THis\Z' );
		$date_stamp  = gmdate( 'Ymd' );
		$payload_hash = hash( 'sha256', $body );

		$canonical_headers  = 'content-type:application/x-www-form-urlencoded; charset=utf-8' . "\n";
		$canonical_headers .= 'host:' . strtolower( $host ) . "\n";
		$canonical_headers .= 'x-amz-content-sha256:' . $payload_hash . "\n";
		$canonical_headers .= 'x-amz-date:' . $amz_date . "\n";

		$signed_headers = 'content-type;host;x-amz-content-sha256;x-amz-date';

		$canonical_request = strtoupper( $method ) . "\n"
			. self::canonical_uri_for_ses( $path ) . "\n"
			. self::canonical_query( $query ) . "\n"
			. $canonical_headers . "\n"
			. $signed_headers . "\n"
			. $payload_hash;

		$credential_scope = $date_stamp . '/' . $region . '/' . $service . '/aws4_request';
		$string_to_sign   = 'AWS4-HMAC-SHA256' . "\n"
			. $amz_date . "\n"
			. $credential_scope . "\n"
			. hash( 'sha256', $canonical_request );

		$signing_key = self::get_signing_key( $secret_key, $date_stamp, $region, $service );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$credential = $access_key . '/' . $credential_scope;
		$auth       = 'AWS4-HMAC-SHA256 Credential=' . $credential
			. ', SignedHeaders=' . $signed_headers
			. ', Signature=' . $signature;

		$headers = array(
			'Authorization'       => $auth,
			'X-Amz-Date'          => $amz_date,
			'X-Amz-Content-Sha256' => $payload_hash,
		);

		return array( $headers, $amz_date );
	}

	/**
	 * Build Authorization header for unsigned-body GET requests (e.g. SES v2 REST API).
	 *
	 * @param string $host        Request host.
	 * @param string $path        URL path (e.g. /v2/email/account).
	 * @param string $access_key  AWS access key ID.
	 * @param string $secret_key  AWS secret access key.
	 * @param string $region      AWS region.
	 * @param string $service     AWS service id (ses).
	 * @return array{0:array<string,string>,1:string} Signed headers and amz_date (ISO8601).
	 */
	public static function signed_headers_get( $host, $path, $access_key, $secret_key, $region, $service ) {
		$method       = 'GET';
		$query        = '';
		$body         = '';
		$amz_date     = gmdate( 'Ymd\THis\Z' );
		$date_stamp   = gmdate( 'Ymd' );
		$payload_hash = hash( 'sha256', $body );

		$canonical_headers  = 'host:' . strtolower( $host ) . "\n";
		$canonical_headers .= 'x-amz-content-sha256:' . $payload_hash . "\n";
		$canonical_headers .= 'x-amz-date:' . $amz_date . "\n";

		$signed_headers = 'host;x-amz-content-sha256;x-amz-date';

		$canonical_request = $method . "\n"
			. self::canonical_uri_for_ses( $path ) . "\n"
			. self::canonical_query( $query ) . "\n"
			. $canonical_headers . "\n"
			. $signed_headers . "\n"
			. $payload_hash;

		$credential_scope = $date_stamp . '/' . $region . '/' . $service . '/aws4_request';
		$string_to_sign   = 'AWS4-HMAC-SHA256' . "\n"
			. $amz_date . "\n"
			. $credential_scope . "\n"
			. hash( 'sha256', $canonical_request );

		$signing_key = self::get_signing_key( $secret_key, $date_stamp, $region, $service );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$credential = $access_key . '/' . $credential_scope;
		$auth       = 'AWS4-HMAC-SHA256 Credential=' . $credential
			. ', SignedHeaders=' . $signed_headers
			. ', Signature=' . $signature;

		$headers = array(
			'Authorization'        => $auth,
			'X-Amz-Date'           => $amz_date,
			'X-Amz-Content-Sha256' => $payload_hash,
		);

		return array( $headers, $amz_date );
	}

	/**
	 * SES query API posts to path "/". Other paths are normalized to a single encoded segment list.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	private static function canonical_uri_for_ses( $path ) {
		$path = (string) $path;
		if ( '' === $path || '/' === $path ) {
			return '/';
		}
		$path = '/' . ltrim( $path, '/' );
		$segs = explode( '/', $path );
		$out  = '';
		foreach ( $segs as $i => $seg ) {
			if ( 0 === $i ) {
				continue;
			}
			$out .= '/' . rawurlencode( $seg );
		}
		return '' === $out ? '/' : $out;
	}

	/**
	 * @param string $query Query string without leading ?.
	 * @return string
	 */
	private static function canonical_query( $query ) {
		$query = (string) $query;
		if ( '' === $query ) {
			return '';
		}
		parse_str( $query, $pairs );
		if ( ! is_array( $pairs ) || empty( $pairs ) ) {
			return '';
		}
		ksort( $pairs );
		$parts = array();
		foreach ( $pairs as $k => $v ) {
			if ( is_array( $v ) ) {
				sort( $v );
				foreach ( $v as $vv ) {
					$parts[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $vv );
				}
			} else {
				$parts[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
			}
		}
		sort( $parts );
		return implode( '&', $parts );
	}

	/**
	 * @param string $secret_key Secret key.
	 * @param string $date_stamp Ymd in UTC.
	 * @param string $region     Region.
	 * @param string $service    Service name.
	 * @return string Binary signing key.
	 */
	private static function get_signing_key( $secret_key, $date_stamp, $region, $service ) {
		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		return $k_signing;
	}
}
