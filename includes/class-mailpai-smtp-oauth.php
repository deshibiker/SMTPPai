<?php
/**
 * OAuth mailbox connections (per-connection Client ID + Secret).
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Oauth
 */
class Mailpai_Smtp_Oauth {

	const STATE_TTL = 900;

	/**
	 * Google OAuth relay endpoint (register this exact URI in Google Cloud Console).
	 */
	const GOOGLE_PROXY_REDIRECT_URI = 'https://auth.mailpai.com/google';

	/**
	 * Microsoft OAuth relay endpoint (register this exact URI in Microsoft Entra).
	 */
	const MICROSOFT_PROXY_REDIRECT_URI = 'https://auth.mailpai.com/microsoft';

	/**
	 * OAuth provider definitions keyed by oauth slug (google|microsoft).
	 *
	 * @return array<string,array>
	 */
	public static function providers() {
		$providers = array(
			'google'    => array(
				'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
				'token_url'     => 'https://oauth2.googleapis.com/token',
				'userinfo_url'  => 'https://www.googleapis.com/oauth2/v2/userinfo',
				'scopes'        => 'https://mail.google.com/ https://www.googleapis.com/auth/userinfo.email',
				'auth_params'   => array(
					'access_type' => 'offline',
					'prompt'      => 'consent',
				),
			),
			'microsoft' => array(
				'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
				'token_url'     => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
				'userinfo_url'  => 'https://graph.microsoft.com/v1.0/me',
				'scopes'        => 'offline_access openid profile email https://outlook.office.com/SMTP.Send',
				'auth_params'   => array(
					'prompt' => 'consent',
				),
			),
		);

		return apply_filters( 'mailpai_smtp_oauth_providers', $providers );
	}

	/**
	 * @param string $oauth_key google|microsoft.
	 * @return array|null
	 */
	public static function provider( $oauth_key ) {
		$oauth_key = sanitize_key( (string) $oauth_key );
		$all       = self::providers();
		return isset( $all[ $oauth_key ] ) ? $all[ $oauth_key ] : null;
	}

