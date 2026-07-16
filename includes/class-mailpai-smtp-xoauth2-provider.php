<?php
/**
 * PHPMailer XOAUTH2 token provider (no League OAuth2 dependency).
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Xoauth2_Provider
 */
class Mailpai_Smtp_Xoauth2_Provider implements PHPMailer\PHPMailer\OAuthTokenProvider {

	/** @var string */
	private $email;

	/** @var string */
	private $oauth_key;

	/** @var string */
	private $connection_id = '';

	/** @var string */
	private $refresh_token;

	/** @var string */
	private $oauth_client_id = '';

	/** @var string */
	private $oauth_client_secret = '';

	/** @var string */
	private $access_token = '';

	/** @var int */
	private $expires_at = 0;

	/**
	 * @param array $config Resolved SMTP config.
	 */
	public function __construct( array $config ) {
		$this->email               = (string) ( $config['user'] ?? $config['from_email'] ?? '' );
		$this->oauth_key           = (string) ( $config['oauth_key'] ?? '' );
		$this->connection_id       = sanitize_key( (string) ( $config['connection_id'] ?? '' ) );
		$this->refresh_token       = (string) ( $config['oauth_refresh'] ?? '' );
		$this->oauth_client_id     = (string) ( $config['oauth_client_id'] ?? '' );
		$this->oauth_client_secret = (string) ( $config['oauth_client_secret'] ?? '' );
	}

	/**
	 * @return string
	 */
	public function getOauth64() {
		$this->ensure_access_token();

		return base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'user=' .
			$this->email .
			"\001auth=Bearer " .
			$this->access_token .
			"\001\001"
		);
	}

	/**
	 * Refresh access token when missing or expired.
	 */
	private function ensure_access_token() {
		if ( '' !== $this->access_token && time() < ( $this->expires_at - 60 ) ) {
			return;
		}

		$tokens = Mailpai_Smtp_Oauth::refresh_access_token(
			$this->oauth_key,
			$this->refresh_token,
			array(
				'client_id'     => (string) ( $this->oauth_client_id ?? '' ),
				'client_secret' => (string) ( $this->oauth_client_secret ?? '' ),
			)
		);
		if ( is_wp_error( $tokens ) ) {
			throw new Exception( $tokens->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$this->access_token = (string) ( $tokens['access_token'] ?? '' );
		$expires_in         = (int) ( $tokens['expires_in'] ?? 3600 );
		$this->expires_at   = time() + max( 60, $expires_in );

		$new_refresh = (string) ( $tokens['refresh_token'] ?? '' );
		if ( '' !== $new_refresh && $new_refresh !== $this->refresh_token ) {
			$this->refresh_token = $new_refresh;
			$this->persist_refresh_token( $new_refresh );
		}
	}

	/**
	 * @param string $refresh_token New refresh token from the provider.
	 * @return void
	 */
	private function persist_refresh_token( $refresh_token ) {
		if ( '' === $this->connection_id ) {
			return;
		}

		$stored = (string) $refresh_token;
		$enc    = Mailpai_Smtp_Crypto::encrypt( $stored );
		if ( $enc ) {
			$stored = $enc;
		}

		Mailpai_Smtp_Connection_Store::patch(
			$this->connection_id,
			array(
				'oauth_refresh_enc' => $stored,
			)
		);
	}
}
