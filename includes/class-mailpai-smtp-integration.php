<?php
/**
 * MailPai marketing plugin integration helpers.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Integration
 */
class Mailpai_Smtp_Integration {

	const MIGRATION_FLAG = 'mailpai_smtp_migrated_from_mailpai';

	/**
	 * Map MailPai purpose tags to SMTPPai route slugs.
	 *
	 * @param string $purpose newsletter|transactional|outreach.
	 * @return string
	 */
	public static function purpose_to_route( $purpose ) {
		$purpose = sanitize_key( (string) $purpose );
		$map     = array(
			'newsletter'    => 'newsletter',
			'outreach'      => 'outreach',
			'transactional' => 'wordpress',
		);

		return isset( $map[ $purpose ] ) ? $map[ $purpose ] : '';
	}

	/**
	 * @param string $route Route slug.
	 * @return bool
	 */
	public static function is_route_ready( $route ) {
		$route = sanitize_key( (string) $route );
		if ( '' === $route ) {
			return false;
		}

		$conn_id = Mailpai_Smtp_Routes::get_connection_id( $route );
		if ( '' === $conn_id ) {
			return false;
		}

		$rec = Mailpai_Smtp_Connection_Store::get( $conn_id );
		return is_array( $rec ) && ! empty( $rec['enabled'] );
	}

	/**
	 * Whether newsletter delivery is configured (minimum for MailPai campaigns).
	 *
	 * @return bool
	 */
	public static function is_sending_ready() {
		return self::is_route_ready( 'newsletter' );
	}

	/**
	 * @param string $route Route slug.
	 * @return string
	 */
	public static function route_connection_title( $route ) {
		$conn_id = Mailpai_Smtp_Routes::get_connection_id( sanitize_key( (string) $route ) );
		if ( '' === $conn_id ) {
			return '';
		}

		$rec = Mailpai_Smtp_Connection_Store::get( $conn_id );
		return is_array( $rec ) ? Mailpai_Smtp_Connection_Store::title( $rec ) : '';
	}

	/**
	 * Summary rows for MailPai Connection tab (purpose label => connection title).
	 *
	 * @return array<string,array{label:string,title:string,ready:bool}>
	 */
	public static function mailpai_delivery_summary() {
		$rows = array(
			'newsletter' => array(
				'label' => __( 'Newsletter', 'smtp-pai' ),
				'route' => 'newsletter',
			),
			'transactional' => array(
				'label' => __( 'Transactional', 'smtp-pai' ),
				'route' => 'wordpress',
			),
			'outreach' => array(
				'label' => __( 'Outreach', 'smtp-pai' ),
				'route' => 'outreach',
			),
		);

		$out = array();
		foreach ( $rows as $key => $row ) {
			$title = self::route_connection_title( $row['route'] );
			$out[ $key ] = array(
				'label' => $row['label'],
				'title' => $title,
				'ready' => '' !== $title,
			);
		}

		if ( class_exists( 'WooCommerce' ) ) {
			$wc_title = self::route_connection_title( 'woocommerce' );
			if ( '' !== $wc_title ) {
				$out['woocommerce'] = array(
					'label' => __( 'WooCommerce', 'smtp-pai' ),
					'title' => $wc_title,
					'ready' => true,
				);
			}
		}

		return $out;
	}

