<?php
/**
 * Provider picker grid.
 *
 * @package Mailpai_Smtp
 *
 * @var bool $picker_embedded Optional. Hide wizard chrome when embedded in dashboard.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$picker_embedded = ! empty( $picker_embedded );
$providers       = Mailpai_Smtp_Provider_Registry::all();
$recommended     = array_intersect_key(
	$providers,
	array(
		'amazon_ses' => true,
	)
);
$other_smtp      = array_intersect_key(
	$providers,
	array(
		'other_smtp' => true,
	)
);
$more            = array_diff_key( $providers, $recommended, $other_smtp );
$wordmark_slugs  = array( 'smtp2go' );
?>
<div class="mailpai-smtp-wizard">
	<?php if ( ! $picker_embedded ) : ?>
		<div class="mailpai-smtp-wizard__head">
			<h2><?php esc_html_e( 'Choose your email service', 'smtp-pai' ); ?></h2>
			<a class="mailpai-smtp-wizard__cancel" href="<?php echo esc_url( Mailpai_Smtp_Urls::tab( 'dashboard' ) ); ?>">
				<?php Mailpai_Smtp_Icons::render( 'x', 16 ); ?>
				<span><?php esc_html_e( 'Cancel', 'smtp-pai' ); ?></span>
			</a>
		</div>
	<?php else : ?>
		<div class="mailpai-smtp-wizard__head mailpai-smtp-wizard__head--embedded">
			<h2 class="mailpai-smtp-wizard__title"><?php esc_html_e( 'Choose your email service', 'smtp-pai' ); ?></h2>
			<button type="button" class="mailpai-smtp-provider-cancel mailpai-smtp-wizard__cancel">
				<?php Mailpai_Smtp_Icons::render( 'x', 16 ); ?>
				<span><?php esc_html_e( 'Cancel', 'smtp-pai' ); ?></span>
			</button>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $recommended ) ) : ?>
		<h3 class="mailpai-smtp-provider-group"><?php esc_html_e( 'Recommended', 'smtp-pai' ); ?></h3>
		<div class="mailpai-smtp-provider-grid">
			<?php foreach ( $recommended as $slug => $def ) : ?>
				<a class="mailpai-smtp-provider-tile<?php echo in_array( $slug, $wordmark_slugs, true ) ? ' mailpai-smtp-provider-tile--wordmark' : ''; ?>" href="<?php echo esc_url( Mailpai_Smtp_Urls::tab( 'dashboard', array( 'wizard' => '1', 'provider' => $slug ) ) ); ?>">
					<span class="mailpai-smtp-provider-tile__logo">
						<img src="<?php echo esc_url( Mailpai_Smtp_Provider_Registry::logo_url( $def['logo'] ) ); ?>" alt="" width="40" height="40" />
					</span>
					<span><?php echo esc_html( $def['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $more ) ) : ?>
	<h3 class="mailpai-smtp-provider-group"><?php esc_html_e( 'More providers', 'smtp-pai' ); ?></h3>
	<div class="mailpai-smtp-provider-grid">
		<?php foreach ( $more as $slug => $def ) : ?>
			<a class="mailpai-smtp-provider-tile<?php echo in_array( $slug, $wordmark_slugs, true ) ? ' mailpai-smtp-provider-tile--wordmark' : ''; ?>" href="<?php echo esc_url( Mailpai_Smtp_Urls::tab( 'dashboard', array( 'wizard' => '1', 'provider' => $slug ) ) ); ?>">
				<span class="mailpai-smtp-provider-tile__logo">
					<img src="<?php echo esc_url( Mailpai_Smtp_Provider_Registry::logo_url( $def['logo'] ) ); ?>" alt="" width="40" height="40" />
				</span>
				<span><?php echo esc_html( $def['label'] ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<?php if ( ! empty( $other_smtp ) ) : ?>
	<h3 class="mailpai-smtp-provider-group"><?php esc_html_e( 'Any Other SMTP', 'smtp-pai' ); ?></h3>
	<div class="mailpai-smtp-provider-grid">
		<?php foreach ( $other_smtp as $slug => $def ) : ?>
			<a class="mailpai-smtp-provider-tile<?php echo in_array( $slug, $wordmark_slugs, true ) ? ' mailpai-smtp-provider-tile--wordmark' : ''; ?>" href="<?php echo esc_url( Mailpai_Smtp_Urls::tab( 'dashboard', array( 'wizard' => '1', 'provider' => $slug ) ) ); ?>">
				<span class="mailpai-smtp-provider-tile__logo">
					<img src="<?php echo esc_url( Mailpai_Smtp_Provider_Registry::logo_url( $def['logo'] ) ); ?>" alt="" width="40" height="40" />
				</span>
				<span><?php echo esc_html( $def['label'] ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
</div>