	/**
	 * @param string $connection_slug google|microsoft (legacy slugs normalized).
	 * @return string
	 */
	public static function oauth_key_for_slug( $connection_slug ) {
		$slug = Mailpai_Smtp_Provider_Registry::normalize_slug( $connection_slug );
		if ( Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $slug ) ) {
			return $slug;
		}
		return '';
	}

	/**
	 * OAuth callback URL registered with the provider.
	 *
	 * Google and Microsoft use the MailPai auth relay.
	 *
	 * @param string $connection_slug google|microsoft (optional).
	 * @return string
	 */
	public static function redirect_uri( $connection_slug = '' ) {
		$slug = Mailpai_Smtp_Provider_Registry::normalize_slug( (string) $connection_slug );
		if ( self::uses_oauth_proxy( $slug ) ) {
			$uri = 'google' === $slug ? self::GOOGLE_PROXY_REDIRECT_URI : self::MICROSOFT_PROXY_REDIRECT_URI;
			/**
			 * Filters the OAuth redirect URI sent to Google or Microsoft.
			 *
			 * @param string $uri  Redirect URI.
			 * @param string $slug Provider slug.
			 */
			return apply_filters( 'mailpai_smtp_oauth_redirect_uri', $uri, $slug );
		}

		$scheme = 'admin';
		if ( is_ssl() ) {
			$scheme = 'https';
		} elseif ( defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN ) {
			$scheme = 'https';
		} else {
			$scheme = 'http';
		}

		$uri = add_query_arg(
			array(
				'page'               => Mailpai_Smtp_Urls::menu_slug(),
				'mailpai_smtp_oauth' => 'callback',
			),
			admin_url( 'admin.php', $scheme )
		);

		/**
		 * Filters the OAuth redirect URI sent to Google or Microsoft.
		 *
		 * @param string $uri  Redirect URI.
		 * @param string $slug Provider slug.
		 */
		return apply_filters( 'mailpai_smtp_oauth_redirect_uri', $uri, $slug );
	}

	/**
	 * Whether Google OAuth uses the MailPai auth relay.
	 *
	 * @return bool
	 */
	public static function uses_google_proxy() {
		return true;
	}

	/**
	 * Whether a mailbox provider uses the MailPai HTTPS OAuth relay.
	 *
	 * @param string $connection_slug Provider slug.
	 * @return bool
	 */
	public static function uses_oauth_proxy( $connection_slug ) {
		$slug = Mailpai_Smtp_Provider_Registry::normalize_slug( (string) $connection_slug );
		return in_array( $slug, array( 'google', 'microsoft' ), true );
	}

	/**
	 * Query parameter name used when the OAuth relay returns an authorization code.
	 *
	 * @param string $connection_slug Provider slug.
	 * @return string
	 */
	public static function proxy_auth_code_query_param( $connection_slug ) {
		return 'microsoft' === Mailpai_Smtp_Provider_Registry::normalize_slug( (string) $connection_slug )
			? 'mailpai_microsoft_auth_code'
			: 'mailpai_google_auth_code';
	}

	/**
	 * User-facing help when a provider reports redirect_uri_mismatch.
	 *
	 * @param string $connection_slug Provider slug.
	 * @return string
	 */
	public static function redirect_uri_mismatch_message( $connection_slug = 'google' ) {
		$slug = Mailpai_Smtp_Provider_Registry::normalize_slug( (string) $connection_slug );
		$uri  = self::redirect_uri( $slug );

		if ( 'microsoft' === $slug ) {
			return sprintf(
				/* translators: %s: exact redirect URI to register with Microsoft Entra */
				__( 'Microsoft rejected the redirect URI. In Microsoft Entra → App registrations → your app → Authentication, add this exact Web redirect URI: %s', 'smtp-pai' ),
				$uri
			);
		}

		return sprintf(
			/* translators: %s: exact redirect URI to register with the provider */
			__( 'Google rejected the redirect URI. In Google Cloud Console, open your OAuth client (Web application), go to Authorized redirect URIs, and add this exact URI: %s', 'smtp-pai' ),
			$uri
		);
	}

	/**
	 * @param string     $oauth_key google|microsoft.
	 * @param array|null $rec       Connection record.
	 * @return array{client_id:string,client_secret:string}
	 */
	public static function client( $oauth_key, $rec = null ) {
		$oauth_key = sanitize_key( (string) $oauth_key );

		if ( is_array( $rec ) && Mailpai_Smtp_Connection_Store::SECRETS_WP_CONFIG === Mailpai_Smtp_Connection_Store::secrets_storage( $rec ) ) {
			$keys   = Mailpai_Smtp_Provider_Registry::oauth_wp_config_keys( $rec['provider'] ?? $oauth_key );
			$id     = defined( $keys['client_id'] ) ? trim( (string) constant( $keys['client_id'] ) ) : '';
			$secret = defined( $keys['client_secret'] ) ? trim( (string) constant( $keys['client_secret'] ) ) : '';
			if ( '' !== $id && '' !== $secret ) {
				return array(
					'client_id'     => $id,
					'client_secret' => $secret,
				);
			}
		}

		if ( is_array( $rec ) ) {
			$id     = trim( (string) ( $rec['oauth_client_id'] ?? '' ) );
			$secret = self::resolve_connection_secret( $rec, 'oauth_client_secret_enc' );
			if ( '' !== $id && '' !== $secret ) {
				return array(
					'client_id'     => $id,
					'client_secret' => $secret,
				);
			}
		}

		return apply_filters(
			'mailpai_smtp_oauth_client',
			array(
				'client_id'     => '',
				'client_secret' => '',
			),
			$oauth_key,
			$rec
		);
	}

	/**
	 * @param array  $rec   Connection record.
	 * @param string $field Encrypted field name.
	 * @return string
	 */
	private static function resolve_connection_secret( array $rec, $field ) {
		$val = (string) ( $rec[ $field ] ?? '' );
		if ( '' === $val ) {
			return '';
		}
		if ( ! empty( $rec['disable_secret_encryption'] ) ) {
			return $val;
		}
		$dec = Mailpai_Smtp_Crypto::decrypt( $val );
		return false !== $dec ? (string) $dec : $val;
	}

	/**
	 * @param string     $connection_slug Provider slug.
	 * @param array|null $rec             Connection record.
	 * @return bool
	 */
	public static function is_configured( $connection_slug, $rec = null ) {
		$oauth_key = self::oauth_key_for_slug( $connection_slug );
		if ( '' === $oauth_key ) {
			return false;
		}
		$client = self::client( $oauth_key, $rec );
		return '' !== ( $client['client_id'] ?? '' ) && '' !== ( $client['client_secret'] ?? '' );
	}

	/**
	 * Build a connection record from OAuth start args (unsaved connections).
	 *
	 * @param string $connection_slug Provider slug.
	 * @param array  $args            Start args.
	 * @return array
	 */
	public static function record_from_start_args( $connection_slug, array $args ) {
		$connection_slug = Mailpai_Smtp_Provider_Registry::normalize_slug( $connection_slug );
		$connection_id   = sanitize_key( (string) ( $args['connection_id'] ?? '' ) );
		$rec             = '' !== $connection_id ? Mailpai_Smtp_Connection_Store::get( $connection_id ) : null;
		if ( ! is_array( $rec ) ) {
			$rec             = Mailpai_Smtp_Connection_Store::empty_record();
			$rec['provider'] = $connection_slug;
		}

		if ( ! empty( $args['oauth_client_id'] ) ) {
			$rec['oauth_client_id'] = sanitize_text_field( (string) $args['oauth_client_id'] );
		}
		if ( ! empty( $args['oauth_client_secret'] ) ) {
			$rec['oauth_client_secret_enc'] = (string) $args['oauth_client_secret'];
		} elseif ( ! empty( $args['oauth_client_secret_enc'] ) ) {
			$dec = Mailpai_Smtp_Crypto::decrypt( (string) $args['oauth_client_secret_enc'] );
			if ( false !== $dec && '' !== $dec ) {
				$rec['oauth_client_secret_enc'] = $dec;
			}
		}

		return $rec;
	}

	/**
	 * @param string $connection_slug Provider slug.
	 * @param array  $args            Optional state args.
	 * @return string|\WP_Error
	 */
	public static function authorize_url( $connection_slug, array $args = array() ) {
		$connection_slug = Mailpai_Smtp_Provider_Registry::normalize_slug( $connection_slug );
		$oauth_key       = self::oauth_key_for_slug( $connection_slug );
		$provider        = self::provider( $oauth_key );
		$rec             = self::record_from_start_args( $connection_slug, $args );
		$client          = self::client( $oauth_key, $rec );

		if ( empty( $provider ) || '' === ( $client['client_id'] ?? '' ) || '' === ( $client['client_secret'] ?? '' ) ) {
			return new WP_Error(
				'mailpai_smtp_oauth',
				__( 'Enter an Application Client ID and Application Client Secret, then save the connection.', 'smtp-pai' )
			);
		}

		$redirect_uri = self::redirect_uri( $connection_slug );
		$return_url   = wp_validate_redirect(
			(string) ( $args['return_url'] ?? '' ),
			Mailpai_Smtp_Urls::tab( 'dashboard' )
		);

		$state_payload = array(
			'user_id'         => get_current_user_id(),
			'provider'        => $connection_slug,
			'connection_id'   => sanitize_key( (string) ( $args['connection_id'] ?? '' ) ),
			'from_name'       => sanitize_text_field( (string) ( $args['from_name'] ?? '' ) ),
			'connection_name' => sanitize_text_field( (string) ( $args['connection_name'] ?? '' ) ),
			'return_url'      => $return_url,
			'oauth_client_id' => (string) ( $rec['oauth_client_id'] ?? '' ),
		);

		if ( ! empty( $args['oauth_client_secret'] ) ) {
			$sealed = Mailpai_Smtp_Crypto::encrypt( (string) $args['oauth_client_secret'] );
			if ( $sealed ) {
				$state_payload['oauth_client_secret_enc'] = $sealed;
			}
		} elseif ( is_array( $rec ) && ! empty( $rec['oauth_client_secret_enc'] ) ) {
			$state_payload['oauth_client_secret_enc'] = (string) $rec['oauth_client_secret_enc'];
		}

		if ( self::uses_oauth_proxy( $connection_slug ) ) {
			// Random token for CSRF + URL-safe return URL so auth.mailpai.com can relay the code back.
			$token = wp_generate_password( 32, false, false );
			$state = $token . '.' . self::base64url_encode( $return_url );
			set_transient(
				self::oauth_proxy_state_key( $oauth_key, $token ),
				$state_payload,
				self::STATE_TTL
			);
		} else {
			$state = wp_generate_password( 32, false, false );
			set_transient(
				self::state_key( $state ),
				$state_payload,
				self::STATE_TTL
			);
		}

		$params = array_merge(
			array(
				'client_id'     => $client['client_id'],
				'redirect_uri'  => $redirect_uri,
				'response_type' => 'code',
				'scope'         => $provider['scopes'],
				'state'         => $state,
			),
			$provider['auth_params'] ?? array()
		);

		return self::build_authorize_url( $provider['authorize_url'], $params );
	}

	/**
	 * Build a provider authorize URL without stripping OAuth query parameters.
	 *
	 * @param string $base_url Authorize endpoint.
	 * @param array  $params   Query parameters.
	 * @return string
	 */
	private static function build_authorize_url( $base_url, array $params ) {
		$base_url = (string) $base_url;
		$query    = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

		return '' === $query ? $base_url : $base_url . '?' . $query;
	}

	/**
	 * @param string $state OAuth state.
	 * @param string $code  Authorization code.
	 * @return array|\WP_Error
	 */
	public static function handle_callback( $state, $code ) {
		$state = sanitize_text_field( (string) $state );
		$code  = sanitize_text_field( (string) $code );

		if ( '' === $state || '' === $code ) {
			return new WP_Error( 'mailpai_smtp_oauth', __( 'OAuth sign-in was interrupted. Try again.', 'smtp-pai' ) );
		}

		$payload = get_transient( self::state_key( $state ) );
		delete_transient( self::state_key( $state ) );

		if ( ! is_array( $payload ) || (int) ( $payload['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return new WP_Error( 'mailpai_smtp_oauth', __( 'OAuth session expired. Try again.', 'smtp-pai' ) );
		}

		$connection_slug = Mailpai_Smtp_Provider_Registry::normalize_slug( (string) ( $payload['provider'] ?? '' ) );
		$oauth_key       = self::oauth_key_for_slug( $connection_slug );
		$provider        = self::provider( $oauth_key );
		$rec             = self::record_from_start_args( $connection_slug, $payload );
		$client          = self::client( $oauth_key, $rec );

		if ( empty( $provider ) || '' === ( $client['client_id'] ?? '' ) || '' === ( $client['client_secret'] ?? '' ) ) {
			return new WP_Error( 'mailpai_smtp_oauth', __( 'OAuth app credentials are missing.', 'smtp-pai' ) );
		}

		$tokens = self::exchange_code( $oauth_key, $code, $client, $provider, self::redirect_uri( $connection_slug ) );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		return self::finalize_oauth_connection( $connection_slug, $payload, $tokens );
	}

	/**
	 * Complete Google OAuth after the auth.mailpai.com relay returns the authorization code.
	 *
	 * @param string $code  Authorization code from Google (via relay).
	 * @param string $state Base64-encoded return admin URL sent to Google.
	 * @return array|\WP_Error
	 */
	public static function handle_google_proxy_callback( $code, $state ) {
		return self::handle_oauth_proxy_callback( 'google', $code, $state );
	}

	/**
	 * Complete Microsoft OAuth after the auth.mailpai.com relay returns the authorization code.
	 *
	 * @param string $code  Authorization code from Microsoft (via relay).
	 * @param string $state Base64-encoded return admin URL sent to Microsoft.
	 * @return array|\WP_Error
	 */
	public static function handle_microsoft_proxy_callback( $code, $state ) {
		return self::handle_oauth_proxy_callback( 'microsoft', $code, $state );
	}

	/**
	 * Finish OAuth using a manually pasted authorization code (Microsoft relay flow).
	 *
	 * @param string $connection_id Saved connection id.
	 * @param string $code          Authorization code copied from the MailPai relay page.
	 * @return array|\WP_Error
	 */
	public static function complete_manual_authorization_code( $connection_id, $code ) {
		$connection_id = sanitize_key( (string) $connection_id );
		$code          = self::sanitize_oauth_code( $code );

		if ( '' === $connection_id || '' === $code ) {
			return new WP_Error(
				'mailpai_smtp_oauth',
				__( 'Paste the authorization code from the OAuth relay page, then try again.', 'smtp-pai' )
			);
		}

		$rec = Mailpai_Smtp_Connection_Store::get( $connection_id );
		if ( ! is_array( $rec ) ) {
			return new WP_Error( 'mailpai_smtp_oauth', __( 'Connection not found.', 'smtp-pai' ) );
		}

		$connection_slug = Mailpai_Smtp_Provider_Registry::normalize_slug( (string) ( $rec['provider'] ?? '' ) );
		if ( ! self::uses_oauth_proxy( $connection_slug ) ) {
			return new WP_Error(
				'mailpai_smtp_oauth',
				__( 'Manual authorization codes are only supported for Google and Microsoft connections.', 'smtp-pai' )
			);
		}

		$oauth_key = self::oauth_key_for_slug( $connection_slug );
		$provider  = self::provider( $oauth_key );
		$client    = self::client( $oauth_key, $rec );

		if ( empty( $provider ) || '' === ( $client['client_id'] ?? '' ) || '' === ( $client['client_secret'] ?? '' ) ) {
			return new WP_Error( 'mailpai_smtp_oauth', __( 'OAuth app credentials are missing.', 'smtp-pai' ) );
		}

		$tokens = self::exchange_code( $oauth_key, $code, $client, $provider, self::redirect_uri( $connection_slug ) );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$payload = array(
			'user_id'         => get_current_user_id(),
			'provider'        => $connection_slug,
			'connection_id'   => $connection_id,
			'from_name'       => (string) ( $rec['from_name'] ?? '' ),
			'connection_name' => (string) ( $rec['connection_name'] ?? '' ),
			'return_url'      => Mailpai_Smtp_Urls::tab( 'dashboard', array( 'edit' => $connection_id ) ),
			'oauth_client_id' => (string) ( $rec['oauth_client_id'] ?? '' ),
		);
		if ( ! empty( $rec['oauth_client_secret_enc'] ) ) {
			$payload['oauth_client_secret_enc'] = (string) $rec['oauth_client_secret_enc'];
		}

		return self::finalize_oauth_connection( $connection_slug, $payload, $tokens );
	}

	/**
	 * Complete OAuth after the auth.mailpai.com relay returns the authorization code.
	 *
	 * @param string $connection_slug google|microsoft.
	 * @param string $code          Authorization code from the provider (via relay).
	 * @param string $state         Opaque OAuth state token (or legacy encoded return URL).
	 * @return array|\WP_Error
	 */
	private static function handle_oauth_proxy_callback( $connection_slug, $code, $state ) {
		$connection_slug = Mailpai_Smtp_Provider_Registry::normalize_slug( (string) $connection_slug );
		$oauth_key       = self::oauth_key_for_slug( $connection_slug );
		$code            = self::sanitize_oauth_code( $code );
		$state           = self::normalize_oauth_proxy_state( (string) $state );

		if ( '' === $code || '' === $state || ! self::uses_oauth_proxy( $connection_slug ) ) {
			return new WP_Error( 'mailpai_smtp_oauth', __( 'OAuth sign-in was interrupted. Try again.', 'smtp-pai' ) );
		}

		$session = self::load_oauth_proxy_payload( $oauth_key, $state, $state );
		if ( null === $session ) {
			$parsed = self::parse_oauth_proxy_state( $state );
			if ( null === $parsed || ! self::is_allowed_proxy_return_url( $parsed['return_url'] ) ) {
				return new WP_Error( 'mailpai_smtp_oauth', __( 'OAuth return URL is invalid.', 'smtp-pai' ) );
			}

			$session = self::load_oauth_proxy_payload( $oauth_key, $parsed['session_key'], $state );
			if ( null === $session ) {
				return new WP_Error( 'mailpai_smtp_oauth', __( 'OAuth session expired. Try again.', 'smtp-pai' ) );
			}
		} else {
			$return_url = (string) ( $session['payload']['return_url'] ?? '' );
			if ( ! self::is_allowed_proxy_return_url( $return_url ) ) {
				return new WP_Error( 'mailpai_smtp_oauth', __( 'OAuth return URL is invalid.', 'smtp-pai' ) );
			}
		}

		$transient_key = $session['transient_key'];
		$payload       = $session['payload'];

		if ( (int) ( $payload['user_id'] ?? 0 ) !== get_current_user_id() ) {
			delete_transient( $transient_key );
			return new WP_Error( 'mailpai_smtp_oauth', __( 'OAuth session expired. Try again.', 'smtp-pai' ) );
		}

		$provider = self::provider( $oauth_key );
		$rec      = self::record_from_start_args( $connection_slug, $payload );
		$client   = self::client( $oauth_key, $rec );

		if ( empty( $provider ) || '' === ( $client['client_id'] ?? '' ) || '' === ( $client['client_secret'] ?? '' ) ) {
			delete_transient( $transient_key );
			return new WP_Error( 'mailpai_smtp_oauth', __( 'OAuth app credentials are missing.', 'smtp-pai' ) );
		}

		$tokens = self::exchange_code( $oauth_key, $code, $client, $provider, self::redirect_uri( $connection_slug ) );
		if ( is_wp_error( $tokens ) ) {
			delete_transient( $transient_key );
			return $tokens;
		}

		$result = self::finalize_oauth_connection( $connection_slug, $payload, $tokens );
		delete_transient( $transient_key );

		return $result;
	}

	/**
	 * Store OAuth tokens on the connection after a successful provider sign-in.
	 *
	 * @param string $connection_slug Provider slug.
	 * @param array  $payload         OAuth start payload from transient.
	 * @param array  $tokens          Token response from provider.
	 * @return array|\WP_Error
	 */
	private static function finalize_oauth_connection( $connection_slug, array $payload, array $tokens ) {
		$oauth_key = self::oauth_key_for_slug( $connection_slug );
		$provider  = self::provider( $oauth_key );

		$email = self::fetch_email( $oauth_key, (string) ( $tokens['access_token'] ?? '' ), $provider, $tokens );
		if ( is_wp_error( $email ) ) {
			return $email;
		}

		$refresh = (string) ( $tokens['refresh_token'] ?? '' );
		if ( '' === $refresh ) {
			return new WP_Error(
				'mailpai_smtp_oauth',
				__( 'No refresh token was returned. Try again and approve all requested permissions.', 'smtp-pai' )
			);
		}

		$connection_id = sanitize_key( (string) ( $payload['connection_id'] ?? '' ) );
		$existing      = '' !== $connection_id ? Mailpai_Smtp_Connection_Store::get( $connection_id ) : null;

		$record = is_array( $existing ) ? $existing : Mailpai_Smtp_Connection_Store::empty_record();
		$def    = Mailpai_Smtp_Provider_Registry::get( $connection_slug );

		$record['provider']           = $connection_slug;
		$record['auth_type']          = 'oauth';
		$record['user']               = $email;
		$record['from_email']         = $email;
		$record['from_name']          = '' !== ( $payload['from_name'] ?? '' ) ? (string) $payload['from_name'] : ( $record['from_name'] ?? get_bloginfo( 'name' ) );
		$record['secrets_storage']    = Mailpai_Smtp_Connection_Store::SECRETS_DATABASE;
		$record['secret_enc']         = '';
		$record['oauth_refresh_enc']  = $refresh;
		$record['disable_encryption'] = false;

		if ( ! empty( $payload['oauth_client_id'] ) ) {
			$record['oauth_client_id'] = sanitize_text_field( (string) $payload['oauth_client_id'] );
		} elseif ( is_array( $existing ) && ! empty( $existing['oauth_client_id'] ) ) {
			$record['oauth_client_id'] = $existing['oauth_client_id'];
		}
		if ( ! empty( $payload['oauth_client_secret'] ) ) {
			$record['oauth_client_secret_enc'] = (string) $payload['oauth_client_secret'];
		} elseif ( ! empty( $payload['oauth_client_secret_enc'] ) ) {
			$dec = Mailpai_Smtp_Crypto::decrypt( (string) $payload['oauth_client_secret_enc'] );
			if ( false !== $dec && '' !== $dec ) {
				$record['oauth_client_secret_enc'] = $dec;
			}
		} elseif ( is_array( $existing ) && ! empty( $existing['oauth_client_secret_enc'] ) ) {
			$record['oauth_client_secret_enc'] = $existing['oauth_client_secret_enc'];
		}

		if ( '' !== ( $payload['connection_name'] ?? '' ) ) {
			$record['connection_name'] = (string) $payload['connection_name'];
		} elseif ( empty( $record['connection_name'] ) && ! empty( $def['label'] ) ) {
			$record['connection_name'] = (string) $def['label'];
		}

		if ( ! empty( $def['host'] ) ) {
			$record['host'] = (string) $def['host'];
		}
		if ( 'microsoft' === $oauth_key ) {
			$consumer_host = self::microsoft_consumer_smtp_host( $email );
			if ( '' !== $consumer_host ) {
				$record['host'] = $consumer_host;
			}
		}
		if ( ! empty( $def['port'] ) ) {
			$record['port'] = (int) $def['port'];
		}
		if ( ! empty( $def['encryption'] ) ) {
			$record['encryption'] = (string) $def['encryption'];
		}

		if ( '' !== $connection_id ) {
			$record['id'] = $connection_id;
		}

		$saved = Mailpai_Smtp_Connection_Store::save( $record );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'connection_id' => $saved,
			'email'         => $email,
			'return_url'    => (string) ( $payload['return_url'] ?? Mailpai_Smtp_Urls::tab( 'dashboard', array( 'edit' => $saved ) ) ),
		);
	}

	/**
	 * @param string $oauth_key google|microsoft.
	 * @param string $refresh   Refresh token.
	 * @param array  $client    Client credentials.
	 * @return array|\WP_Error
	 */
	public static function refresh_access_token( $oauth_key, $refresh, array $client = array() ) {
		$oauth_key = sanitize_key( (string) $oauth_key );
		$provider  = self::provider( $oauth_key );

		if ( empty( $provider ) || '' === ( $client['client_id'] ?? '' ) || '' === ( $client['client_secret'] ?? '' ) ) {
			return new WP_Error( 'mailpai_smtp_oauth', __( 'OAuth credentials are missing.', 'smtp-pai' ) );
		}

		$body = array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh,
			'client_id'     => $client['client_id'],
			'client_secret' => $client['client_secret'],
		);

		$scope = self::oauth_scope_string( $oauth_key, $provider );
		if ( '' !== $scope && in_array( $oauth_key, array( 'google', 'microsoft' ), true ) ) {
			$body['scope'] = $scope;
		}

		$headers = array(
			'Content-Type' => 'application/x-www-form-urlencoded',
		);

		if ( ! empty( $provider['basic_auth'] ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $client['client_id'] . ':' . $client['client_secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			unset( $body['client_id'], $body['client_secret'] );
		}

		$response = wp_remote_post(
			$provider['token_url'],
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		return self::parse_token_response( $response );
	}

	/**
	 * @param string $oauth_key google|microsoft.
	 * @param string $code      Authorization code.
	 * @param array  $client    Client credentials.
	 * @param array  $provider  Provider config.
	 * @param string $redirect_uri Redirect URI.
	 * @return array|\WP_Error
	 */
	private static function exchange_code( $oauth_key, $code, array $client, array $provider, $redirect_uri ) {
		$body = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => $redirect_uri,
			'client_id'     => $client['client_id'],
			'client_secret' => $client['client_secret'],
		);

		$headers = array(
			'Content-Type' => 'application/x-www-form-urlencoded',
		);

		if ( ! empty( $provider['basic_auth'] ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $client['client_id'] . ':' . $client['client_secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			unset( $body['client_id'], $body['client_secret'] );
		}

		$response = wp_remote_post(
			$provider['token_url'],
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		return self::parse_token_response( $response );
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 * @return array|\WP_Error
	 */
	private static function parse_token_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$error_code = is_array( $body ) ? sanitize_key( (string) ( $body['error'] ?? '' ) ) : '';
			if ( 'redirect_uri_mismatch' === $error_code ) {
				return new WP_Error(
					'mailpai_smtp_oauth',
					__( 'The redirect URI does not match your OAuth app. Copy the Authorized Redirect URI from SMTPPai and register it exactly in your provider console.', 'smtp-pai' )
				);
			}
			$msg = is_array( $body ) ? (string) ( $body['error_description'] ?? $body['error'] ?? '' ) : '';
			if ( '' === $msg ) {
				$msg = __( 'Could not complete OAuth sign-in.', 'smtp-pai' );
			}
			return new WP_Error( 'mailpai_smtp_oauth', $msg );
		}

		return $body;
	}

	/**
	 * @param string $oauth_key    google|microsoft.
	 * @param string $access_token Access token.
	 * @param array  $provider     Provider config.
	 * @param array  $tokens       Full token response (Microsoft id_token fallback).
	 * @return string|\WP_Error
	 */
	private static function fetch_email( $oauth_key, $access_token, array $provider, array $tokens = array() ) {
		if ( 'microsoft' === $oauth_key ) {
			$email = self::microsoft_email_from_graph( $access_token, $provider );
			if ( is_email( $email ) ) {
				return $email;
			}

			$email = self::email_from_id_token( (string) ( $tokens['id_token'] ?? '' ) );
			if ( is_email( $email ) ) {
				return $email;
			}

			return new WP_Error( 'mailpai_smtp_oauth', __( 'Could not read your email address from the provider.', 'smtp-pai' ) );
		}

		$response = wp_remote_get(
			$provider['userinfo_url'],
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'mailpai_smtp_oauth', __( 'Could not read your email address from the provider.', 'smtp-pai' ) );
		}

		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'mailpai_smtp_oauth', __( 'Could not read your email address from the provider.', 'smtp-pai' ) );
		}

		return $email;
	}

	/**
	 * Read the signed-in mailbox from Microsoft Graph.
	 *
	 * @param string $access_token Access token.
	 * @param array  $provider     Provider config.
	 * @return string
	 */
	private static function microsoft_email_from_graph( $access_token, array $provider ) {
		if ( '' === $access_token ) {
			return '';
		}

		$response = wp_remote_get(
			$provider['userinfo_url'],
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return '';
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return '';
		}

		$email = sanitize_email( (string) ( $data['mail'] ?? $data['userPrincipalName'] ?? '' ) );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Extract an email address from an OpenID id_token JWT payload.
	 *
	 * @param string $id_token JWT from the OAuth token response.
	 * @return string
	 */
	private static function email_from_id_token( $id_token ) {
		$id_token = trim( (string) $id_token );
		if ( '' === $id_token ) {
			return '';
		}

		$parts = explode( '.', $id_token );
		if ( count( $parts ) < 2 ) {
			return '';
		}

		$payload = self::base64url_decode( $parts[1] );
		if ( ! is_string( $payload ) || '' === $payload ) {
			return '';
		}

		$data = json_decode( $payload, true );
		if ( ! is_array( $data ) ) {
			return '';
		}

		foreach ( array( 'email', 'preferred_username', 'upn' ) as $claim ) {
			if ( empty( $data[ $claim ] ) ) {
				continue;
			}
			$email = sanitize_email( (string) $data[ $claim ] );
			if ( is_email( $email ) ) {
				return $email;
			}
		}

		return '';
	}

	/**
	 * @param string $oauth_key google|microsoft.
	 * @param array  $provider  Provider config.
	 * @return string
	 */
	private static function oauth_scope_string( $oauth_key, array $provider ) {
		return trim( (string) ( $provider['scopes'] ?? '' ) );
	}

	/**
	 * @param string $state OAuth state token.
	 * @return string
	 */
	private static function state_key( $state ) {
		return 'mailpai_smtp_oauth_' . md5( (string) $state );
	}

	/**
	 * @param string $session_key OAuth session token (not the full state param).
	 * @return string
	 */
	private static function google_proxy_state_key( $session_key ) {
		return 'mailpai_smtp_google_proxy_' . md5( (string) $session_key );
	}

	/**
	 * @param string $provider    google|microsoft.
	 * @param string $session_key OAuth session token (not the full state param).
	 * @return string
	 */
	private static function oauth_proxy_state_key( $provider, $session_key ) {
		$provider = sanitize_key( (string) $provider );
		if ( 'google' === $provider ) {
			return self::google_proxy_state_key( $session_key );
		}
		return 'mailpai_smtp_' . $provider . '_proxy_' . md5( (string) $session_key );
	}

	/**
	 * SMTP host for personal Microsoft mailboxes (Hotmail, Outlook.com, Live).
	 *
	 * @param string $email Mailbox email address.
	 * @return string smtp-mail.outlook.com or empty for work/school mailboxes.
	 */
	public static function microsoft_consumer_smtp_host( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) {
			return '';
		}
		$domain = strtolower( (string) substr( strrchr( $email, '@' ), 1 ) );
		$consumer_domains = array( 'hotmail.com', 'outlook.com', 'live.com', 'msn.com' );
		if ( in_array( $domain, $consumer_domains, true ) ) {
			return 'smtp-mail.outlook.com';
		}
		return '';
	}

	/**
	 * URL-safe base64 encode (no +, /, or =).
	 *
	 * @param string $data Raw string.
	 * @return string
	 */
	private static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( (string) $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @param string $data URL-safe base64 string.
	 * @return string|false
	 */
	private static function base64url_decode( $data ) {
		$data = strtr( (string) $data, '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( $pad > 0 ) {
			$data .= str_repeat( '=', 4 - $pad );
		}

		return base64_decode( $data, true );
	}

	/**
	 * Restore OAuth state mangled by query-string decoding (+ becomes space).
	 *
	 * @param string $state Raw state from the request.
	 * @return string
	 */
	private static function normalize_google_proxy_state( $state ) {
		$state = trim( (string) $state );
		if ( '' === $state ) {
			return '';
		}

		if ( false === strpos( $state, '.' ) && false !== strpos( $state, ' ' ) && false === strpos( $state, '+' ) ) {
			$state = str_replace( ' ', '+', $state );
		}

		return $state;
	}

	/**
	 * @param string $state Raw state from the request.
	 * @return string
	 */
	private static function normalize_oauth_proxy_state( $state ) {
		return self::normalize_google_proxy_state( $state );
	}

	/**
	 * Parse Google proxy state into session key and return URL.
	 *
	 * Supported formats:
	 * - {token}.{base64url(return_url)} — current (relay decodes URL; WP transient keyed by token)
	 * - opaque session token — legacy (return URL only in transient)
	 * - base64url(return_url) — legacy
	 * - base64(return_url) — legacy
	 *
	 * @param string $state OAuth state parameter.
	 * @return array{return_url:string,session_key:string}|null
	 */
	private static function parse_google_proxy_state( $state ) {
		$state = self::normalize_google_proxy_state( $state );
		if ( '' === $state ) {
			return null;
		}

		if ( preg_match( '/^([A-Za-z0-9]+)\.([A-Za-z0-9_-]+)$/', $state, $matches ) ) {
			$return_url = self::base64url_decode( $matches[2] );
			if ( ! is_string( $return_url ) || '' === $return_url ) {
				return null;
			}

			return array(
				'return_url'  => $return_url,
				'session_key' => $matches[1],
			);
		}

		$return_url = self::base64url_decode( $state );
		if ( is_string( $return_url ) && '' !== $return_url ) {
			return array(
				'return_url'  => $return_url,
				'session_key' => $state,
			);
		}

		$return_url = base64_decode( $state, true );
		if ( ! is_string( $return_url ) || '' === $return_url ) {
			return null;
		}

		return array(
			'return_url'  => $return_url,
			'session_key' => $state,
		);
	}

	/**
	 * @param string $state OAuth state parameter.
	 * @return array{return_url:string,session_key:string}|null
	 */
	private static function parse_oauth_proxy_state( $state ) {
		return self::parse_google_proxy_state( $state );
	}

	/**
	 * @param string $session_key Primary session lookup key.
	 * @param string $raw_state   Raw state from the request.
	 * @return array{payload:array,transient_key:string}|null
	 */
	private static function load_google_proxy_payload( $session_key, $raw_state ) {
		return self::load_oauth_proxy_payload( 'google', $session_key, $raw_state );
	}

	/**
	 * @param string $provider    google|microsoft.
	 * @param string $session_key Primary session lookup key.
	 * @param string $raw_state   Raw state from the request.
	 * @return array{payload:array,transient_key:string}|null
	 */
	private static function load_oauth_proxy_payload( $provider, $session_key, $raw_state ) {
		$candidates = array( (string) $session_key, (string) $raw_state );
		$normalized = self::normalize_oauth_proxy_state( $raw_state );

		if ( $normalized !== $raw_state ) {
			$candidates[] = $normalized;
		}
		if ( false !== strpos( $raw_state, ' ' ) ) {
			$candidates[] = str_replace( ' ', '+', $raw_state );
		}

		$candidates = array_values( array_unique( array_filter( $candidates ) ) );

		foreach ( $candidates as $candidate ) {
			$transient_key = self::oauth_proxy_state_key( $provider, $candidate );
			$payload       = get_transient( $transient_key );
			if ( is_array( $payload ) ) {
				return array(
					'payload'       => $payload,
					'transient_key' => $transient_key,
				);
			}
		}

		return null;
	}

	/**
	 * @param string $code OAuth authorization code.
	 * @return string
	 */
	private static function sanitize_oauth_code( $code ) {
		// Strip accidental whitespace/newlines from manual paste; Microsoft codes include !, *, etc.
		$code = preg_replace( '/\s+/', '', trim( (string) $code ) );
		if ( '' === $code || strlen( $code ) > 4096 ) {
			return '';
		}

		if ( preg_match( '/^[!-~]+$/', $code ) ) {
			return $code;
		}

		return '';
	}

	/**
	 * @param string $url Decoded return URL from Google proxy state.
	 * @return bool
	 */
	private static function is_allowed_proxy_return_url( $url ) {
		$url_parts  = wp_parse_url( $url );
		$admin_url  = wp_parse_url( admin_url( 'admin.php' ) );
		$site_parts = wp_parse_url( home_url( '/' ) );

		if (
			! is_array( $url_parts )
			|| empty( $url_parts['host'] )
			|| empty( $url_parts['path'] )
			|| ! is_array( $admin_url )
			|| ! is_array( $site_parts )
		) {
			return false;
		}

		if ( strtolower( (string) $url_parts['host'] ) !== strtolower( (string) ( $site_parts['host'] ?? '' ) ) ) {
			return false;
		}

		if ( false === strpos( (string) $url_parts['path'], 'wp-admin' ) ) {
			return false;
		}

		parse_str( (string) ( $url_parts['query'] ?? '' ), $query );
		if ( empty( $query['page'] ) || Mailpai_Smtp_Urls::menu_slug() !== sanitize_key( (string) $query['page'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param string $connection_slug google|microsoft.
	 * @return string
	 */
	public static function button_label( $connection_slug ) {
		$labels = array(
			'google'    => __( 'Connect to Google', 'smtp-pai' ),
			'microsoft' => __( 'Connect to Microsoft', 'smtp-pai' ),
		);
		$slug = Mailpai_Smtp_Provider_Registry::normalize_slug( $connection_slug );
		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : __( 'Connect account', 'smtp-pai' );
	}

	/**
	 * @param string $connection_slug Provider slug.
	 * @return string
	 */
	public static function provider_display_name( $connection_slug ) {
		$labels = array(
			'google'    => __( 'Google', 'smtp-pai' ),
			'microsoft' => __( 'Microsoft', 'smtp-pai' ),
		);
		$slug = Mailpai_Smtp_Provider_Registry::normalize_slug( $connection_slug );
		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : __( 'your provider', 'smtp-pai' );
	}

	/**
	 * OAuth start URL for a saved connection that still needs provider sign-in.
	 *
	 * @param array $rec Connection record.
	 * @return string Empty when sign-in is unavailable.
	 */
	public static function connection_start_url( array $rec ) {
		$connection_id = sanitize_key( (string) ( $rec['id'] ?? '' ) );
		if ( '' === $connection_id || Mailpai_Smtp_Connection_Store::uses_oauth( $rec ) ) {
			return '';
		}

		$provider = Mailpai_Smtp_Provider_Registry::normalize_slug( (string) ( $rec['provider'] ?? '' ) );
		if ( ! Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $provider ) || ! self::is_configured( $provider, $rec ) ) {
			return '';
		}

		return self::start_url(
			$provider,
			array(
				'connection_id'   => $connection_id,
				'from_name'       => (string) ( $rec['from_name'] ?? '' ),
				'connection_name' => (string) ( $rec['connection_name'] ?? '' ),
				'return_url'      => Mailpai_Smtp_Urls::tab( 'dashboard', array( 'edit' => $connection_id ) ),
			)
		);
	}

	/**
	 * @param string $connection_slug Provider slug.
	 * @param array  $args            Optional args for state.
	 * @return string
	 */
	public static function start_url( $connection_slug, array $args = array() ) {
		$query = array(
			'page'               => Mailpai_Smtp_Urls::menu_slug(),
			'mailpai_smtp_oauth' => 'start',
			'provider'           => Mailpai_Smtp_Provider_Registry::normalize_slug( $connection_slug ),
		);
		if ( ! empty( $args['connection_id'] ) ) {
			$query['connection_id'] = sanitize_key( (string) $args['connection_id'] );
		}
		if ( ! empty( $args['from_name'] ) ) {
			$query['from_name'] = sanitize_text_field( (string) $args['from_name'] );
		}
		if ( ! empty( $args['connection_name'] ) ) {
			$query['connection_name'] = sanitize_text_field( (string) $args['connection_name'] );
		}
		if ( ! empty( $args['return_url'] ) ) {
			$query['return_url'] = esc_url_raw( (string) $args['return_url'] );
		}
		if ( ! empty( $args['oauth_client_id'] ) ) {
			$query['oauth_client_id'] = sanitize_text_field( (string) $args['oauth_client_id'] );
		}

		return wp_nonce_url( add_query_arg( $query, admin_url( 'admin.php' ) ), 'mailpai_smtp_oauth_start' );
	}

	/**
	 * Redirect to an external OAuth provider safely.
	 *
	 * @param string $url Provider authorize URL.
	 */
	public static function redirect_to_provider( $url ) {
		$host    = (string) wp_parse_url( $url, PHP_URL_HOST );
		$allowed = array(
			'accounts.google.com',
			'oauth2.googleapis.com',
			'login.microsoftonline.com',
			'graph.microsoft.com',
		);

		if ( in_array( $host, $allowed, true ) ) {
			// Do not run esc_url_raw() here — it can strip valid OAuth scope parameters.
			wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External OAuth provider allowlist.
			exit;
		}

		wp_safe_redirect( Mailpai_Smtp_Urls::tab( 'dashboard' ) );
		exit;
	}

	/**
	 * @param string $connection_id Connection id.
	 * @return string
	 */
	public static function disconnect_url( $connection_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'               => Mailpai_Smtp_Urls::menu_slug(),
					'mailpai_smtp_oauth' => 'disconnect',
					'connection_id'      => sanitize_key( (string) $connection_id ),
				),
				admin_url( 'admin.php' )
			),
			'mailpai_smtp_oauth_disconnect'
		);
	}
}
