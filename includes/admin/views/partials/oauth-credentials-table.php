<?php
/**
 * OAuth credential fields shared by Google and Microsoft.
 *
 * @package Mailpai_Smtp
 *
 * @var string $uid                Connection uid.
 * @var array  $rec                Connection record.
 * @var string $oauth_redirect_uri Redirect URI.
 * @var string $redirect_suffix    Optional suffix for redirect input id.
 * @var bool   $redirect_only      When true, render redirect URI row only.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$redirect_suffix = isset( $redirect_suffix ) ? (string) $redirect_suffix : '';
$redirect_only   = ! empty( $redirect_only );
$redirect_id     = 'mailpai_smtp_oauth_redirect' . $redirect_suffix . '_' . $uid;
?>
<table class="mailpai-smtp-form-table mailpai-smtp-form-table--tight mailpai-smtp-form-table--oauth">
	<tbody>
		<?php if ( ! $redirect_only ) : ?>
			<tr>
				<th scope="row">
					<label for="mailpai_smtp_oauth_client_id_<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Application Client ID', 'smtp-pai' ); ?></label>
				</th>
				<td>
					<input type="text" class="regular-text mailpai-smtp-oauth-client-id" name="mailpai_smtp_oauth_client_id" id="mailpai_smtp_oauth_client_id_<?php echo esc_attr( $uid ); ?>" value="<?php echo esc_attr( $rec['oauth_client_id'] ?? '' ); ?>" autocomplete="off" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="mailpai_smtp_oauth_client_secret_<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Application Client Secret', 'smtp-pai' ); ?></label>
				</th>
				<td>
					<input type="password" class="regular-text mailpai-smtp-oauth-client-secret" name="mailpai_smtp_oauth_client_secret" id="mailpai_smtp_oauth_client_secret_<?php echo esc_attr( $uid ); ?>" autocomplete="new-password" placeholder="<?php echo esc_attr( Mailpai_Smtp_Connection_Store::stored_secret_placeholder( $rec['oauth_client_secret_enc'] ?? '' ) ); ?>" />
				</td>
			</tr>
		<?php endif; ?>
		<tr class="mailpai-smtp-oauth-redirect-row">
			<th scope="row">
				<label for="<?php echo esc_attr( $redirect_id ); ?>"><?php esc_html_e( 'Authorized Redirect URI', 'smtp-pai' ); ?></label>
			</th>
			<td>
				<span class="mailpai-smtp-oauth-redirect-wrap">
					<input type="text" class="regular-text code mailpai-smtp-oauth-redirect-uri" id="<?php echo esc_attr( $redirect_id ); ?>" readonly value="<?php echo esc_attr( $oauth_redirect_uri ); ?>" />
					<button type="button" class="mailpai-smtp-btn mailpai-smtp-btn--outline mailpai-smtp-btn--sm mailpai-smtp-oauth-copy" data-copy-target="<?php echo esc_attr( $redirect_id ); ?>" data-default-label="<?php esc_attr_e( 'Copy', 'smtp-pai' ); ?>" data-copied-label="<?php esc_attr_e( 'Copied', 'smtp-pai' ); ?>" aria-label="<?php esc_attr_e( 'Copy redirect URI', 'smtp-pai' ); ?>"><?php esc_html_e( 'Copy', 'smtp-pai' ); ?></button>
				</span>
			</td>
		</tr>
	</tbody>
</table>