	/**
	 * Build a legacy-style config array for MailPai callers.
	 *
	 * @param string $purpose newsletter|transactional|outreach.
	 * @return array|\WP_Error
	 */
	public static function legacy_config_for_purpose( $purpose ) {
		$route = self::purpose_to_route( $purpose );
		if ( '' === $route ) {
			return new WP_Error( 'mailpai_smtp_route', __( 'Unknown mail purpose.', 'smtp-pai' ) );
		}

		$conn_id = Mailpai_Smtp_Routes::get_connection_id( $route );
		if ( '' === $conn_id ) {
			return new WP_Error(
				'mailpai_no_connection',
				sprintf(
					/* translators: %s: route label */
					__( 'No SMTPPai connection is assigned for %s email. Open SMTPPai → Mail Routes.', 'smtp-pai' ),
					Mailpai_Smtp_Routes::label( $route )
				)
			);
		}

		$rec = Mailpai_Smtp_Connection_Store::get( $conn_id );
		if ( ! is_array( $rec ) || empty( $rec['enabled'] ) ) {
			return new WP_Error( 'mailpai_smtp_connection', __( 'The assigned SMTPPai connection is disabled.', 'smtp-pai' ) );
		}

		$config = Mailpai_Smtp_Mailer::build_config( $rec );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$provider = Mailpai_Smtp_Provider_Registry::normalize_slug( $rec['provider'] ?? '' );
		$legacy   = array(
			'_id'        => $conn_id,
			'from_name'  => (string) ( $config['from_name'] ?? '' ),
			'from_email' => (string) ( $config['from_email'] ?? '' ),
			'transport'  => (string) ( $config['transport'] ?? 'smtp' ),
		);

		if ( 'amazon_ses' === $provider || 'ses_api' === ( $config['api_driver'] ?? '' ) ) {
			$legacy['transport']  = 'ses_api';
			$legacy['mode']       = 'ses_api';
			$legacy['region']     = (string) ( $config['region'] ?? $rec['aws_region'] ?? '' );
			$legacy['aws_region'] = $legacy['region'];
		}

		return $legacy;
	}

	/**
	 * Migrate legacy MailPai connections into SMTPPai once.
	 *
	 * @return void
	 */
	public static function maybe_migrate_mailpai_connections() {
		if ( ! defined( 'MAILPAI_VERSION' ) ) {
			return;
		}

		if ( get_option( self::MIGRATION_FLAG, false ) ) {
			return;
		}

		$stored = get_option( 'mailpai_connections', null );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			update_option( self::MIGRATION_FLAG, 1, false );
			return;
		}

		if ( ! class_exists( 'Mailpai_Mailer' ) || ! class_exists( 'Mailpai_Crypto' ) ) {
			return;
		}

		$records = Mailpai_Mailer::get_connections_ordered();
		if ( empty( $records ) ) {
			update_option( self::MIGRATION_FLAG, 1, false );
			return;
		}

		$routes       = Mailpai_Smtp_Routes::get_all();
		$purpose_map  = array(
			'newsletter'    => array( 'marketing', 'newsletter' ),
			'outreach'      => array( 'marketing', 'outreach' ),
			'transactional' => array( 'transactional', 'wordpress' ),
		);

		Mailpai_Smtp_Connection_Store::suspend_route_auto_assign( true );

		foreach ( $records as $rec ) {
			if ( empty( $rec['enabled'] ) ) {
				continue;
			}

			$smtp_rec = self::convert_mailpai_record( $rec );
			if ( empty( $smtp_rec ) ) {
				continue;
			}

			$saved_id = Mailpai_Smtp_Connection_Store::save( $smtp_rec );
			if ( is_wp_error( $saved_id ) ) {
				continue;
			}

			$purposes = isset( $rec['purposes'] ) && is_array( $rec['purposes'] ) ? $rec['purposes'] : array();
			foreach ( $purposes as $purpose ) {
				$purpose = sanitize_key( (string) $purpose );
				if ( ! isset( $purpose_map[ $purpose ] ) ) {
					continue;
				}
				list( $bucket, $key ) = $purpose_map[ $purpose ];
				if ( empty( $routes[ $bucket ][ $key ] ) ) {
					$routes[ $bucket ][ $key ] = $saved_id;
				}
			}

			if ( in_array( 'transactional', $purposes, true ) && class_exists( 'WooCommerce' ) && empty( $routes['transactional']['woocommerce'] ) ) {
				$routes['transactional']['woocommerce'] = $saved_id;
			}
		}

