<?php
/**
 * Unified outbound mail pipeline with backup failover.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Mailer
 */
class Mailpai_Smtp_Mailer {

	/** @var string */
	private static $pending_server_status = '';

	/** @var string */
	private static $last_api_server_status = '';

	/** @var string */
	private static $last_smtp_com_message_id = '';

	/** @var string[] */
	private static $smtp_server_lines = array();

	/**
	 * @param string $str   Debug line.
	 * @param int    $level Debug level.
	 */
	public static function collect_smtp_debug( $str, $level ) {
		unset( $level );
		if ( false !== strpos( (string) $str, 'SERVER -> CLIENT:' ) ) {
			$line = trim( preg_replace( '/^.*?SERVER -> CLIENT:\s*/s', '', (string) $str ) );
			if ( '' !== $line ) {
				self::$smtp_server_lines[] = $line;
			}
		}
	}

	/**
	 * @return string
	 */
	private static function take_server_status() {
		$status                        = self::$pending_server_status;
		self::$pending_server_status   = '';
		self::$smtp_server_lines       = array();
		return sanitize_textarea_field( (string) $status );
	}

	/**
	 * @return string
	 */
	public static function last_api_server_status() {
		return sanitize_textarea_field( (string) self::$last_api_server_status );
	}

	/**
	 * @return string SMTP.com message id from the last accepted send, if any.
	 */
	public static function last_smtp_com_message_id() {
		return sanitize_text_field( (string) self::$last_smtp_com_message_id );
	}

