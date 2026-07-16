<?php
/**
 * Backup Connection tab.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$backup  = Mailpai_Smtp_Backup::get();
$choices = Mailpai_Smtp_Connection_Store::choices();
$choices = array( '' => __( '— None —', 'smtp-pai' ) ) + $choices;
?>
<div class="mailpai-smtp-page-head">
	<h2><?php esc_html_e( 'Backup', 'smtp-pai' ); ?></h2>
</div>

<div class="mailpai-smtp-conn-layout">
	<div class="mailpai-smtp-conn-layout__main">
		<form method="post" class="mailpai-smtp-conn-form mailpai-smtp-routes-form">
			<?php wp_nonce_field( 'mailpai_smtp_save_backup' ); ?>
			<input type="hidden" name="mailpai_smtp_action" value="save_backup" />

			<div class="mailpai-smtp-conn-form__stack">
				<div class="mailpai-smtp-conn-panel">
					<div class="mailpai-smtp-section-badge">
						<h3><?php esc_html_e( 'Fallback Connection', 'smtp-pai' ); ?></h3>
					</div>
					<div class="mailpai-smtp-card mailpai-smtp-card--conn-block mailpai-smtp-card--labeled">
						<div class="mailpai-smtp-section__body mailpai-smtp-routes-section__body">
							<div class="mailpai-smtp-route-row">
								<label for="backup_global"><?php esc_html_e( 'Choose Connection', 'smtp-pai' ); ?></label>
								<div class="mailpai-smtp-select-wrap">
									<select name="backup_global" id="backup_global" class="mailpai-smtp-route-row__select">
										<?php foreach ( $choices as $id => $title ) : ?>
											<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $backup['global_backup_id'] ?? '', $id ); ?>><?php echo esc_html( $title ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div class="mailpai-smtp-conn-form__actions">
					<button type="submit" class="mailpai-smtp-btn"><?php esc_html_e( 'Save backup', 'smtp-pai' ); ?></button>
				</div>
			</div>
		</form>
	</div>
	<?php require __DIR__ . '/partials/backup-guide.php'; ?>
</div>