		Mailpai_Smtp_Routes::save( $routes );
		Mailpai_Smtp_Connection_Store::suspend_route_auto_assign( false );
		update_option( self::MIGRATION_FLAG, 1, false );
	}

	/**
	 * @param array $rec MailPai connection record.
	 * @return array
	 */
	private static function convert_mailpai_record( array $rec ) {
		$from_email = sanitize_email( $rec['from_email'] ?? '' );
		$transport  = sanitize_key( (string) ( $rec['transport'] ?? '' ) );

		$base = array(
			'enabled'         => true,
			'connection_name' => sanitize_text_field( $rec['connection_name'] ?? '' ),
			'from_name'       => sanitize_text_field( $rec['from_name'] ?? get_bloginfo( 'name' ) ),
			'from_email'      => $from_email,
			'secrets_storage' => Mailpai_Smtp_Connection_Store::SECRETS_DATABASE,
		);

		if ( 'ses_api' === $transport ) {
			$secret = '';
			if ( ! empty( $rec['aws_secret_enc'] ) && class_exists( 'Mailpai_Crypto' ) ) {
				$secret = Mailpai_Crypto::decrypt( $rec['aws_secret_enc'] );
			}
			if ( ! is_string( $secret ) ) {
				$secret = '';
			}

			return array_merge(
				$base,
				array(
					'provider'          => 'amazon_ses',
					'aws_region'        => sanitize_text_field( $rec['aws_region'] ?? 'us-east-1' ),
					'aws_access_key_id' => sanitize_text_field( $rec['aws_access_key_id'] ?? '' ),
					'aws_secret_enc'    => $secret,
				)
			);
		}

		if ( 'smtp' === $transport ) {
			$secret = '';
			if ( ! empty( $rec['secret_enc'] ) && class_exists( 'Mailpai_Crypto' ) ) {
				$secret = Mailpai_Crypto::decrypt( $rec['secret_enc'] );
			}
			if ( ! is_string( $secret ) ) {
				$secret = '';
			}

			return array_merge(
				$base,
				array(
					'provider'    => 'other_smtp',
					'host'        => sanitize_text_field( $rec['host'] ?? '' ),
					'port'        => absint( $rec['port'] ?? 587 ),
					'encryption'  => sanitize_key( (string) ( $rec['encryption'] ?? 'tls' ) ),
					'user'        => sanitize_text_field( $rec['user'] ?? '' ),
					'secret_enc'  => $secret,
				)
			);
		}

		return array();
	}

	/**
	 * Plugin file path relative to plugins directory.
	 *
	 * @return string
	 */
	public static function plugin_basename() {
		if ( defined( 'MAILPAI_SMTP_PLUGIN_BASENAME' ) ) {
			return MAILPAI_SMTP_PLUGIN_BASENAME;
		}

		$candidates = array(
			'smtp-pai/smtp-pai.php',
			'smtp-pai/smtppai.php',
			'smtppai/smtppai.php',
			'mailpai-smtp/mailpai-smtp.php',
		);

		foreach ( $candidates as $basename ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $basename ) ) {
				return $basename;
			}
		}

		return 'smtp-pai/smtp-pai.php';
	}

	/**
	 * @return bool
	 */
	public static function is_plugin_installed() {
		return file_exists( WP_PLUGIN_DIR . '/' . self::plugin_basename() );
	}

	/**
	 * @return bool
	 */
	public static function is_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( self::plugin_basename() );
	}

	/**
	 * @return string Admin URL to activate or open SMTPPai.
	 */
	public static function admin_action_url() {
		if ( self::is_plugin_active() ) {
			return Mailpai_Smtp_Urls::tab( 'dashboard' );
		}

		if ( self::is_plugin_installed() ) {
			return wp_nonce_url(
				admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( self::plugin_basename() ) ),
				'activate-plugin_' . self::plugin_basename()
			);
		}

		return admin_url( 'plugin-install.php?s=' . rawurlencode( defined( 'MAILPAI_SMTP_ORG_SLUG' ) ? MAILPAI_SMTP_ORG_SLUG : 'smtp-pai' ) . '&tab=search&type=term' );
	}

	/**
	 * @return string
	 */
	public static function admin_action_label() {
		if ( self::is_plugin_active() ) {
			return __( 'Open SMTPPai', 'smtp-pai' );
		}
		if ( self::is_plugin_installed() ) {
			return __( 'Activate SMTPPai', 'smtp-pai' );
		}

		return __( 'Install SMTPPai', 'smtp-pai' );
	}
}