	/**
	 * @param string $status Server status text.
	 */
	private static function set_server_status( $status ) {
		$status                        = sanitize_textarea_field( (string) $status );
		self::$pending_server_status   = $status;
		self::$last_api_server_status  = $status;
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 * @return string
	 */
	private static function format_api_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return sanitize_textarea_field( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		$lines = array( 'HTTP ' . $code );

		if ( '' !== $body ) {
			$json = json_decode( $body, true );
			if ( is_array( $json ) ) {
				$encoded = wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				$lines[] = is_string( $encoded ) ? $encoded : mb_substr( $body, 0, 4000 );
			} else {
				$lines[] = mb_substr( $body, 0, 4000 );
			}
		}

		return sanitize_textarea_field( implode( "\n", $lines ) );
	}

	/**
	 * Send for a mail route with backup failover.
	 *
	 * @param string $route Route slug.
	 * @param array  $args  Message args.
	 * @return true|\WP_Error
	 */
	public static function send_for_route( $route, array $args ) {
		$route = sanitize_key( (string) $route );
		$conn  = Mailpai_Smtp_Routes::get_connection_id( $route );

		if ( '' === $conn ) {
			return new WP_Error( 'mailpai_smtp_no_route', __( 'No connection assigned for this mail route.', 'smtp-pai' ) );
		}

		$args['route'] = $route;
		return self::send_with_backup( $conn, $args );
	}

	/**
	 * @param string $connection_id Connection id.
	 * @param array  $args          Args.
	 * @return true|\WP_Error
	 */
	public static function send_via_connection( $connection_id, array $args ) {
		$args['route'] = isset( $args['route'] ) ? sanitize_key( (string) $args['route'] ) : '';
		return self::send_with_backup( sanitize_key( (string) $connection_id ), $args );
	}

	/**
	 * @param string $connection_id Primary connection.
	 * @param array  $args          Args.
	 * @return true|\WP_Error
	 */
	private static function send_with_backup( $connection_id, array $args ) {
		$backup_cfg = Mailpai_Smtp_Backup::get();
		$result     = self::attempt_send( $connection_id, $args, false );

		if ( ! is_wp_error( $result ) ) {
			return true;
		}

		if ( empty( $backup_cfg['enabled'] ) || ! Mailpai_Smtp_Backup::is_failover_eligible( $result ) ) {
			return $result;
		}

		$backup_id = Mailpai_Smtp_Backup::backup_for( $connection_id );
		if ( '' === $backup_id ) {
			return $result;
		}

		$failover_args = $args;
		$failover_args['primary_connection_id'] = $connection_id;
		$failover_args['failover']              = true;

		$backup_result = self::attempt_send( $backup_id, $failover_args, true );
		if ( is_wp_error( $backup_result ) ) {
			return $backup_result;
		}

		return true;
	}

	/**
	 * @param string $connection_id Connection id.
	 * @param array  $args          Args.
	 * @param bool   $is_failover   Failover attempt.
	 * @return true|\WP_Error
	 */
	private static function attempt_send( $connection_id, array $args, $is_failover ) {
		$rec = Mailpai_Smtp_Connection_Store::get( $connection_id );
		if ( null === $rec || empty( $rec['enabled'] ) ) {
			return new WP_Error( 'mailpai_smtp_connection', __( 'Connection is not available.', 'smtp-pai' ) );
		}

		$to      = isset( $args['to'] ) ? $args['to'] : '';
		$subject = isset( $args['subject'] ) ? (string) $args['subject'] : '';
		$message = isset( $args['message'] ) ? (string) $args['message'] : '';
		$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();

		$recipients = self::parse_recipients( $to );
		if ( empty( $recipients ) ) {
			return new WP_Error( 'mailpai_smtp_invalid_to', __( 'Invalid recipient email.', 'smtp-pai' ) );
		}

		$config = self::build_config( $rec );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		list( $config, $headers ) = self::apply_sender_settings( $config, $rec, $headers );
		$headers        = Mailpai_Smtp_Mail_Headers::sanitize( $headers );
		$inline_images  = self::normalize_inline_attachments( $args );

		$last_error = null;
		foreach ( $recipients as $recipient ) {
			$res = self::deliver( $config, $recipient, $subject, $message, $headers, $inline_images );
			if ( is_wp_error( $res ) ) {
				$last_error = $res;
				self::log_attempt( $rec, $args, $recipient, $subject, $message, $headers, 'failed', $res->get_error_message(), $is_failover, $config );
				continue;
			}
			self::log_attempt( $rec, $args, $recipient, $subject, $message, $headers, 'sent', '', $is_failover, $config );
		}

		if ( null !== $last_error && count( $recipients ) === 1 ) {
			if ( ! self::should_skip_connection_failure_status( $rec, $last_error ) ) {
				Mailpai_Smtp_Connection_Store::patch_meta(
					$connection_id,
					array(
						'last_status'  => 'failed',
						'last_error'   => $last_error->get_error_message(),
						'last_test_at' => time(),
					)
				);
			}
			return $last_error;
		}

		Mailpai_Smtp_Connection_Store::patch_meta(
			$connection_id,
			array(
				'last_status'  => 'working',
				'last_error'   => '',
				'last_sent_at' => time(),
				'last_test_at' => time(),
			)
		);

		return null !== $last_error ? $last_error : true;
	}

	/**
	 * @param array  $config  Resolved config.
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @param array  $headers       Headers.
	 * @param array  $inline_images Inline CID attachments.
	 * @return true|\WP_Error
	 */
	private static function deliver( array $config, $to, $subject, $body, array $headers, array $inline_images = array() ) {
		self::$pending_server_status = '';
		self::$smtp_server_lines     = array();

		$transport = $config['transport'] ?? 'smtp';
		if ( 'api' === $transport ) {
			return self::deliver_api( $config, $to, $subject, $body, $headers, $inline_images );
		}
		return self::deliver_smtp( $config, $to, $subject, $body, $headers, $inline_images );
	}

	/**
	 * @param array $args Send args (inline_attachments from MailPai).
	 * @return array<int,array{cid:string,path:string,mime?:string,filename?:string}>
	 */
	private static function normalize_inline_attachments( array $args ) {
		$raw = isset( $args['inline_attachments'] ) && is_array( $args['inline_attachments'] )
			? $args['inline_attachments']
			: array();
		$out = array();
		foreach ( $raw as $img ) {
			if ( ! is_array( $img ) || empty( $img['path'] ) || empty( $img['cid'] ) ) {
				continue;
			}
			if ( ! is_readable( (string) $img['path'] ) ) {
				continue;
			}
			$out[] = array(
				'cid'      => (string) $img['cid'],
				'path'     => (string) $img['path'],
				'mime'     => ! empty( $img['mime'] ) ? (string) $img['mime'] : '',
				'filename' => ! empty( $img['filename'] ) ? (string) $img['filename'] : basename( (string) $img['path'] ),
			);
		}
		return $out;
	}

	/**
	 * @param array $rec Connection record.
	 * @return array|\WP_Error
	 */
	public static function build_config( array $rec ) {
		$provider_slug = Mailpai_Smtp_Provider_Registry::normalize_slug( $rec['provider'] ?? '' );
		$provider      = Mailpai_Smtp_Provider_Registry::get( $provider_slug );
		if ( empty( $provider ) ) {
			return new WP_Error( 'mailpai_smtp_provider', __( 'Unknown provider.', 'smtp-pai' ) );
		}

		$from_email = sanitize_email( $rec['from_email'] ?? '' );
		if ( ! is_email( $from_email ) ) {
			return new WP_Error( 'mailpai_smtp_from', __( 'From email is missing or invalid.', 'smtp-pai' ) );
		}

		$base = array(
			'provider'   => $provider_slug,
			'transport'  => $provider['transport'],
			'from_email' => $from_email,
			'from_name'  => sanitize_text_field( $rec['from_name'] ?? get_bloginfo( 'name' ) ),
		);

		if ( 'api' === $provider['transport'] ) {
			if ( 'amazon_ses' === $provider_slug ) {
				$creds = self::resolve_ses_credentials( $rec );
				if ( is_wp_error( $creds ) ) {
					return $creds;
				}
				return array_merge( $base, $creds );
			}

			$key = self::resolve_secret( $rec, 'api_key_enc', 'MAILPAI_SMTP_API_KEY' );
			if ( is_wp_error( $key ) ) {
				return $key;
			}
			$cfg = array_merge(
				$base,
				array(
					'api_key' => $key,
				)
			);
			if ( 'mailgun' === $provider_slug ) {
				$cfg['api_domain']     = self::normalize_mailgun_region( $rec['meta']['api_domain'] ?? 'us' );
				$cfg['mailgun_domain'] = self::resolve_mailgun_sending_domain( $rec );
				$cfg['api_key']        = self::normalize_mailgun_api_key( $cfg['api_key'] );
			}
			if ( 'postmark' === $provider_slug ) {
				$cfg['postmark_message_stream'] = self::resolve_postmark_message_stream( $rec );
			}
			if ( 'sparkpost' === $provider_slug ) {
				$cfg['api_key']    = self::normalize_sparkpost_api_key( $cfg['api_key'] );
				$cfg['api_domain'] = self::normalize_mailgun_region( $rec['meta']['api_domain'] ?? 'us' );
			}
			if ( 'sendgrid' === $provider_slug ) {
				$cfg['api_key']    = self::normalize_sendgrid_api_key( $cfg['api_key'] );
				$cfg['api_domain'] = self::normalize_mailgun_region( $rec['meta']['api_domain'] ?? 'us' );
			}
			if ( 'zeptomail' === $provider_slug ) {
				$cfg['api_key']    = self::normalize_zeptomail_api_key( $cfg['api_key'] );
				$cfg['api_domain'] = self::normalize_zeptomail_region( $rec['meta']['api_domain'] ?? 'us' );
			}
			if ( 'smtp2go' === $provider_slug ) {
				$cfg['api_key'] = self::normalize_smtp2go_api_key( $cfg['api_key'] );
			}
			if ( 'smtp_com' === $provider_slug ) {
				$cfg['api_key']          = self::normalize_smtp_com_api_key( $cfg['api_key'] );
				$cfg['smtp_com_channel'] = self::resolve_smtp_com_channel( $rec );
			}
			if ( 'mailjet' === $provider_slug ) {
				$secret = self::resolve_secret( $rec, 'api_secret_enc', 'MAILPAI_SMTP_API_SECRET', true );
				if ( is_wp_error( $secret ) ) {
					return new WP_Error( 'mailpai_smtp_mailjet', __( 'Enter your Mailjet Secret key.', 'smtp-pai' ) );
				}
				if ( '' !== $secret ) {
					$cfg['api_secret'] = $secret;
				}
			}
			return $cfg;
		}

		$smtp = self::resolve_smtp_credentials( $rec, $provider );
		if ( is_wp_error( $smtp ) ) {
			return $smtp;
		}
		if ( 'google' === Mailpai_Smtp_Provider_Registry::normalize_slug( $rec['provider'] ?? '' ) && ! empty( $smtp['user'] ) && preg_match( '/@gmail\.com$/i', (string) $smtp['user'] ) ) {
			$base['from_email'] = sanitize_email( $smtp['user'] );
		} elseif (
			Mailpai_Smtp_Provider_Registry::uses_mailbox_smtp( $rec )
			&& ! empty( $smtp['user'] )
			&& preg_match( '/@gmail\.com$/i', (string) $smtp['user'] )
			&& 'smtp.gmail.com' === Mailpai_Smtp_Provider_Registry::normalize_smtp_host( $smtp['host'] ?? '' )
		) {
			$base['from_email'] = sanitize_email( $smtp['user'] );
		}
		return array_merge( $base, $smtp, array( 'transport' => 'smtp' ) );
	}

	/**
	 * Apply connection sender settings and optional wp_mail From header overrides.
	 *
	 * @param array $config  Delivery config.
	 * @param array $rec     Connection record.
	 * @param array $headers Message headers.
	 * @return array{0:array,1:array}
	 */
	private static function apply_sender_settings( array $config, array $rec, array $headers ) {
		$force_name  = ! empty( $rec['force_from_name'] );
		$force_email = ! isset( $rec['force_from_email'] ) || ! empty( $rec['force_from_email'] );
		$parsed      = self::parse_from_header( $headers );

		if ( ! $force_name && null !== $parsed && '' !== $parsed['name'] ) {
			$config['from_name'] = $parsed['name'];
		}

		if ( ! $force_email && null !== $parsed && is_email( $parsed['email'] ) ) {
			$config['from_email'] = sanitize_email( $parsed['email'] );
		}

		if ( $force_name || $force_email || null !== $parsed ) {
			$headers = self::strip_sender_headers( $headers );
		}

		return array( $config, $headers );
	}

	/**
	 * @param array $headers Message headers.
	 * @return array{name:string,email:string}|null
	 */
	private static function parse_from_header( array $headers ) {
		foreach ( $headers as $header ) {
			$header = trim( (string) $header );
			if ( '' === $header ) {
				continue;
			}

			if ( 0 !== stripos( $header, 'From:' ) ) {
				continue;
			}

			$value = trim( (string) preg_replace( '/^From:\s*/i', '', $header ) );
			if ( '' === $value ) {
				return null;
			}

			$name  = '';
			$email = sanitize_email( $value );
			if ( preg_match( '/^\s*(?:"([^"]+)"|\'([^\']+)\'|([^<]+?))\s*<\s*([^>]+)\s*>/u', $value, $matches ) ) {
				$name  = trim( (string) ( $matches[1] ?: $matches[2] ?: $matches[3] ) );
				$email = sanitize_email( $matches[4] );
			} elseif ( preg_match( '/<([^>]+)>/', $value, $matches ) ) {
				$email = sanitize_email( $matches[1] );
				$name  = trim( str_replace( $matches[0], '', $value ), " \t\"'" );
			}

			return array(
				'name'  => sanitize_text_field( $name ),
				'email' => $email,
			);
		}

		return null;
	}

	/**
	 * @param array $headers Message headers.
	 * @return array
	 */
	private static function strip_sender_headers( array $headers ) {
		$out = array();
		foreach ( $headers as $header ) {
			$header = trim( (string) $header );
			if ( '' === $header ) {
				continue;
			}
			if ( 0 === stripos( $header, 'From:' ) || 0 === stripos( $header, 'Sender:' ) || 0 === stripos( $header, 'Return-Path:' ) ) {
				continue;
			}
			$out[] = $header;
		}
		return $out;
	}

	/**
	 * @param array $rec Record.
	 * @return array|\WP_Error
	 */
	public static function resolve_ses_credentials( array $rec ) {
		if ( Mailpai_Smtp_Connection_Store::SECRETS_WP_CONFIG === Mailpai_Smtp_Connection_Store::secrets_storage( $rec ) ) {
			if ( ! defined( 'MAILPAI_SMTP_SES_ACCESS_KEY' ) || ! defined( 'MAILPAI_SMTP_SES_SECRET_KEY' ) ) {
				return new WP_Error( 'mailpai_smtp_ses', __( 'Define MAILPAI_SMTP_SES_ACCESS_KEY and MAILPAI_SMTP_SES_SECRET_KEY in wp-config.php.', 'smtp-pai' ) );
			}
			$region = defined( 'MAILPAI_SMTP_SES_REGION' ) ? (string) MAILPAI_SMTP_SES_REGION : ( $rec['aws_region'] ?? 'us-east-1' );
			return array(
				'transport'  => 'api',
				'api_driver' => 'amazon_ses',
				'region'     => Mailpai_Smtp_Ses_Api::is_region_allowed( $region ) ? $region : 'us-east-1',
				'access_key' => (string) MAILPAI_SMTP_SES_ACCESS_KEY,
				'secret'     => (string) MAILPAI_SMTP_SES_SECRET_KEY,
			);
		}

		$key = trim( (string) ( $rec['aws_access_key_id'] ?? '' ) );
		$sec = self::resolve_secret( $rec, 'aws_secret_enc', 'MAILPAI_SMTP_SES_SECRET_KEY', true );
		if ( is_wp_error( $sec ) ) {
			return $sec;
		}
		if ( '' === $key ) {
			return new WP_Error( 'mailpai_smtp_ses', __( 'Amazon SES credentials are incomplete.', 'smtp-pai' ) );
		}
		$region = $rec['aws_region'] ?? 'us-east-1';
		return array(
			'transport'  => 'api',
			'api_driver' => 'amazon_ses',
			'region'     => Mailpai_Smtp_Ses_Api::is_region_allowed( $region ) ? $region : 'us-east-1',
			'access_key' => $key,
			'secret'     => $sec,
		);
	}

	/**
	 * @param array $rec      Record.
	 * @param array $provider Provider def.
	 * @return array|\WP_Error
	 */
	private static function resolve_smtp_credentials( array $rec, array $provider ) {
		if ( Mailpai_Smtp_Connection_Store::SECRETS_WP_CONFIG === Mailpai_Smtp_Connection_Store::secrets_storage( $rec ) ) {
			if ( ! defined( 'MAILPAI_SMTP_HOST' ) || '' === trim( (string) MAILPAI_SMTP_HOST ) ) {
				return new WP_Error(
					'mailpai_smtp_smtp',
					__( 'Define MAILPAI_SMTP_HOST in wp-config.php, or switch to “Store keys in database”.', 'smtp-pai' )
				);
			}
			$enc  = defined( 'MAILPAI_SMTP_ENCRYPTION' ) ? (string) MAILPAI_SMTP_ENCRYPTION : 'tls';
			$user = defined( 'MAILPAI_SMTP_USER' ) ? trim( (string) MAILPAI_SMTP_USER ) : '';
			$pass = defined( 'MAILPAI_SMTP_PASSWORD' ) ? (string) MAILPAI_SMTP_PASSWORD : '';
			if ( '' !== $user && '' === $pass ) {
				return new WP_Error(
					'mailpai_smtp_secret',
					__( 'Define MAILPAI_SMTP_PASSWORD in wp-config.php, or switch to “Store keys in database” and enter your password there.', 'smtp-pai' )
				);
			}
			$port = defined( 'MAILPAI_SMTP_PORT' ) ? absint( MAILPAI_SMTP_PORT ) : 587;
			return array(
				'host'       => trim( (string) MAILPAI_SMTP_HOST ),
				'port'       => $port > 0 ? $port : 587,
				'encryption' => self::normalize_encryption_for_port(
					$port > 0 ? $port : 587,
					in_array( $enc, array( 'tls', 'ssl', '' ), true ) ? $enc : 'tls'
				),
				'user'       => $user,
				'pass'       => $pass,
			);
		}

		$host = ! empty( $rec['host'] ) ? (string) $rec['host'] : (string) ( $provider['host'] ?? '' );
		$port = ! empty( $rec['port'] ) ? absint( $rec['port'] ) : absint( $provider['port'] ?? 587 );
		$enc  = ! empty( $rec['disable_encryption'] ) ? '' : ( ! empty( $rec['encryption'] ) ? (string) $rec['encryption'] : (string) ( $provider['encryption'] ?? 'tls' ) );
		$user = trim( (string) ( $rec['user'] ?? '' ) );

		if ( Mailpai_Smtp_Connection_Store::uses_oauth( $rec ) ) {
			$refresh = self::resolve_secret( $rec, 'oauth_refresh_enc', 'MAILPAI_SMTP_OAUTH_REFRESH', true );
			if ( is_wp_error( $refresh ) ) {
				return $refresh;
			}
			$refresh = trim( (string) $refresh );
			if ( '' === $user && is_email( $rec['from_email'] ?? '' ) ) {
				$user = sanitize_email( $rec['from_email'] );
			}
			if ( '' === $user || '' === $refresh ) {
				return new WP_Error(
					'mailpai_smtp_oauth',
					__( 'OAuth authorization is missing or expired. Save the connection again to sign in.', 'smtp-pai' )
				);
			}
			if ( '' === $host ) {
				return new WP_Error( 'mailpai_smtp_smtp', __( 'SMTP host is required.', 'smtp-pai' ) );
			}
			if ( 'microsoft' === Mailpai_Smtp_Provider_Registry::normalize_slug( $rec['provider'] ?? '' ) ) {
				$consumer_host = Mailpai_Smtp_Oauth::microsoft_consumer_smtp_host( $user );
				if ( '' !== $consumer_host ) {
					$host = $consumer_host;
				}
			}
			$enc = self::normalize_encryption_for_port( $port, $enc );
			$oauth_key = Mailpai_Smtp_Oauth::oauth_key_for_slug( $rec['provider'] ?? '' );
			$oauth_client = Mailpai_Smtp_Oauth::client( $oauth_key, $rec );
			return array(
				'host'          => $host,
				'port'          => $port,
				'encryption'    => $enc,
				'user'          => $user,
				'auth_type'     => 'oauth',
				'oauth_key'     => $oauth_key,
				'oauth_refresh' => $refresh,
				'oauth_client_id'     => (string) ( $oauth_client['client_id'] ?? '' ),
				'oauth_client_secret' => (string) ( $oauth_client['client_secret'] ?? '' ),
				'connection_id'       => sanitize_key( (string) ( $rec['id'] ?? '' ) ),
			);
		}

		$pass = self::resolve_secret( $rec, 'secret_enc', 'MAILPAI_SMTP_PASSWORD', false );
		if ( is_wp_error( $pass ) ) {
			return $pass;
		}
		$pass = trim( (string) $pass );

		if ( Mailpai_Smtp_Provider_Registry::uses_mailbox_smtp( $rec ) ) {
			if ( Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $rec['provider'] ?? '' ) ) {
				if ( Mailpai_Smtp_Oauth::is_configured( $rec['provider'] ?? '', $rec ) ) {
					return new WP_Error(
						'mailpai_smtp_oauth',
						sprintf(
							/* translators: 1: provider name, 2: connect button label */
							__( 'Sign in with %1$s before sending. Edit the connection and click %2$s.', 'smtp-pai' ),
							Mailpai_Smtp_Oauth::provider_display_name( $rec['provider'] ?? '' ),
							Mailpai_Smtp_Oauth::button_label( $rec['provider'] ?? '' )
						)
					);
				}

				return new WP_Error(
					'mailpai_smtp_oauth',
					__( 'Enter an Application Client ID and Application Client Secret, then save the connection.', 'smtp-pai' )
				);
			}
			if ( '' === $user && is_email( $rec['from_email'] ?? '' ) ) {
				$user = sanitize_email( $rec['from_email'] );
			}
			$enc = ! empty( $rec['encryption'] ) ? (string) $rec['encryption'] : (string) ( $provider['encryption'] ?? 'tls' );
		}

		if ( Mailpai_Smtp_Provider_Registry::uses_mailbox_smtp( $rec ) ) {
			if ( '' === $user || '' === $pass ) {
				return new WP_Error(
					'mailpai_smtp_secret',
					__( 'Enter your email address and password, then save.', 'smtp-pai' )
				);
			}
		} elseif ( '' !== $user && '' === $pass ) {
			return new WP_Error(
				'mailpai_smtp_secret',
				__( 'SMTP password is missing or could not be decrypted. Re-enter your password and save.', 'smtp-pai' )
			);
		}

		if ( '' === $host ) {
			return new WP_Error( 'mailpai_smtp_smtp', __( 'SMTP host is required.', 'smtp-pai' ) );
		}

		$enc = self::normalize_encryption_for_port( $port, $enc );

		return array(
			'host'       => $host,
			'port'       => $port,
			'encryption' => $enc,
			'user'       => $user,
			'pass'       => $pass,
		);
	}

