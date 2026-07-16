<?php
/**
 * Connection setup form.
 *
 * @package Mailpai_Smtp
 *
 * @var array $rec Connection record.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$provider = Mailpai_Smtp_Provider_Registry::get( $rec['provider'] ?? '' );
if ( empty( $provider ) ) {
	echo '<p>' . esc_html__( 'Unknown provider.', 'smtp-pai' ) . '</p>';
	return;
}

$cid         = $rec['id'] ?? '';
$uid         = '' !== $cid ? $cid : 'new';
$rec['provider'] = Mailpai_Smtp_Provider_Registry::normalize_slug( $rec['provider'] ?? '' );
$secrets_st     = Mailpai_Smtp_Connection_Store::secrets_storage( $rec );
$secrets_wp     = ( Mailpai_Smtp_Connection_Store::SECRETS_WP_CONFIG === $secrets_st );
$wp_config      = $provider['wp_config'] ?? array();
$provider_label   = (string) ( $provider['label'] ?? __( 'Email provider', 'smtp-pai' ) );
$hint_sections    = Mailpai_Smtp_Connection_Hints::sections( $rec['provider'] ?? '' );
$hints_title      = sprintf(
	/* translators: %s: email provider name, e.g. MailerSend */
	__( '%s setup guide', 'smtp-pai' ),
	$provider_label
);
$hints_aria_label = sprintf(
	/* translators: %s: email provider name, e.g. MailerSend */
	__( 'How to set up %s', 'smtp-pai' ),
	$provider_label
);
$snippet     = '';
$is_ses               = ( 'amazon_ses' === ( $rec['provider'] ?? '' ) );
$has_encryption_field = isset( $provider['fields']['encryption'] );
$is_smtp_transport    = 'smtp' === ( $provider['transport'] ?? '' );
$uses_api_credentials = 'api' === ( $provider['transport'] ?? '' ) || $is_ses;
$managed_smtp_preset  = $is_smtp_transport && 'other_smtp' !== ( $rec['provider'] ?? '' );
$cred_title           = $is_ses ? __( 'Amazon SES Key Setup', 'smtp-pai' ) : __( 'Credentials', 'smtp-pai' );

foreach ( $wp_config as $const => $val ) {
	$snippet .= "define( '{$const}', '{$val}' );\n";
}

$name_map = array(
	'aws_access_key_id' => 'mailpai_smtp_aws_key',
	'aws_secret'        => 'mailpai_smtp_aws_secret',
	'aws_region'        => 'mailpai_smtp_aws_region',
	'api_key'           => 'mailpai_smtp_api_key',
	'api_secret'        => 'mailpai_smtp_api_secret',
	'host'              => 'mailpai_smtp_host',
	'port'              => 'mailpai_smtp_port',
	'encryption'        => 'mailpai_smtp_encryption',
	'user'              => 'mailpai_smtp_user',
	'smtp_secret'       => 'mailpai_smtp_smtp_secret',
);

$change_url   = '' !== $cid
	? Mailpai_Smtp_Urls::tab( 'dashboard' )
	: Mailpai_Smtp_Urls::tab( 'dashboard', array( 'wizard' => '1' ) );
$change_label = '' !== $cid
	? __( 'Choose another connection', 'smtp-pai' )
	: __( 'Change email service', 'smtp-pai' );
