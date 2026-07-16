<?php
/**
 * Prompt to finish OAuth mailbox setup with a provider sign-in button.
 *
 * @package Mailpai_Smtp
 *
 * @var array  $rec              Connection record.
 * @var string $oauth_signin_url Optional precomputed sign-in URL.
 * @var string $cid              Connection id.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$provider_slug  = Mailpai_Smtp_Provider_Registry::normalize_slug( (string) ( $rec['provider'] ?? '' ) );
$configured     = Mailpai_Smtp_Oauth::is_configured( $provider_slug, $rec );
$signin_url     = isset( $oauth_signin_url ) ? (string) $oauth_signin_url : Mailpai_Smtp_Oauth::connection_start_url( $rec );
$button_label   = Mailpai_Smtp_Oauth::button_label( $provider_slug );
$provider_label = Mailpai_Smtp_Oauth::provider_display_name( $provider_slug );
$connection_id  = isset( $cid ) ? sanitize_key( (string) $cid ) : sanitize_key( (string) ( $rec['id'] ?? '' ) );
$supports_manual = Mailpai_Smtp_Oauth::uses_oauth_proxy( $provider_slug );
?>
<div class="mailpai-smtp-oauth-setup">
	<?php if ( $configured && '' !== $signin_url ) : ?>
		<p class="mailpai-smtp-oauth-setup__copy description">
			<?php
			printf(
				/* translators: %s: provider name (Google, Microsoft) */
				esc_html__( 'Your app credentials are saved. Sign in with %s to finish setup and enable sending.', 'smtp-pai' ),
				esc_html( $provider_label )
			);
			?>
		</p>
		<a class="mailpai-smtp-btn mailpai-smtp-oauth-connect-btn" href="<?php echo esc_url( $signin_url ); ?>">
			<?php echo esc_html( $button_label ); ?>
		</a>
		<?php if ( $supports_manual && '' !== $connection_id ) : ?>
			<div class="mailpai-smtp-oauth-manual">
				<p class="mailpai-smtp-oauth-setup__copy description">
					<?php
					if ( 'microsoft' === $provider_slug ) {
						esc_html_e( 'If you are not redirected back to WordPress, copy the authorization code from the OAuth relay page and paste it below.', 'smtp-pai' );
					} else {
						esc_html_e( 'If automatic sign-in does not complete, paste the authorization code from the OAuth relay page below.', 'smtp-pai' );
					}
					?>
				</p>
				<label class="mailpai-smtp-oauth-manual__label" for="mailpai_smtp_oauth_auth_code_<?php echo esc_attr( $connection_id ); ?>">
					<?php esc_html_e( 'Authorization code', 'smtp-pai' ); ?>
				</label>
				<textarea class="large-text code mailpai-smtp-oauth-manual__input" name="mailpai_smtp_oauth_auth_code" id="mailpai_smtp_oauth_auth_code_<?php echo esc_attr( $connection_id ); ?>" rows="3" placeholder="<?php esc_attr_e( 'Paste access code here', 'smtp-pai' ); ?>"></textarea>
				<button type="submit" class="mailpai-smtp-btn mailpai-smtp-btn--outline mailpai-smtp-oauth-manual__btn" name="mailpai_smtp_action" value="complete_oauth_code">
					<?php esc_html_e( 'Complete authorization', 'smtp-pai' ); ?>
				</button>
			</div>
		<?php endif; ?>
	<?php else : ?>
		<p class="mailpai-smtp-oauth-setup__copy description">
			<?php esc_html_e( 'Enter an Application Client ID and Application Client Secret, then save the connection to continue.', 'smtp-pai' ); ?>
		</p>
	<?php endif; ?>
</div>