	/**
	 * Skip marking a connection as failed when the error is expected setup state.
	 *
	 * @param array     $rec   Connection record.
	 * @param \WP_Error $error Delivery error.
	 * @return bool
	 */
	private static function should_skip_connection_failure_status( array $rec, WP_Error $error ) {
		if ( 'mailpai_smtp_oauth' !== $error->get_error_code() ) {
			return false;
		}

		return Mailpai_Smtp_Connection_Store::needs_oauth_signin( $rec );
	}

	/**
	 * @param array  $rec        Record.
	 * @param string $field      Encrypted field.
	 * @param string $constant   wp-config constant.
	 * @param bool   $required   Required.
	 * @return string|\WP_Error
	 */
	private static function resolve_secret( array $rec, $field, $constant, $required = true ) {
		if ( Mailpai_Smtp_Connection_Store::SECRETS_WP_CONFIG === Mailpai_Smtp_Connection_Store::secrets_storage( $rec ) ) {
			if ( defined( $constant ) ) {
				return trim( (string) constant( $constant ) );
			}
			return new WP_Error( 'mailpai_smtp_secret', sprintf(
				/* translators: %s: constant name */
				__( 'Define %s in wp-config.php.', 'smtp-pai' ),
				$constant
			) );
		}

		$stored = (string) ( $rec[ $field ] ?? '' );
		if ( '' === $stored ) {
			return $required ? new WP_Error( 'mailpai_smtp_secret', __( 'API key is missing.', 'smtp-pai' ) ) : '';
		}

		$plain = Mailpai_Smtp_Crypto::decrypt( $stored );
		if ( false !== $plain && '' !== $plain ) {
			return trim( (string) $plain );
		}

		if ( '' !== $stored ) {
			return trim( $stored );
		}

		return $required ? new WP_Error( 'mailpai_smtp_secret', __( 'API key is missing.', 'smtp-pai' ) ) : '';
	}

