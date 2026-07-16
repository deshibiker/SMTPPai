<?php
/**
 * Helpful Hints accordion content for connection setup.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailpai_Smtp_Connection_Hints
 */
class Mailpai_Smtp_Connection_Hints {

	/**
	 * Accordion sections for a provider connection form.
	 *
	 * @param string $slug Provider slug.
	 * @return array<int,array{title:string,content:string,open?:bool}>
	 */
	public static function sections( $slug ) {
		$slug = Mailpai_Smtp_Provider_Registry::normalize_slug( (string) $slug );
		$map  = self::provider_sections();

		if ( isset( $map[ $slug ] ) ) {
			$sections = $map[ $slug ];
		} else {
			$sections = self::generic_sections( $slug );
		}

		/**
		 * Filter connection setup hint sections.
		 *
		 * @param array  $sections Accordion sections.
		 * @param string $slug     Provider slug.
		 */
		return apply_filters( 'mailpai_smtp_connection_hints', $sections, $slug );
	}

	/**
	 * @param string $url   External URL.
	 * @param string $label Link label.
	 * @return string
	 */
	private static function link( $url, $label ) {
		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * @param string[] $items Step lines.
	 * @return string
	 */
	private static function steps( array $items ) {
		if ( empty( $items ) ) {
			return '';
		}
		$html = '<ol class="mailpai-smtp-hints-steps">';
		foreach ( $items as $item ) {
			$html .= '<li>' . wp_kses_post( $item ) . '</li>';
		}
		$html .= '</ol>';
		return $html;
	}

	/**
	 * @return array{title:string,content:string}
	 */
	private static function connection_name_section() {
		return array(
			'title'   => __( 'Connection name', 'smtp-pai' ),
			'content' => sprintf(
				'<p>%s</p>',
				wp_kses(
					sprintf(
						/* translators: 1: WordPress, 2: WooCommerce, 3: Newsletter, 4: Outreach */
						__( 'Name this connection anything that helps you specify its routing task. Use separate connections for different purposes like %1$s, %2$s, %3$s, or %4$s. It won\'t be seen by recipients and doesn\'t affect From details.', 'smtp-pai' ),
						'<strong>' . esc_html__( 'WordPress', 'smtp-pai' ) . '</strong>',
						'<strong>' . esc_html__( 'WooCommerce', 'smtp-pai' ) . '</strong>',
						'<strong>' . esc_html__( 'Newsletter', 'smtp-pai' ) . '</strong>',
						'<strong>' . esc_html__( 'Outreach', 'smtp-pai' ) . '</strong>'
					),
					array( 'strong' => array() )
				)
			),
		);
	}

	/**
	 * @return array{title:string,content:string}
	 */
	private static function credential_storage_section() {
		return array(
			'title'   => __( 'Credential storage', 'smtp-pai' ),
			'content' => sprintf(
				'<p>%s</p>',
				wp_kses(
					sprintf(
						/* translators: 1: database storage label, 2: wp-config storage label */
						__( 'Choose %1$s to save your credentials encrypted in WordPress, or %2$s to define constants securely in your configuration file.', 'smtp-pai' ),
						'<strong>' . esc_html__( 'Store keys in database', 'smtp-pai' ) . '</strong>',
						'<strong>' . esc_html__( 'Keep in wp-config.php', 'smtp-pai' ) . '</strong>'
					),
					array( 'strong' => array() )
				)
			),
		);
	}

	/**
	 * @param string $slug Provider slug.
	 * @return array{title:string,content:string}|null
	 */
	private static function bounce_handler_section( $slug ) {
		if ( 'amazon_ses' === $slug && class_exists( 'Mailpai_Bounce_Handler' ) ) {
			$url = Mailpai_Bounce_Handler::public_url();
			return array(
				'title'   => __( 'Bounce handler (recommended)', 'smtp-pai' ),
				'content' => sprintf(
					/* translators: 1: bounce handler URL, 2: AWS SNS setup link */
					__( '<p>Optional for sending, but recommended in production. Subscribe this HTTPS endpoint in Amazon SNS if you use SES event notifications for bounces and complaints:</p><p class="mailpai-smtp-hints__url-wrap"><code class="mailpai-smtp-hints__url">%1$s</code></p><p>%2$s</p>', 'smtp-pai' ),
					esc_html( $url ),
					self::link(
						'https://docs.aws.amazon.com/ses/latest/dg/event-publishing-add-sns-event-destination.html',
						__( 'How to connect SNS to SES', 'smtp-pai' )
					)
				),
			);
		}

		$docs = array(
			'amazon_ses' => array(
				'https://docs.aws.amazon.com/ses/latest/dg/event-publishing-add-sns-event-destination.html',
				__( 'How to configure SES bounce notifications (AWS)', 'smtp-pai' ),
			),
			'mailgun'    => array(
				'https://documentation.mailgun.com/docs/mailgun/user-manual/events/webhooks',
				__( 'Mailgun webhooks documentation', 'smtp-pai' ),
			),
			'postmark'   => array(
				'https://postmarkapp.com/developer/webhooks/bounce-webhook',
				__( 'Postmark bounce webhook setup', 'smtp-pai' ),
			),
		);

		if ( ! isset( $docs[ $slug ] ) ) {
			return null;
		}

		return array(
			'title'   => __( 'Bounce handler (optional)', 'smtp-pai' ),
			'content' => sprintf(
				/* translators: %s: provider documentation link */
				__( '<p>Not required to send email, but recommended in production so bounces and complaints are tracked. Configure webhooks in your provider dashboard:</p><p>%s</p>', 'smtp-pai' ),
				self::link( $docs[ $slug ][0], $docs[ $slug ][1] )
			),
		);
	}

	/**
	 * @param string $slug Provider slug.
	 * @return array<int,array{title:string,content:string,open?:bool}>
	 */
	private static function generic_sections( $slug ) {
		$def = Mailpai_Smtp_Provider_Registry::get( $slug );
		if ( empty( $def ) ) {
			return array();
		}

		$sections   = array( self::connection_name_section() );
		$open_set   = false;
		$api_links  = self::api_provider_links();
		$api_hint   = isset( $api_links[ $slug ] ) ? $api_links[ $slug ] : null;

		if ( 'api' === ( $def['transport'] ?? '' ) ) {
			$content = '<p>' . esc_html__( 'Create an API key in your provider dashboard and paste it into the field on the left.', 'smtp-pai' ) . '</p>';
			if ( $api_hint ) {
				$content .= '<ul>';
				if ( ! empty( $api_hint['create'] ) ) {
					$content .= '<li>' . sprintf(
						/* translators: %s: create API key link */
						__( '<strong>Create key:</strong> %s', 'smtp-pai' ),
						self::link( $api_hint['create'][0], $api_hint['create'][1] )
					) . '</li>';
				}
				if ( ! empty( $api_hint['find'] ) ) {
					$content .= '<li>' . sprintf(
						/* translators: %s: find credentials link */
						__( '<strong>Find credentials:</strong> %s', 'smtp-pai' ),
						self::link( $api_hint['find'][0], $api_hint['find'][1] )
					) . '</li>';
				}
				$content .= '</ul>';
			}
			$sections[] = array(
				'title'   => __( 'API key', 'smtp-pai' ),
				'content' => $content,
				'open'    => true,
			);
			$open_set   = true;
		}

		if ( 'smtp' === ( $def['transport'] ?? '' ) && ! empty( $def['fields'] ) ) {
			foreach ( $def['fields'] as $key => $field ) {
				if ( 'encryption' === $key ) {
					continue;
				}
				$sections[] = array(
					'title'   => (string) ( $field['label'] ?? $key ),
					'content' => '<p>' . esc_html( self::field_hint( $key, $slug ) ) . '</p>',
					'open'    => ! $open_set,
				);
				if ( ! $open_set ) {
					$open_set = true;
				}
			}
		}

		if ( empty( $open_set ) ) {
			$sections[] = array(
				'title'   => __( 'Credentials', 'smtp-pai' ),
				'content' => '<p>' . esc_html__( 'Paste the credentials from your email provider dashboard into the matching fields on the left.', 'smtp-pai' ) . '</p>',
				'open'    => true,
			);
		}

		$bounce = self::bounce_handler_section( $slug );
		if ( null !== $bounce ) {
			$sections[] = $bounce;
		}

		$sections[] = self::credential_storage_section();

		return $sections;
	}

	/**
	 * @return array<string,array{create?:array{0:string,1:string},find?:array{0:string,1:string}}>
	 */
	private static function api_provider_links() {
		return array(
			'brevo'         => array(
				'create' => array( 'https://app.brevo.com/settings/keys/smtp', __( 'Brevo → SMTP & API keys', 'smtp-pai' ) ),
				'find'   => array( 'https://app.brevo.com/settings/keys/smtp', __( 'View saved API keys', 'smtp-pai' ) ),
			),
			'resend'        => array(
				'create' => array( 'https://resend.com/api-keys', __( 'Resend → API Keys → Create', 'smtp-pai' ) ),
				'find'   => array( 'https://resend.com/api-keys', __( 'View API keys', 'smtp-pai' ) ),
			),
			'sendgrid'      => array(
				'create' => array( 'https://app.sendgrid.com/settings/api_keys', __( 'SendGrid → Settings → API Keys', 'smtp-pai' ) ),
				'find'   => array( 'https://app.sendgrid.com/settings/api_keys', __( 'View API keys', 'smtp-pai' ) ),
			),
			'mailersend'    => array(
				'create' => array( 'https://www.mailersend.com/help/managing-api-tokens', __( 'MailerSend API tokens', 'smtp-pai' ) ),
				'find'   => array( 'https://app.mailersend.com/domains', __( 'MailerSend dashboard', 'smtp-pai' ) ),
			),
			'mailjet'       => array(
				'create' => array( 'https://app.mailjet.com/account/apikeys', __( 'Mailjet → Account → API keys', 'smtp-pai' ) ),
				'find'   => array( 'https://app.mailjet.com/account/apikeys', __( 'View API keys', 'smtp-pai' ) ),
			),
			'elastic_email' => array(
				'create' => array( 'https://app.elasticemail.com/marketing/settings/manage-api', __( 'Elastic Email → Settings → API', 'smtp-pai' ) ),
				'find'   => array( 'https://app.elasticemail.com/marketing/settings/manage-api', __( 'View API keys', 'smtp-pai' ) ),
			),
			'mandrill'      => array(
				'create' => array( 'https://mandrillapp.com/settings', __( 'Mailchimp Transactional → Settings → SMTP & API', 'smtp-pai' ) ),
				'find'   => array( 'https://mandrillapp.com/settings', __( 'View API key', 'smtp-pai' ) ),
			),
			'sparkpost'     => array(
				'create' => array( 'https://app.sparkpost.com/account/api-keys', __( 'SparkPost → Account → API Keys', 'smtp-pai' ) ),
				'find'   => array( 'https://app.sparkpost.com/account/api-keys', __( 'View API keys', 'smtp-pai' ) ),
			),
			'zeptomail'     => array(
				'create' => array( 'https://www.zoho.com/zeptomail/help/api/email-sending.html', __( 'Zepto Mail → Agent → SMTP/API → API', 'smtp-pai' ) ),
				'find'   => array( 'https://www.zoho.com/zeptomail/', __( 'Zepto Mail dashboard', 'smtp-pai' ) ),
			),
			'smtp2go'       => array(
				'create' => array( 'https://support.smtp2go.com/hc/en-gb/articles/20733554340249-API-Keys', __( 'SMTP2GO API keys guide', 'smtp-pai' ) ),
				'find'   => array( 'https://app.smtp2go.com/', __( 'SMTP2GO app (Sending → API Keys)', 'smtp-pai' ) ),
			),
			'smtp_com'      => array(
				'create' => array( 'https://www.smtp.com/resources/api-documentation/', __( 'SMTP.com API documentation', 'smtp-pai' ) ),
				'find'   => array( 'https://my.smtp.com/', __( 'SMTP.com dashboard', 'smtp-pai' ) ),
			),
		);
	}

	/**
	 * @param string $field_key Field key.
	 * @param string $slug      Provider slug.
	 * @return string
	 */
	private static function field_hint( $field_key, $slug ) {
		$map = array(
			'host'        => __( 'The SMTP hostname from your provider (for example smtp.example.com).', 'smtp-pai' ),
			'port'        => __( 'SMTP port. Common values are 587 for TLS and 465 for SSL.', 'smtp-pai' ),
			'user'        => __( 'Usually your full email address or the SMTP username from your provider.', 'smtp-pai' ),
			'smtp_secret' => __( 'Your SMTP password or app-specific password from the provider.', 'smtp-pai' ),
			'api_key'     => __( 'API key from your provider dashboard.', 'smtp-pai' ),
			'api_secret'  => __( 'Secondary secret if your provider requires one.', 'smtp-pai' ),
		);
		return isset( $map[ $field_key ] ) ? $map[ $field_key ] : __( 'Enter the value from your provider documentation.', 'smtp-pai' );
	}

	/**
	 * @param string $provider_label Provider display name.
	 * @param string $create_url   OAuth app creation URL.
	 * @param string $create_label Link label for creation.
	 * @param string $scopes_note  Optional scopes note.
	 * @return array<int,array{title:string,content:string,open?:bool}>
	 */
	private static function oauth_sections( $provider_label, $create_url, $create_label, $scopes_note = '' ) {
		$sections = array(
			self::connection_name_section(),
			array(
				'title'   => __( 'From email', 'smtp-pai' ),
				'content' => sprintf(
					'<p>%s</p>',
					sprintf(
						/* translators: %s: provider name such as Google */
						esc_html__( 'The address recipients see in the From field. For %s mailboxes, this is usually filled in automatically after you save and authorize the connection.', 'smtp-pai' ),
						esc_html( $provider_label )
					)
				),
			),
			array(
				'title'   => __( 'Application Client ID', 'smtp-pai' ),
				'content' => sprintf(
					/* translators: 1: create app link, 2: optional scopes note */
					__( '<p>Create an OAuth app (Web application), then copy the Client ID here.</p><ul><li><strong>Create app:</strong> %1$s</li></ul>%2$s', 'smtp-pai' ),
					self::link( $create_url, $create_label ),
					'' !== $scopes_note ? '<p>' . esc_html( $scopes_note ) . '</p>' : ''
				),
				'open'    => true,
			),
			array(
				'title'   => __( 'Application Client Secret', 'smtp-pai' ),
				'content' => '<p>' . esc_html__( 'From the same OAuth app, open credentials and copy the Client secret. Treat it like a password — SMTPPai stores it encrypted unless you use wp-config.php storage.', 'smtp-pai' ) . '</p>',
			),
			array(
				'title'   => __( 'Authorized Redirect URI', 'smtp-pai' ),
				'content' => sprintf(
					'<p>%s</p><p>%s</p>',
					wp_kses(
						sprintf(
							/* translators: 1: OAuth redirect URIs field label */
							__( 'Copy the redirect URI from the form on the left and paste it into your Google OAuth client under %1$s. It must match exactly.', 'smtp-pai' ),
							'<strong>' . esc_html__( 'Authorized redirect URIs', 'smtp-pai' ) . '</strong>'
						),
						array(
							'strong' => array(),
						)
					),
					esc_html__( 'For Google, SMTPPai uses https://auth.mailpai.com/google. You do not need your WordPress site URL in Google Console.', 'smtp-pai' )
				),
			),
			self::credential_storage_section(),
		);

		return $sections;
	}

	/**
	 * Microsoft / Hotmail / Outlook.com OAuth setup sections.
	 *
	 * @return array<int,array{title:string,content:string,open?:bool}>
	 */
	private static function microsoft_oauth_sections() {
		return array(
			self::connection_name_section(),
			array(
				'title'   => __( 'From email', 'smtp-pai' ),
				'content' => '<p>' . esc_html__( 'For Microsoft mailboxes, the From email is usually filled in automatically after you authorize. Hotmail and Outlook.com personal accounts are supported.', 'smtp-pai' ) . '</p>',
			),
			array(
				'title'   => __( 'Application Client ID & Secret', 'smtp-pai' ),
				'content' => sprintf(
					/* translators: 1: Entra app registrations link */
					__( '<p>Register a <strong>Web</strong> app in Microsoft Entra, create a client secret, and paste both values into SMTPPai.</p><p><strong>Create app:</strong> %1$s → New registration → Web platform</p><p>Under <strong>Supported account types</strong>, choose <strong>personal Microsoft accounts and work/school accounts</strong> if you use Hotmail or Outlook.com.</p>', 'smtp-pai' ),
					self::link( 'https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade', __( 'Microsoft Entra → App registrations', 'smtp-pai' ) )
				),
				'open'    => true,
			),
			array(
				'title'   => __( 'Authorized Redirect URI', 'smtp-pai' ),
				'content' => '<p>' . esc_html__( 'Copy the redirect URI from the form on the left and add it under Microsoft Entra → your app → Authentication → Web redirect URIs. Use the exact relay URL https://auth.mailpai.com/microsoft — not your WordPress site URL.', 'smtp-pai' ) . '</p>',
			),
			array(
				'title'   => __( 'API permissions', 'smtp-pai' ),
				'content' => sprintf(
					/* translators: %s: Microsoft SMTP OAuth guide link */
					__( '<p>Add delegated permission <strong>Office 365 Exchange Online → SMTP.Send</strong>. Grant admin consent if your tenant requires it. SMTPPai reads your mailbox address from the OpenID sign-in response — you do not need Microsoft Graph User.Read.</p><p><strong>Docs:</strong> %s</p>', 'smtp-pai' ),
					self::link( 'https://learn.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth', __( 'Microsoft SMTP OAuth guide', 'smtp-pai' ) )
				),
			),
			array(
				'title'   => __( 'Authorization code', 'smtp-pai' ),
				'content' => '<p>' . esc_html__( 'After you sign in, the OAuth relay shows your authorization code on the token page. You are redirected back to WordPress automatically. If that fails, copy the code and paste it into the Authorization code field in SMTPPai, then click Complete authorization.', 'smtp-pai' ) . '</p>',
			),
			array(
				'title'   => __( 'Hotmail / Outlook.com SMTP', 'smtp-pai' ),
				'content' => '<p>' . esc_html__( 'Personal accounts (@hotmail.com, @outlook.com, @live.com) send through smtp-mail.outlook.com. SMTPPai selects that host automatically after sign-in.', 'smtp-pai' ) . '</p>',
			),
			self::credential_storage_section(),
		);
	}

	/**
	 * @return array<string,array<int,array{title:string,content:string,open?:bool}>>
	 */
	private static function provider_sections() {
		$append_bounce = static function ( array $sections, $slug ) {
			$bounce = self::bounce_handler_section( $slug );
			if ( null !== $bounce ) {
				array_splice( $sections, -1, 0, array( $bounce ) );
			}
			return $sections;
		};

		return array(
			'google'     => self::oauth_sections(
				__( 'Google', 'smtp-pai' ),
				'https://console.cloud.google.com/apis/credentials',
				__( 'Google Cloud → Credentials → Create OAuth client ID', 'smtp-pai' ),
				__( 'Enable the Gmail API and add Gmail send scope. For Google Workspace, use a Web application client.', 'smtp-pai' )
			),
			'microsoft'  => self::microsoft_oauth_sections(),
			'amazon_ses' => $append_bounce(
				array(
					self::connection_name_section(),
					array(
						'title'   => __( 'Access key ID', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: 1: IAM users link, 2: SES setup guide link */
							__( '<p>Create an IAM user with permission to send through SES, then create an access key.</p><ul><li><strong>Create keys:</strong> %1$s → Security credentials → Create access key</li><li><strong>Setup guide:</strong> %2$s</li></ul><p>Use the Access key ID shown after creation.</p>', 'smtp-pai' ),
							self::link( 'https://console.aws.amazon.com/iam/home#/users', __( 'AWS IAM → Users', 'smtp-pai' ) ),
							self::link( 'https://docs.aws.amazon.com/ses/latest/dg/setting-up.html', __( 'Amazon SES getting started', 'smtp-pai' ) )
						),
						'open'    => true,
					),
					array(
						'title'   => __( 'Secret access key', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: %s: IAM credentials link */
							__( '<p>AWS shows the secret access key only once when the key is created. Copy it immediately into this field or wp-config.php.</p><p><strong>Find existing keys:</strong> %s → select user → Security credentials (you cannot view an old secret; create a new key if lost).</p>', 'smtp-pai' ),
							self::link( 'https://console.aws.amazon.com/iam/home#/users', __( 'AWS IAM → Users', 'smtp-pai' ) )
						),
					),
					array(
						'title'   => __( 'Region', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: %s: SES console link */
							__( '<p>Choose the AWS region where your domain or email identity is verified in SES. Sending from a different region will fail until that identity is verified there too.</p><p><strong>Open SES console:</strong> %s (switch region in the top-right of AWS).</p>', 'smtp-pai' ),
							self::link( 'https://console.aws.amazon.com/ses/home', __( 'Amazon SES console', 'smtp-pai' ) )
						),
					),
					self::credential_storage_section(),
				),
				'amazon_ses'
			),
			'mailgun'    => $append_bounce(
				array(
					self::connection_name_section(),
					array(
						'title'   => __( 'API key', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: 1: API security link, 2: domains link */
							__( '<p>Copy your <strong>Private API key</strong>. If your Mailgun account is in the EU, choose <strong>EU</strong> in the region field.</p><ul><li><strong>Find credentials:</strong> %1$s</li><li><strong>Domains:</strong> %2$s</li></ul>', 'smtp-pai' ),
							self::link( 'https://app.mailgun.com/settings/api_security', __( 'Mailgun → Settings → API security', 'smtp-pai' ) ),
							self::link( 'https://app.mailgun.com/mg/sending/domains', __( 'Mailgun → Sending → Domains', 'smtp-pai' ) )
						),
						'open'    => true,
					),
					array(
						'title'   => __( 'Domain name', 'smtp-pai' ),
						'content' => self::steps(
							array(
								sprintf(
									/* translators: %s: Mailgun domains link */
									__( '→ %s — copy exact sending domain', 'smtp-pai' ),
									self::link( 'https://app.mailgun.com/mg/sending/domains', __( 'Mailgun → Sending → Domains', 'smtp-pai' ) )
								),
								__( 'Paste into <strong>Domain name</strong> left (e.g. mg.yourdomain.com or sandbox…mailgun.org)', 'smtp-pai' ),
								__( 'Must match domain verified in Mailgun — not always same as From email', 'smtp-pai' ),
							)
						),
					),
					self::credential_storage_section(),
				),
				'mailgun'
			),
			'postmark'   => $append_bounce(
				array(
					self::connection_name_section(),
					array(
						'title'   => __( 'Server API token', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: 1: server API token link, 2: postmark servers link */
							__( '<p>Each Postmark server has its own API token. Use the token for the server that sends your mail.</p><ul><li><strong>Find credentials:</strong> %1$s → API Tokens</li><li><strong>Create server:</strong> %2$s</li></ul>', 'smtp-pai' ),
							self::link( 'https://account.postmarkapp.com/servers', __( 'Postmark → Servers', 'smtp-pai' ) ),
							self::link( 'https://account.postmarkapp.com/servers/new', __( 'Add a Postmark server', 'smtp-pai' ) )
						),
						'open'    => true,
					),
					array(
						'title'   => __( 'Message Stream ID', 'smtp-pai' ),
						'content' => '<p>' . esc_html__( 'Use outbound for normal transactional mail. Copy the stream ID from Postmark → your server → Message Streams if you use a custom stream.', 'smtp-pai' ) . '</p>',
						'open'    => false,
					),
					array(
						'title'   => __( 'Pending account', 'smtp-pai' ),
						'content' => '<p>' . esc_html__( 'New Postmark accounts can only send to addresses on the same domain as your From email until approval. For tests, use an admin email like support@yourdomain.com or wait for Postmark to approve your account.', 'smtp-pai' ) . '</p>',
						'open'    => false,
					),
					self::credential_storage_section(),
				),
				'postmark'
			),
			'brevo'      => $append_bounce(
				array(
					self::connection_name_section(),
					array(
						'title'   => __( 'API key', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: 1: create API key link, 2: find API keys link */
							__( '<p>Create an <strong>API v3</strong> key with transactional email permission.</p><ul><li><strong>Create key:</strong> %1$s</li><li><strong>Find credentials:</strong> %2$s</li></ul>', 'smtp-pai' ),
							self::link( 'https://app.brevo.com/settings/keys/smtp', __( 'Brevo → SMTP & API → API keys', 'smtp-pai' ) ),
							self::link( 'https://app.brevo.com/settings/keys/smtp', __( 'View saved API keys', 'smtp-pai' ) )
						),
						'open'    => true,
					),
					array(
						'title'   => __( 'Authorized IPs', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: %s: Brevo authorized IPs link */
							__( '<p>If Brevo blocks requests from an unknown IP, add your server public IP under <strong>Security → Authorized IPs</strong>, or disable IP restriction for API access.</p><p><strong>Open settings:</strong> %s</p><p>On local dev sites, your outgoing IP is usually your home/office public IP — add that address, not <code>127.0.0.1</code>.</p>', 'smtp-pai' ),
							self::link( 'https://app.brevo.com/security/authorised_ips', __( 'Brevo → Security → Authorized IPs', 'smtp-pai' ) )
						),
						'open'    => false,
					),
					array(
						'title'   => __( 'From email', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: %s: Brevo senders link */
							__( '<p>Your From address must be a verified sender or on a verified domain in Brevo.</p><p><strong>Verify sender:</strong> %s</p>', 'smtp-pai' ),
							self::link( 'https://app.brevo.com/senders/list', __( 'Brevo → Senders, Domains & Dedicated IPs', 'smtp-pai' ) )
						),
						'open'    => false,
					),
					self::credential_storage_section(),
				),
				'brevo'
			),
			'mailjet'    => $append_bounce(
				array(
					self::connection_name_section(),
					array(
						'title'   => __( 'API key & Secret key', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: 1: create API keys link, 2: find API keys link */
							__( '<p>Mailjet gives you an <strong>API key</strong> and a <strong>Secret key</strong>. Copy both into the matching fields on the left.</p><ul><li><strong>Find credentials:</strong> %1$s</li><li><strong>Create keys:</strong> %2$s</li></ul>', 'smtp-pai' ),
							self::link( 'https://app.mailjet.com/account/apikeys', __( 'Mailjet → Account → API keys', 'smtp-pai' ) ),
							self::link( 'https://app.mailjet.com/account/apikeys', __( 'View API keys', 'smtp-pai' ) )
						),
						'open'    => true,
					),
					array(
						'title'   => __( 'From email', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: %s: Mailjet sender domains link */
							__( '<p>Your From address must be on a verified sender domain in Mailjet.</p><p><strong>Verify domain:</strong> %s</p>', 'smtp-pai' ),
							self::link( 'https://app.mailjet.com/account/sender', __( 'Mailjet → Sender domains & addresses', 'smtp-pai' ) )
						),
						'open'    => false,
					),
					self::credential_storage_section(),
				),
				'mailjet'
			),
			'elastic_email' => $append_bounce(
				array(
					self::connection_name_section(),
					array(
						'title'   => __( 'API key', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: 1: API settings link, 2: view API keys link */
							__( '<p>Create an API key with <strong>Send HTTP</strong> permission.</p><ul><li><strong>Create key:</strong> %1$s</li><li><strong>Find credentials:</strong> %2$s</li></ul>', 'smtp-pai' ),
							self::link( 'https://app.elasticemail.com/marketing/settings/manage-api', __( 'Elastic Email → Settings → API', 'smtp-pai' ) ),
							self::link( 'https://app.elasticemail.com/marketing/settings/manage-api', __( 'View API keys', 'smtp-pai' ) )
						),
						'open'    => true,
					),
					array(
						'title'   => __( 'Verified sending domain', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: %s: Elastic Email domains link */
							__( '<p>Your <strong>From email</strong> must use a domain verified in Elastic Email with SPF and DKIM records. Unverified senders are often blocked or sent from elasticemail.com and may land in spam.</p><p><strong>Verify domain:</strong> %s</p>', 'smtp-pai' ),
							self::link( 'https://app.elasticemail.com/marketing/settings/manage/domains', __( 'Elastic Email → Settings → Domains', 'smtp-pai' ) )
						),
						'open'    => false,
					),
					array(
						'title'   => __( 'Test not received?', 'smtp-pai' ),
						'content' => '<p>' . esc_html__( 'If the test says sent but nothing arrives, check spam/junk, confirm the recipient is not unsubscribed in Elastic Email, and open Activity in your Elastic Email dashboard for the delivery status.', 'smtp-pai' ) . '</p>',
						'open'    => false,
					),
					self::credential_storage_section(),
				),
				'elastic_email'
			),
			'sendgrid'   => $append_bounce(
				array(
					self::connection_name_section(),
					array(
						'title'   => __( 'API key', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: 1: SendGrid API keys link */
							__( '<p>Create an API key with <strong>Mail Send</strong> permission (Restricted Access is fine).</p><p><strong>Create key:</strong> %1$s → Create API Key</p>', 'smtp-pai' ),
							self::link( 'https://app.sendgrid.com/settings/api_keys', __( 'SendGrid → Settings → API Keys', 'smtp-pai' ) )
						),
						'open'    => true,
					),
					array(
						'title'   => __( 'Region', 'smtp-pai' ),
						'content' => '<p>' . esc_html__( 'Select EU if your SendGrid account is in the EU (api.eu.sendgrid.com). US accounts use the default US endpoint.', 'smtp-pai' ) . '</p>',
						'open'    => false,
					),
					array(
						'title'   => __( 'Sender authentication', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: %s: SendGrid sender authentication link */
							__( '<p>Your <strong>From email</strong> must use a verified single sender or a domain authenticated in SendGrid (SPF and DKIM).</p><p><strong>Verify sender:</strong> %s</p>', 'smtp-pai' ),
							self::link( 'https://app.sendgrid.com/settings/sender_auth', __( 'SendGrid → Settings → Sender Authentication', 'smtp-pai' ) )
						),
						'open'    => false,
					),
					array(
						'title'   => __( 'Test not received?', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: %s: SendGrid activity feed link */
							__( '<p>If SMTPPai says sent but nothing arrives: check spam/junk, confirm sender authentication is complete, and open <strong>Activity</strong> in SendGrid for the delivery/bounce reason.</p><p><strong>Check delivery:</strong> %s</p>', 'smtp-pai' ),
							self::link( 'https://app.sendgrid.com/email_activity', __( 'SendGrid → Activity', 'smtp-pai' ) )
						),
						'open'    => false,
					),
					self::credential_storage_section(),
				),
				'sendgrid'
			),
			'sparkpost'  => $append_bounce(
				array(
					self::connection_name_section(),
					array(
						'title'   => __( 'API key', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: 1: SparkPost API keys link */
							__( '<p>Create an API key with permission to send via the Transmissions API.</p><p><strong>Create key:</strong> %1$s → Create API key</p>', 'smtp-pai' ),
							self::link( 'https://app.sparkpost.com/account/api-keys', __( 'SparkPost → Account → API Keys', 'smtp-pai' ) )
						),
						'open'    => true,
					),
					array(
						'title'   => __( 'Region', 'smtp-pai' ),
						'content' => '<p>' . esc_html__( 'Select EU if your SparkPost account is in the EU (api.eu.sparkpost.com). US accounts use the default US endpoint.', 'smtp-pai' ) . '</p>',
						'open'    => false,
					),
					array(
						'title'   => __( 'From email', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: %s: SparkPost sending domains link */
							__( '<p>Your From address must be on a <strong>verified</strong> sending domain in SparkPost (DNS records completed).</p><p><strong>Verify domain:</strong> %s</p>', 'smtp-pai' ),
							self::link( 'https://app.sparkpost.com/account/sending-domains', __( 'SparkPost → Sending → Sending Domains', 'smtp-pai' ) )
						),
						'open'    => false,
					),
					array(
						'title'   => __( 'Test not received?', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: %s: SparkPost events link */
							__( '<p>If SMTPPai says sent but nothing arrives: check spam/junk, confirm the From domain is verified, and open <strong>Events</strong> in SparkPost for the delivery/bounce reason.</p><p><strong>Check delivery:</strong> %s</p>', 'smtp-pai' ),
							self::link( 'https://app.sparkpost.com/reports/message-events', __( 'SparkPost → Reports → Events', 'smtp-pai' ) )
						),
						'open'    => false,
					),
					self::credential_storage_section(),
				),
				'sparkpost'
			),
			'zeptomail'  => $append_bounce(
				array(
					self::connection_name_section(),
					array(
						'title'   => __( 'Send Mail token', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: 1: Zepto Mail API docs link */
							__( '<p>Copy the <strong>Send Mail token</strong> from your Agent → SMTP/API → API tab.</p><p>Paste <strong>only the token</strong> — not <code>Zoho-enczapikey</code> and not the full Authorization header from the sample curl command.</p><p><strong>Find token:</strong> %1$s</p>', 'smtp-pai' ),
							self::link( 'https://www.zoho.com/zeptomail/help/api/email-sending.html', __( 'Zepto Mail API guide', 'smtp-pai' ) )
						),
						'open'    => true,
					),
					array(
						'title'   => __( 'Hosted region', 'smtp-pai' ),
						'content' => '<p>' . esc_html__( 'Select the region where your Zepto Mail account is hosted (US, EU, or India). A wrong region often causes access denied or invalid token errors.', 'smtp-pai' ) . '</p>',
						'open'    => false,
					),
					array(
						'title'   => __( 'From email', 'smtp-pai' ),
						'content' => '<p>' . esc_html__( 'Use an address on a domain verified in your Zepto Mail Agent (Domains tab). The sample uses noreply@yourdomain.com — any address on the verified domain works.', 'smtp-pai' ) . '</p>',
						'open'    => false,
					),
					array(
						'title'   => __( 'Access denied?', 'smtp-pai' ),
						'content' => '<p>' . esc_html__( 'If you see access denied: re-paste only the token (no prefix), match Hosted region, verify the From domain, and check Zepto Mail → Settings → Allowed IPs if IP restriction is enabled.', 'smtp-pai' ) . '</p>',
						'open'    => false,
					),
					self::credential_storage_section(),
				),
				'zeptomail'
			),
			'other_smtp' => array(
				self::connection_name_section(),
				array(
					'title'   => __( 'SMTP host', 'smtp-pai' ),
					'content' => sprintf(
						'<p>%s</p>',
						wp_kses(
							sprintf(
								/* translators: %s: example SMTP hostname */
								__( 'Your provider SMTP hostname (for example %s). Check your hosting or email provider documentation for the correct host.', 'smtp-pai' ),
								'<code>smtp.example.com</code>'
							),
							array( 'code' => array() )
						)
					),
					'open'    => true,
				),
				array(
					'title'   => __( 'Port & encryption', 'smtp-pai' ),
					'content' => sprintf(
						'<p>%s</p>',
						wp_kses(
							sprintf(
								/* translators: 1: TLS port, 2: SSL port */
								__( 'Port %1$s with TLS or port %2$s with SSL are most common. Match what your provider documents.', 'smtp-pai' ),
								'<code>587</code>',
								'<code>465</code>'
							),
							array( 'code' => array() )
						)
					),
				),
				array(
					'title'   => __( 'Username & password', 'smtp-pai' ),
					'content' => '<p>' . esc_html__( 'Usually your full email address and an SMTP password or app password. Many providers require an app-specific password instead of your login password.', 'smtp-pai' ) . '</p>',
				),
				self::credential_storage_section(),
			),
			'smtp2go'    => $append_bounce(
				array(
					self::connection_name_section(),
					array(
						'title'   => __( 'API key', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: 1: SMTP2GO app link, 2: API keys guide link */
							__( '<p>Sign in to SMTP2GO, open <strong>Sending → API Keys</strong>, click <strong>Add API Key</strong>, and enable <strong>Email Sending</strong> permission.</p><p>SMTPPai uses the global API at <code>api.smtp2go.com</code>, which SMTP2GO routes to the nearest region automatically — no regional setting is required.</p><p><strong>Open app:</strong> %1$s</p><p><strong>Setup guide:</strong> %2$s</p>', 'smtp-pai' ),
							self::link( 'https://app.smtp2go.com/', __( 'SMTP2GO dashboard', 'smtp-pai' ) ),
							self::link( 'https://support.smtp2go.com/hc/en-gb/articles/20733554340249-API-Keys', __( 'SMTP2GO API keys guide', 'smtp-pai' ) )
						),
						'open'    => true,
					),
					array(
						'title'   => __( 'From email', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: 1: SMTP2GO app link, 2: verified senders guide link */
							__( '<p>Your From address must be a verified sender in SMTP2GO (<strong>Sending → Verified Senders</strong>).</p><p><strong>Open app:</strong> %1$s</p><p><strong>Setup guide:</strong> %2$s</p>', 'smtp-pai' ),
							self::link( 'https://app.smtp2go.com/', __( 'SMTP2GO dashboard', 'smtp-pai' ) ),
							self::link( 'https://support.smtp2go.com/hc/en-gb/articles/115004408567-Verified-Senders', __( 'SMTP2GO verified senders guide', 'smtp-pai' ) )
						),
						'open'    => false,
					),
					self::credential_storage_section(),
				),
				'smtp2go'
			),
			'smtp_com'   => $append_bounce(
				array(
					self::connection_name_section(),
					array(
						'title'   => __( 'API key', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: 1: SMTP.com dashboard link, 2: API documentation link */
							__( '<p>Sign in to SMTP.com, open <strong>Account → API Keys</strong>, and create or copy an API key with permission to send email.</p><p>SMTPPai sends through the global API at <code>api.smtp.com/v4/messages</code>.</p><p><strong>Open dashboard:</strong> %1$s</p><p><strong>API docs:</strong> %2$s</p>', 'smtp-pai' ),
							self::link( 'https://my.smtp.com/', __( 'SMTP.com dashboard', 'smtp-pai' ) ),
							self::link( 'https://www.smtp.com/resources/api-documentation/', __( 'SMTP.com API documentation', 'smtp-pai' ) )
						),
						'open'    => true,
					),
					array(
						'title'   => __( 'Channel name', 'smtp-pai' ),
						'content' => sprintf(
							/* translators: %s: SMTP.com dashboard link */
							__( '<p>Each SMTP.com account uses <strong>channels</strong> (senders) to group sending domains and From addresses. Copy the channel name from <strong>Sending → Channels</strong> — it must match exactly (case-sensitive).</p><p><strong>Open dashboard:</strong> %s</p>', 'smtp-pai' ),
							self::link( 'https://my.smtp.com/', __( 'SMTP.com dashboard', 'smtp-pai' ) )
						),
						'open'    => false,
					),
					array(
						'title'   => __( 'From email', 'smtp-pai' ),
						'content' => '<p>' . esc_html__( 'Use a From address allowed on the selected channel — it must match an address configured for that sender in SMTP.com → Manage Senders. If the channel was created for a specific mailbox (for example a Gmail address), use that exact address here.', 'smtp-pai' ) . '</p>',
						'open'    => false,
					),
					array(
						'title'   => __( 'Delivery to other addresses', 'smtp-pai' ),
						'content' => '<p>' . esc_html__( 'SMTPPai sends the same API request for every recipient — there is no recipient filter in the plugin. SMTP.com only confirms that it accepted the message (HTTP 200); delivery to external inboxes happens afterward and can fail silently from the plugin’s view.', 'smtp-pai' ) . '</p><p>' . esc_html__( 'If tests reach your own Gmail but not addresses on your own domain, verify that domain in SMTP.com (SPF/DKIM), use a From address on that verified domain, and check Message Reports / suppression lists in the SMTP.com dashboard.', 'smtp-pai' ) . '</p>',
						'open'    => false,
					),
					self::credential_storage_section(),
				),
				'smtp_com'
			),
		);
	}
}
