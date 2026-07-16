<?php
/**
 * Specify Connections tab.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$routes             = Mailpai_Smtp_Routes::get_all();
$choices            = Mailpai_Smtp_Connection_Store::choices();
$choice_logos       = Mailpai_Smtp_Connection_Store::choice_logos();
$choices            = array( '' => __( '— Select —', 'smtp-pai' ) ) + $choices;
$marketing_labels   = Mailpai_Smtp_Routes::marketing_labels();
$use_one            = ! empty( $routes['use_one'] );
$use_one_connection = Mailpai_Smtp_Routes::use_one_connection_id();
?>
<div class="mailpai-smtp-page-head">
	<h2><?php esc_html_e( 'Specify Connections', 'smtp-pai' ); ?></h2>
</div>

<div class="mailpai-smtp-conn-layout">
	<div class="mailpai-smtp-conn-layout__main">
		<form method="post" class="mailpai-smtp-conn-form mailpai-smtp-routes-form">
			<?php wp_nonce_field( 'mailpai_smtp_save_routes' ); ?>
			<input type="hidden" name="mailpai_smtp_action" value="save_routes" />

			<div class="mailpai-smtp-conn-form__stack mailpai-smtp-routes-mode">
				<section class="mailpai-smtp-routes-mode__option">
					<div class="mailpai-smtp-conn-panel" data-route-mode-panel="one">
						<div class="mailpai-smtp-section-badge">
							<h3>
								<label class="mailpai-smtp-routes-mode__label" for="mailpai_smtp_route_mode_one">
									<input type="radio" name="route_mode" id="mailpai_smtp_route_mode_one" class="mailpai-smtp-routes-mode__radio" value="one" <?php checked( $use_one ); ?> />
									<span><?php esc_html_e( 'One for Everything', 'smtp-pai' ); ?></span>
								</label>
							</h3>
						</div>
						<div class="mailpai-smtp-card mailpai-smtp-card--conn-block mailpai-smtp-card--labeled">
							<div class="mailpai-smtp-section__body mailpai-smtp-routes-section__body">
								<?php
								$slug     = 'all';
								$selected = $use_one_connection;
								require __DIR__ . '/partials/route-row.php';
								?>
							</div>
						</div>
					</div>
				</section>

				<section class="mailpai-smtp-routes-mode__option">
					<div class="mailpai-smtp-conn-panel" data-route-mode-panel="separate">
						<div class="mailpai-smtp-section-badge">
							<h3>
								<label class="mailpai-smtp-routes-mode__label" for="mailpai_smtp_route_mode_separate">
									<input type="radio" name="route_mode" id="mailpai_smtp_route_mode_separate" class="mailpai-smtp-routes-mode__radio" value="separate" <?php checked( ! $use_one ); ?> />
									<span><?php esc_html_e( 'Use Separate Connection (Recommended)', 'smtp-pai' ); ?></span>
								</label>
							</h3>
						</div>
						<div class="mailpai-smtp-card mailpai-smtp-card--conn-block mailpai-smtp-card--labeled mailpai-smtp-card--routes-combined">
							<div class="mailpai-smtp-routes-subsection">
								<h3 class="mailpai-smtp-routes-subsection__title"><?php esc_html_e( 'Transactional', 'smtp-pai' ); ?></h3>
								<div class="mailpai-smtp-section__body mailpai-smtp-routes-section__body">
									<?php
									foreach ( Mailpai_Smtp_Routes::transactional_labels() as $slug => $label ) :
										$selected = $routes['transactional'][ $slug ] ?? '';
										require __DIR__ . '/partials/route-row.php';
									endforeach;
									?>
								</div>
							</div>

							<?php if ( ! empty( $marketing_labels ) ) : ?>
								<div class="mailpai-smtp-routes-subsection">
									<h3 class="mailpai-smtp-routes-subsection__title">
										<?php esc_html_e( 'Marketing', 'smtp-pai' ); ?>
										<span class="mailpai-smtp-help-tip" tabindex="0" title="<?php esc_attr_e( 'Used by MailPai for campaigns when installed.', 'smtp-pai' ); ?>"><?php Mailpai_Smtp_Icons::render( 'info', 14 ); ?></span>
									</h3>
									<div class="mailpai-smtp-section__body mailpai-smtp-routes-section__body">
										<?php
										foreach ( $marketing_labels as $slug => $label ) :
											$selected = $routes['marketing'][ $slug ] ?? '';
											require __DIR__ . '/partials/route-row.php';
										endforeach;
										?>
									</div>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</section>

				<div class="mailpai-smtp-conn-form__actions">
					<button type="submit" class="mailpai-smtp-btn"><?php esc_html_e( 'Save routes', 'smtp-pai' ); ?></button>
				</div>
			</div>
		</form>
	</div>
	<?php require __DIR__ . '/partials/routes-guide.php'; ?>
</div>
