<?php
/**
 * OAuth connected status for Google and Microsoft.
 *
 * @package Mailpai_Smtp
 *
 * @var string $cid Connection id.
 * @var array  $rec Connection record.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mailpai-smtp-oauth-status is-connected">
	<p class="mailpai-smtp-oauth-status__text">
		<?php
		printf(
			/* translators: %s: connected email address */
			esc_html__( 'Signed in as %s', 'smtp-pai' ),
			esc_html( $rec['user'] ?? $rec['from_email'] ?? '' )
		);
		?>
	</p>
	<?php if ( '' !== $cid ) : ?>
		<a class="mailpai-smtp-oauth-disconnect" href="<?php echo esc_url( Mailpai_Smtp_Oauth::disconnect_url( $cid ) ); ?>"><?php esc_html_e( 'Remove authorization', 'smtp-pai' ); ?></a>
	<?php endif; ?>
</div>