	/**
	 * @param array  $config  Config.
	 * @param string $to      To.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @param array  $headers       Headers.
	 * @param array  $inline_images Inline CID attachments.
	 * @return true|\WP_Error
	 */
	private static function deliver_api( array $config, $to, $subject, $body, array $headers, array $inline_images = array() ) {
		if ( 'amazon_ses' === ( $config['api_driver'] ?? '' ) || 'amazon_ses' === ( $config['provider'] ?? '' ) ) {
			$mime = Mailpai_Smtp_Ses_Api::build_raw_mime( $config['from_name'], $config['from_email'], $to, $subject, $body, $headers, $inline_images );
			$res  = Mailpai_Smtp_Ses_Api::send_raw_email(
				$config['region'],
				$config['access_key'],
				$config['secret'],
				$config['from_email'],
				$to,
				$mime
			);
			if ( is_wp_error( $res ) ) {
				self::set_server_status( $res->get_error_message() );
				return $res;
			}
			self::set_server_status( Mailpai_Smtp_Ses_Api::format_success_status( $res ) );
			return true;
		}

		$provider = Mailpai_Smtp_Provider_Registry::normalize_slug( $config['provider'] ?? '' );

		switch ( $provider ) {
			case 'brevo':
				$result = self::http_json(
					'https://api.brevo.com/v3/smtp/email',
					array( 'api-key' => $config['api_key'] ),
					array(
						'sender'      => array( 'email' => $config['from_email'], 'name' => $config['from_name'] ),
						'to'          => array( array( 'email' => $to ) ),
						'subject'     => $subject,
						'htmlContent' => $body,
					)
				);
				if ( is_wp_error( $result ) ) {
					return self::enrich_brevo_error( $result );
				}
				return $result;

			case 'resend':
				return self::http_json(
					'https://api.resend.com/emails',
					array( 'Authorization' => 'Bearer ' . $config['api_key'] ),
					array(
						'from'    => $config['from_name'] . ' <' . $config['from_email'] . '>',
						'to'      => array( $to ),
						'subject' => $subject,
						'html'    => $body,
					)
				);

			case 'sendgrid':
				return self::send_sendgrid_email( $config, $to, $subject, $body );

			case 'postmark':
				$from_email = sanitize_email( $config['from_email'] ?? '' );
				if ( ! is_email( $from_email ) ) {
					return new WP_Error(
						'mailpai_smtp_postmark',
						__( 'From email is missing or invalid.', 'smtp-pai' )
					);
				}
				$postmark_body = array(
					'From'          => self::format_mailgun_from( $config['from_name'] ?? '', $from_email ),
					'To'            => $to,
					'Subject'       => $subject,
					'HtmlBody'      => $body,
					'MessageStream' => self::resolve_postmark_message_stream_from_config( $config ),
				);
				$result = self::http_json(
					'https://api.postmarkapp.com/email',
					array(
						'X-Postmark-Server-Token' => trim( (string) ( $config['api_key'] ?? '' ) ),
						'Accept'                  => 'application/json',
					),
					$postmark_body
				);
				if ( is_wp_error( $result ) ) {
					return self::enrich_postmark_error( $result );
				}
				return $result;

			case 'mailgun':
				$api_host  = ( 'eu' === self::normalize_mailgun_region( $config['api_domain'] ?? 'us' ) ) ? 'api.eu.mailgun.net' : 'api.mailgun.net';
				$mg_domain = ! empty( $config['mailgun_domain'] ) ? self::normalize_mailgun_domain( (string) $config['mailgun_domain'] ) : self::mailgun_domain_from_email( $config['from_email'] ?? '' );
				if ( '' === $mg_domain ) {
					return new WP_Error(
						'mailpai_smtp_mailgun',
						__( 'Enter your Mailgun sending domain.', 'smtp-pai' )
					);
				}
				$from_email = sanitize_email( $config['from_email'] ?? '' );
				if ( ! is_email( $from_email ) ) {
					return new WP_Error(
						'mailpai_smtp_mailgun',
						__( 'From email is missing or invalid.', 'smtp-pai' )
					);
				}
				if ( ! self::mailgun_from_authorized_for_domain( $from_email, $mg_domain ) ) {
					return new WP_Error(
						'mailpai_smtp_mailgun',
						sprintf(
							/* translators: 1: from email, 2: Mailgun sending domain */
							__( 'From email %1$s is not on Mailgun domain %2$s. Use an address like name@%2$s, or set Domain name to the domain in your From email.', 'smtp-pai' ),
							$from_email,
							$mg_domain
						)
					);
				}
				$api_key = self::normalize_mailgun_api_key( $config['api_key'] ?? '' );
				if ( '' === $api_key ) {
					return new WP_Error(
						'mailpai_smtp_mailgun',
						__( 'Enter a Mailgun API key.', 'smtp-pai' )
					);
				}
				$url = 'https://' . $api_host . '/v3/' . $mg_domain . '/messages';
				$response = wp_remote_post(
					$url,
					array(
						'timeout'     => 20,
						'httpversion' => '1.1',
						'headers'     => array(
							'Authorization' => 'Basic ' . base64_encode( 'api:' . $api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						),
						'body'        => array(
							'from'    => self::format_mailgun_from( $config['from_name'] ?? '', $from_email ),
							'to'      => $to,
							'subject' => $subject,
							'html'    => $body,
						),
					)
				);
				return self::parse_mailgun_response( $response, $config );

			case 'mailersend':
				return self::http_json(
					'https://api.mailersend.com/v1/email',
					array( 'Authorization' => 'Bearer ' . $config['api_key'] ),
					array(
						'from'    => array( 'email' => $config['from_email'], 'name' => $config['from_name'] ),
						'to'      => array( array( 'email' => $to ) ),
						'subject' => $subject,
						'html'    => $body,
					)
				);

			case 'mailjet':
				$api_secret = trim( (string) ( $config['api_secret'] ?? '' ) );
				if ( '' === $api_secret ) {
					return new WP_Error(
						'mailpai_smtp_mailjet',
						__( 'Enter your Mailjet Secret key.', 'smtp-pai' )
					);
				}
				return self::http_json(
					'https://api.mailjet.com/v3.1/send',
					array(
						'Authorization' => 'Basic ' . base64_encode( $config['api_key'] . ':' . $api_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					),
					array(
						'Messages' => array(
							array(
								'From'     => array( 'Email' => $config['from_email'], 'Name' => $config['from_name'] ),
								'To'       => array( array( 'Email' => $to ) ),
								'Subject'  => $subject,
								'HTMLPart' => $body,
							),
						),
					)
				);

			case 'elastic_email':
				$from_email = sanitize_email( $config['from_email'] ?? '' );
				if ( ! is_email( $from_email ) ) {
					return new WP_Error(
						'mailpai_smtp_elastic_email',
						__( 'From email is missing or invalid.', 'smtp-pai' )
					);
				}
				$from_header = self::format_mailgun_from( $config['from_name'] ?? '', $from_email );
				$plain_body  = trim( wp_strip_all_tags( $body ) );
				if ( '' === $plain_body ) {
					$plain_body = $subject;
				}
				$response = wp_remote_post(
					'https://api.elasticemail.com/v4/emails/transactional',
					array(
						'timeout' => 20,
						'headers' => array(
							'Content-Type'          => 'application/json',
							'X-ElasticEmail-ApiKey' => $config['api_key'],
						),
						'body'    => wp_json_encode(
							array(
								'Recipients' => array(
									'To' => array( $to ),
								),
								'Content'    => array(
									'From'         => $from_header,
									'EnvelopeFrom' => $from_header,
									'ReplyTo'      => $from_header,
									'Subject'      => $subject,
									'Body'         => array(
										array(
											'ContentType' => 'HTML',
											'Charset'     => 'utf-8',
											'Content'     => $body,
										),
										array(
											'ContentType' => 'PlainText',
											'Charset'     => 'utf-8',
											'Content'     => $plain_body,
										),
									),
								),
							)
						),
					)
				);
				$result = self::parse_elastic_email_response( $response );
				if ( is_wp_error( $result ) ) {
					return self::enrich_elastic_email_error( $result );
				}
				return $result;

			case 'mandrill':
				return self::http_json(
					'https://mandrillapp.com/api/1.0/messages/send.json',
					array(),
					array(
						'key'     => $config['api_key'],
						'message' => array(
							'from_email' => $config['from_email'],
							'from_name'  => $config['from_name'],
							'to'         => array( array( 'email' => $to, 'type' => 'to' ) ),
							'subject'    => $subject,
							'html'       => $body,
						),
					)
				);

			case 'sparkpost':
				return self::send_sparkpost_email( $config, $to, $subject, $body );

			case 'zeptomail':
				return self::send_zeptomail_email( $config, $to, $subject, $body );

			case 'smtp2go':
				return self::send_smtp2go_email( $config, $to, $subject, $body );

			case 'smtp_com':
				return self::send_smtp_com_email( $config, $to, $subject, $body );

			default:
				return new WP_Error( 'mailpai_smtp_api', __( 'API provider is not supported yet.', 'smtp-pai' ) );
		}
	}

	/**
	 * @param string $stream Raw message stream id.
	 * @return string
	 */
	public static function normalize_postmark_message_stream( $stream ) {
		$stream = strtolower( trim( (string) $stream ) );
		$stream = preg_replace( '/[^a-z0-9\-_]/', '', $stream );
		return sanitize_key( $stream );
	}

	/**
	 * @param array $rec Connection record.
	 * @return string
	 */
	public static function resolve_postmark_message_stream( array $rec ) {
		$stream = isset( $rec['meta']['postmark_message_stream'] )
			? self::normalize_postmark_message_stream( $rec['meta']['postmark_message_stream'] )
			: '';
		return '' !== $stream ? $stream : 'outbound';
	}

	private static function enrich_postmark_error( WP_Error $error ) {
		$msg = $error->get_error_message();
		if ( false !== stripos( $msg, 'pending approval' ) || false !== stripos( $msg, 'same domain' ) ) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'While Postmark approves your account, send tests to an address on the same domain as your From email (e.g. support@mailpai.com), or change the WordPress admin email under Settings → General.', 'smtp-pai' )
			);
		}
		if ( false !== stripos( $msg, 'invalid' ) && false !== stripos( $msg, 'token' ) ) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Use the Server API token from Postmark → your server → API Tokens (not the account token).', 'smtp-pai' )
			);
		}
		if ( false !== stripos( $msg, 'sender signature' ) || false !== stripos( $msg, 'not a Sender Signature' ) ) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Verify your From email as a sender signature in Postmark → Sender Signatures.', 'smtp-pai' )
			);
		}
		return $error;
	}

	/**
	 * @param \WP_Error $error Parsed API error.
	 * @return \WP_Error
	 */
	private static function enrich_brevo_error( WP_Error $error ) {
		$msg = $error->get_error_message();
		if (
			false !== stripos( $msg, 'unrecognised ip' )
			|| false !== stripos( $msg, 'unrecognized ip' )
			|| false !== stripos( $msg, 'authorised_ips' )
			|| false !== stripos( $msg, 'authorized_ips' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . sprintf(
					/* translators: %s: Brevo authorized IPs settings URL */
					__( 'In Brevo → Security → Authorized IPs, add this server IP or turn off IP restriction for API calls. %s', 'smtp-pai' ),
					'https://app.brevo.com/security/authorised_ips'
				)
			);
		}
		if ( false !== stripos( $msg, 'sender' ) && ( false !== stripos( $msg, 'not valid' ) || false !== stripos( $msg, 'not verified' ) || false !== stripos( $msg, 'inactive' ) ) ) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Verify your From email or domain under Brevo → Senders, Domains & Dedicated IPs.', 'smtp-pai' )
			);
		}
		if ( false !== stripos( $msg, 'invalid api' ) || false !== stripos( $msg, 'key not found' ) ) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Use an API v3 key from Brevo → SMTP & API → API keys (not the SMTP password).', 'smtp-pai' )
			);
		}
		return $error;
	}

	/**
	 * @param \WP_Error $error Parsed API error.
	 * @return \WP_Error
	 */
	private static function enrich_elastic_email_error( WP_Error $error ) {
		$msg = $error->get_error_message();
		if ( false !== stripos( $msg, 'apikey' ) || false !== stripos( $msg, 'api key' ) || false !== stripos( $msg, 'unauthorized' ) ) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Use an API key from Elastic Email → Settings → API with Send HTTP permission.', 'smtp-pai' )
			);
		}
		if (
			false !== stripos( $msg, 'from' )
			|| false !== stripos( $msg, 'sender' )
			|| false !== stripos( $msg, 'domain' )
			|| false !== stripos( $msg, 'verify' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Verify your sending domain in Elastic Email → Settings → Domains and use a From address on that domain.', 'smtp-pai' )
			);
		}
		if ( false !== stripos( $msg, 'notdelivered' ) || false !== stripos( $msg, 'unsubscribed' ) || false !== stripos( $msg, 'suppression' ) ) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Elastic Email may block delivery if the recipient is unsubscribed or on your suppression list. Check Activity in the Elastic Email dashboard.', 'smtp-pai' )
			);
		}
		return $error;
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 * @return true|\WP_Error
	 */
	private static function parse_elastic_email_response( $response ) {
		self::set_server_status( self::format_api_response( $response ) );

		if ( is_wp_error( $response ) ) {
			return self::humanize_http_transport_error( $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( (string) $body, true );

		if ( is_array( $json ) ) {
			foreach ( array( 'Error', 'error' ) as $key ) {
				if ( ! empty( $json[ $key ] ) && is_string( $json[ $key ] ) ) {
					return new WP_Error( 'mailpai_smtp_api', (string) $json[ $key ] );
				}
			}
		}

		if ( $code >= 200 && $code < 300 ) {
			if ( is_array( $json ) && empty( $json['MessageID'] ) && empty( $json['TransactionID'] ) && '' !== trim( (string) $body ) ) {
				return new WP_Error(
					'mailpai_smtp_api',
					self::extract_api_error_message( $body, $code )
				);
			}
			return true;
		}

		return new WP_Error( 'mailpai_smtp_api', self::extract_api_error_message( $body, $code ) );
	}

	/**
	 * @param array  $config  Delivery config.
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    HTML body.
	 * @return true|\WP_Error
	 */
	private static function send_sparkpost_email( array $config, $to, $subject, $body ) {
		$api_key = self::normalize_sparkpost_api_key( $config['api_key'] ?? '' );
		if ( '' === $api_key ) {
			return new WP_Error( 'mailpai_smtp_sparkpost', __( 'Enter a SparkPost API key.', 'smtp-pai' ) );
		}

		$from_email = sanitize_email( $config['from_email'] ?? '' );
		if ( ! is_email( $from_email ) ) {
			return new WP_Error( 'mailpai_smtp_sparkpost', __( 'From email is missing or invalid.', 'smtp-pai' ) );
		}

		$from_name  = sanitize_text_field( (string) ( $config['from_name'] ?? '' ) );
		$from_field = '' !== $from_name
			? array(
				'email' => $from_email,
				'name'  => $from_name,
			)
			: $from_email;

		$plain_body = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) );
		if ( '' === $plain_body ) {
			$plain_body = $subject;
		}

		$response = wp_remote_post(
			self::sparkpost_api_base( $config ) . '/transmissions',
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => $api_key,
				),
				'body'    => wp_json_encode(
					array(
						'options'    => array( 'transactional' => true ),
						'content'    => array(
							'from'    => $from_field,
							'subject' => $subject,
							'html'    => $body,
							'text'    => $plain_body,
						),
						'recipients' => array(
							array(
								'address' => array(
									'email' => $to,
								),
							),
						),
					)
				),
			)
		);

		$result = self::parse_sparkpost_response( $response );
		if ( is_wp_error( $result ) ) {
			return self::enrich_sparkpost_error( $result );
		}
		return $result;
	}

	/**
	 * @param array  $config  Delivery config.
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    HTML body.
	 * @return true|\WP_Error
	 */
	private static function send_sendgrid_email( array $config, $to, $subject, $body ) {
		$api_key = self::normalize_sendgrid_api_key( $config['api_key'] ?? '' );
		if ( '' === $api_key ) {
			return new WP_Error( 'mailpai_smtp_sendgrid', __( 'Enter a SendGrid API key.', 'smtp-pai' ) );
		}

		$from_email = sanitize_email( $config['from_email'] ?? '' );
		if ( ! is_email( $from_email ) ) {
			return new WP_Error( 'mailpai_smtp_sendgrid', __( 'From email is missing or invalid.', 'smtp-pai' ) );
		}

		$from_name = sanitize_text_field( (string) ( $config['from_name'] ?? '' ) );
		$from      = array( 'email' => $from_email );
		if ( '' !== $from_name ) {
			$from['name'] = $from_name;
		}

		$plain_body = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) );
		if ( '' === $plain_body ) {
			$plain_body = $subject;
		}

		$payload = array(
			'personalizations' => array(
				array(
					'to' => array(
						array( 'email' => $to ),
					),
				),
			),
			'from'    => $from,
			'subject' => $subject,
			'content' => array(
				array(
					'type'  => 'text/plain',
					'value' => $plain_body,
				),
				array(
					'type'  => 'text/html',
					'value' => $body,
				),
			),
		);

		$result = self::http_json(
			self::sendgrid_api_base( $config ) . '/v3/mail/send',
			array(
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => 'application/json',
			),
			$payload
		);
		if ( is_wp_error( $result ) ) {
			return self::enrich_sendgrid_error( $result );
		}
		return $result;
	}

	/**
	 * @param string $api_key Raw API key.
	 * @return string
	 */
	public static function normalize_sendgrid_api_key( $api_key ) {
		$api_key = trim( (string) $api_key );
		if ( 0 === stripos( $api_key, 'bearer ' ) ) {
			$api_key = trim( substr( $api_key, 7 ) );
		}
		return $api_key;
	}

	/**
	 * @param array $config Delivery config.
	 * @return string
	 */
	private static function sendgrid_api_base( array $config ) {
		if ( 'eu' === self::normalize_mailgun_region( $config['api_domain'] ?? 'us' ) ) {
			return 'https://api.eu.sendgrid.com';
		}
		return 'https://api.sendgrid.com';
	}

	/**
	 * @param \WP_Error $error Parsed API error.
	 * @return \WP_Error
	 */
	private static function enrich_sendgrid_error( WP_Error $error ) {
		$msg = $error->get_error_message();
		if (
			false !== stripos( $msg, 'unauthorized' )
			|| false !== stripos( $msg, '401' )
			|| false !== stripos( $msg, 'invalid api key' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Use a SendGrid API key from Settings → API Keys with Mail Send permission. EU accounts must select EU in Region.', 'smtp-pai' )
			);
		}
		if (
			false !== stripos( $msg, 'forbidden' )
			|| false !== stripos( $msg, '403' )
			|| false !== stripos( $msg, 'access forbidden' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'The API key may lack Mail Send permission. Edit the key in SendGrid and enable Mail Send.', 'smtp-pai' )
			);
		}
		if (
			false !== stripos( $msg, 'verified' )
			|| false !== stripos( $msg, 'sender' )
			|| false !== stripos( $msg, 'from address' )
			|| false !== stripos( $msg, 'domain' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Verify your sender identity or domain in SendGrid → Settings → Sender Authentication, then use a From address on that domain.', 'smtp-pai' )
			);
		}
		return $error;
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 * @return true|\WP_Error
	 */
	private static function parse_sparkpost_response( $response ) {
		self::set_server_status( self::format_api_response( $response ) );

		if ( is_wp_error( $response ) ) {
			return self::humanize_http_transport_error( $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( (string) $body, true );

		if ( ! is_array( $json ) ) {
			if ( $code >= 200 && $code < 300 ) {
				return true;
			}
			return new WP_Error( 'mailpai_smtp_api', self::extract_api_error_message( $body, $code ) );
		}

		$messages = self::sparkpost_response_messages( $json );
		$results  = isset( $json['results'] ) && is_array( $json['results'] ) ? $json['results'] : array();
		$accepted = (int) ( $results['total_accepted_recipients'] ?? 0 );
		$rejected = (int) ( $results['total_rejected_recipients'] ?? 0 );

		if ( $code < 200 || $code >= 300 ) {
			$msg = ! empty( $messages ) ? implode( ' ', array_unique( $messages ) ) : self::extract_api_error_message( $body, $code );
			return new WP_Error( 'mailpai_smtp_api', $msg );
		}

		if ( $rejected > 0 || 0 === $accepted ) {
			$msg = ! empty( $messages )
				? implode( ' ', array_unique( $messages ) )
				: __( 'SparkPost rejected the recipient.', 'smtp-pai' );
			return new WP_Error( 'mailpai_smtp_api', $msg );
		}

		if ( ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) {
			foreach ( $json['errors'] as $err ) {
				if ( ! is_array( $err ) ) {
					continue;
				}
				$err_code = isset( $err['code'] ) ? (string) $err['code'] : '';
				if ( '' !== $err_code && '2000' !== $err_code ) {
					$msg = ! empty( $messages ) ? implode( ' ', array_unique( $messages ) ) : self::extract_api_error_message( $body, $code );
					return new WP_Error( 'mailpai_smtp_api', $msg );
				}
			}
		}

		return true;
	}

	/**
	 * @param array $json Decoded SparkPost response.
	 * @return string[]
	 */
	private static function sparkpost_response_messages( array $json ) {
		$messages = array();

		if ( ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) {
			foreach ( $json['errors'] as $err ) {
				if ( ! is_array( $err ) ) {
					continue;
				}
				$part = '';
				if ( ! empty( $err['message'] ) && is_string( $err['message'] ) ) {
					$part = (string) $err['message'];
				}
				if ( ! empty( $err['description'] ) && is_string( $err['description'] ) ) {
					$part = '' !== $part ? $part . ' — ' . $err['description'] : (string) $err['description'];
				}
				if ( '' !== $part ) {
					$messages[] = $part;
				}
			}
		}

		$results = isset( $json['results'] ) && is_array( $json['results'] ) ? $json['results'] : array();
		if ( ! empty( $results['rcpt_to_errors'] ) && is_array( $results['rcpt_to_errors'] ) ) {
			foreach ( $results['rcpt_to_errors'] as $err ) {
				if ( ! is_array( $err ) ) {
					continue;
				}
				if ( ! empty( $err['description'] ) && is_string( $err['description'] ) ) {
					$messages[] = (string) $err['description'];
				} elseif ( ! empty( $err['message'] ) && is_string( $err['message'] ) ) {
					$messages[] = (string) $err['message'];
				}
			}
		}

		return $messages;
	}

	/**
	 * @param string $api_key Raw API key.
	 * @return string
	 */
	public static function normalize_sparkpost_api_key( $api_key ) {
		$api_key = trim( (string) $api_key );
		if ( 0 === stripos( $api_key, 'bearer ' ) ) {
			$api_key = trim( substr( $api_key, 7 ) );
		}
		return $api_key;
	}

	/**
	 * @param array $config Delivery config.
	 * @return string
	 */
	private static function sparkpost_api_base( array $config ) {
		$region = isset( $config['api_domain'] ) ? sanitize_key( (string) $config['api_domain'] ) : '';
		if ( 'eu' === $region ) {
			return 'https://api.eu.sparkpost.com/api/v1';
		}
		return 'https://api.sparkpost.com/api/v1';
	}

	/**
	 * @param \WP_Error $error Parsed API error.
	 * @return \WP_Error
	 */
	private static function enrich_sparkpost_error( WP_Error $error ) {
		$msg = $error->get_error_message();
		if ( false !== stripos( $msg, 'unauthorized' ) || false !== stripos( $msg, '401' ) ) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Use a SparkPost API key from Account → API Keys (not a Bird key). EU accounts must select EU in Region.', 'smtp-pai' )
			);
		}
		if (
			false !== stripos( $msg, 'unverified' )
			|| false !== stripos( $msg, 'unconfigured' )
			|| false !== stripos( $msg, 'invalid domain' )
			|| false !== stripos( $msg, '7001' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Add and verify your sending domain in SparkPost → Sending → Sending Domains, then use a From address on that domain.', 'smtp-pai' )
			);
		}
		if (
			false !== stripos( $msg, 'suppressed' )
			|| false !== stripos( $msg, 'suppression' )
			|| false !== stripos( $msg, '1902' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'The recipient may be on SparkPost’s suppression list. Check Suppression List in your SparkPost dashboard.', 'smtp-pai' )
			);
		}
		if ( false !== stripos( $msg, 'exceed' ) || false !== stripos( $msg, '420' ) || false !== stripos( $msg, 'sandbox' ) ) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'You may have hit a SparkPost sandbox or plan sending limit. Verify a sending domain or upgrade your plan.', 'smtp-pai' )
			);
		}
		if ( false !== stripos( $msg, 'sender' ) || false !== stripos( $msg, 'domain' ) || false !== stripos( $msg, 'verify' ) || false !== stripos( $msg, 'dkim' ) ) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Verify your sending domain in SparkPost before using that From address.', 'smtp-pai' )
			);
		}
		return $error;
	}

	/**
	 * @param array  $config  Delivery config.
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    HTML body.
	 * @return true|\WP_Error
	 */
	private static function send_zeptomail_email( array $config, $to, $subject, $body ) {
		$api_key = self::normalize_zeptomail_api_key( $config['api_key'] ?? '' );
		if ( '' === $api_key ) {
			return new WP_Error( 'mailpai_smtp_zeptomail', __( 'Enter a Zepto Mail Send Mail token.', 'smtp-pai' ) );
		}

		$from_email = sanitize_email( $config['from_email'] ?? '' );
		if ( ! is_email( $from_email ) ) {
			return new WP_Error( 'mailpai_smtp_zeptomail', __( 'From email is missing or invalid.', 'smtp-pai' ) );
		}

		$from = array( 'address' => $from_email );
		$from_name = sanitize_text_field( (string) ( $config['from_name'] ?? '' ) );
		if ( '' !== $from_name ) {
			$from['name'] = $from_name;
		}

		$response = wp_remote_post(
			self::zeptomail_api_url( $config ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => self::zeptomail_authorization_header( $api_key ),
				),
				'body'    => wp_json_encode(
					array(
						'from'     => $from,
						'to'       => array(
							array(
								'email_address' => array(
									'address' => $to,
								),
							),
						),
						'subject'  => $subject,
						'htmlbody' => $body,
					)
				),
			)
		);

		$result = self::parse_zeptomail_response( $response );
		if ( is_wp_error( $result ) ) {
			return self::enrich_zeptomail_error( $result );
		}
		return $result;
	}

	/**
	 * @param string $api_key Raw Send Mail token.
	 * @return string
	 */
	public static function normalize_zeptomail_api_key( $api_key ) {
		$api_key = trim( (string) $api_key );
		if ( preg_match( '/^authorization\s*:\s*/i', $api_key ) ) {
			$api_key = trim( (string) preg_replace( '/^authorization\s*:\s*/i', '', $api_key ) );
		}
		while ( preg_match( '/^zoho-enczapikey\s+/i', $api_key ) ) {
			$api_key = trim( (string) preg_replace( '/^zoho-enczapikey\s+/i', '', $api_key, 1 ) );
		}
		return $api_key;
	}

	/**
	 * @param string $api_key Normalized Send Mail token.
	 * @return string
	 */
	private static function zeptomail_authorization_header( $api_key ) {
		return 'Zoho-enczapikey ' . self::normalize_zeptomail_api_key( $api_key );
	}

	/**
	 * @param string $region Raw region value.
	 * @return string us|eu|in
	 */
	public static function normalize_zeptomail_region( $region ) {
		$region = sanitize_key( (string) $region );
		if ( in_array( $region, array( 'eu', 'in' ), true ) ) {
			return $region;
		}
		return 'us';
	}

	/**
	 * @param array $config Delivery config.
	 * @return string
	 */
	private static function zeptomail_api_url( array $config ) {
		$region = self::normalize_zeptomail_region( $config['api_domain'] ?? 'us' );
		$hosts  = array(
			'eu' => 'https://api.zeptomail.eu',
			'in' => 'https://api.zeptomail.in',
		);
		$host = isset( $hosts[ $region ] ) ? $hosts[ $region ] : 'https://api.zeptomail.com';
		return $host . '/v1.1/email';
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 * @return true|\WP_Error
	 */
	private static function parse_zeptomail_response( $response ) {
		self::set_server_status( self::format_api_response( $response ) );

		if ( is_wp_error( $response ) ) {
			return self::humanize_http_transport_error( $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( (string) $body, true );

		$message = self::extract_zeptomail_error_message( $json, $body, $code );
		if ( null !== $message ) {
			return new WP_Error( 'mailpai_smtp_api', $message );
		}

		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		return new WP_Error( 'mailpai_smtp_api', self::extract_api_error_message( $body, $code ) );
	}

	/**
	 * @param mixed  $json Decoded response JSON.
	 * @param string $body Raw response body.
	 * @param int    $code HTTP status code.
	 * @return string|null Error message or null when successful.
	 */
	private static function extract_zeptomail_error_message( $json, $body, $code ) {
		if ( ! is_array( $json ) ) {
			return $code >= 400 ? self::extract_api_error_message( $body, $code ) : null;
		}

		if ( ! empty( $json['error'] ) && is_array( $json['error'] ) ) {
			$err   = $json['error'];
			$parts = array();
			if ( ! empty( $err['message'] ) && is_string( $err['message'] ) ) {
				$parts[] = (string) $err['message'];
			}
			if ( ! empty( $err['code'] ) ) {
				$parts[] = '(' . (string) $err['code'] . ')';
			}
			if ( ! empty( $err['details'] ) && is_array( $err['details'] ) ) {
				foreach ( $err['details'] as $detail ) {
					if ( is_array( $detail ) && ! empty( $detail['message'] ) && is_string( $detail['message'] ) ) {
						$parts[] = (string) $detail['message'];
					}
				}
			}
			return ! empty( $parts ) ? implode( ' ', array_unique( $parts ) ) : __( 'Zepto Mail rejected the request.', 'smtp-pai' );
		}

		if ( ! empty( $json['data'] ) && is_array( $json['data'] ) ) {
			foreach ( $json['data'] as $item ) {
				if ( ! is_array( $item ) || empty( $item['code'] ) ) {
					continue;
				}
				$item_code = (string) $item['code'];
				if ( 0 === stripos( $item_code, 'EM_' ) ) {
					continue;
				}
				return ! empty( $item['message'] ) && is_string( $item['message'] )
					? (string) $item['message']
					: $item_code;
			}
			return null;
		}

		return $code >= 400 ? self::extract_api_error_message( $body, $code ) : null;
	}

	/**
	 * @param \WP_Error $error Parsed API error.
	 * @return \WP_Error
	 */
	private static function enrich_zeptomail_error( WP_Error $error ) {
		$msg = $error->get_error_message();
		if (
			false !== stripos( $msg, 'access denied' )
			|| false !== stripos( $msg, 'unauthorized' )
			|| false !== stripos( $msg, '401' )
			|| false !== stripos( $msg, 'SERR_157' )
			|| false !== stripos( $msg, 'invalid sendmail token' )
			|| false !== stripos( $msg, 'invalid send mail token' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Paste only the Send Mail token from your Agent → SMTP/API → API tab (not the Zoho-enczapikey prefix). Confirm Hosted region matches your Zepto Mail account.', 'smtp-pai' )
			);
		}
		if (
			false !== stripos( $msg, 'SERR_156' )
			|| false !== stripos( $msg, 'allowed ip' )
			|| false !== stripos( $msg, 'ip is not in the allowed' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Add this server’s public IP to Zepto Mail → Settings → Allowed IPs, or disable IP restriction for API sending.', 'smtp-pai' )
			);
		}
		if (
			false !== stripos( $msg, 'SM_111' )
			|| false !== stripos( $msg, 'domain is not verified' )
			|| false !== stripos( $msg, 'unverified' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Verify your sending domain in Zepto Mail → Agent → Domains, then use a From address on that verified domain.', 'smtp-pai' )
			);
		}
		if ( false !== stripos( $msg, 'SM_128' ) || false !== stripos( $msg, 'yet to be reviewed' ) ) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Your Zepto Mail account must be reviewed and approved before API sending works.', 'smtp-pai' )
			);
		}
		if ( false !== stripos( $msg, 'forbidden' ) || false !== stripos( $msg, '403' ) ) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Check the Send Mail token, Hosted region, verified From domain, and Allowed IPs in Zepto Mail.', 'smtp-pai' )
			);
		}
		return $error;
	}

	/**
	 * @param array  $config  Delivery config.
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    HTML body.
	 * @return true|\WP_Error
	 */
	private static function send_smtp2go_email( array $config, $to, $subject, $body ) {
		$api_key = self::normalize_smtp2go_api_key( $config['api_key'] ?? '' );
		if ( '' === $api_key ) {
			return new WP_Error( 'mailpai_smtp_smtp2go', __( 'Enter an SMTP2GO API key.', 'smtp-pai' ) );
		}

		$from_email = sanitize_email( $config['from_email'] ?? '' );
		if ( ! is_email( $from_email ) ) {
			return new WP_Error( 'mailpai_smtp_smtp2go', __( 'From email is missing or invalid.', 'smtp-pai' ) );
		}

		$plain_body = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) );
		if ( '' === $plain_body ) {
			$plain_body = $subject;
		}

		$payload = array(
			'api_key'   => $api_key,
			'sender'    => self::format_mailgun_from( $config['from_name'] ?? '', $from_email ),
			'to'        => array( $to ),
			'subject'   => $subject,
			'html_body' => $body,
			'text_body' => $plain_body,
		);

		$response = wp_remote_post(
			self::smtp2go_api_url(),
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'             => 'application/json',
					'Content-Type'       => 'application/json',
					'X-Smtp2go-Api-Key'  => $api_key,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		$result = self::parse_smtp2go_response( $response );
		if ( is_wp_error( $result ) ) {
			return self::enrich_smtp2go_error( $result );
		}
		return $result;
	}

	/**
	 * @param string $api_key Raw API key.
	 * @return string
	 */
	public static function normalize_smtp2go_api_key( $api_key ) {
		return trim( (string) $api_key );
	}

	/**
	 * SMTP2GO global API send endpoint (auto-routes to nearest US/EU/AU region).
	 *
	 * @see https://developers.smtp2go.com/docs/endpoints
	 * @return string
	 */
	private static function smtp2go_api_url() {
		return 'https://api.smtp2go.com/v3/email/send';
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 * @return true|\WP_Error
	 */
	private static function parse_smtp2go_response( $response ) {
		self::set_server_status( self::format_api_response( $response ) );

		if ( is_wp_error( $response ) ) {
			return self::humanize_http_transport_error( $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( (string) $body, true );

		$message = self::extract_smtp2go_error_message( $json, $body, $code );
		if ( null !== $message ) {
			return new WP_Error( 'mailpai_smtp_api', $message );
		}

		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		return new WP_Error( 'mailpai_smtp_api', self::extract_api_error_message( $body, $code ) );
	}

	/**
	 * @param mixed  $json Decoded response JSON.
	 * @param string $body Raw response body.
	 * @param int    $code HTTP status code.
	 * @return string|null Error message or null when successful.
	 */
	private static function extract_smtp2go_error_message( $json, $body, $code ) {
		if ( ! is_array( $json ) ) {
			return $code >= 400 ? self::extract_api_error_message( $body, $code ) : null;
		}

		if ( ! empty( $json['data'] ) && is_array( $json['data'] ) ) {
			$data = $json['data'];
			if ( ! empty( $data['error'] ) && is_string( $data['error'] ) ) {
				$msg = (string) $data['error'];
				if ( ! empty( $data['error_code'] ) ) {
					$msg .= ' (' . (string) $data['error_code'] . ')';
				}
				return $msg;
			}
		}

		if ( ! empty( $json['email_response'] ) && is_array( $json['email_response'] ) ) {
			$email_response = $json['email_response'];
			$failed         = (int) ( $email_response['failed'] ?? 0 );
			if ( $failed > 0 ) {
				$failures = array();
				if ( ! empty( $email_response['failures'] ) && is_array( $email_response['failures'] ) ) {
					foreach ( $email_response['failures'] as $failure ) {
						if ( is_string( $failure ) && '' !== trim( $failure ) ) {
							$failures[] = trim( $failure );
						}
					}
				}
				return ! empty( $failures )
					? implode( ' ', array_unique( $failures ) )
					: __( 'SMTP2GO rejected the email.', 'smtp-pai' );
			}
			return null;
		}

		foreach ( array( 'error', 'Error', 'message', 'Message' ) as $key ) {
			if ( ! empty( $json[ $key ] ) && is_string( $json[ $key ] ) ) {
				return (string) $json[ $key ];
			}
		}

		return $code >= 400 ? self::extract_api_error_message( $body, $code ) : null;
	}

	/**
	 * @param \WP_Error $error Parsed API error.
	 * @return \WP_Error
	 */
	private static function enrich_smtp2go_error( WP_Error $error ) {
		$msg = $error->get_error_message();
		if (
			false !== stripos( $msg, 'unauthorized' )
			|| false !== stripos( $msg, '401' )
			|| false !== stripos( $msg, 'invalid api' )
			|| false !== stripos( $msg, 'api key' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Use an API key from SMTP2GO → Sending → API Keys (starts with api-). Enable Email Sending permission.', 'smtp-pai' )
			);
		}
		if (
			false !== stripos( $msg, 'permission' )
			|| false !== stripos( $msg, 'ENDPOINT_PERMISSION_DENIED' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Edit the API key in SMTP2GO and enable the Email Sending permission.', 'smtp-pai' )
			);
		}
		if (
			false !== stripos( $msg, 'sender' )
			|| false !== stripos( $msg, 'verified' )
			|| false !== stripos( $msg, 'authorised' )
			|| false !== stripos( $msg, 'authorized' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Use a From address on a verified sender domain in SMTP2GO.', 'smtp-pai' )
			);
		}
		return $error;
	}

	/**
	 * @param array  $config  Delivery config.
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    HTML body.
	 * @return true|\WP_Error
	 */
	private static function send_smtp_com_email( array $config, $to, $subject, $body ) {
		self::$last_smtp_com_message_id = '';

		$api_key = self::normalize_smtp_com_api_key( $config['api_key'] ?? '' );
		if ( '' === $api_key ) {
			return new WP_Error( 'mailpai_smtp_smtp_com', __( 'Enter an SMTP.com API key.', 'smtp-pai' ) );
		}

		$channel = self::normalize_smtp_com_channel( $config['smtp_com_channel'] ?? '' );
		if ( '' === $channel ) {
			return new WP_Error( 'mailpai_smtp_smtp_com', __( 'Enter your SMTP.com channel name.', 'smtp-pai' ) );
		}

		$from_email = sanitize_email( $config['from_email'] ?? '' );
		if ( ! is_email( $from_email ) ) {
			return new WP_Error( 'mailpai_smtp_smtp_com', __( 'From email is missing or invalid.', 'smtp-pai' ) );
		}

		$from_name = trim( sanitize_text_field( (string) ( $config['from_name'] ?? '' ) ) );
		$from      = array( 'address' => $from_email );
		if ( '' !== $from_name ) {
			$from['name'] = $from_name;
		}

		$plain_body = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) );
		if ( '' === $plain_body ) {
			$plain_body = $subject;
		}

		$payload = array(
			'channel'    => $channel,
			'originator' => array( 'from' => $from ),
			'recipients' => array(
				'to' => array(
					array( 'address' => $to ),
				),
			),
			'subject'    => $subject,
			'body'       => array(
				'parts' => array(
					array(
						'type'    => 'text/html',
						'charset' => 'utf-8',
						'content' => $body,
					),
					array(
						'type'    => 'text/plain',
						'charset' => 'utf-8',
						'content' => $plain_body,
					),
				),
			),
		);

		$response = wp_remote_post(
			self::smtp_com_api_url(),
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => self::smtp_com_authorization_header( $api_key ),
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		$result = self::parse_smtp_com_response( $response );
		if ( is_wp_error( $result ) ) {
			return self::enrich_smtp_com_error( $result );
		}
		return $result;
	}

	/**
	 * @param string $api_key Raw API key.
	 * @return string
	 */
	public static function normalize_smtp_com_api_key( $api_key ) {
		$api_key = trim( (string) $api_key );
		if ( preg_match( '/^authorization\s*:\s*/i', $api_key ) ) {
			$api_key = trim( (string) preg_replace( '/^authorization\s*:\s*/i', '', $api_key ) );
		}
		if ( preg_match( '/^bearer\s+/i', $api_key ) ) {
			$api_key = trim( (string) preg_replace( '/^bearer\s+/i', '', $api_key, 1 ) );
		}
		if ( preg_match( '/^basic\s+/i', $api_key ) ) {
			$api_key = trim( (string) preg_replace( '/^basic\s+/i', '', $api_key, 1 ) );
		}
		return $api_key;
	}

	/**
	 * @param string $channel Raw channel name.
	 * @return string
	 */
	public static function normalize_smtp_com_channel( $channel ) {
		$channel = trim( (string) $channel );
		// Preserve the exact SMTP.com sender alias; only strip control characters.
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $channel );
	}

	/**
	 * @param array $rec Connection record.
	 * @return string
	 */
	public static function resolve_smtp_com_channel( array $rec ) {
		return self::normalize_smtp_com_channel( $rec['meta']['smtp_com_channel'] ?? '' );
	}

	/**
	 * @param string $api_key Normalized API key.
	 * @return string
	 */
	private static function smtp_com_authorization_header( $api_key ) {
		return 'Basic ' . base64_encode( 'api:' . self::normalize_smtp_com_api_key( $api_key ) );
	}

	/**
	 * @see https://www.smtp.com/resources/api-documentation/
	 * @return string
	 */
	private static function smtp_com_api_url() {
		return 'https://api.smtp.com/v4/messages';
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 * @return true|\WP_Error
	 */
	private static function parse_smtp_com_response( $response ) {
		self::set_server_status( self::format_api_response( $response ) );

		if ( is_wp_error( $response ) ) {
			return self::humanize_http_transport_error( $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( (string) $body, true );

		$message = self::extract_smtp_com_error_message( $json, $body, $code );
		if ( null !== $message ) {
			return new WP_Error( 'mailpai_smtp_api', $message );
		}

		if ( $code >= 200 && $code < 300 ) {
			self::$last_smtp_com_message_id = self::extract_smtp_com_message_id( $json );
			return true;
		}

		return new WP_Error( 'mailpai_smtp_api', self::extract_api_error_message( $body, $code ) );
	}

	/**
	 * @param mixed $json Decoded SMTP.com response JSON.
	 * @return string
	 */
	private static function extract_smtp_com_message_id( $json ) {
		if ( ! is_array( $json ) || empty( $json['data'] ) || ! is_array( $json['data'] ) ) {
			return '';
		}

		$data = $json['data'];
		if ( ! empty( $data['msg_id'] ) && is_string( $data['msg_id'] ) ) {
			return trim( (string) $data['msg_id'] );
		}

		if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
			$message = trim( (string) $data['message'] );
			if ( preg_match( '/msg_id:\s*([a-f0-9\-]+)/i', $message, $matches ) ) {
				return trim( (string) $matches[1] );
			}
		}

		if ( ! empty( $data['id'] ) && is_string( $data['id'] ) ) {
			return trim( (string) $data['id'] );
		}

		return '';
	}

	/**
	 * @param mixed  $json Decoded response JSON.
	 * @param string $body Raw response body.
	 * @param int    $code HTTP status code.
	 * @return string|null Error message or null when successful.
	 */
	private static function extract_smtp_com_error_message( $json, $body, $code ) {
		if ( ! is_array( $json ) ) {
			return $code >= 400 ? self::extract_api_error_message( $body, $code ) : null;
		}

		$status = strtolower( trim( (string) ( $json['status'] ?? '' ) ) );
		if ( 'success' === $status && $code >= 200 && $code < 300 ) {
			return null;
		}

		if ( 'fail' === $status || 'error' === $status || $code >= 400 ) {
			if ( ! empty( $json['message'] ) && is_string( $json['message'] ) ) {
				return (string) $json['message'];
			}

			if ( ! empty( $json['data'] ) && is_array( $json['data'] ) ) {
				if ( ! empty( $json['data']['errors'] ) ) {
					$flattened = self::flatten_smtp_com_errors( $json['data']['errors'] );
					if ( '' !== $flattened ) {
						return $flattened;
					}
				}

				$messages = array();
				foreach ( $json['data'] as $key => $value ) {
					if ( is_string( $value ) && '' !== trim( $value ) ) {
						$messages[] = is_numeric( $key ) ? trim( $value ) : trim( $key ) . ': ' . trim( $value );
					}
				}
				if ( ! empty( $messages ) ) {
					return implode( ' ', array_unique( $messages ) );
				}
			}
		}

		foreach ( array( 'error', 'Error', 'message', 'Message' ) as $key ) {
			if ( ! empty( $json[ $key ] ) && is_string( $json[ $key ] ) ) {
				return (string) $json[ $key ];
			}
		}

		return $code >= 400 ? self::extract_api_error_message( $body, $code ) : null;
	}

	/**
	 * @param mixed $errors SMTP.com validation errors object.
	 * @return string
	 */
	private static function flatten_smtp_com_errors( $errors ) {
		if ( ! is_array( $errors ) ) {
			return is_string( $errors ) ? trim( $errors ) : '';
		}

		$messages = array();
		foreach ( $errors as $key => $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$label      = is_int( $key ) || is_numeric( $key ) ? '' : (string) $key . ': ';
				$messages[] = $label . trim( $value );
				continue;
			}
			if ( is_array( $value ) ) {
				$nested = self::flatten_smtp_com_errors( $value );
				if ( '' !== $nested ) {
					$messages[] = ( is_int( $key ) || is_numeric( $key ) ? '' : (string) $key . ': ' ) . $nested;
				}
			}
		}

		return implode( ' ', array_unique( array_filter( $messages ) ) );
	}

	/**
	 * @param \WP_Error $error Parsed API error.
	 * @return \WP_Error
	 */
	private static function enrich_smtp_com_error( WP_Error $error ) {
		$msg = $error->get_error_message();
		if (
			false !== stripos( $msg, 'unauthorized' )
			|| false !== stripos( $msg, '401' )
			|| false !== stripos( $msg, 'invalid api' )
			|| false !== stripos( $msg, 'api key' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Use an API key from SMTP.com → Account → API Keys.', 'smtp-pai' )
			);
		}
		if (
			false !== stripos( $msg, 'channel' )
			|| false !== stripos( $msg, 'sender' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Check the channel name under SMTP.com → Sending → Channels (it must match exactly).', 'smtp-pai' )
			);
		}
		if (
			false !== stripos( $msg, 'domain' )
			|| false !== stripos( $msg, 'verified' )
			|| false !== stripos( $msg, 'from' )
			|| false !== stripos( $msg, 'originator' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Use a From address allowed on the selected SMTP.com channel. The From email must match an address configured for that sender in SMTP.com → Manage Senders.', 'smtp-pai' )
			);
		}
		if ( false !== stripos( $msg, '@gmail.com' ) || preg_match( '/\bgmail\b/i', $msg ) ) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'If you use a Gmail address, confirm that exact address is allowed on your SMTP.com channel. For production sending, use a domain you own and verify it in SMTP.com.', 'smtp-pai' )
			);
		}
		return $error;
	}

	/**
	 * @param array $config Delivery config.
	 * @return string
	 */
	private static function resolve_postmark_message_stream_from_config( array $config ) {
		$stream = isset( $config['postmark_message_stream'] )
			? self::normalize_postmark_message_stream( $config['postmark_message_stream'] )
			: '';
		return '' !== $stream ? $stream : 'outbound';
	}

	/**
	 * @param string $domain Raw domain input.
	 * @return string
	 */
	public static function normalize_mailgun_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = trim( $domain, '/' );
		if ( false !== strpos( $domain, '/' ) ) {
			$parts  = explode( '/', $domain, 2 );
			$domain = $parts[0];
		}
		return sanitize_text_field( $domain );
	}

	/**
	 * @param string $api_domain Raw region value.
	 * @return string us|eu
	 */
	public static function normalize_mailgun_region( $api_domain ) {
		return ( 'eu' === sanitize_key( (string) $api_domain ) ) ? 'eu' : 'us';
	}

	/**
	 * @param string $api_key Raw API key.
	 * @return string
	 */
	public static function normalize_mailgun_api_key( $api_key ) {
		$api_key = trim( (string) $api_key );
		if ( 0 === stripos( $api_key, 'api:' ) ) {
			$api_key = trim( substr( $api_key, 4 ) );
		}
		return $api_key;
	}

	/**
	 * @param string $name  Sender name.
	 * @param string $email Sender email.
	 * @return string
	 */
	private static function format_mailgun_from( $name, $email ) {
		$email = sanitize_email( (string) $email );
		$name  = trim( sanitize_text_field( (string) $name ) );
		if ( '' === $name ) {
			return $email;
		}
		return $name . ' <' . $email . '>';
	}

	/**
	 * @param array $rec Connection record.
	 * @return string
	 */
	public static function resolve_mailgun_sending_domain( array $rec ) {
		$configured = isset( $rec['meta']['mailgun_domain'] ) ? self::normalize_mailgun_domain( $rec['meta']['mailgun_domain'] ) : '';
		if ( '' !== $configured ) {
			return $configured;
		}
		return self::mailgun_domain_from_email( $rec['from_email'] ?? '' );
	}

	/**
	 * @param string $email From email.
	 * @return string
	 */
	public static function mailgun_domain_from_email( $email ) {
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) {
			return '';
		}
		$parts = explode( '@', $email, 2 );
		return isset( $parts[1] ) ? strtolower( $parts[1] ) : '';
	}

	/**
	 * Whether a From address is allowed for the Mailgun sending domain in the API path.
	 *
	 * @param string $from_email From email.
	 * @param string $mg_domain  Mailgun sending domain.
	 * @return bool
	 */
	private static function mailgun_from_authorized_for_domain( $from_email, $mg_domain ) {
		$from_domain = self::mailgun_domain_from_email( $from_email );
		$mg_domain   = self::normalize_mailgun_domain( $mg_domain );
		if ( '' === $from_domain || '' === $mg_domain ) {
			return true;
		}
		if ( $from_domain === $mg_domain ) {
			return true;
		}
		$suffix = '.' . $mg_domain;
		return strlen( $from_domain ) > strlen( $suffix ) && substr( $from_domain, -strlen( $suffix ) ) === $suffix;
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 * @param array           $config   Mailgun delivery config.
	 * @return true|\WP_Error
	 */
	private static function parse_mailgun_response( $response, array $config ) {
		$result = self::parse_http_response( $response );
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$msg  = $result->get_error_message();
		$mg_domain = isset( $config['mailgun_domain'] ) ? self::normalize_mailgun_domain( (string) $config['mailgun_domain'] ) : '';

		if (
			( false !== stripos( $msg, 'authorized' ) || false !== stripos( $msg, 'sandbox' ) )
			&& false !== stripos( $mg_domain, 'sandbox' )
		) {
			return new WP_Error(
				'mailpai_smtp_api',
				$msg . ' ' . __( 'Sandbox domains only send to authorized recipients — add the test address in Mailgun → Sending → your sandbox domain → Authorized recipients.', 'smtp-pai' )
			);
		}

		if ( ! in_array( $code, array( 401, 403 ), true ) && false === stripos( $msg, 'forbidden' ) && false === stripos( $msg, 'unauthorized' ) ) {
			return $result;
		}

		$hints = array();
		if ( 'eu' === self::normalize_mailgun_region( $config['api_domain'] ?? 'us' ) ) {
			$hints[] = __( 'If your domain is in the US Mailgun region, set Region to US.', 'smtp-pai' );
		} else {
			$hints[] = __( 'If your domain is in the EU Mailgun region, set Region to EU.', 'smtp-pai' );
		}
		$hints[] = __( 'Use the Private API key from Mailgun → Settings → API security.', 'smtp-pai' );
		if ( '' !== $mg_domain ) {
			$hints[] = sprintf(
				/* translators: %s: Mailgun sending domain */
				__( 'Domain name must exactly match “%s” under Mailgun → Sending → Domains.', 'smtp-pai' ),
				$mg_domain
			);
		}
		if ( false !== stripos( $mg_domain, 'sandbox' ) && false !== stripos( $mg_domain, 'mailgun.org' ) ) {
			$hints[] = __( 'Sandbox domains can only send to authorized recipients in Mailgun → Sending → Domain settings → Authorized recipients.', 'smtp-pai' );
		}

		return new WP_Error( 'mailpai_smtp_api', $msg . ' ' . implode( ' ', $hints ) );
	}

	/**
	 * @param string $url     URL.
	 * @param array  $headers Headers.
	 * @param array  $body    JSON body.
	 * @return true|\WP_Error
	 */
	private static function http_json( $url, array $headers, array $body ) {
		$headers['Content-Type'] = 'application/json';
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);
		return self::parse_http_response( $response );
	}

	/**
	 * @param string $body Response body.
	 * @param int    $code HTTP status code.
	 * @return string
	 */
	private static function extract_api_error_message( $body, $code ) {
		$fallback = __( 'Email provider rejected the request.', 'smtp-pai' );
		$body     = trim( (string) $body );

		if ( '' === $body ) {
			if ( 401 === $code ) {
				return __( 'Invalid API key or unauthorized.', 'smtp-pai' );
			}
			if ( 403 === $code ) {
				return __( 'Forbidden — check API key permissions and sending domain.', 'smtp-pai' );
			}
			return $fallback;
		}

		$json = json_decode( $body, true );
		if ( is_array( $json ) ) {
			if ( ! empty( $json['error'] ) && is_array( $json['error'] ) ) {
				$nested = $json['error'];
				if ( ! empty( $nested['message'] ) && is_string( $nested['message'] ) ) {
					return (string) $nested['message'];
				}
				if ( ! empty( $nested['name'] ) && is_string( $nested['name'] ) ) {
					return (string) $nested['name'];
				}
			}
			foreach ( array( 'message', 'Message', 'error', 'Error', 'reason', 'detail' ) as $key ) {
				if ( ! empty( $json[ $key ] ) && is_string( $json[ $key ] ) ) {
					return (string) $json[ $key ];
				}
			}
			if ( ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) {
				$first = $json['errors'][0] ?? null;
				if ( is_array( $first ) && ! empty( $first['message'] ) ) {
					return (string) $first['message'];
				}
				if ( is_string( $first ) ) {
					return $first;
				}
			}
		}

		$plain = trim( wp_strip_all_tags( $body ) );
		if ( '' !== $plain ) {
			return mb_substr( $plain, 0, 500 );
		}

		return $fallback;
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 * @return true|\WP_Error
	 */
	private static function parse_http_response( $response ) {
		self::set_server_status( self::format_api_response( $response ) );

		if ( is_wp_error( $response ) ) {
			return self::humanize_http_transport_error( $response );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		$body = wp_remote_retrieve_body( $response );
		return new WP_Error( 'mailpai_smtp_api', self::extract_api_error_message( $body, $code ) );
	}

	/**
	 * @param \WP_Error $error Transport failure from wp_remote_*.
	 * @return \WP_Error
	 */
	private static function humanize_http_transport_error( WP_Error $error ) {
		$msg = $error->get_error_message();
		if ( false !== stripos( $msg, 'could not resolve host' ) ) {
			return new WP_Error(
				$error->get_error_code(),
				__( 'This server could not reach the email provider (DNS lookup failed). Check internet/DNS on your hosting environment.', 'smtp-pai' ) . ' ' . $msg
			);
		}
		if ( false !== stripos( $msg, 'curl error 28' ) || false !== stripos( $msg, 'timed out' ) ) {
			return new WP_Error(
				$error->get_error_code(),
				__( 'The email provider did not respond in time. Try again or check outbound firewall rules.', 'smtp-pai' ) . ' ' . $msg
			);
		}
		return $error;
	}

	/**
	 * @param array  $config  Config.
	 * @param string $to      To.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @param array  $headers       Headers.
	 * @param array  $inline_images Inline CID attachments.
	 * @return true|\WP_Error
	 */
	private static function deliver_smtp( array $config, $to, $subject, $body, array $headers, array $inline_images = array() ) {
		$attempts = self::smtp_delivery_attempts( $config );
		$last     = null;

		foreach ( $attempts as $attempt_config ) {
			$result = self::deliver_smtp_once( $attempt_config, $to, $subject, $body, $headers, $inline_images );
			if ( ! is_wp_error( $result ) ) {
				return true;
			}
			$last = $result;
		}

		return $last ? self::humanize_mailbox_smtp_error( $last, $config ) : new WP_Error( 'mailpai_smtp_smtp', __( 'SMTP delivery failed.', 'smtp-pai' ) );
	}

	/**
	 * @param array $config SMTP config.
	 * @return array<int,array>
	 */
	private static function smtp_delivery_attempts( array $config ) {
		$host = Mailpai_Smtp_Provider_Registry::normalize_smtp_host( $config['host'] ?? '' );
		$port = (int) ( $config['port'] ?? 587 );
		$enc  = (string) ( $config['encryption'] ?? 'tls' );
		$base = array_merge( $config, array( 'host' => $host, 'port' => $port, 'encryption' => $enc ) );
		$out  = array( $base );

		$profile = Mailpai_Smtp_Provider_Registry::mailbox_profile_for_host( $host );
		if ( $profile && ! empty( $profile['fallback'] ) ) {
			foreach ( $profile['fallback'] as $fallback ) {
				$fb_host = ! empty( $fallback['host'] )
					? Mailpai_Smtp_Provider_Registry::normalize_smtp_host( $fallback['host'] )
					: $host;
				$fb_port = (int) ( $fallback['port'] ?? 0 );
				$fb_enc  = (string) ( $fallback['encryption'] ?? 'tls' );
				if ( $fb_host === $host && $fb_port === $port && $fb_enc === $enc ) {
					continue;
				}
				$out[] = array_merge(
					$config,
					array(
						'host'       => $fb_host,
						'port'       => $fb_port,
						'encryption' => $fb_enc,
					)
				);
			}
		}

		return $out;
	}

	/**
	 * @param \WP_Error       $error  SMTP error.
	 * @param array           $config Config used.
	 * @return \WP_Error
	 */
	private static function humanize_mailbox_smtp_error( WP_Error $error, array $config ) {
		$rec = array(
			'provider' => $config['provider'] ?? '',
			'host'     => $config['host'] ?? '',
		);
		if ( ! Mailpai_Smtp_Provider_Registry::uses_mailbox_smtp( $rec ) ) {
			return $error;
		}
		$msg = $error->get_error_message();
		if ( false === stripos( $msg, 'authenticate' ) && false === stripos( $msg, 'auth' ) ) {
			return $error;
		}
		if ( 'oauth' === ( $config['auth_type'] ?? '' ) ) {
			$detail = trim( $error->get_error_message() );
			$msg    = __( 'Could not send with OAuth. Save the connection again to sign in.', 'smtp-pai' );
			if ( '' !== $detail ) {
				$msg .= ' ' . $detail;
			}
			return new WP_Error(
				$error->get_error_code(),
				$msg
			);
		}
		return new WP_Error(
			$error->get_error_code(),
			__( 'Could not sign in with this email and password. Re-enter both fields, save, and test again.', 'smtp-pai' )
		);
	}

	/**
	 * @param array  $config  Config.
	 * @param string $to      To.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @param array  $headers       Headers.
	 * @param array  $inline_images Inline CID attachments.
	 * @return true|\WP_Error
	 */
	private static function deliver_smtp_once( array $config, $to, $subject, $body, array $headers, array $inline_images = array() ) {
		if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer', false ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
			require_once ABSPATH . WPINC . '/PHPMailer/OAuthTokenProvider.php';
		}

		$mail = new PHPMailer\PHPMailer\PHPMailer( true );
		$uses_oauth = 'oauth' === ( $config['auth_type'] ?? '' );
		self::$smtp_server_lines = array();

		try {
			$mail->SMTPDebug   = PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
			$mail->Debugoutput = array( __CLASS__, 'collect_smtp_debug' );
			$mail->isSMTP();
			$mail->Host       = $config['host'];
			$mail->Port       = (int) $config['port'];
			$mail->SMTPAuth   = true;
			$mail->Username   = (string) ( $config['user'] ?? '' );
			if ( $uses_oauth ) {
				$mail->AuthType = 'XOAUTH2';
				$mail->setOAuth( new Mailpai_Smtp_Xoauth2_Provider( $config ) );
			} else {
				$mail->Password = (string) ( $config['pass'] ?? '' );
			}
			$mail->CharSet    = 'UTF-8';

			$encryption = self::normalize_encryption_for_port(
				(int) ( $config['port'] ?? 587 ),
				(string) ( $config['encryption'] ?? 'tls' )
			);

			if ( 'ssl' === $encryption ) {
				$mail->SMTPSecure = 'ssl';
			} elseif ( 'tls' === $encryption ) {
				$mail->SMTPSecure = 'tls';
			} else {
				$mail->SMTPSecure  = false;
				$mail->SMTPAutoTLS = false;
			}

			$smtp_options = apply_filters( 'mailpai_smtp_smtp_options', array(), $config );
			if ( empty( $smtp_options ) && defined( 'MAILPAI_SMTP_INSECURE_SSL' ) && MAILPAI_SMTP_INSECURE_SSL ) {
				$smtp_options = array(
					'ssl' => array(
						'verify_peer'       => false,
						'verify_peer_name'  => false,
						'allow_self_signed' => true,
					),
				);
			}
			if ( ! empty( $smtp_options ) ) {
				$mail->SMTPOptions = $smtp_options;
			}

			$mail->setFrom( $config['from_email'], $config['from_name'] );
			if ( is_email( $config['from_email'] ) ) {
				$mail->Sender = $config['from_email'];
			}
			$mail->addAddress( $to );
			$mail->Subject = $subject;
			$mail->isHTML( true );
			$mail->Body    = $body;

			foreach ( $inline_images as $img ) {
				if ( empty( $img['path'] ) || empty( $img['cid'] ) || ! is_readable( (string) $img['path'] ) ) {
					continue;
				}
				$filename = ! empty( $img['filename'] ) ? (string) $img['filename'] : basename( (string) $img['path'] );
				$mail->addEmbeddedImage( (string) $img['path'], (string) $img['cid'], $filename );
			}

			foreach ( $headers as $h ) {
				$mail->addCustomHeader( (string) $h );
			}

			$mail->send();
			$smtp = $mail->getSMTPInstance();
			if ( $smtp ) {
				$tx = trim( (string) $smtp->getLastTransactionID() );
				if ( '' !== $tx ) {
					self::$smtp_server_lines[] = 'Transaction ID: ' . $tx;
				}
			}
			self::set_server_status( implode( "\n", self::$smtp_server_lines ) );
		} catch ( \Exception $e ) {
			$smtp = $mail->getSMTPInstance();
			if ( empty( self::$smtp_server_lines ) && $smtp ) {
				$reply = trim( (string) $smtp->getLastReply() );
				if ( '' !== $reply ) {
					self::$smtp_server_lines[] = $reply;
				}
			}
			if ( ! empty( self::$smtp_server_lines ) ) {
				self::set_server_status( implode( "\n", self::$smtp_server_lines ) );
			}
			return new WP_Error( 'mailpai_smtp_smtp', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Resolve one recipient from raw input (supports "Name <email@domain.com>").
	 *
	 * @param string $to_raw Raw recipient input.
	 * @return string|\WP_Error Normalized email or error.
	 */
	public static function resolve_single_recipient( $to_raw ) {
		$to_raw = trim( (string) $to_raw );
		if ( '' === $to_raw ) {
			return '';
		}

		$recipients = self::parse_recipients( $to_raw );
		if ( ! empty( $recipients ) ) {
			return $recipients[0];
		}

		return new WP_Error(
			'mailpai_smtp_invalid_to',
			__( 'Enter a valid recipient email address.', 'smtp-pai' )
		);
	}

	/**
	 * @param mixed $to To field.
	 * @return string[]
	 */
	public static function parse_recipients( $to ) {
		if ( is_array( $to ) ) {
			$out = array();
			foreach ( $to as $addr ) {
				$email = sanitize_email( is_array( $addr ) ? ( $addr[0] ?? '' ) : $addr );
				if ( is_email( $email ) ) {
					$out[] = $email;
				}
			}
			return array_values( array_unique( $out ) );
		}

		$parts = preg_split( '/[,;]/', (string) $to );
		$out   = array();
		foreach ( $parts as $part ) {
			if ( preg_match( '/<([^>]+)>/', $part, $m ) ) {
				$email = sanitize_email( $m[1] );
			} else {
				$email = sanitize_email( trim( $part ) );
			}
			if ( is_email( $email ) ) {
				$out[] = $email;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array  $rec       Connection.
	 * @param array  $args      Args.
	 * @param string $recipient Recipient.
	 * @param string $subject   Subject.
	 * @param string $message   Message.
	 * @param array  $headers   Headers.
	 * @param string $status    Status.
	 * @param string $error     Error.
	 * @param bool   $failover  Failover flag.
	 * @param array  $config    Resolved delivery config.
	 */
	private static function log_attempt( array $rec, array $args, $recipient, $subject, $message, array $headers, $status, $error, $failover, array $config = array() ) {
		Mailpai_Smtp_Log::insert(
			array(
				'connection_id'         => $rec['id'] ?? '',
				'primary_connection_id' => $args['primary_connection_id'] ?? '',
				'provider'              => $rec['provider'] ?? '',
				'route'                 => $args['route'] ?? '',
				'recipient'             => $recipient,
				'subject'               => $subject,
				'status'                => $status,
				'failover'              => $failover || ! empty( $args['failover'] ),
				'error_message'         => $error,
				'headers'               => array(
					'list' => $headers,
					'meta' => array(
						'from_name'     => (string) ( $config['from_name'] ?? '' ),
						'from_email'    => (string) ( $config['from_email'] ?? '' ),
						'return_path'   => (string) ( $config['from_email'] ?? '' ),
						'transport'     => (string) ( $config['transport'] ?? 'smtp' ),
						'host'          => (string) ( $config['host'] ?? '' ),
						'port'          => (int) ( $config['port'] ?? 0 ),
						'server_status' => self::take_server_status(),
					),
				),
				'body'                  => $message,
			)
		);
	}

	/**
	 * Map common port ↔ encryption pairings to what PHPMailer expects.
	 *
	 * @param int    $port       SMTP port.
	 * @param string $encryption '', 'tls', or 'ssl'.
	 * @return string
	 */
	public static function normalize_encryption_for_port( $port, $encryption ) {
		$port = absint( $port );
		$enc  = in_array( $encryption, array( 'tls', 'ssl', '' ), true ) ? $encryption : 'tls';
		if ( 465 === $port && 'tls' === $enc ) {
			return 'ssl';
		}
		if ( 587 === $port && 'ssl' === $enc ) {
			return 'tls';
		}
		return $enc;
	}

	/**
	 * Send connection test email.
	 *
	 * @param string $connection_id Connection id.
	 * @param string $to            Optional recipient.
	 * @return true|\WP_Error
	 */
	public static function send_test( $connection_id, $to = '' ) {
		$rec = Mailpai_Smtp_Connection_Store::get( sanitize_key( (string) $connection_id ) );

		if ( '' !== trim( (string) $to ) ) {
			$resolved = self::resolve_single_recipient( $to );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$to = $resolved;
		} elseif ( is_array( $rec ) && Mailpai_Smtp_Connection_Store::uses_oauth( $rec ) && is_email( $rec['from_email'] ?? '' ) ) {
			$to = sanitize_email( (string) $rec['from_email'] );
		} else {
			$to = sanitize_email( get_option( 'admin_email' ) );
		}
		$subject = Mailpai_Smtp_Test_Email::subject( $connection_id );
		$body    = Mailpai_Smtp_Test_Email::html( $connection_id, $to );

		return self::send_via_connection(
			$connection_id,
			array(
				'to'      => $to,
				'subject' => $subject,
				'message' => $body,
				'headers' => array(),
				'route'   => 'test',
			)
		);
	}
}