$is_oauth_mailbox    = Mailpai_Smtp_Provider_Registry::is_oauth_mailbox( $rec['provider'] ?? '' );
$oauth_connected     = Mailpai_Smtp_Connection_Store::uses_oauth( $rec );
$oauth_redirect_uri  = Mailpai_Smtp_Oauth::redirect_uri( $rec['provider'] ?? '' );
$aws_region          = isset( $rec['aws_region'] ) && Mailpai_Smtp_Ses_Api::is_region_allowed( (string) $rec['aws_region'] ) ? (string) $rec['aws_region'] : 'us-east-1';
$console_url         = Mailpai_Smtp_Ses_Api::console_url( $aws_region );
$snapshot            = ( isset( $rec['ses_dns_snapshot'] ) && is_array( $rec['ses_dns_snapshot'] ) ) ? $rec['ses_dns_snapshot'] : null;
$dns_check           = ( isset( $rec['ses_dns_check'] ) && is_array( $rec['ses_dns_check'] ) ) ? $rec['ses_dns_check'] : null;
?>
<div class="mailpai-smtp-wizard">
	<div class="mailpai-smtp-conn-layout">
	<div class="mailpai-smtp-conn-layout__main">
	<div class="mailpai-smtp-conn-setup">
	<div class="mailpai-smtp-wizard__head">
		<div class="mailpai-smtp-wizard__provider">
			<?php if ( ! empty( $provider['logo'] ) ) : ?>
				<img class="mailpai-smtp-wizard__logo" src="<?php echo esc_url( Mailpai_Smtp_Provider_Registry::logo_url( $provider['logo'] ) ); ?>" alt="" width="40" height="40" />
			<?php endif; ?>
			<h2 class="mailpai-smtp-wizard__title"><?php echo esc_html( $provider['label'] ); ?></h2>
			<a href="<?php echo esc_url( $change_url ); ?>" class="mailpai-smtp-wizard__change" aria-label="<?php echo esc_attr( $change_label ); ?>" title="<?php echo esc_attr( $change_label ); ?>">
				<?php Mailpai_Smtp_Icons::render( 'chevron-down', 18 ); ?>
			</a>
		</div>
		<a class="mailpai-smtp-wizard__cancel" href="<?php echo esc_url( Mailpai_Smtp_Urls::tab( 'dashboard' ) ); ?>">
			<?php Mailpai_Smtp_Icons::render( 'x', 16 ); ?>
			<span><?php esc_html_e( 'Cancel', 'smtp-pai' ); ?></span>
		</a>
	</div>

	<form method="post" class="mailpai-smtp-conn-form">
		<?php wp_nonce_field( 'mailpai_smtp_save_connection' ); ?>
		<input type="hidden" name="mailpai_smtp_action" value="save_connection" />
		<input type="hidden" name="mailpai_smtp_conn_id" value="<?php echo esc_attr( $cid ); ?>" />
		<input type="hidden" name="mailpai_smtp_provider" value="<?php echo esc_attr( $rec['provider'] ); ?>" />

		<div class="mailpai-smtp-conn-form__stack">
			<div class="mailpai-smtp-conn-name-row">
				<label class="mailpai-smtp-conn-name-row__label" for="mailpai_smtp_connection_name_<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Connection name', 'smtp-pai' ); ?></label>
				<input type="text" class="regular-text mailpai-smtp-conn-name-row__input" name="mailpai_smtp_connection_name" id="mailpai_smtp_connection_name_<?php echo esc_attr( $uid ); ?>" value="<?php echo esc_attr( $rec['connection_name'] ?? '' ); ?>" placeholder="<?php echo esc_attr( $provider['label'] ); ?>" />
			</div>

			<div class="mailpai-smtp-conn-panel">
				<div class="mailpai-smtp-section-badge">
					<h3><?php esc_html_e( 'Sender', 'smtp-pai' ); ?></h3>
				</div>
				<div class="mailpai-smtp-card mailpai-smtp-card--conn-block mailpai-smtp-card--labeled">
					<div class="mailpai-smtp-section__body">
						<div class="mailpai-smtp-from-row">
							<div class="mailpai-smtp-from-row__field">
								<label for="mailpai_smtp_from_name_<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'From name', 'smtp-pai' ); ?></label>
								<input type="text" class="regular-text mailpai-smtp-from-row__input" name="mailpai_smtp_from_name" id="mailpai_smtp_from_name_<?php echo esc_attr( $uid ); ?>" value="<?php echo esc_attr( $rec['from_name'] ?? get_bloginfo( 'name' ) ); ?>" />
								<div class="mailpai-smtp-from-row__force">
									<label class="mailpai-smtp-from-row__force-control" for="mailpai_smtp_force_from_name_<?php echo esc_attr( $uid ); ?>">
										<span class="mailpai-smtp-toggle">
											<input type="checkbox" name="mailpai_smtp_force_from_name" id="mailpai_smtp_force_from_name_<?php echo esc_attr( $uid ); ?>" value="1" <?php checked( ! empty( $rec['force_from_name'] ) ); ?> />
											<span class="mailpai-smtp-toggle__track" aria-hidden="true"></span>
										</span>
										<span class="mailpai-smtp-from-row__force-label"><?php esc_html_e( 'Force From Name', 'smtp-pai' ); ?></span>
									</label>
								</div>
							</div>
							<div class="mailpai-smtp-from-row__field">
								<label for="mailpai_smtp_from_email_<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'From email', 'smtp-pai' ); ?></label>
								<input type="email" class="regular-text mailpai-smtp-from-row__input" name="mailpai_smtp_from_email" id="mailpai_smtp_from_email_<?php echo esc_attr( $uid ); ?>" value="<?php echo esc_attr( $rec['from_email'] ?? '' ); ?>" <?php echo $is_oauth_mailbox && ! $oauth_connected ? '' : 'required'; ?> placeholder="<?php echo esc_attr( $is_oauth_mailbox && ! $oauth_connected ? __( 'Set automatically after authorization', 'smtp-pai' ) : 'hello@yourdomain.com' ); ?>" <?php echo $is_oauth_mailbox && $oauth_connected ? 'readonly' : ''; ?> />
								<div class="mailpai-smtp-from-row__force">
									<label class="mailpai-smtp-from-row__force-control" for="mailpai_smtp_force_from_email_<?php echo esc_attr( $uid ); ?>">
										<span class="mailpai-smtp-toggle">
											<input type="checkbox" name="mailpai_smtp_force_from_email" id="mailpai_smtp_force_from_email_<?php echo esc_attr( $uid ); ?>" value="1" <?php checked( ! isset( $rec['force_from_email'] ) || ! empty( $rec['force_from_email'] ) ); ?> />
											<span class="mailpai-smtp-toggle__track" aria-hidden="true"></span>
										</span>
										<span class="mailpai-smtp-from-row__force-label"><?php esc_html_e( 'Force From Email', 'smtp-pai' ); ?></span>
									</label>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="mailpai-smtp-conn-panel mailpai-smtp-secrets-panel">
				<div class="mailpai-smtp-section-badge">
					<h3><?php echo esc_html( $cred_title ); ?></h3>
				</div>
				<?php if ( $is_oauth_mailbox || ! $oauth_connected ) : ?>
				<div class="mailpai-smtp-secrets-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Credential storage', 'smtp-pai' ); ?>">
						<label class="mailpai-smtp-secrets-tab <?php echo ! $secrets_wp ? 'is-active' : ''; ?>">
							<input type="radio" name="mailpai_smtp_secrets_storage" value="<?php echo esc_attr( Mailpai_Smtp_Connection_Store::SECRETS_DATABASE ); ?>" <?php checked( ! $secrets_wp ); ?> />
							<span><?php esc_html_e( 'Store keys in database', 'smtp-pai' ); ?></span>
						</label>
						<label class="mailpai-smtp-secrets-tab <?php echo $secrets_wp ? 'is-active' : ''; ?>">
							<input type="radio" name="mailpai_smtp_secrets_storage" value="<?php echo esc_attr( Mailpai_Smtp_Connection_Store::SECRETS_WP_CONFIG ); ?>" <?php checked( $secrets_wp ); ?> />
							<span><?php esc_html_e( 'Keep in wp-config.php', 'smtp-pai' ); ?></span>
						</label>
					</div>
					<div class="mailpai-smtp-card mailpai-smtp-card--conn-block mailpai-smtp-card--secrets">
						<div class="mailpai-smtp-secrets-chooser-wrap">

				<div class="mailpai-smtp-secrets-pane" <?php echo $secrets_wp ? 'hidden' : ''; ?> data-secrets-pane="database">
					<?php if ( $is_oauth_mailbox ) : ?>
						<?php
						$redirect_suffix = '';
						$redirect_only   = false;
						require MAILPAI_SMTP_PLUGIN_DIR . 'includes/admin/views/partials/oauth-credentials-table.php';
						if ( $oauth_connected ) {
							require MAILPAI_SMTP_PLUGIN_DIR . 'includes/admin/views/partials/oauth-connected-status.php';
						} else {
							require MAILPAI_SMTP_PLUGIN_DIR . 'includes/admin/views/partials/oauth-signin-prompt.php';
						}
						?>
					<?php else : ?>
					<?php if ( $is_ses ) : ?>
						<div class="mailpai-smtp-ses-keys-row">
							<div class="mailpai-smtp-ses-keys-row__field">
								<label class="mailpai-smtp-ses-keys-row__label" for="mailpai_smtp_aws_key_<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Access key ID', 'smtp-pai' ); ?></label>
								<input type="text" class="regular-text mailpai-smtp-ses-keys-row__input" name="mailpai_smtp_aws_key" id="mailpai_smtp_aws_key_<?php echo esc_attr( $uid ); ?>" value="<?php echo esc_attr( $rec['aws_access_key_id'] ?? '' ); ?>" autocomplete="off" />
							</div>
							<div class="mailpai-smtp-ses-keys-row__field">
								<label class="mailpai-smtp-ses-keys-row__label" for="mailpai_smtp_aws_secret_<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Secret access key', 'smtp-pai' ); ?></label>
								<input type="password" class="regular-text mailpai-smtp-ses-keys-row__input" name="mailpai_smtp_aws_secret" id="mailpai_smtp_aws_secret_<?php echo esc_attr( $uid ); ?>" autocomplete="new-password" placeholder="<?php echo esc_attr( Mailpai_Smtp_Connection_Store::stored_secret_placeholder( $rec['aws_secret_enc'] ?? '' ) ); ?>" />
							</div>
						</div>
						<div class="mailpai-smtp-ses-region-row">
							<div class="mailpai-smtp-ses-keys-row__field">
								<label class="mailpai-smtp-ses-keys-row__label" for="mailpai_smtp_aws_region_<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Region', 'smtp-pai' ); ?></label>
								<span class="mailpai-smtp-select-wrap">
									<select name="mailpai_smtp_aws_region" id="mailpai_smtp_aws_region_<?php echo esc_attr( $uid ); ?>" class="regular-text mailpai-smtp-ses-keys-row__input">
										<?php foreach ( Mailpai_Smtp_Ses_Api::regions() as $r ) : ?>
											<option value="<?php echo esc_attr( $r ); ?>" <?php selected( $rec['aws_region'] ?? 'us-east-1', $r ); ?>><?php echo esc_html( $r ); ?></option>
										<?php endforeach; ?>
									</select>
								</span>
							</div>
						</div>
					<?php else : ?>
						<table class="mailpai-smtp-form-table mailpai-smtp-form-table--tight">
							<tbody>
								<?php foreach ( $provider['fields'] as $key => $field ) : ?>
									<?php
									if ( 'encryption' === $key ) {
										continue;
									}
									$type     = $field['type'] ?? 'text';
									$label    = $field['label'] ?? $key;
									$name     = isset( $name_map[ $key ] ) ? $name_map[ $key ] : 'mailpai_smtp_' . $key;
									$val_key  = $key;
									$field_id = $name . '_' . $uid;
									$meta_key = isset( $field['meta_key'] ) ? (string) $field['meta_key'] : '';
									$field_val = '' !== $meta_key
										? (string) ( $rec['meta'][ $meta_key ] ?? '' )
										: (string) ( $rec[ $val_key ] ?? '' );
									?>
									<tr>
										<th scope="row">
											<label for="<?php echo esc_attr( $field_id ); ?>">
												<?php echo esc_html( $label ); ?>
												<?php if ( ! empty( $field['help'] ) ) : ?>
													<span class="mailpai-smtp-help-tip" tabindex="0" title="<?php echo esc_attr( $field['help'] ); ?>"><?php Mailpai_Smtp_Icons::render( 'info', 14 ); ?></span>
												<?php endif; ?>
											</label>
										</th>
										<td>
											<?php if ( 'select' === $type && ! empty( $field['options'] ) ) : ?>
												<span class="mailpai-smtp-select-wrap">
													<select name="mailpai_smtp_api_domain" id="<?php echo esc_attr( $field_id ); ?>" class="regular-text">
													<?php
													$region_default = isset( $field['default'] ) ? (string) $field['default'] : 'us';
													foreach ( $field['options'] as $val => $opt_label ) :
														?>
														<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $rec['meta']['api_domain'] ?? $region_default, $val ); ?>><?php echo esc_html( $opt_label ); ?></option>
													<?php endforeach; ?>
													</select>
												</span>
											<?php elseif ( 'encryption' === $type ) : ?>
												<span class="mailpai-smtp-select-wrap">
													<select name="mailpai_smtp_encryption" id="<?php echo esc_attr( $field_id ); ?>" class="regular-text">
														<option value="tls" <?php selected( $rec['encryption'] ?? 'tls', 'tls' ); ?>>TLS</option>
														<option value="ssl" <?php selected( $rec['encryption'] ?? '', 'ssl' ); ?>>SSL</option>
														<option value="" <?php selected( $rec['encryption'] ?? '', '' ); ?>><?php esc_html_e( 'None', 'smtp-pai' ); ?></option>
													</select>
												</span>
											<?php elseif ( 'password' === $type ) : ?>
												<?php
												$stored_key  = isset( $field['secret_key'] ) ? (string) $field['secret_key'] : '';
												$placeholder = '' !== $stored_key
													? Mailpai_Smtp_Connection_Store::stored_secret_placeholder( $rec[ $stored_key ] ?? '' )
													: '';
												?>
												<input type="password" class="regular-text" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $field_id ); ?>" autocomplete="new-password" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
											<?php else : ?>
												<input type="<?php echo 'number' === $type ? 'number' : 'text'; ?>" class="regular-text" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $field_val ); ?>" <?php echo ! empty( $field['placeholder'] ) ? 'placeholder="' . esc_attr( (string) $field['placeholder'] ) . '"' : ''; ?> <?php echo ! empty( $field['required'] ) ? 'required' : ''; ?> />
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<?php if ( ! $is_oauth_mailbox && $is_smtp_transport && ! $managed_smtp_preset ) : ?>
						<?php if ( $has_encryption_field ) : ?>
							<div class="mailpai-smtp-encryption-setup__row">
								<label class="mailpai-smtp-encryption-setup__label" for="mailpai_smtp_encryption_<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Encryption', 'smtp-pai' ); ?></label>
								<span class="mailpai-smtp-select-wrap mailpai-smtp-encryption-setup__control">
									<select name="mailpai_smtp_encryption" id="mailpai_smtp_encryption_<?php echo esc_attr( $uid ); ?>" class="regular-text">
										<option value="tls" <?php selected( $rec['encryption'] ?? 'tls', 'tls' ); ?>>TLS</option>
										<option value="ssl" <?php selected( $rec['encryption'] ?? '', 'ssl' ); ?>>SSL</option>
										<option value="" <?php selected( $rec['encryption'] ?? '', '' ); ?>><?php esc_html_e( 'None', 'smtp-pai' ); ?></option>
									</select>
								</span>
							</div>
						<?php endif; ?>
						<label class="mailpai-smtp-conn-option">
							<input type="checkbox" name="mailpai_smtp_disable_encryption" value="1" <?php checked( ! empty( $rec['disable_encryption'] ) ); ?> />
							<span><?php esc_html_e( 'Send without TLS or SSL encryption — not recommended', 'smtp-pai' ); ?></span>
						</label>
					<?php endif; ?>

					<?php if ( ! $is_oauth_mailbox && $uses_api_credentials ) : ?>
						<label class="mailpai-smtp-conn-option">
							<input type="checkbox" name="mailpai_smtp_disable_secret_encryption" value="1" <?php checked( ! empty( $rec['disable_secret_encryption'] ) ); ?> />
							<span><?php esc_html_e( 'Save API keys without encrypting them in the database (not recommended)', 'smtp-pai' ); ?></span>
						</label>
					<?php endif; ?>
					<?php endif; ?>
				</div>

				<div class="mailpai-smtp-secrets-pane" <?php echo $secrets_wp ? '' : 'hidden'; ?> data-secrets-pane="wpconfig">
					<p class="mailpai-smtp-secrets-wpconfig-tag"><strong><?php esc_html_e( '(Another Method & Safe)', 'smtp-pai' ); ?></strong></p>
					<p class="mailpai-smtp-secrets-intro"><?php esc_html_e( 'Add the snippet below to wp-config.php, replace the placeholders with your keys, then save this connection. Fields on the other tab are ignored while this option is selected.', 'smtp-pai' ); ?></p>
					<textarea class="large-text code mailpai-smtp-wpconfig-snippet" rows="<?php echo esc_attr( (string) max( 4, count( $wp_config ) + 1 ) ); ?>" readonly><?php echo esc_textarea( $snippet ); ?></textarea>
					<?php if ( $is_oauth_mailbox ) : ?>
						<?php
						$redirect_suffix = '_wp';
						$redirect_only   = true;
						require MAILPAI_SMTP_PLUGIN_DIR . 'includes/admin/views/partials/oauth-credentials-table.php';
						if ( $oauth_connected ) {
							require MAILPAI_SMTP_PLUGIN_DIR . 'includes/admin/views/partials/oauth-connected-status.php';
						} else {
							require MAILPAI_SMTP_PLUGIN_DIR . 'includes/admin/views/partials/oauth-signin-prompt.php';
						}
						?>
					<?php endif; ?>
				</div>
			</div>
					</div>
				</div>
				<?php endif; ?>

			<div class="mailpai-smtp-conn-form__actions">
				<button type="submit" class="mailpai-smtp-btn"><?php esc_html_e( 'Save Connection', 'smtp-pai' ); ?></button>
				<?php if ( '' !== $cid ) : ?>
					<button type="submit" class="mailpai-smtp-btn mailpai-smtp-btn--outline mailpai-smtp-btn--danger" name="mailpai_smtp_action" value="delete_connection" onclick="return confirm('<?php echo esc_js( __( 'Remove this connection?', 'smtp-pai' ) ); ?>');"><?php esc_html_e( 'Delete', 'smtp-pai' ); ?></button>
				<?php endif; ?>
			</div>
		</div>
	</form>
	</div>
	</div>
	<?php require MAILPAI_SMTP_PLUGIN_DIR . 'includes/admin/views/partials/connection-hints.php'; ?>
	</div>
	<?php if ( '' !== $cid && $is_ses ) : ?>
	<div class="mailpai-smtp-conn-layout mailpai-smtp-conn-layout--dns">
		<div class="mailpai-smtp-conn-layout__main">
			<?php require MAILPAI_SMTP_PLUGIN_DIR . 'includes/admin/views/partials/connection-ses-dns.php'; ?>
		</div>
		<aside class="mailpai-smtp-hints mailpai-smtp-hints--blank" aria-hidden="true"></aside>
	</div>
	<?php endif; ?>
</div>
