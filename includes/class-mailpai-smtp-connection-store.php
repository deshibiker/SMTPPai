<?php
/**
 * Connection CRUD and validation.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Connection_Store
 */
class Mailpai_Smtp_Connection_Store {

	const OPTION = 'mailpai_smtp_connections';
	const META_OPTION = 'mailpai_smtp_connection_meta';
	const SECRETS_DATABASE  = 'database';
	const SECRETS_WP_CONFIG = 'wp_config';

	/** @var array<string,array>|null */
	private static $cache = null;

	/** @var array<string,array>|null */
	private static $meta_cache = null;

	/** @var bool */
	private static $suspend_route_auto_assign = false;

	/**
	 * Pause automatic One for Everything assignment (e.g. during MailPai migration).
	 *
	 * @param bool $suspend Whether to suspend auto-assign.
	 */
	public static function suspend_route_auto_assign( $suspend = true ) {
		self::$suspend_route_auto_assign = (bool) $suspend;
	}

	/**
	 * @return array<string,array>
	 */
	public static function get_all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = self::sanitize_all( $stored );
		foreach ( self::$cache as $id => $rec ) {
			self::$cache[ $id ] = self::with_runtime_meta( $rec );
		}
		return self::$cache;
	}

	/**
	 * @return array<int,array>
	 */
	public static function get_ordered() {
		$all = array_filter(
			self::get_all(),
			static function ( $rec ) {
				$slug = Mailpai_Smtp_Provider_Registry::normalize_slug( $rec['provider'] ?? '' );
				return ! empty( Mailpai_Smtp_Provider_Registry::get( $slug ) );
			}
		);
		uasort(
			$all,
			static function ( $a, $b ) {
				return (int) ( $a['sort'] ?? 0 ) <=> (int) ( $b['sort'] ?? 0 );
			}
		);
		return array_values( $all );
	}

	/**
	 * @param string $id Connection id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$id   = sanitize_key( (string) $id );
		$all  = self::get_all();
		if ( ! isset( $all[ $id ] ) ) {
			return null;
		}

		return self::with_runtime_meta( $all[ $id ] );
	}

	/**
	 * @return array<string,array>
	 */
	private static function get_meta_all() {
		if ( null !== self::$meta_cache ) {
			return self::$meta_cache;
		}

		$stored = get_option( self::META_OPTION, array() );
		self::$meta_cache = is_array( $stored ) ? $stored : array();
		return self::$meta_cache;
	}

	/**
	 * @param array $rec Connection record.
	 * @return array
	 */
	private static function with_runtime_meta( array $rec ) {
		$id = sanitize_key( (string) ( $rec['id'] ?? '' ) );
		if ( '' === $id ) {
			return $rec;
		}

		$meta = self::get_meta_all();
		if ( empty( $meta[ $id ] ) || ! is_array( $meta[ $id ] ) ) {
			return $rec;
		}

		return array_merge( $rec, $meta[ $id ] );
	}

	/**
	 * @return array
	 */
	public static function empty_record() {
		return array(
			'id'                => '',
			'sort'              => 0,
			'enabled'           => true,
			'provider'          => '',
			'connection_name'   => '',
			'from_name'         => '',
			'from_email'        => '',
			'force_from_name'   => false,
			'force_from_email'  => true,
			'secrets_storage'   => self::SECRETS_DATABASE,
			'last_status'       => '',
			'last_test_at'      => 0,
			'last_sent_at'      => 0,
			'last_error'        => '',
			'aws_region'        => 'us-east-1',
			'aws_access_key_id' => '',
			'aws_secret_enc'    => '',
			'api_key_enc'       => '',
			'api_secret_enc'    => '',
			'host'              => '',
			'port'              => 587,
			'encryption'        => 'tls',
			'disable_encryption'=> false,
			'disable_secret_encryption' => false,
			'user'              => '',
			'secret_enc'        => '',
			'auth_type'         => '',
			'oauth_refresh_enc' => '',
			'oauth_client_id'   => '',
			'oauth_client_secret_enc' => '',
			'meta'              => array(),
			'ses_dns_snapshot'  => null,
			'ses_dns_check'     => null,
		);
	}

	/**
	 * @param array $record Connection data.
	 * @return string|\WP_Error Connection id.
	 */
	public static function save( array $record ) {
		$all = self::get_all();
		$id  = isset( $record['id'] ) ? sanitize_key( (string) $record['id'] ) : '';

		if ( '' === $id || ! isset( $all[ $id ] ) ) {
			$id             = self::generate_id( $all );
			$record['sort'] = self::next_sort( $all );
		} else {
			$record['sort'] = isset( $all[ $id ]['sort'] ) ? (int) $all[ $id ]['sort'] : self::next_sort( $all );
		}

		$existing = isset( $all[ $id ] ) ? $all[ $id ] : self::empty_record();
		$merged   = array_merge( $existing, $record );
		$merged['id'] = $id;

		$provider = Mailpai_Smtp_Provider_Registry::get( $merged['provider'] ?? '' );
		if ( empty( $provider ) ) {
			return new WP_Error( 'mailpai_smtp_provider', __( 'Choose a valid email service provider.', 'smtp-pai' ) );
		}

		$merged['from_email'] = sanitize_email( $merged['from_email'] ?? '' );
		if ( ! is_email( $merged['from_email'] ) ) {
			if ( Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $merged['provider'] ?? '' ) && ! self::uses_oauth( $merged ) ) {
				$merged['from_email'] = '';
			} else {
				return new WP_Error( 'mailpai_smtp_from', __( 'Enter a valid From email address.', 'smtp-pai' ) );
			}
		}

		$merged['from_name']       = sanitize_text_field( $merged['from_name'] ?? get_bloginfo( 'name' ) );
		$merged['connection_name'] = sanitize_text_field( $merged['connection_name'] ?? '' );
		if ( '' === $merged['connection_name'] ) {
			$merged['connection_name'] = $provider['label'];
		}

		$secrets_st = isset( $merged['secrets_storage'] ) ? sanitize_key( (string) $merged['secrets_storage'] ) : self::SECRETS_DATABASE;
		$merged['secrets_storage'] = ( self::SECRETS_WP_CONFIG === $secrets_st ) ? self::SECRETS_WP_CONFIG : self::SECRETS_DATABASE;

		if ( Mailpai_Smtp_Provider_Registry::uses_mailbox_smtp( $merged ) ) {
			$merged['disable_encryption'] = false;
			if ( '' === trim( (string) ( $merged['user'] ?? '' ) ) ) {
				$merged['user'] = $merged['from_email'];
			}
			if ( Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $merged['provider'] ?? '' ) ) {
				if ( self::SECRETS_WP_CONFIG === $merged['secrets_storage'] && ! self::wp_config_oauth_ready( $merged['provider'] ) ) {
					$merged['secrets_storage'] = self::SECRETS_DATABASE;
				}
			} elseif ( self::SECRETS_WP_CONFIG === $merged['secrets_storage'] && ! self::wp_config_smtp_ready() ) {
				$merged['secrets_storage'] = self::SECRETS_DATABASE;
			}
		}

		$sanitized = self::sanitize_record( $merged, $existing );
		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}

		$all[ $id ] = $sanitized;
		self::persist( $all );

		if ( ! self::$suspend_route_auto_assign ) {
			Mailpai_Smtp_Routes::maybe_auto_assign_use_one( $id );
		}

		return $id;
	}

	/**
	 * Whether wp-config SMTP constants are defined.
	 *
	 * @return bool
	 */
	public static function wp_config_smtp_ready() {
		foreach ( array( 'MAILPAI_SMTP_HOST', 'MAILPAI_SMTP_USER', 'MAILPAI_SMTP_PASSWORD' ) as $constant ) {
			if ( ! defined( $constant ) || '' === trim( (string) constant( $constant ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether wp-config OAuth client constants are defined for a provider.
	 *
	 * @param string $provider_slug google|microsoft.
	 * @return bool
	 */
	public static function wp_config_oauth_ready( $provider_slug ) {
		$keys = Mailpai_Smtp_Provider_Registry::oauth_wp_config_keys( $provider_slug );
		foreach ( $keys as $constant ) {
			if ( ! defined( $constant ) || '' === trim( (string) constant( $constant ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $id Connection id.
	 * @return bool
	 */
	public static function delete( $id ) {
		$id  = sanitize_key( (string) $id );
		$all = self::get_all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		unset( $all[ $id ] );
		self::persist( $all );
		return true;
	}

	/**
	 * @param string $id     Connection id.
	 * @param array  $patch  Fields to merge.
	 */
	public static function patch( $id, array $patch ) {
		$id  = sanitize_key( (string) $id );
		$all = self::get_all();
		if ( ! isset( $all[ $id ] ) ) {
			return;
		}
		$all[ $id ] = array_merge( $all[ $id ], $patch );
		self::persist( $all );
	}

	/**
	 * Update runtime send metadata without rewriting connection secrets.
	 *
	 * @param string $id    Connection id.
	 * @param array  $patch Meta fields.
	 */
	public static function patch_meta( $id, array $patch ) {
		$id = sanitize_key( (string) $id );
		if ( '' === $id ) {
			return;
		}

		$allowed = array( 'last_status', 'last_error', 'last_test_at', 'last_sent_at' );
		$clean   = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $patch ) ) {
				continue;
			}
			if ( in_array( $key, array( 'last_test_at', 'last_sent_at' ), true ) ) {
				$clean[ $key ] = absint( $patch[ $key ] );
			} else {
				$clean[ $key ] = sanitize_text_field( (string) $patch[ $key ] );
			}
		}

		if ( empty( $clean ) ) {
			return;
		}

		$meta         = self::get_meta_all();
		$meta[ $id ]  = array_merge( isset( $meta[ $id ] ) && is_array( $meta[ $id ] ) ? $meta[ $id ] : array(), $clean );
		self::$meta_cache = $meta;
		update_option( self::META_OPTION, $meta, false );

		if ( is_array( self::$cache ) && isset( self::$cache[ $id ] ) ) {
			self::$cache[ $id ] = array_merge( self::$cache[ $id ], $clean );
		}
	}

	/**
	 * @param string $id Connection id.
	 * @return string
	 */
	public static function title( $id ) {
		$rec = is_array( $id ) ? $id : self::get( $id );
		if ( ! is_array( $rec ) ) {
			return '';
		}
		if ( ! empty( $rec['connection_name'] ) ) {
			return (string) $rec['connection_name'];
		}
		$def = Mailpai_Smtp_Provider_Registry::get( $rec['provider'] ?? '' );
		return ! empty( $def['label'] ) ? (string) $def['label'] : __( 'Connection', 'smtp-pai' );
	}

	/**
	 * @param array $all Existing connections.
	 * @return string
	 */
	private static function generate_id( array $all ) {
		do {
			$id = 'c' . wp_generate_password( 8, false, false );
		} while ( isset( $all[ $id ] ) );
		return $id;
	}

	/**
	 * @param array $all Connections.
	 * @return int
	 */
	private static function next_sort( array $all ) {
		$max = 0;
		foreach ( $all as $rec ) {
			$max = max( $max, (int) ( $rec['sort'] ?? 0 ) );
		}
		return $max + 10;
	}

	/**
	 * @param array $stored Raw option.
	 * @return array<string,array>
	 */
	private static function sanitize_all( array $stored ) {
		$out = array();
		foreach ( $stored as $key => $rec ) {
			if ( ! is_array( $rec ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $rec['id'] ?? $key ) );
			if ( '' === $id ) {
				continue;
			}
			$sanitized = self::sanitize_record_read( array_merge( self::empty_record(), $rec ) );
			if ( is_wp_error( $sanitized ) ) {
				continue;
			}
			$out[ $id ] = $sanitized;
			$out[ $id ]['id'] = $id;
		}
		return $out;
	}

	/**
	 * @param array $rec Incoming record.
	 * @return array|\WP_Error
	 */
	private static function sanitize_record_read( array $rec ) {
		return self::sanitize_record( $rec, self::empty_record(), false );
	}

	/**
	 * @param array $rec      Incoming record.
	 * @param array $existing Previous record for secret retention.
	 * @param bool  $encrypt  Whether to encrypt changed secrets.
	 * @return array|\WP_Error
	 */
	private static function sanitize_record( array $rec, array $existing, $encrypt = true ) {
		$base = self::empty_record();
		$out  = array_merge( $base, $rec );

		$out['enabled']    = ! empty( $rec['enabled'] );
		$out['provider']   = Mailpai_Smtp_Provider_Registry::normalize_slug( (string) ( $rec['provider'] ?? '' ) );

		if ( Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $out['provider'] ) ) {
			$def = Mailpai_Smtp_Provider_Registry::get( $out['provider'] );
			if ( ! empty( $def['host'] ) ) {
				$out['host'] = (string) $def['host'];
			}
			if ( ! empty( $def['port'] ) ) {
				$out['port'] = (int) $def['port'];
			}
			if ( ! empty( $def['encryption'] ) ) {
				$out['encryption'] = (string) $def['encryption'];
			}
			unset( $out['meta']['oauth_one_click'] );
			if ( self::SECRETS_WP_CONFIG === $out['secrets_storage'] && ! self::wp_config_oauth_ready( $out['provider'] ) ) {
				$out['secrets_storage'] = self::SECRETS_DATABASE;
			}
		}
		$out['port']       = absint( $rec['port'] ?? 587 );
		$out['encryption'] = in_array( $rec['encryption'] ?? 'tls', array( 'tls', 'ssl', '' ), true ) ? (string) $rec['encryption'] : 'tls';
		$out['disable_encryption'] = ! empty( $rec['disable_encryption'] );
		$out['disable_secret_encryption'] = ! empty( $rec['disable_secret_encryption'] );
		$out['force_from_name']  = ! empty( $rec['force_from_name'] );
		$out['force_from_email'] = array_key_exists( 'force_from_email', $rec ) ? ! empty( $rec['force_from_email'] ) : true;
		$out['aws_region'] = sanitize_text_field( $rec['aws_region'] ?? 'us-east-1' );
		$out['auth_type']  = in_array( $rec['auth_type'] ?? '', array( 'oauth', 'password' ), true ) ? (string) $rec['auth_type'] : '';
		$out['oauth_client_id'] = sanitize_text_field( $rec['oauth_client_id'] ?? '' );
		if ( is_array( $rec['meta'] ?? null ) ) {
			$out['meta'] = array_merge( $base['meta'], $rec['meta'] );
		}

		$out = self::merge_ses_dns_fields( $out, $rec, $existing );

		if ( self::SECRETS_WP_CONFIG === $out['secrets_storage'] ) {
			return $out;
		}

		$secret_fields = array( 'aws_secret_enc', 'api_key_enc', 'api_secret_enc', 'secret_enc', 'oauth_refresh_enc', 'oauth_client_secret_enc' );
		foreach ( $secret_fields as $field ) {
			if ( isset( $rec[ $field ] ) && '' !== $rec[ $field ] ) {
				$plain = (string) $rec[ $field ];
				if ( ! $encrypt ) {
					$out[ $field ] = $plain;
					continue;
				}
				if ( $out['disable_secret_encryption'] ) {
					$out[ $field ] = $plain;
				} elseif ( ! empty( $existing[ $field ] ) && hash_equals( (string) $existing[ $field ], $plain ) ) {
					$out[ $field ] = $plain;
				} elseif ( Mailpai_Smtp_Crypto::is_encrypted_payload( $plain ) ) {
					$out[ $field ] = $plain;
				} else {
					$enc = Mailpai_Smtp_Crypto::encrypt( $plain );
					if ( ! $enc && '' === (string) ( $existing[ $field ] ?? '' ) ) {
						return new WP_Error(
							'mailpai_smtp_secret',
							__( 'Password could not be saved securely. Check that OpenSSL is enabled in PHP and try again.', 'smtp-pai' )
						);
					}
					$out[ $field ] = $enc ? $enc : ( $existing[ $field ] ?? '' );
				}
			} else {
				$out[ $field ] = $existing[ $field ] ?? '';
			}
		}

		return $out;
	}

	/**
	 * Preserve SES DNS snapshot/check data on read and save.
	 *
	 * @param array $out      Sanitized record.
	 * @param array $rec      Incoming record.
	 * @param array $existing Previous record.
	 * @return array
	 */
	private static function merge_ses_dns_fields( array $out, array $rec, array $existing ) {
		if ( array_key_exists( 'ses_dns_snapshot', $rec ) ) {
			$out['ses_dns_snapshot'] = self::sanitize_ses_dns_snapshot( $rec['ses_dns_snapshot'] );
		} else {
			$out['ses_dns_snapshot'] = isset( $existing['ses_dns_snapshot'] ) ? $existing['ses_dns_snapshot'] : null;
		}

		if ( array_key_exists( 'ses_dns_check', $rec ) ) {
			$out['ses_dns_check'] = self::sanitize_ses_dns_check( $rec['ses_dns_check'] );
		} else {
			$out['ses_dns_check'] = isset( $existing['ses_dns_check'] ) ? $existing['ses_dns_check'] : null;
		}

		return $out;
	}

	/**
	 * @param mixed $snapshot Raw snapshot.
	 * @return array|null
	 */
	private static function sanitize_ses_dns_snapshot( $snapshot ) {
		if ( null === $snapshot || ! is_array( $snapshot ) ) {
			return null;
		}

		$records = array();
		if ( ! empty( $snapshot['records'] ) && is_array( $snapshot['records'] ) ) {
			foreach ( $snapshot['records'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$records[] = array(
					'name'  => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
					'type'  => sanitize_text_field( (string) ( $row['type'] ?? '' ) ),
					'value' => sanitize_text_field( (string) ( $row['value'] ?? '' ) ),
				);
			}
		}

		$tokens = array();
		if ( ! empty( $snapshot['dkim_tokens'] ) && is_array( $snapshot['dkim_tokens'] ) ) {
			foreach ( $snapshot['dkim_tokens'] as $token ) {
				$token = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );
				if ( '' !== $token ) {
					$tokens[] = $token;
				}
			}
		}

		return array(
			'api_identity'        => sanitize_text_field( (string) ( $snapshot['api_identity'] ?? '' ) ),
			'verification_status' => sanitize_text_field( (string) ( $snapshot['verification_status'] ?? '' ) ),
			'dkim_tokens'         => $tokens,
			'records'             => $records,
			'domain_for_checks'   => sanitize_text_field( (string) ( $snapshot['domain_for_checks'] ?? '' ) ),
			'fetched_at'          => absint( $snapshot['fetched_at'] ?? 0 ),
		);
	}

	/**
	 * @param mixed $check Raw check result.
	 * @return array|null
	 */
	private static function sanitize_ses_dns_check( $check ) {
		if ( null === $check || ! is_array( $check ) ) {
			return null;
		}

		$rows = array();
		if ( ! empty( $check['rows'] ) && is_array( $check['rows'] ) ) {
			foreach ( $check['rows'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
				if ( ! in_array( $status, array( 'pass', 'warn', 'pending', 'fail' ), true ) ) {
					$status = 'pending';
				}
				$rows[] = array(
					'key'     => sanitize_text_field( (string) ( $row['key'] ?? '' ) ),
					'label'   => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
					'status'  => $status,
					'message' => sanitize_text_field( (string) ( $row['message'] ?? '' ) ),
				);
			}
		}

		return array(
			'checked_at' => absint( $check['checked_at'] ?? 0 ),
			'rows'       => $rows,
		);
	}

	/**
	 * @param array<string,array> $all Connections.
	 */
	private static function persist( array $all ) {
		$all = self::sanitize_all( $all );
		update_option( self::OPTION, $all, false );
		self::$cache = $all;
	}

	/**
	 * Flush in-memory cache.
	 */
	public static function flush_cache() {
		self::$cache      = null;
		self::$meta_cache = null;
	}

	/**
	 * Placeholder for password inputs when a secret is already stored.
	 *
	 * @param mixed $stored Encrypted or plain stored secret.
	 * @return string
	 */
	public static function stored_secret_placeholder( $stored ) {
		return '' !== trim( (string) $stored )
			? __( '•••••••• (saved)', 'smtp-pai' )
			: '';
	}

	/**
	 * @param array|null $rec Connection record.
	 * @return string
	 */
	public static function secrets_storage( $rec = null ) {
		if ( is_array( $rec ) && self::SECRETS_WP_CONFIG === ( $rec['secrets_storage'] ?? '' ) ) {
			return self::SECRETS_WP_CONFIG;
		}
		return self::SECRETS_DATABASE;
	}

	/**
	 * Whether a connection uses OAuth instead of a password.
	 *
	 * @param array|null $rec Connection record.
	 * @return bool
	 */
	public static function uses_oauth( $rec ) {
		if ( ! is_array( $rec ) ) {
			return false;
		}
		if ( 'oauth' === ( $rec['auth_type'] ?? '' ) && '' !== ( $rec['oauth_refresh_enc'] ?? '' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether an OAuth mailbox connection still needs provider sign-in.
	 *
	 * @param array|null $rec Connection record.
	 * @return bool
	 */
	public static function needs_oauth_signin( $rec ) {
		if ( ! is_array( $rec ) ) {
			return false;
		}
		if ( ! Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $rec['provider'] ?? '' ) ) {
			return false;
		}

		return ! self::uses_oauth( $rec );
	}

	/**
	 * Enabled connections for dropdowns.
	 *
	 * @return array<string,string> id => title
	 */
	public static function choices() {
		$out = array();
		foreach ( self::get_ordered() as $rec ) {
			if ( empty( $rec['enabled'] ) ) {
				continue;
			}
			$out[ $rec['id'] ] = self::title( $rec );
		}
		return $out;
	}

	/**
	 * Provider logos for connection dropdowns (Specify Connections).
	 *
	 * @return array<string,string> id => logo URL
	 */
	public static function choice_logos() {
		$out = array( '' => '' );
		foreach ( self::get_ordered() as $rec ) {
			if ( empty( $rec['enabled'] ) ) {
				continue;
			}
			$slug = Mailpai_Smtp_Provider_Registry::normalize_slug( $rec['provider'] ?? '' );
			$def  = Mailpai_Smtp_Provider_Registry::get( $slug );
			$out[ $rec['id'] ] = ! empty( $def['logo'] )
				? Mailpai_Smtp_Provider_Registry::logo_url( $def['logo'] )
				: '';
		}
		return $out;
	}
}
