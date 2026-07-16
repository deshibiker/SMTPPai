<?php
/**
 * Admin UI and POST handlers.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Admin
 */
class Mailpai_Smtp_Admin {

	/** @var self|null */
	private static $instance = null;

	/** @var string */
	private $wizard_provider = '';

	/** @var string */
	private $wizard_step = '';

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), $this->register_as_mailpai_submenu() ? 11 : 10 );
		add_action( 'admin_menu', array( $this, 'remove_duplicate_submenu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
		add_action( 'admin_init', array( $this, 'handle_oauth' ) );
		add_action( 'admin_init', array( $this, 'handle_request' ) );
		add_action( 'mailpai_smtp_routes_auto_assigned', array( $this, 'queue_routes_auto_assigned_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_show_routes_auto_assigned_notice' ), 4 );
		add_action( 'wp_ajax_mailpai_smtp_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_mailpai_smtp_log_view', array( $this, 'ajax_log_view' ) );
		add_action( 'wp_ajax_mailpai_smtp_retry_log', array( $this, 'ajax_retry_log' ) );
	}

	/**
	 * @param string $classes Space-separated admin body classes.
	 * @return string
	 */
	public function admin_body_class( $classes ) {
		if ( Mailpai_Smtp_Urls::is_admin_screen() ) {
			$classes .= ' mailpai-smtp-admin-page';
		}
		return $classes;
	}

	/**
	 * Whether SMTP should appear under the MailPai top-level menu.
	 *
	 * @return bool
	 */
	private function register_as_mailpai_submenu() {
		/**
		 * Register SMTPPai as a MailPai submenu instead of its own top-level item.
		 *
		 * @param bool $register_as_submenu Default true when MailPai is active.
		 */
		return (bool) apply_filters( 'mailpai_smtp_register_as_submenu', defined( 'MAILPAI_VERSION' ) );
	}

	/**
	 * Register menu.
	 */
	public function menu() {
		$slug = Mailpai_Smtp_Urls::menu_slug();

		if ( $this->register_as_mailpai_submenu() ) {
			add_submenu_page(
				'mailpai',
				MAILPAI_SMTP_BRAND_NAME,
				MAILPAI_SMTP_BRAND_NAME,
				Mailpai_Smtp_Capabilities::manage_cap(),
				$slug,
				array( $this, 'render' )
			);
			return;
		}

		add_menu_page(
			MAILPAI_SMTP_BRAND_NAME,
			MAILPAI_SMTP_BRAND_NAME,
			Mailpai_Smtp_Capabilities::manage_cap(),
			$slug,
			array( $this, 'render' ),
			'dashicons-email-alt',
			58
		);
	}

	/**
	 * Remove the auto-created duplicate submenu entry on standalone top-level menu.
	 */
	public function remove_duplicate_submenu() {
		if ( $this->register_as_mailpai_submenu() ) {
			return;
		}

		$slug = Mailpai_Smtp_Urls::menu_slug();
		remove_submenu_page( $slug, $slug );
	}

	/**
	 * @param string $hook Hook suffix.
	 */
	public function assets( $hook ) {
		if ( ! Mailpai_Smtp_Urls::is_admin_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'mailpai-smtp-admin',
			MAILPAI_SMTP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			MAILPAI_SMTP_VERSION
		);

		wp_enqueue_script(
			'mailpai-smtp-admin',
			MAILPAI_SMTP_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			MAILPAI_SMTP_VERSION,
			true
		);

		wp_localize_script(
			'mailpai-smtp-admin',
			'mailpaiSmtpAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mailpai_smtp_admin' ),
				'adminEmail' => sanitize_email( get_option( 'admin_email' ) ),
				'i18n'    => array(
					'confirmDelete' => __( 'Delete this log entry?', 'smtp-pai' ),
					'sending'       => __( 'Sending…', 'smtp-pai' ),
					'testing'       => __( 'Testing…', 'smtp-pai' ),
					/* translators: %s: connection name */
					'testModalTitle' => __( 'Send test email — %s', 'smtp-pai' ),
					'testModalTitleDefault' => __( 'Send test email', 'smtp-pai' ),
					'testSent'      => __( 'Test email sent.', 'smtp-pai' ),
					/* translators: %s: connection name */
					'testSentFor'   => __( 'Test email sent for %s.', 'smtp-pai' ),
					/* translators: 1: connection name, 2: recipient email address */
					'testSentForTo' => __( 'Test email sent for %1$s to %2$s.', 'smtp-pai' ),
					'testSuccess'   => __( 'Success', 'smtp-pai' ),
					'testFailed'    => __( 'Test failed.', 'smtp-pai' ),
					/* translators: %s: connection name */
					'testFailedFor' => __( 'Test failed for %s.', 'smtp-pai' ),
					'connectAccount' => __( 'Connect account', 'smtp-pai' ),
					'invalidEmail'  => __( 'Enter a valid email address.', 'smtp-pai' ),
					'connectionStatus' => array(
						'working'  => __( 'Working', 'smtp-pai' ),
						'failed'   => __( 'Failed', 'smtp-pai' ),
						'untested' => __( 'Not tested', 'smtp-pai' ),
						'disabled' => __( 'Disabled', 'smtp-pai' ),
					),
					'logDetail'     => array(
						'overview'          => __( 'Overview', 'smtp-pai' ),
						'envelope'          => __( 'Envelope', 'smtp-pai' ),
						'delivery'          => __( 'Delivery', 'smtp-pai' ),
						'headers'           => __( 'Headers', 'smtp-pai' ),
						'mailServerStatus'  => __( 'Mail Server Status', 'smtp-pai' ),
						'serverStatusUnavailable' => __( 'Server response was not recorded for this entry.', 'smtp-pai' ),
						'message'           => __( 'Message', 'smtp-pai' ),
						'sentAt'            => __( 'Sent at', 'smtp-pai' ),
						'sentAtUtc'         => __( 'UTC', 'smtp-pai' ),
						'status'            => __( 'Status', 'smtp-pai' ),
						'logId'             => __( 'Log ID', 'smtp-pai' ),
						'from'              => __( 'From', 'smtp-pai' ),
						'to'                => __( 'To', 'smtp-pai' ),
						'replyTo'           => __( 'Reply-To', 'smtp-pai' ),
						'cc'                => __( 'Cc', 'smtp-pai' ),
						'bcc'               => __( 'Bcc', 'smtp-pai' ),
						'returnPath'        => __( 'Return-Path', 'smtp-pai' ),
						'messageId'         => __( 'Message-ID', 'smtp-pai' ),
						'connection'        => __( 'Connection', 'smtp-pai' ),
						'provider'          => __( 'Provider', 'smtp-pai' ),
						'route'             => __( 'Route', 'smtp-pai' ),
						'transport'         => __( 'Transport', 'smtp-pai' ),
						'failover'          => __( 'Failover', 'smtp-pai' ),
						'primaryConnection' => __( 'Primary connection', 'smtp-pai' ),
						'contentType'       => __( 'Content-Type', 'smtp-pai' ),
						'yes'               => __( 'Yes', 'smtp-pai' ),
						'bodyDisabled'      => __( 'Email body is not stored. Enable it under Settings if you need a full message preview.', 'smtp-pai' ),
						'bodyUnavailable'   => __( 'Message body was not saved for this entry.', 'smtp-pai' ),
						'headerCount'       => __( 'headers', 'smtp-pai' ),
						'htmlMessage'       => __( 'HTML message preview', 'smtp-pai' ),
					),
				),
			)
		);
	}

	/**
	 * Handle POST actions.
	 */
	public function handle_request() {
		if ( ! is_admin() || ! Mailpai_Smtp_Capabilities::current_user_can_manage() ) {
			return;
		}

		if ( ! isset( $_POST['mailpai_smtp_action'] ) ) {
			return;
		}

		if ( ! Mailpai_Smtp_Urls::is_admin_screen() ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['mailpai_smtp_action'] ) );
		if ( '' === $action ) {
			return;
		}

		if ( 'save_connection' === $action ) {
			check_admin_referer( 'mailpai_smtp_save_connection' );
			$record   = $this->connection_from_post();
			$cred_err = $this->validate_connection_credentials( $record );
			if ( is_wp_error( $cred_err ) ) {
				add_settings_error( 'mailpai_smtp', 'connection', $cred_err->get_error_message(), 'error' );
				return;
			}
			$result = Mailpai_Smtp_Connection_Store::save( $record );
			if ( is_wp_error( $result ) ) {
				add_settings_error( 'mailpai_smtp', 'connection', $result->get_error_message(), 'error' );
				return;
			}

			if ( Mailpai_Smtp_Connection_Store::needs_oauth_signin( Mailpai_Smtp_Connection_Store::get( $result ) ) ) {
				Mailpai_Smtp_Connection_Store::patch_meta(
					$result,
					array(
						'last_status' => '',
						'last_error'  => '',
					)
				);
			}

			$redirect = $this->maybe_redirect_oauth_after_save( $result );
			if ( $redirect ) {
				exit;
			}

			add_settings_error( 'mailpai_smtp', 'connection', __( 'Connection saved.', 'smtp-pai' ), 'success' );

			$this->redirect_with_notices( Mailpai_Smtp_Urls::tab( 'dashboard', array( 'edit' => $result ) ) );
		}

		if ( 'complete_oauth_code' === $action ) {
			check_admin_referer( 'mailpai_smtp_save_connection' );
			$id = isset( $_POST['mailpai_smtp_conn_id'] ) ? sanitize_key( wp_unslash( $_POST['mailpai_smtp_conn_id'] ) ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in OAuth handler.
			$code = isset( $_POST['mailpai_smtp_oauth_auth_code'] ) ? wp_unslash( $_POST['mailpai_smtp_oauth_auth_code'] ) : '';
			$result = Mailpai_Smtp_Oauth::complete_manual_authorization_code( $id, $code );
			$this->complete_oauth_flow( $result );
			exit;
		}

		if ( 'delete_connection' === $action ) {
			check_admin_referer( 'mailpai_smtp_save_connection' );
			$id = isset( $_POST['mailpai_smtp_conn_id'] ) ? sanitize_key( wp_unslash( $_POST['mailpai_smtp_conn_id'] ) ) : '';
			Mailpai_Smtp_Connection_Store::delete( $id );
			add_settings_error( 'mailpai_smtp', 'connection', __( 'Connection removed.', 'smtp-pai' ), 'success' );
			$this->redirect_with_notices( Mailpai_Smtp_Urls::tab( 'dashboard' ) );
		}

		if ( 'save_routes' === $action ) {
			check_admin_referer( 'mailpai_smtp_save_routes' );
			$route_mode = sanitize_key( wp_unslash( $_POST['route_mode'] ?? 'separate' ) );
			$is_one     = ( 'one' === $route_mode );

			if ( $is_one ) {
				Mailpai_Smtp_Routes::save(
					array(
						'use_one'            => true,
						'use_one_connection' => sanitize_key( wp_unslash( $_POST['route_use_one_connection'] ?? '' ) ),
						'transactional'      => array(
							'wordpress'   => '',
							'woocommerce' => '',
						),
						'marketing'          => array(
							'newsletter' => '',
							'outreach'   => '',
						),
					)
				);
			} else {
				Mailpai_Smtp_Routes::save(
					array(
						'use_one'       => false,
						'transactional' => array(
							'wordpress'   => sanitize_key( wp_unslash( $_POST['route_wordpress'] ?? '' ) ),
							'woocommerce' => sanitize_key( wp_unslash( $_POST['route_woocommerce'] ?? '' ) ),
						),
						'marketing'     => array(
							'newsletter' => sanitize_key( wp_unslash( $_POST['route_newsletter'] ?? '' ) ),
							'outreach'   => sanitize_key( wp_unslash( $_POST['route_outreach'] ?? '' ) ),
						),
					)
				);
			}
			add_settings_error( 'mailpai_smtp', 'routes', __( 'Mail routes saved.', 'smtp-pai' ), 'success' );
			$this->redirect_with_notices( Mailpai_Smtp_Urls::tab( 'routes' ) );
		}

		if ( 'save_backup' === $action ) {
			check_admin_referer( 'mailpai_smtp_save_backup' );
			$result = Mailpai_Smtp_Backup::save(
				array(
					'global_backup_id' => sanitize_key( wp_unslash( $_POST['backup_global'] ?? '' ) ),
				)
			);
			if ( is_wp_error( $result ) ) {
				add_settings_error( 'mailpai_smtp', 'backup', $result->get_error_message(), 'error' );
			} else {
				add_settings_error( 'mailpai_smtp', 'backup', __( 'Backup settings saved.', 'smtp-pai' ), 'success' );
			}
			$this->redirect_with_notices( Mailpai_Smtp_Urls::tab( 'backup' ) );
		}

		if ( 'save_settings' === $action ) {
			check_admin_referer( 'mailpai_smtp_save_settings' );

			$existing = get_option( 'mailpai_smtp_settings', array() );
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}

			$settings = array_merge(
				$existing,
				array(
					'log_retention_days'  => max( 1, absint( $_POST['log_retention_days'] ?? 14 ) ),
					'log_body'            => ! empty( $_POST['log_body'] ),
					'routing_enabled'     => true,
					'delete_on_uninstall' => ! empty( $_POST['delete_on_uninstall'] ),
				)
			);

			update_option( 'mailpai_smtp_settings', $settings, false );
			add_settings_error( 'mailpai_smtp', 'settings', __( 'Settings saved.', 'smtp-pai' ), 'success' );
			$this->redirect_with_notices( Mailpai_Smtp_Urls::tab( 'settings' ) );
		}

		if ( 'delete_logs' === $action ) {
			check_admin_referer( 'mailpai_smtp_log_bulk' );
			if ( ! empty( $_POST['log_ids'] ) && is_array( $_POST['log_ids'] ) ) {
				$ids = array_map( 'absint', wp_unslash( $_POST['log_ids'] ) );
				Mailpai_Smtp_Log::delete_many( $ids );
			}
			wp_safe_redirect( Mailpai_Smtp_Urls::tab( 'log' ) );
			exit;
		}

		if ( 'delete_all_logs' === $action ) {
			check_admin_referer( 'mailpai_smtp_log_bulk' );
			Mailpai_Smtp_Log::delete_all();
			wp_safe_redirect( Mailpai_Smtp_Urls::tab( 'log' ) );
			exit;
		}

		if ( 'ses_dns_refresh' === $action ) {
			check_admin_referer( 'mailpai_smtp_ses_dns' );
			$conn_id = isset( $_POST['mailpai_smtp_conn_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['mailpai_smtp_conn_id'] ) ) : '';
			$slot    = '' !== $conn_id ? Mailpai_Smtp_Connection_Store::get( $conn_id ) : null;
			if ( ! is_array( $slot ) || 'amazon_ses' !== ( $slot['provider'] ?? '' ) ) {
				add_settings_error( 'mailpai_smtp', 'ses_dns', __( 'Save Amazon SES settings first.', 'smtp-pai' ), 'error' );
			} else {
				$creds = Mailpai_Smtp_Mailer::resolve_ses_credentials( $slot );
				$fe    = isset( $slot['from_email'] ) ? (string) $slot['from_email'] : '';
				if ( is_wp_error( $creds ) ) {
					add_settings_error( 'mailpai_smtp', 'ses_dns', $creds->get_error_message(), 'error' );
				} elseif ( ! is_email( $fe ) ) {
					add_settings_error( 'mailpai_smtp', 'ses_dns', __( 'Enter a valid From email, save, then load DNS again.', 'smtp-pai' ), 'error' );
				} else {
					$snap = Mailpai_Smtp_Ses_Api::load_dns_snapshot( $creds['region'], $creds['access_key'], $creds['secret'], $fe );
					if ( is_wp_error( $snap ) ) {
						add_settings_error( 'mailpai_smtp', 'ses_dns', $snap->get_error_message(), 'error' );
					} else {
						$snap['fetched_at'] = time();
						Mailpai_Smtp_Connection_Store::patch(
							$conn_id,
							array(
								'ses_dns_snapshot' => $snap,
								'ses_dns_check'    => null,
							)
						);
						Mailpai_Smtp_Connection_Store::flush_cache();
						add_settings_error( 'mailpai_smtp', 'ses_dns', __( 'DNS records loaded from Amazon SES.', 'smtp-pai' ), 'success' );
					}
				}
			}
			$this->redirect_with_notices( Mailpai_Smtp_Urls::tab( 'dashboard', array( 'edit' => $conn_id ) ) );
		}

		if ( 'ses_dns_check' === $action ) {
			check_admin_referer( 'mailpai_smtp_ses_dns' );
			$conn_id = isset( $_POST['mailpai_smtp_conn_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['mailpai_smtp_conn_id'] ) ) : '';
			$slot    = '' !== $conn_id ? Mailpai_Smtp_Connection_Store::get( $conn_id ) : null;
			if ( ! is_array( $slot ) || empty( $slot['ses_dns_snapshot'] ) || ! is_array( $slot['ses_dns_snapshot'] ) ) {
				add_settings_error( 'mailpai_smtp', 'ses_dns', __( 'Load records from SES first.', 'smtp-pai' ), 'error' );
			} else {
				$snap   = $slot['ses_dns_snapshot'];
				$domain = isset( $snap['domain_for_checks'] ) ? (string) $snap['domain_for_checks'] : '';
				$recs   = isset( $snap['records'] ) && is_array( $snap['records'] ) ? $snap['records'] : array();
				if ( '' === $domain ) {
					add_settings_error( 'mailpai_smtp', 'ses_dns', __( 'From email must include a domain for DNS checks.', 'smtp-pai' ), 'error' );
				} else {
					$res = Mailpai_Smtp_Ses_Api::dns_check_domain( $domain, $recs );
					Mailpai_Smtp_Connection_Store::patch(
						$conn_id,
						array(
							'ses_dns_check' => array(
								'checked_at' => time(),
								'rows'       => $res['rows'],
							),
						)
					);
					Mailpai_Smtp_Connection_Store::flush_cache();
					add_settings_error( 'mailpai_smtp', 'ses_dns', __( 'DNS check finished.', 'smtp-pai' ), 'success' );
				}
			}
			$this->redirect_with_notices( Mailpai_Smtp_Urls::tab( 'dashboard', array( 'edit' => $conn_id ) ) );
		}
	}

	/**
	 * Handle OAuth redirects (start, callback, disconnect).
	 */
	public function handle_oauth() {
		if ( ! is_admin() || ! Mailpai_Smtp_Capabilities::current_user_can_manage() ) {
			return;
		}

		if ( isset( $_GET['mailpai_google_auth_code'], $_GET['mailpai_oauth_state'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in OAuth handler.
			$state = wp_unslash( $_GET['mailpai_oauth_state'] );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in OAuth handler.
			$code  = wp_unslash( $_GET['mailpai_google_auth_code'] );
			$result = Mailpai_Smtp_Oauth::handle_google_proxy_callback( $code, $state );
			$this->complete_oauth_flow( $result );
			return;
		}

		if ( isset( $_GET['mailpai_microsoft_auth_code'], $_GET['mailpai_oauth_state'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in OAuth handler.
			$state = wp_unslash( $_GET['mailpai_oauth_state'] );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in OAuth handler.
			$code  = wp_unslash( $_GET['mailpai_microsoft_auth_code'] );
			$result = Mailpai_Smtp_Oauth::handle_microsoft_proxy_callback( $code, $state );
			$this->complete_oauth_flow( $result );
			return;
		}

		$mode = isset( $_GET['mailpai_smtp_oauth'] ) ? sanitize_key( wp_unslash( $_GET['mailpai_smtp_oauth'] ) ) : '';
		if ( '' === $mode ) {
			return;
		}

		if ( ! Mailpai_Smtp_Urls::is_admin_screen() ) {
			return;
		}

		if ( 'start' === $mode ) {
			check_admin_referer( 'mailpai_smtp_oauth_start' );
			$provider = Mailpai_Smtp_Provider_Registry::normalize_slug( sanitize_text_field( wp_unslash( $_GET['provider'] ?? '' ) ) );
			if ( ! Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $provider ) ) {
				wp_die( esc_html__( 'This provider does not support OAuth sign-in.', 'smtp-pai' ) );
			}

			$return_url = Mailpai_Smtp_Urls::tab( 'dashboard' );
			if ( ! empty( $_GET['connection_id'] ) ) {
				$return_url = Mailpai_Smtp_Urls::tab( 'dashboard', array( 'edit' => sanitize_key( wp_unslash( $_GET['connection_id'] ) ) ) );
			} elseif ( ! empty( $_GET['return_url'] ) ) {
				$return_url = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['return_url'] ) ), Mailpai_Smtp_Urls::tab( 'dashboard' ) );
			}

			$url = Mailpai_Smtp_Oauth::authorize_url(
				$provider,
				array(
					'connection_id'   => sanitize_key( wp_unslash( $_GET['connection_id'] ?? '' ) ),
					'from_name'       => sanitize_text_field( wp_unslash( $_GET['from_name'] ?? '' ) ),
					'connection_name' => sanitize_text_field( wp_unslash( $_GET['connection_name'] ?? '' ) ),
					'return_url'      => $return_url,
					'oauth_client_id' => sanitize_text_field( wp_unslash( $_GET['oauth_client_id'] ?? '' ) ),
				)
			);

			if ( is_wp_error( $url ) ) {
				add_settings_error( 'mailpai_smtp', 'oauth', $url->get_error_message(), 'error' );
				$this->redirect_with_notices( $return_url );
			}

			Mailpai_Smtp_Oauth::redirect_to_provider( $url );
		}

		if ( 'callback' === $mode ) {
			if ( isset( $_GET['error'] ) ) {
				$error_code = sanitize_key( wp_unslash( $_GET['error'] ?? '' ) );
				if ( 'redirect_uri_mismatch' === $error_code ) {
					$msg = Mailpai_Smtp_Oauth::redirect_uri_mismatch_message( sanitize_text_field( wp_unslash( $_GET['provider'] ?? 'microsoft' ) ) );
				} else {
					$msg = sanitize_text_field( wp_unslash( $_GET['error_description'] ?? $_GET['error'] ?? '' ) );
					if ( '' === $msg ) {
						$msg = __( 'OAuth sign-in was cancelled.', 'smtp-pai' );
					}
				}
				add_settings_error( 'mailpai_smtp', 'oauth', $msg, 'error' );
				$this->redirect_with_notices( Mailpai_Smtp_Urls::tab( 'dashboard' ) );
			}

			$result = Mailpai_Smtp_Oauth::handle_callback(
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- State is verified inside the OAuth handler.
				isset( $_GET['state'] ) ? wp_unslash( $_GET['state'] ) : '',
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Authorization code is exchanged server-side.
				isset( $_GET['code'] ) ? wp_unslash( $_GET['code'] ) : ''
			);

			$this->complete_oauth_flow( $result );
		}

		if ( 'disconnect' === $mode ) {
			check_admin_referer( 'mailpai_smtp_oauth_disconnect' );
			$id = sanitize_key( wp_unslash( $_GET['connection_id'] ?? '' ) );
			$rec = Mailpai_Smtp_Connection_Store::get( $id );
			if ( is_array( $rec ) ) {
				Mailpai_Smtp_Connection_Store::patch(
					$id,
					array(
						'auth_type'         => '',
						'oauth_refresh_enc' => '',
					)
				);
				add_settings_error( 'mailpai_smtp', 'oauth', __( 'Authorization removed. Save the connection again to re-authorize.', 'smtp-pai' ), 'success' );
			}
			$this->redirect_with_notices( Mailpai_Smtp_Urls::tab( 'dashboard', array( 'edit' => $id ) ) );
		}
	}

	/**
	 * Finish OAuth sign-in notices, optional test email, and redirect.
	 *
	 * @param array|\WP_Error $result OAuth handler result.
	 */
	private function complete_oauth_flow( $result ) {
		$fallback = Mailpai_Smtp_Urls::tab( 'dashboard' );

		if ( is_wp_error( $result ) ) {
			add_settings_error( 'mailpai_smtp', 'oauth', $result->get_error_message(), 'error' );
			$this->redirect_with_notices( $fallback );
		}

		add_settings_error(
			'mailpai_smtp',
			'oauth_connected',
			sprintf(
				/* translators: %s: email address */
				__( 'Connected as %s.', 'smtp-pai' ),
				$result['email']
			),
			'success'
		);

		$test_recipient = $this->connection_test_recipient( (string) ( $result['email'] ?? '' ) );
		$test           = Mailpai_Smtp_Mailer::send_test( $result['connection_id'], (string) ( $result['email'] ?? '' ) );
		$test_rec       = Mailpai_Smtp_Connection_Store::get( (string) ( $result['connection_id'] ?? '' ) );
		$test_title     = Mailpai_Smtp_Connection_Store::title( is_array( $test_rec ) ? $test_rec : (string) ( $result['connection_id'] ?? '' ) );
		if ( is_wp_error( $test ) ) {
			$this->add_connection_test_failure_notice( $test, $test_title );
		} else {
			$this->add_connection_test_success_notice( is_string( $test_recipient ) ? $test_recipient : '', $test_title );
		}

		$this->redirect_with_notices(
			Mailpai_Smtp_Urls::tab(
				'dashboard',
				array( 'edit' => $result['connection_id'] )
			)
		);
	}

	/**
	 * Queue an admin notice after routes are auto-assigned to One for Everything.
	 *
	 * @param string $connection_id Connection id.
	 */
	public function queue_routes_auto_assigned_notice( $connection_id ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		set_transient(
			'mailpai_smtp_auto_routes_' . $user_id,
			sanitize_key( (string) $connection_id ),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Show a one-time notice when routes were auto-assigned for the current user.
	 */
	public function maybe_show_routes_auto_assigned_notice() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$connection_id = get_transient( 'mailpai_smtp_auto_routes_' . $user_id );
		if ( false === $connection_id || '' === $connection_id ) {
			return;
		}

		delete_transient( 'mailpai_smtp_auto_routes_' . $user_id );

		$rec   = Mailpai_Smtp_Connection_Store::get( $connection_id );
		$title = Mailpai_Smtp_Connection_Store::title( is_array( $rec ) ? $rec : $connection_id );

		add_settings_error(
			'mailpai_smtp',
			'routes_auto_assigned',
			sprintf(
				/* translators: %s: connection title */
				__( '%s is now used for all email (One for Everything). Change routing anytime under Specify Connections.', 'smtp-pai' ),
				$title
			),
			'success'
		);
	}

	/**
	 * Build a connection record from the current POST payload.
	 *
	 * Nonce verification is performed by handle_request() before this runs.
	 *
	 * @return array
	 */
	private function connection_from_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in handle_request().
		$id = isset( $_POST['mailpai_smtp_conn_id'] ) ? sanitize_key( wp_unslash( $_POST['mailpai_smtp_conn_id'] ) ) : '';
		$existing = '' !== $id ? Mailpai_Smtp_Connection_Store::get( $id ) : Mailpai_Smtp_Connection_Store::empty_record();

		$provider = Mailpai_Smtp_Provider_Registry::normalize_slug( sanitize_text_field( wp_unslash( $_POST['mailpai_smtp_provider'] ?? '' ) ) );
		$def      = Mailpai_Smtp_Provider_Registry::get( $provider );

		$existing_meta = is_array( $existing['meta'] ?? null ) ? $existing['meta'] : array();
		unset( $existing_meta['oauth_one_click'] );

		$record = array_merge(
			$existing ?: Mailpai_Smtp_Connection_Store::empty_record(),
			array(
				'id'              => $id,
				'enabled'         => true,
				'provider'        => $provider,
				'connection_name' => sanitize_text_field( wp_unslash( $_POST['mailpai_smtp_connection_name'] ?? '' ) ),
				'from_name'       => sanitize_text_field( wp_unslash( $_POST['mailpai_smtp_from_name'] ?? '' ) ),
				'from_email'      => sanitize_email( wp_unslash( $_POST['mailpai_smtp_from_email'] ?? '' ) ),
				'force_from_name'  => ! empty( $_POST['mailpai_smtp_force_from_name'] ),
				'force_from_email' => ! empty( $_POST['mailpai_smtp_force_from_email'] ),
				'secrets_storage' => sanitize_key( wp_unslash( $_POST['mailpai_smtp_secrets_storage'] ?? Mailpai_Smtp_Connection_Store::SECRETS_DATABASE ) ),
				'aws_access_key_id' => sanitize_text_field( wp_unslash( $_POST['mailpai_smtp_aws_key'] ?? '' ) ),
				'aws_region'        => sanitize_text_field( wp_unslash( $_POST['mailpai_smtp_aws_region'] ?? 'us-east-1' ) ),
				'host'              => sanitize_text_field( wp_unslash( $_POST['mailpai_smtp_host'] ?? '' ) ),
				'port'              => absint( $_POST['mailpai_smtp_port'] ?? 587 ),
				'encryption'        => sanitize_key( wp_unslash( $_POST['mailpai_smtp_encryption'] ?? 'tls' ) ),
				'disable_encryption'=> ! empty( $_POST['mailpai_smtp_disable_encryption'] ),
				'disable_secret_encryption' => ! empty( $_POST['mailpai_smtp_disable_secret_encryption'] ),
				'user'              => sanitize_text_field( wp_unslash( $_POST['mailpai_smtp_user'] ?? '' ) ),
				'meta'              => array_merge(
					$existing_meta,
					array(
						'api_domain'              => sanitize_key( wp_unslash( $_POST['mailpai_smtp_api_domain'] ?? 'us' ) ),
						'mailgun_domain'          => Mailpai_Smtp_Mailer::normalize_mailgun_domain( sanitize_text_field( wp_unslash( $_POST['mailpai_smtp_mailgun_domain'] ?? '' ) ) ),
						'postmark_message_stream' => Mailpai_Smtp_Mailer::normalize_postmark_message_stream( sanitize_text_field( wp_unslash( $_POST['mailpai_smtp_message_stream_id'] ?? '' ) ) ),
						'smtp_com_channel'        => Mailpai_Smtp_Mailer::normalize_smtp_com_channel( sanitize_text_field( wp_unslash( $_POST['mailpai_smtp_channel_name'] ?? '' ) ) ),
					)
				),
				'oauth_client_id'   => sanitize_text_field( wp_unslash( $_POST['mailpai_smtp_oauth_client_id'] ?? '' ) ),
			)
		);

		if (
			Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $provider )
			&& ! is_email( $record['from_email'] ?? '' )
			&& Mailpai_Smtp_Connection_Store::uses_oauth( $existing )
			&& is_email( $existing['from_email'] ?? '' )
		) {
			$record['from_email'] = sanitize_email( $existing['from_email'] );
		}

		if ( Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $provider ) ) {
			if ( Mailpai_Smtp_Connection_Store::SECRETS_WP_CONFIG === ( $record['secrets_storage'] ?? '' ) && ! Mailpai_Smtp_Connection_Store::wp_config_oauth_ready( $provider ) ) {
				$record['secrets_storage'] = Mailpai_Smtp_Connection_Store::SECRETS_DATABASE;
			}
		}

		if ( ! empty( $_POST['mailpai_smtp_aws_secret'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Stored encrypted as submitted.
			$record['aws_secret_enc'] = (string) wp_unslash( $_POST['mailpai_smtp_aws_secret'] );
		}
		if ( ! empty( $_POST['mailpai_smtp_api_key'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Stored encrypted as submitted.
			$record['api_key_enc'] = (string) wp_unslash( $_POST['mailpai_smtp_api_key'] );
		}
		if ( ! empty( $_POST['mailpai_smtp_api_secret'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Stored encrypted as submitted.
			$record['api_secret_enc'] = (string) wp_unslash( $_POST['mailpai_smtp_api_secret'] );
		}
		if ( ! empty( $_POST['mailpai_smtp_smtp_secret'] ) && Mailpai_Smtp_Connection_Store::SECRETS_DATABASE === sanitize_key( wp_unslash( $_POST['mailpai_smtp_secrets_storage'] ?? Mailpai_Smtp_Connection_Store::SECRETS_DATABASE ) ) && ! Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $provider ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Stored encrypted as submitted.
			$record['secret_enc']        = (string) wp_unslash( $_POST['mailpai_smtp_smtp_secret'] );
			$record['auth_type']         = 'password';
			$record['oauth_refresh_enc'] = '';
		} elseif ( Mailpai_Smtp_Connection_Store::uses_oauth( $existing ) ) {
			$record['auth_type']         = $existing['auth_type'] ?? 'oauth';
			$record['oauth_refresh_enc'] = $existing['oauth_refresh_enc'] ?? '';
		}
		if ( ! empty( $_POST['mailpai_smtp_oauth_client_secret'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Stored encrypted as submitted.
			$record['oauth_client_secret_enc'] = (string) wp_unslash( $_POST['mailpai_smtp_oauth_client_secret'] );
		} elseif ( is_array( $existing ) && ! empty( $existing['oauth_client_secret_enc'] ) ) {
			$record['oauth_client_secret_enc'] = $existing['oauth_client_secret_enc'];
		}

		if ( Mailpai_Smtp_Provider_Registry::uses_mailbox_smtp( $record ) ) {
			if ( '' === trim( (string) ( $record['user'] ?? '' ) ) && is_email( $record['from_email'] ?? '' ) ) {
				$record['user'] = $record['from_email'];
			}
			$record['disable_encryption'] = false;
			if ( Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $provider ) ) {
				if ( Mailpai_Smtp_Connection_Store::SECRETS_WP_CONFIG === ( $record['secrets_storage'] ?? '' ) && ! Mailpai_Smtp_Connection_Store::wp_config_oauth_ready( $provider ) ) {
					$record['secrets_storage'] = Mailpai_Smtp_Connection_Store::SECRETS_DATABASE;
				}
			} elseif ( Mailpai_Smtp_Connection_Store::SECRETS_WP_CONFIG === ( $record['secrets_storage'] ?? '' ) && ! Mailpai_Smtp_Connection_Store::wp_config_smtp_ready() ) {
				$record['secrets_storage'] = Mailpai_Smtp_Connection_Store::SECRETS_DATABASE;
			}
		}

		if ( ! empty( $def['host'] ) && empty( $record['host'] ) ) {
			$record['host'] = $def['host'];
		}
		if ( ! empty( $def['port'] ) && empty( $record['port'] ) ) {
			$record['port'] = $def['port'];
		}
		if ( ! empty( $def['encryption'] ) && empty( $record['encryption'] ) ) {
			$record['encryption'] = $def['encryption'];
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $record;
	}

	/**
	 * Ensure connection credentials are present before save.
	 *
	 * @param array $record Connection record from POST.
	 * @return true|\WP_Error
	 */
	private function validate_connection_credentials( array $record ) {
		$smtp = $this->validate_smtp_credentials( $record );
		if ( is_wp_error( $smtp ) ) {
			return $smtp;
		}

		return $this->validate_api_credentials( $record );
	}

	/**
	 * Ensure API credentials are present when required.
	 *
	 * @param array $record Connection record from POST.
	 * @return true|\WP_Error
	 */
	private function validate_api_credentials( array $record ) {
		$provider = Mailpai_Smtp_Provider_Registry::get( $record['provider'] ?? '' );
		if ( empty( $provider ) || 'api' !== ( $provider['transport'] ?? '' ) ) {
			return true;
		}

		if ( Mailpai_Smtp_Connection_Store::SECRETS_WP_CONFIG === Mailpai_Smtp_Connection_Store::secrets_storage( $record ) ) {
			return true;
		}

		$slug = sanitize_key( (string) ( $record['provider'] ?? '' ) );
		$id   = isset( $record['id'] ) ? sanitize_key( (string) $record['id'] ) : '';
		$existing = '' !== $id ? Mailpai_Smtp_Connection_Store::get( $id ) : null;

		if ( 'amazon_ses' === $slug ) {
			if ( '' === trim( (string) ( $record['aws_access_key_id'] ?? '' ) ) ) {
				return new WP_Error(
					'mailpai_smtp_ses',
					__( 'Enter an Amazon SES access key ID.', 'smtp-pai' )
				);
			}

			$has_secret = ! empty( $record['aws_secret_enc'] )
				|| ( is_array( $existing ) && ! empty( $existing['aws_secret_enc'] ) );

			if ( ! $has_secret ) {
				return new WP_Error(
					'mailpai_smtp_ses',
					__( 'Enter an Amazon SES secret access key.', 'smtp-pai' )
				);
			}
		}

		if ( 'mailgun' === $slug ) {
			if ( '' === Mailpai_Smtp_Mailer::normalize_mailgun_domain( $record['meta']['mailgun_domain'] ?? '' ) ) {
				return new WP_Error(
					'mailpai_smtp_mailgun',
					__( 'Enter your Mailgun sending domain.', 'smtp-pai' )
				);
			}

			$has_key = ! empty( $record['api_key_enc'] )
				|| ( is_array( $existing ) && ! empty( $existing['api_key_enc'] ) );

			if ( ! $has_key ) {
				return new WP_Error(
					'mailpai_smtp_mailgun',
					__( 'Enter a Mailgun API key.', 'smtp-pai' )
				);
			}
		}

		if ( 'mailjet' === $slug ) {
			$has_key = ! empty( $record['api_key_enc'] )
				|| ( is_array( $existing ) && ! empty( $existing['api_key_enc'] ) );

			if ( ! $has_key ) {
				return new WP_Error(
					'mailpai_smtp_mailjet',
					__( 'Enter your Mailjet API key.', 'smtp-pai' )
				);
			}

			$has_secret = ! empty( $record['api_secret_enc'] )
				|| ( is_array( $existing ) && ! empty( $existing['api_secret_enc'] ) );

			if ( ! $has_secret ) {
				return new WP_Error(
					'mailpai_smtp_mailjet',
					__( 'Enter your Mailjet Secret key.', 'smtp-pai' )
				);
			}
		}

		if ( 'smtp_com' === $slug ) {
			if ( '' === Mailpai_Smtp_Mailer::normalize_smtp_com_channel( $record['meta']['smtp_com_channel'] ?? '' ) ) {
				return new WP_Error(
					'mailpai_smtp_smtp_com',
					__( 'Enter your SMTP.com channel name.', 'smtp-pai' )
				);
			}

			$has_key = ! empty( $record['api_key_enc'] )
				|| ( is_array( $existing ) && ! empty( $existing['api_key_enc'] ) );

			if ( ! $has_key ) {
				return new WP_Error(
					'mailpai_smtp_smtp_com',
					__( 'Enter an SMTP.com API key.', 'smtp-pai' )
				);
			}
		}

		$provider_specific = array( 'amazon_ses', 'mailgun', 'mailjet', 'smtp_com' );
		if ( ! in_array( $slug, $provider_specific, true ) ) {
			$has_key = ! empty( $record['api_key_enc'] )
				|| ( is_array( $existing ) && ! empty( $existing['api_key_enc'] ) );

			if ( ! $has_key ) {
				return new WP_Error(
					'mailpai_smtp_api',
					__( 'Enter an API key.', 'smtp-pai' )
				);
			}
		}

		return true;
	}

	/**
	 * Ensure SMTP credentials are present when authentication is required.
	 *
	 * @param array $record Connection record from POST.
	 * @return true|\WP_Error
	 */
	private function validate_smtp_credentials( array $record ) {
		$provider = Mailpai_Smtp_Provider_Registry::get( $record['provider'] ?? '' );
		if ( empty( $provider ) || 'smtp' !== ( $provider['transport'] ?? '' ) ) {
			return true;
		}

		if ( Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $record['provider'] ?? '' ) ) {
			if ( Mailpai_Smtp_Connection_Store::uses_oauth( $record ) ) {
				return true;
			}

			if ( Mailpai_Smtp_Connection_Store::SECRETS_WP_CONFIG === Mailpai_Smtp_Connection_Store::secrets_storage( $record ) ) {
				return $this->validate_wp_config_oauth_constants( $record['provider'] ?? '' );
			}

			$id       = isset( $record['id'] ) ? sanitize_key( (string) $record['id'] ) : '';
			$existing = '' !== $id ? Mailpai_Smtp_Connection_Store::get( $id ) : null;
			$client_id = trim( (string) ( $record['oauth_client_id'] ?? '' ) );
			$has_secret = ! empty( $record['oauth_client_secret_enc'] )
				|| ( is_array( $existing ) && ! empty( $existing['oauth_client_secret_enc'] ) );

			if ( '' !== $client_id && $has_secret ) {
				return true;
			}

			return new WP_Error(
				'mailpai_smtp_oauth',
				__( 'Enter an Application Client ID and Application Client Secret, then save the connection.', 'smtp-pai' )
			);
		}

		if ( Mailpai_Smtp_Connection_Store::uses_oauth( $record ) ) {
			return true;
		}
		if ( Mailpai_Smtp_Connection_Store::SECRETS_WP_CONFIG === Mailpai_Smtp_Connection_Store::secrets_storage( $record ) ) {
			return $this->validate_wp_config_smtp_constants();
		}

		$slug = sanitize_key( (string) ( $record['provider'] ?? '' ) );
		if ( 'other_smtp' === $slug ) {
			if ( '' === trim( (string) ( $record['host'] ?? '' ) ) ) {
				return new WP_Error(
					'mailpai_smtp_host',
					__( 'Enter an SMTP server hostname.', 'smtp-pai' )
				);
			}

			$port = absint( $record['port'] ?? 0 );
			if ( $port <= 0 ) {
				return new WP_Error(
					'mailpai_smtp_port',
					__( 'Enter a valid SMTP port.', 'smtp-pai' )
				);
			}
		}

		$user = trim( (string) ( $record['user'] ?? '' ) );
		if ( '' === $user ) {
			return true;
		}

		$id       = isset( $record['id'] ) ? sanitize_key( (string) $record['id'] ) : '';
		$existing = '' !== $id ? Mailpai_Smtp_Connection_Store::get( $id ) : null;
		if ( is_array( $existing ) && Mailpai_Smtp_Connection_Store::uses_oauth( $existing ) && empty( $record['secret_enc'] ) ) {
			return true;
		}
		$stored   = is_array( $existing ) ? (string) ( $existing['secret_enc'] ?? '' ) : '';
		$incoming = (string) ( $record['secret_enc'] ?? '' );

		if ( '' !== $incoming || '' !== $stored ) {
			return true;
		}

		return new WP_Error(
			'mailpai_smtp_secret',
			__( 'Enter your password and save.', 'smtp-pai' )
		);
	}

	/**
	 * Redirect to the provider OAuth screen after saving when authorization is still required.
	 *
	 * @param string $connection_id Saved connection id.
	 * @return bool True when redirect was sent.
	 */
	private function maybe_redirect_oauth_after_save( $connection_id ) {
		$rec = Mailpai_Smtp_Connection_Store::get( $connection_id );
		if ( ! is_array( $rec ) || ! Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $rec['provider'] ?? '' ) ) {
			return false;
		}
		if ( Mailpai_Smtp_Connection_Store::uses_oauth( $rec ) ) {
			return false;
		}
		if ( ! Mailpai_Smtp_Oauth::is_configured( $rec['provider'], $rec ) ) {
			return false;
		}

		$url = Mailpai_Smtp_Oauth::connection_start_url( $rec );
		if ( '' === $url ) {
			return false;
		}

		wp_safe_redirect( $url );
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	private function validate_wp_config_smtp_constants() {
		if ( Mailpai_Smtp_Connection_Store::wp_config_smtp_ready() ) {
			return true;
		}

		$missing = array();
		foreach ( array( 'MAILPAI_SMTP_HOST', 'MAILPAI_SMTP_USER', 'MAILPAI_SMTP_PASSWORD' ) as $constant ) {
			if ( ! defined( $constant ) || '' === trim( (string) constant( $constant ) ) ) {
				$missing[] = $constant;
			}
		}

		return new WP_Error(
			'mailpai_smtp_wpconfig',
			sprintf(
				/* translators: %s: comma-separated PHP constant names */
				__( '“Keep in wp-config.php” is selected but these constants are missing from wp-config.php: %s. Add them using the snippet on this page, or switch to “Store keys in database”.', 'smtp-pai' ),
				implode( ', ', $missing )
			)
		);
	}

	/**
	 * @param string $provider_slug google|microsoft.
	 * @return true|\WP_Error
	 */
	private function validate_wp_config_oauth_constants( $provider_slug ) {
		if ( Mailpai_Smtp_Connection_Store::wp_config_oauth_ready( $provider_slug ) ) {
			return true;
		}

		$missing = array();
		foreach ( Mailpai_Smtp_Provider_Registry::oauth_wp_config_keys( $provider_slug ) as $constant ) {
			if ( ! defined( $constant ) || '' === trim( (string) constant( $constant ) ) ) {
				$missing[] = $constant;
			}
		}

		return new WP_Error(
			'mailpai_smtp_wpconfig',
			sprintf(
				/* translators: %s: comma-separated PHP constant names */
				__( '“Keep in wp-config.php” is selected but these constants are missing from wp-config.php: %s. Add them using the snippet on this page, or switch to “Store keys in database”.', 'smtp-pai' ),
				implode( ', ', $missing )
			)
		);
	}

	/**
	 * Resolve the recipient used for a connection test email.
	 *
	 * @param string $to Optional recipient from the request.
	 * @return string|\WP_Error
	 */
	private function connection_test_recipient( $to ) {
		$to = is_string( $to ) ? trim( $to ) : '';
		if ( '' !== $to ) {
			return Mailpai_Smtp_Mailer::resolve_single_recipient( $to );
		}

		return sanitize_email( get_option( 'admin_email' ) );
	}

	/**
	 * Build the standard success message for a connection test email.
	 *
	 * @param string $to                Recipient email address.
	 * @param string $connection_title  Connection display name.
	 * @return string
	 */
	private function connection_test_success_message( $to, $connection_title = '' ) {
		$to               = sanitize_email( (string) $to );
		$connection_title = sanitize_text_field( (string) $connection_title );

		if ( '' !== $connection_title && '' !== $to ) {
			return sprintf(
				/* translators: 1: connection name, 2: recipient email address */
				__( 'Test email sent for %1$s to %2$s.', 'smtp-pai' ),
				$connection_title,
				$to
			);
		}

		if ( '' !== $connection_title ) {
			return sprintf(
				/* translators: %s: connection name */
				__( 'Test email sent for %s.', 'smtp-pai' ),
				$connection_title
			);
		}

		if ( '' !== $to ) {
			return sprintf(
				/* translators: %s: recipient email address */
				__( 'Test email sent to %s.', 'smtp-pai' ),
				$to
			);
		}

		return __( 'Test email sent.', 'smtp-pai' );
	}

	/**
	 * Build the standard failure message for a connection test email.
	 *
	 * @param string $connection_title Connection display name.
	 * @param string $error_message    Error detail.
	 * @return string
	 */
	private function connection_test_failure_message( $connection_title, $error_message ) {
		$connection_title = sanitize_text_field( (string) $connection_title );
		$error_message    = (string) $error_message;

		if ( '' !== $connection_title && '' !== $error_message ) {
			return sprintf(
				/* translators: 1: connection name, 2: error message */
				__( 'Test failed for %1$s: %2$s', 'smtp-pai' ),
				$connection_title,
				$error_message
			);
		}

		if ( '' !== $connection_title ) {
			return sprintf(
				/* translators: %s: connection name */
				__( 'Test failed for %s.', 'smtp-pai' ),
				$connection_title
			);
		}

		return '' !== $error_message ? $error_message : __( 'Test failed.', 'smtp-pai' );
	}

	/**
	 * Queue a connection test success notice.
	 *
	 * @param string $to               Recipient email address.
	 * @param string $connection_title Connection display name.
	 */
	private function add_connection_test_success_notice( $to, $connection_title = '' ) {
		add_settings_error(
			'mailpai_smtp',
			'test_success',
			$this->connection_test_success_message( $to, $connection_title ),
			'success'
		);
	}

	/**
	 * Queue a connection test failure notice.
	 *
	 * @param \WP_Error $error            Send failure.
	 * @param string    $connection_title Connection display name.
	 */
	private function add_connection_test_failure_notice( WP_Error $error, $connection_title = '' ) {
		add_settings_error(
			'mailpai_smtp',
			'test_failed',
			$this->connection_test_failure_message( $connection_title, $error->get_error_message() ),
			'error'
		);
	}

	/**
	 * Build the AJAX error payload when an OAuth mailbox still needs provider sign-in.
	 *
	 * @param array $rec Connection record.
	 * @return array<string,string>
	 */
	private function oauth_signin_test_error_payload( array $rec ) {
		$provider = $rec['provider'] ?? '';
		$title    = Mailpai_Smtp_Connection_Store::title( $rec );
		$detail   = Mailpai_Smtp_Oauth::is_configured( $provider, $rec )
			? sprintf(
				/* translators: 1: provider name, 2: connect button label */
				__( 'Sign in with %1$s before sending a test email. Click %2$s below.', 'smtp-pai' ),
				Mailpai_Smtp_Oauth::provider_display_name( $provider ),
				Mailpai_Smtp_Oauth::button_label( $provider )
			)
			: __( 'Enter an Application Client ID and Application Client Secret, then save the connection.', 'smtp-pai' );
		$payload  = array(
			'message' => $this->connection_test_failure_message( $title, $detail ),
		);

		$signin_url = Mailpai_Smtp_Oauth::connection_start_url( $rec );
		if ( '' !== $signin_url ) {
			$payload['oauth_signin_url']   = $signin_url;
			$payload['oauth_signin_label'] = Mailpai_Smtp_Oauth::button_label( $provider );
		}

		return $payload;
	}

	/**
	 * AJAX test connection.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'mailpai_smtp_admin', 'nonce' );
		if ( ! Mailpai_Smtp_Capabilities::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'smtp-pai' ) ), 403 );
		}
		$id        = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		$to_raw    = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$to_raw    = is_string( $to_raw ) ? trim( $to_raw ) : '';
		$recipient = $this->connection_test_recipient( $to_raw );
		if ( is_wp_error( $recipient ) ) {
			wp_send_json_error( array( 'message' => $recipient->get_error_message() ) );
		}

		$rec = Mailpai_Smtp_Connection_Store::get( $id );
		$title = Mailpai_Smtp_Connection_Store::title( is_array( $rec ) ? $rec : $id );
		if ( is_array( $rec ) && Mailpai_Smtp_Connection_Store::needs_oauth_signin( $rec ) ) {
			wp_send_json_error( $this->oauth_signin_test_error_payload( $rec ) );
		}

		$res = Mailpai_Smtp_Mailer::send_test( $id, $to_raw );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error(
				array(
					'message' => $this->connection_test_failure_message( $title, $res->get_error_message() ),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => $this->connection_test_success_message( $recipient, $title ),
			)
		);
	}

	/**
	 * AJAX log detail.
	 */
	public function ajax_log_view() {
		check_ajax_referer( 'mailpai_smtp_admin', 'nonce' );
		if ( ! Mailpai_Smtp_Capabilities::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'smtp-pai' ) ), 403 );
		}
		$id  = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		$row = Mailpai_Smtp_Log::get( $id );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Log not found.', 'smtp-pai' ) ) );
		}

		$filters = array(
			'status'    => isset( $_POST['log_status'] ) ? sanitize_key( wp_unslash( $_POST['log_status'] ) ) : '',
			'search'    => isset( $_POST['log_s'] ) ? sanitize_text_field( wp_unslash( $_POST['log_s'] ) ) : '',
			'date_from' => isset( $_POST['log_from'] ) ? sanitize_text_field( wp_unslash( $_POST['log_from'] ) ) : '',
			'date_to'   => isset( $_POST['log_to'] ) ? sanitize_text_field( wp_unslash( $_POST['log_to'] ) ) : '',
		);
		$adjacent = Mailpai_Smtp_Log::adjacent_ids( $id, $filters );

		wp_send_json_success(
			array(
				'log'     => Mailpai_Smtp_Log::detail_for_view( $row ),
				'prev_id' => $adjacent['prev_id'],
				'next_id' => $adjacent['next_id'],
			)
		);
	}

	/**
	 * AJAX retry failed log.
	 */
	public function ajax_retry_log() {
		check_ajax_referer( 'mailpai_smtp_admin', 'nonce' );
		if ( ! Mailpai_Smtp_Capabilities::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'smtp-pai' ) ), 403 );
		}
		$id  = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		$row = Mailpai_Smtp_Log::get( $id );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Log not found.', 'smtp-pai' ) ) );
		}
		$route = $row['route'] ?: 'default';
		$res   = Mailpai_Smtp_Mailer::send_for_route(
			$route,
			array(
				'to'      => $row['recipient'],
				'subject' => $row['subject'],
				'message' => ! empty( $row['body'] ) ? $row['body'] : '<p>' . esc_html__( 'Retry send', 'smtp-pai' ) . '</p>',
				'headers' => array(),
			)
		);
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Retry sent.', 'smtp-pai' ) ) );
	}

	/**
	 * Render admin page.
	 */
	public function render() {
		if ( ! Mailpai_Smtp_Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'smtp-pai' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed = array( 'dashboard', 'routes', 'backup', 'log', 'settings' );
		if ( ! in_array( $tab, $allowed, true ) ) {
			$tab = 'dashboard';
		}

		$view = MAILPAI_SMTP_PLUGIN_DIR . 'includes/admin/views/' . $tab . '.php';
		if ( ! is_readable( $view ) ) {
			$view = MAILPAI_SMTP_PLUGIN_DIR . 'includes/admin/views/dashboard.php';
		}

		include MAILPAI_SMTP_PLUGIN_DIR . 'includes/admin/views/layout.php';
	}

	/**
	 * Persist admin notices and redirect (post/redirect/get).
	 *
	 * @param string $url Safe redirect URL.
	 */
	private function redirect_with_notices( $url ) {
		if ( function_exists( 'get_settings_errors' ) ) {
			set_transient( 'settings_errors', get_settings_errors(), 30 );
		}
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', $url ) );
		exit;
	}

	/**
	 * Render admin notices.
	 */
	public function render_notices() {
		if ( ! function_exists( 'get_settings_errors' ) ) {
			return;
		}

		$errors = get_settings_errors( 'mailpai_smtp' );
		if ( empty( $errors ) || ! is_array( $errors ) ) {
			return;
		}

		foreach ( $errors as $error ) {
			if ( empty( $error['message'] ) ) {
				continue;
			}
			$code     = sanitize_key( (string) ( $error['code'] ?? '' ) );
			$is_error = ( 'error' === ( $error['type'] ?? '' ) );
			$class    = 'mailpai-smtp-flash-notice mailpai-smtp-flash-notice--' . ( $is_error ? 'error' : 'success' );

			if ( 'test_success' === $code ) {
				printf(
					'<div class="%1$s" role="status"><strong class="mailpai-smtp-flash-notice__title">%2$s</strong> <span class="mailpai-smtp-flash-notice__text">%3$s</span></div>',
					esc_attr( $class ),
					esc_html__( 'Success', 'smtp-pai' ),
					esc_html( $error['message'] )
				);
				continue;
			}

			if ( 'test_failed' === $code ) {
				printf(
					'<div class="%1$s" role="alert"><strong class="mailpai-smtp-flash-notice__title">%2$s</strong> <span class="mailpai-smtp-flash-notice__text">%3$s</span></div>',
					esc_attr( $class ),
					esc_html__( 'Test failed.', 'smtp-pai' ),
					esc_html( $error['message'] )
				);
				continue;
			}

			printf(
				'<div class="%1$s" role="%2$s"><p>%3$s</p></div>',
				esc_attr( $class ),
				$is_error ? 'alert' : 'status',
				esc_html( $error['message'] )
			);
		}
	}
}
