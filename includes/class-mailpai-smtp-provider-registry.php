<?php
/**
 * Email service provider definitions.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Provider_Registry
 */
class Mailpai_Smtp_Provider_Registry {

	/**
	 * Legacy provider slugs mapped to current slugs.
	 *
	 * @return array<string,string>
	 */
	public static function legacy_slug_map() {
		return array(
			'gmail'            => 'google',
			'google_workspace' => 'google',
			'outlook'          => 'microsoft',
			'microsoft_365'    => 'microsoft',
			'bird'             => 'sparkpost',
		);
	}

	/**
	 * @param string $slug Provider slug.
	 * @return string
	 */
	public static function normalize_slug( $slug ) {
		$slug = sanitize_key( (string) $slug );
		$map  = self::legacy_slug_map();
		return isset( $map[ $slug ] ) ? $map[ $slug ] : $slug;
	}

	/**
	 * OAuth mailbox providers (Client ID + Secret + Connect).
	 *
	 * @return string[]
	 */
	public static function oauth_mailbox_slugs() {
		return array( 'google', 'microsoft' );
	}

	/**
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public static function is_oauth_mailbox( $slug ) {
		return in_array( self::normalize_slug( $slug ), self::oauth_mailbox_slugs(), true );
	}

	/**
	 * @param string $slug Provider slug.
	 * @return string
	 */
	public static function mailbox_address_label( $slug ) {
		return __( 'Email address', 'smtp-pai' );
	}

	/**
	 * Known mailbox SMTP hosts and fallback settings.
	 *
	 * @return array<string,array{slug:string,fallback:array<int,array{host?:string,port:int,encryption:string}>}>
	 */
	public static function mailbox_host_profiles() {
		return array(
			'smtp.gmail.com'        => array(
				'slug'     => 'google',
				'fallback' => array(
					array( 'port' => 465, 'encryption' => 'ssl' ),
				),
			),
			'smtp.office365.com'    => array(
				'slug'     => 'microsoft',
				'fallback' => array(
					array( 'host' => 'smtp-mail.outlook.com', 'port' => 587, 'encryption' => 'tls' ),
				),
			),
			'smtp-mail.outlook.com' => array(
				'slug'     => 'microsoft',
				'fallback' => array(
					array( 'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls' ),
				),
			),
		);
	}

	/**
	 * @param string $host SMTP host.
	 * @return string
	 */
	public static function normalize_smtp_host( $host ) {
		return strtolower( trim( (string) $host ) );
	}

	/**
	 * @param string $host SMTP host.
	 * @return array|null
	 */
	public static function mailbox_profile_for_host( $host ) {
		$host     = self::normalize_smtp_host( $host );
		$profiles = self::mailbox_host_profiles();
		return isset( $profiles[ $host ] ) ? $profiles[ $host ] : null;
	}

	/**
	 * Whether a connection uses a known consumer mailbox SMTP server.
	 *
	 * @param array $rec Connection record.
	 * @return bool
	 */
	public static function uses_mailbox_smtp( array $rec ) {
		if ( self::is_oauth_mailbox( $rec['provider'] ?? '' ) ) {
			return true;
		}

		$host = self::normalize_smtp_host( $rec['host'] ?? '' );
		if ( '' === $host ) {
			$provider = self::get( $rec['provider'] ?? '' );
			$host     = self::normalize_smtp_host( $provider['host'] ?? '' );
		}

		return null !== self::mailbox_profile_for_host( $host );
	}

