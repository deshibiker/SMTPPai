<?php
/**
 * Settings tab.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$settings = get_option( 'mailpai_smtp_settings', array() );
if ( ! is_array( $settings ) ) {
	$settings = array();
}
?>
<div class="mailpai-smtp-page-head">
	<h2><?php esc_html_e( 'Settings', 'smtp-pai' ); ?></h2>
</div>

<div class="mailpai-smtp-conn-layout">
	<div class="mailpai-smtp-conn-layout__main">
		<form method="post" class="mailpai-smtp-conn-form mailpai-smtp-routes-form mailpai-smtp-settings-form">
			<?php wp_nonce_field( 'mailpai_smtp_save_settings' ); ?>
			<input type="hidden" name="mailpai_smtp_action" value="save_settings" />

			<div class="mailpai-smtp-conn-form__stack">
				<div class="mailpai-smtp-conn-panel">
					<div class="mailpai-smtp-section-badge">
						<h3><?php esc_html_e( 'Email log', 'smtp-pai' ); ?></h3>
					</div>
					<div class="mailpai-smtp-card mailpai-smtp-card--conn-block mailpai-smtp-card--labeled">
						<div class="mailpai-smtp-section__body mailpai-smtp-routes-section__body">
							<div class="mailpai-smtp-route-row">
								<label for="log_retention_days"><?php esc_html_e( 'Keep logs for (days)', 'smtp-pai' ); ?></label>
								<input type="number" min="1" name="log_retention_days" id="log_retention_days" class="mailpai-smtp-route-row__input" value="<?php echo esc_attr( (string) ( $settings['log_retention_days'] ?? 14 ) ); ?>" />
							</div>
							<div class="mailpai-smtp-settings-option">
								<label class="mailpai-smtp-enabled-toggle" for="log_body">
									<input type="checkbox" name="log_body" id="log_body" value="1" <?php checked( ! empty( $settings['log_body'] ) ); ?> />
									<span><?php esc_html_e( 'Store email body in log (not recommended)', 'smtp-pai' ); ?></span>
								</label>
							</div>
						</div>
					</div>
				</div>

				<div class="mailpai-smtp-conn-panel">
					<div class="mailpai-smtp-section-badge">
						<h3><?php esc_html_e( 'Uninstall', 'smtp-pai' ); ?></h3>
					</div>
					<div class="mailpai-smtp-card mailpai-smtp-card--conn-block mailpai-smtp-card--labeled">
						<div class="mailpai-smtp-section__body mailpai-smtp-routes-section__body">
							<div class="mailpai-smtp-settings-option">
								<label class="mailpai-smtp-enabled-toggle" for="delete_on_uninstall">
									<input type="checkbox" name="delete_on_uninstall" id="delete_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_on_uninstall'] ) ); ?> />
									<span><?php esc_html_e( 'Delete all plugin data when uninstalling', 'smtp-pai' ); ?></span>
								</label>
							</div>
						</div>
					</div>
				</div>

				<div class="mailpai-smtp-conn-form__actions">
					<button type="submit" class="mailpai-smtp-btn"><?php esc_html_e( 'Save settings', 'smtp-pai' ); ?></button>
				</div>
			</div>
		</form>
	</div>
	<?php require __DIR__ . '/partials/settings-guide.php'; ?>
</div>
