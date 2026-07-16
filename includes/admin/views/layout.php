<?php
/**
 * Admin layout shell.
 *
 * @package Mailpai_Smtp
 *
 * @var string $tab   Current tab.
 * @var string $view  View file path.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$tabs = array(
	'dashboard' => array( 'label' => __( 'Dashboard', 'smtp-pai' ), 'icon' => 'plug' ),
	'routes'    => array( 'label' => __( 'Specify Connections', 'smtp-pai' ), 'icon' => 'route' ),
	'backup'    => array( 'label' => __( 'Backup', 'smtp-pai' ), 'icon' => 'backup' ),
	'log'       => array( 'label' => __( 'Email Log', 'smtp-pai' ), 'icon' => 'log' ),
	'settings'  => array( 'label' => __( 'Settings', 'smtp-pai' ), 'icon' => 'gear' ),
);

?>
<div class="wrap mailpai-smtp-app">
	<div class="mailpai-smtp-app__canvas">
		<header class="mailpai-smtp-header">
			<div class="mailpai-smtp-brand">
				<a class="mailpai-smtp-brand__link" href="<?php echo esc_url( Mailpai_Smtp_Urls::tab( 'dashboard' ) ); ?>">
					<img
						class="mailpai-smtp-brand__logo"
						src="<?php echo esc_url( MAILPAI_SMTP_PLUGIN_URL . 'assets/img/smtppai-logo.png' ); ?>"
						alt="<?php echo esc_attr( MAILPAI_SMTP_BRAND_NAME ); ?>"
						width="400"
						height="93"
						decoding="async"
					/>
				</a>
			</div>
			<div class="mailpai-smtp-header__actions">
				<nav class="mailpai-smtp-tabs" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: plugin name */ __( '%s sections', 'smtp-pai' ), MAILPAI_SMTP_BRAND_NAME ) ); ?>">
					<?php foreach ( $tabs as $slug => $meta ) : ?>
						<a class="mailpai-smtp-tabs__item <?php echo $slug === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( Mailpai_Smtp_Urls::tab( $slug ) ); ?>">
							<?php Mailpai_Smtp_Icons::render( $meta['icon'], 16 ); ?>
							<span><?php echo esc_html( $meta['label'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</nav>
			</div>
		</header>

		<div class="mailpai-smtp-app__body">
			<?php Mailpai_Smtp_Admin::instance()->render_notices(); ?>
			<main class="mailpai-smtp-main">
				<?php include $view; ?>
			</main>
		</div>

		<footer class="mailpai-smtp-footer">
			<span><?php
				printf(
					/* translators: 1: plugin name, 2: version */
					esc_html__( '%1$s %2$s', 'smtp-pai' ),
					esc_html( MAILPAI_SMTP_BRAND_NAME ),
					esc_html( MAILPAI_SMTP_VERSION )
				);
			?></span>
		</footer>
	</div>
</div>
<div id="mailpai-smtp-test-modal" class="mailpai-smtp-test-modal" hidden>
	<div class="mailpai-smtp-test-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="mailpai-smtp-test-modal-title">
		<button type="button" class="mailpai-smtp-test-modal__close" aria-label="<?php esc_attr_e( 'Close', 'smtp-pai' ); ?>">&times;</button>
		<div class="mailpai-smtp-test-modal__body">
			<h2 id="mailpai-smtp-test-modal-title" class="mailpai-smtp-test-modal__title"><?php esc_html_e( 'Send test email', 'smtp-pai' ); ?></h2>
			<p class="mailpai-smtp-test-modal__intro"><?php esc_html_e( 'Enter the email address that should receive the test message.', 'smtp-pai' ); ?></p>
			<label class="mailpai-smtp-test-modal__label" for="mailpai-smtp-test-email"><?php esc_html_e( 'Recipient email', 'smtp-pai' ); ?></label>
			<input type="email" id="mailpai-smtp-test-email" class="mailpai-smtp-input" autocomplete="email" required />
			<p id="mailpai-smtp-test-modal-error" class="mailpai-smtp-test-modal__error" hidden role="alert"></p>
		</div>
		<div class="mailpai-smtp-test-modal__footer">
			<button type="button" class="mailpai-smtp-btn mailpai-smtp-btn--outline mailpai-smtp-test-modal__cancel"><?php esc_html_e( 'Cancel', 'smtp-pai' ); ?></button>
			<button type="button" class="mailpai-smtp-btn mailpai-smtp-test-modal__submit"><?php esc_html_e( 'Send test', 'smtp-pai' ); ?></button>
		</div>
	</div>
</div>