	/**
	 * @return array<string,array>
	 */
	public static function all() {
		static $providers = null;
		if ( null !== $providers ) {
			return $providers;
		}

		$providers = array(
			'amazon_ses'    => self::base( 'amazon_ses', __( 'Amazon SES', 'smtp-pai' ), 'api', 'amazonaws.svg', 'popular' ),
			'mailgun'       => self::base( 'mailgun', __( 'Mailgun', 'smtp-pai' ), 'api', 'mailgun.png', 'api' ),
			'postmark'      => self::base( 'postmark', __( 'Postmark', 'smtp-pai' ), 'api', 'postmark.png', 'api' ),
			'brevo'         => self::base( 'brevo', __( 'Brevo', 'smtp-pai' ), 'api', 'brevo.svg', 'api' ),
			'resend'        => self::base( 'resend', __( 'Resend', 'smtp-pai' ), 'api', 'resend.png', 'api' ),
			'sendgrid'      => self::base( 'sendgrid', __( 'SendGrid (by Twilio)', 'smtp-pai' ), 'api', 'sendgrid.svg', 'api' ),
			'mailersend'    => self::base( 'mailersend', __( 'MailerSend', 'smtp-pai' ), 'api', 'mailersend.png', 'api' ),
			'mailjet'       => self::base( 'mailjet', __( 'Mailjet', 'smtp-pai' ), 'api', 'mailjet.png', 'api' ),
			'elastic_email' => self::base( 'elastic_email', __( 'Elastic Email', 'smtp-pai' ), 'api', 'elasticemail.png', 'api' ),
			'mandrill'      => self::base( 'mandrill', __( 'Mailchimp Transactional (Mandrill)', 'smtp-pai' ), 'api', 'mailchimp.png', 'api' ),
			'sparkpost'     => self::base( 'sparkpost', __( 'SparkPost', 'smtp-pai' ), 'api', 'sparkpost.svg', 'api' ),
			'zeptomail'     => self::base( 'zeptomail', __( 'Zepto Mail', 'smtp-pai' ), 'api', 'zeptomail.png', 'api' ),
			'smtp2go'       => self::base( 'smtp2go', __( 'SMTP2GO', 'smtp-pai' ), 'api', 'smtp2go.png', 'api' ),
			'smtp_com'      => self::base( 'smtp_com', __( 'SMTP.com', 'smtp-pai' ), 'api', 'smtpcom.png', 'api' ),
			'google'        => self::oauth_mailbox_preset( 'google', __( 'Google Workplace/Gmail', 'smtp-pai' ), 'google.svg', 'smtp.gmail.com', 587, 'tls' ),
			'microsoft'     => self::oauth_mailbox_preset( 'microsoft', __( 'Microsoft 365/Outlook', 'smtp-pai' ), 'microsoft.svg', 'smtp.office365.com', 587, 'tls' ),
			'other_smtp'    => self::base( 'other_smtp', __( 'Other SMTP', 'smtp-pai' ), 'smtp', 'other-smtp.png', 'other' ),
		);

		$providers = apply_filters( 'mailpai_smtp_providers', $providers );
		return $providers;
	}

	/**
	 * @param string $slug Provider slug.
	 * @return array
	 */
	public static function get( $slug ) {
		$slug = self::normalize_slug( $slug );
		$all  = self::all();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : array();
	}

	/**
	 * @param string $group popular|api|smtp|other.
	 * @return array<string,array>
	 */
	public static function by_group( $group ) {
		$out = array();
		foreach ( self::all() as $slug => $def ) {
			if ( ( $def['group'] ?? '' ) === $group ) {
				$out[ $slug ] = $def;
			}
		}
		return $out;
	}

	/**
	 * @param string $slug   Slug.
	 * @param string $label  Label.
	 * @param string $transport api|smtp.
	 * @param string $logo   Logo file.
	 * @param string $group  Group.
	 * @return array
	 */
	private static function base( $slug, $label, $transport, $logo, $group ) {
		return array(
			'slug'       => $slug,
			'label'      => $label,
			'transport'  => $transport,
			'logo'       => $logo,
			'group'      => $group,
			'host'       => '',
			'port'       => 587,
			'encryption' => 'tls',
			'auth_mode'  => '',
			'fields'     => self::fields_for( $slug, $transport ),
			'help'       => self::help_for( $slug ),
			'wp_config'  => self::wp_config_for( $slug, $transport ),
		);
	}

	/**
	 * @param string $slug Slug.
	 * @param string $label Label.
	 * @param string $logo Logo.
	 * @param string $host Host.
	 * @param int    $port Port.
	 * @param string $enc Encryption.
	 * @return array
	 */
	private static function smtp_preset( $slug, $label, $logo, $host, $port, $enc ) {
		$def               = self::base( $slug, $label, 'smtp', $logo, 'smtp' );
		$def['host']       = $host;
		$def['port']       = $port;
		$def['encryption'] = $enc;
		$def['wp_config']  = self::wp_config_smtp_snippet( $host, $port, $enc );
		return $def;
	}

	/**
	 * @param string $slug Slug.
	 * @param string $label Label.
	 * @param string $logo Logo.
	 * @param string $host Host.
	 * @param int    $port Port.
	 * @param string $enc Encryption.
	 * @return array
	 */
	private static function oauth_mailbox_preset( $slug, $label, $logo, $host, $port, $enc ) {
		$def               = self::base( $slug, $label, 'smtp', $logo, 'smtp' );
		$def['host']       = $host;
		$def['port']       = $port;
		$def['encryption'] = $enc;
		$def['auth_mode']  = 'oauth';
		$def['fields']     = array();
		$def['wp_config']  = self::oauth_wp_config_snippet( $slug );
		$def['help']       = self::help_for( $slug );
		return $def;
	}

	/**
	 * wp-config.php constant names for OAuth mailbox credentials.
	 *
	 * @param string $slug google|microsoft.
	 * @return array{client_id:string,client_secret:string}
	 */
	public static function oauth_wp_config_keys( $slug ) {
		$slug = strtoupper( self::normalize_slug( $slug ) );
		return array(
			'client_id'     => 'MAILPAI_SMTP_OAUTH_' . $slug . '_CLIENT_ID',
			'client_secret' => 'MAILPAI_SMTP_OAUTH_' . $slug . '_CLIENT_SECRET',
		);
	}

	/**
	 * wp-config.php snippet placeholders for OAuth mailbox providers.
	 *
	 * @param string $slug google|microsoft.
	 * @return array<string,string>
	 */
	public static function oauth_wp_config_snippet( $slug ) {
		$keys = self::oauth_wp_config_keys( $slug );
		return array(
			$keys['client_id']     => 'your-client-id',
			$keys['client_secret'] => 'your-client-secret',
		);
	}

	/**
	 * @param string $slug      Provider slug.
	 * @param string $transport Transport type.
	 * @return array<string,array>
	 */
	private static function fields_for( $slug, $transport ) {
		if ( self::is_oauth_mailbox( $slug ) ) {
			return array();
		}

		if ( 'amazon_ses' === $slug ) {
			return array(
				'aws_access_key_id' => array( 'type' => 'text', 'label' => __( 'Access key ID', 'smtp-pai' ), 'required' => true ),
				'aws_secret'        => array( 'type' => 'password', 'label' => __( 'Secret access key', 'smtp-pai' ), 'required' => true, 'secret_key' => 'aws_secret_enc' ),
				'aws_region'        => array( 'type' => 'region', 'label' => __( 'Region', 'smtp-pai' ), 'required' => true ),
			);
		}

		if ( 'api' === $transport ) {
			$fields = array(
				'api_key' => array( 'type' => 'password', 'label' => __( 'API key', 'smtp-pai' ), 'required' => true, 'secret_key' => 'api_key_enc' ),
			);
			if ( 'mailgun' === $slug ) {
				$fields['api_key']['label'] = __( 'Private API key', 'smtp-pai' );
				$fields['mailgun_domain'] = array(
					'type'        => 'text',
					'label'       => __( 'Domain name', 'smtp-pai' ),
					'required'    => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Connection field map key, not a WP meta query.
					'meta_key'    => 'mailgun_domain',
					'placeholder' => 'mg.yourdomain.com',
					'help'        => __( 'Sending domain from Mailgun → Sending → Domains (not your From email unless they match).', 'smtp-pai' ),
				);
				$fields['api_domain'] = array(
					'type'     => 'select',
					'label'    => __( 'Region', 'smtp-pai' ),
					'options'  => array( 'us' => 'US', 'eu' => 'EU' ),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Connection field map key, not a WP meta query.
					'meta_key' => 'api_domain',
					'help'     => __( 'Must match where your domain was created in Mailgun (EU accounts need EU).', 'smtp-pai' ),
				);
			}
			if ( 'postmark' === $slug ) {
				$fields['api_key']['label'] = __( 'Server API token', 'smtp-pai' );
				$fields['message_stream_id'] = array(
					'type'        => 'text',
					'label'       => __( 'Message Stream ID', 'smtp-pai' ),
					'required'    => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Connection field map key, not a WP meta query.
					'meta_key'    => 'postmark_message_stream',
					'placeholder' => 'outbound',
					'help'        => __( 'Postmark message stream for this server (default: outbound). Find it under Server → Message Streams.', 'smtp-pai' ),
				);
			}
			if ( 'mailjet' === $slug ) {
				$fields['api_secret'] = array(
					'type'       => 'password',
					'label'      => __( 'Secret key', 'smtp-pai' ),
					'required'   => true,
					'secret_key' => 'api_secret_enc',
				);
			}
			if ( 'sendgrid' === $slug ) {
				$fields['api_key']['help'] = __( 'Create under Settings → API Keys with Mail Send permission. Keys usually start with SG.', 'smtp-pai' );
				$fields['api_domain']      = array(
					'type'     => 'select',
					'label'    => __( 'Region', 'smtp-pai' ),
					'options'  => array( 'us' => 'US', 'eu' => 'EU' ),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Connection field map key, not a WP meta query.
					'meta_key' => 'api_domain',
					'help'     => __( 'Must match your SendGrid account region (EU accounts use api.eu.sendgrid.com).', 'smtp-pai' ),
				);
			}
			if ( 'sparkpost' === $slug ) {
				$fields['api_domain'] = array(
					'type'     => 'select',
					'label'    => __( 'Region', 'smtp-pai' ),
					'options'  => array( 'us' => 'US', 'eu' => 'EU' ),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Connection field map key, not a WP meta query.
					'meta_key' => 'api_domain',
					'help'     => __( 'Must match your SparkPost account region (EU accounts use api.eu.sparkpost.com).', 'smtp-pai' ),
				);
			}
			if ( 'zeptomail' === $slug ) {
				$fields['api_key']['label'] = __( 'Send Mail token', 'smtp-pai' );
				$fields['api_key']['help']  = __( 'Paste only the token from Zepto Mail → Agent → SMTP/API → API. Do not include the Zoho-enczapikey prefix.', 'smtp-pai' );
				$fields['api_domain'] = array(
					'type'     => 'select',
					'label'    => __( 'Hosted region', 'smtp-pai' ),
					'options'  => array(
						'us' => __( 'US (api.zeptomail.com)', 'smtp-pai' ),
						'eu' => __( 'EU (api.zeptomail.eu)', 'smtp-pai' ),
						'in' => __( 'India (api.zeptomail.in)', 'smtp-pai' ),
					),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Connection field map key, not a WP meta query.
					'meta_key' => 'api_domain',
					'help'     => __( 'Must match where your Zepto Mail account is hosted.', 'smtp-pai' ),
				);
			}
			if ( 'smtp2go' === $slug ) {
				$fields['api_key']['label'] = __( 'API key', 'smtp-pai' );
				$fields['api_key']['help']  = __( 'Create under Sending → API Keys. Keys start with api-. Enable Email Sending permission.', 'smtp-pai' );
			}
			if ( 'smtp_com' === $slug ) {
				$fields['api_key']['label'] = __( 'API key', 'smtp-pai' );
				$fields['api_key']['help']  = __( 'Create under Account → API Keys in the SMTP.com dashboard.', 'smtp-pai' );
				$fields['channel_name']     = array(
					'type'        => 'text',
					'label'       => __( 'Channel name', 'smtp-pai' ),
					'required'    => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Connection field map key, not a WP meta query.
					'meta_key'    => 'smtp_com_channel',
					'placeholder' => 'My Channel',
					'help'        => __( 'Sending channel name from SMTP.com → Sending → Channels (must match exactly).', 'smtp-pai' ),
				);
			}
			return $fields;
		}

		$fields = array(
			'host'        => array( 'type' => 'text', 'label' => __( 'SMTP host', 'smtp-pai' ), 'required' => true ),
			'port'        => array( 'type' => 'number', 'label' => __( 'Port', 'smtp-pai' ), 'required' => true ),
			'encryption'  => array( 'type' => 'encryption', 'label' => __( 'Encryption', 'smtp-pai' ), 'required' => false ),
			'user'        => array( 'type' => 'text', 'label' => __( 'Username', 'smtp-pai' ), 'required' => false ),
			'smtp_secret' => array( 'type' => 'password', 'label' => __( 'Password', 'smtp-pai' ), 'required' => false, 'secret_key' => 'secret_enc' ),
		);

		if ( 'other_smtp' !== $slug ) {
			unset( $fields['host'], $fields['port'], $fields['encryption'] );
		}

		return $fields;
	}

	/**
	 * @param string $slug Provider slug.
	 * @return array<string,string>
	 */
	private static function help_for( $slug ) {
		$slug = self::normalize_slug( $slug );
		$map  = array(
			'google'     => __( 'Create an OAuth application in Google Cloud Console and add the redirect URI below.', 'smtp-pai' ),
			'microsoft'  => __( 'Register an app in Microsoft Entra ID and add the redirect URI below.', 'smtp-pai' ),
			'amazon_ses' => __( 'Create IAM access keys with permission to send email through Amazon SES.', 'smtp-pai' ),
			'other_smtp' => __( 'Enter the SMTP details from your email provider.', 'smtp-pai' ),
		);
		return array(
			'_main' => isset( $map[ $slug ] ) ? $map[ $slug ] : __( 'Paste the credentials from your provider dashboard.', 'smtp-pai' ),
		);
	}

	/**
	 * @param string $slug      Provider slug.
	 * @param string $transport api|smtp.
	 * @return array<string,string>
	 */
	private static function wp_config_for( $slug, $transport = 'api' ) {
		if ( self::is_oauth_mailbox( $slug ) ) {
			return self::oauth_wp_config_snippet( $slug );
		}
		if ( 'amazon_ses' === $slug ) {
			return array(
				'MAILPAI_SMTP_SES_ACCESS_KEY' => 'YOUR_KEY_ID',
				'MAILPAI_SMTP_SES_SECRET_KEY' => 'YOUR_SECRET',
				'MAILPAI_SMTP_SES_REGION'     => 'us-east-1',
			);
		}
		if ( 'other_smtp' === $slug || 'smtp' === $transport ) {
			return self::wp_config_smtp_snippet( 'smtp.example.com', '587', 'tls' );
		}
		if ( 'mailjet' === $slug ) {
			return array(
				'MAILPAI_SMTP_API_KEY'    => 'your-api-key',
				'MAILPAI_SMTP_API_SECRET' => 'your-secret-key',
			);
		}
		return array(
			'MAILPAI_SMTP_API_KEY' => 'your-api-key',
		);
	}

	/**
	 * wp-config.php constants for SMTP providers.
	 *
	 * @param string $host SMTP host.
	 * @param int    $port SMTP port.
	 * @param string $enc  Encryption.
	 * @return array<string,string>
	 */
	private static function wp_config_smtp_snippet( $host, $port, $enc ) {
		return array(
			'MAILPAI_SMTP_HOST'       => (string) $host,
			'MAILPAI_SMTP_PORT'       => (string) $port,
			'MAILPAI_SMTP_USER'       => 'your-email@example.com',
			'MAILPAI_SMTP_PASSWORD'   => 'your-password',
			'MAILPAI_SMTP_ENCRYPTION' => (string) $enc,
		);
	}

	/**
	 * Logo URL for provider.
	 *
	 * @param string $file Filename.
	 * @return string
	 */
	public static function logo_url( $file ) {
		return MAILPAI_SMTP_PLUGIN_URL . 'assets/img/providers/' . ltrim( (string) $file, '/' );
	}
}
