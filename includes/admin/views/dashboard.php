<?php
/**
 * Dashboard — connections.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$connections = Mailpai_Smtp_Connection_Store::get_ordered();
$wizard      = isset( $_GET['wizard'] ) ? sanitize_key( wp_unslash( $_GET['wizard'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$edit_id     = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$pick        = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( '' !== $pick && empty( Mailpai_Smtp_Provider_Registry::get( $pick ) ) ) {
	$pick = '';
}

$show_form   = ( '1' === $wizard && '' !== $pick ) || '' !== $edit_id;
$panel_open  = ( '1' === $wizard && '' === $pick && ! $show_form );

if ( $show_form ) {
	$rec = '' !== $edit_id ? Mailpai_Smtp_Connection_Store::get( $edit_id ) : Mailpai_Smtp_Connection_Store::empty_record();
	if ( ! is_array( $rec ) ) {
		$rec = Mailpai_Smtp_Connection_Store::empty_record();
	}
	if ( '' !== $pick ) {
		$rec['provider'] = Mailpai_Smtp_Provider_Registry::normalize_slug( $pick );
	} elseif ( '' !== $edit_id && empty( Mailpai_Smtp_Provider_Registry::get( $rec['provider'] ?? '' ) ) ) {
		wp_safe_redirect( Mailpai_Smtp_Urls::tab( 'dashboard' ) );
		exit;
	}
	include __DIR__ . '/partials/connection-form.php';
	return;
}
?>
<div class="mailpai-smtp-page-head">
	<h2><?php esc_html_e( 'Email connections', 'smtp-pai' ); ?></h2>
</div>

<div class="mailpai-smtp-conn-layout">
<div class="mailpai-smtp-conn-layout__main">

<div class="mailpai-smtp-connect-cta<?php echo $panel_open ? ' is-open' : ''; ?>" id="mailpai-smtp-connect-cta">
	<div class="mailpai-smtp-empty mailpai-smtp-empty--connect" id="mailpai-smtp-connect-empty" <?php echo $panel_open ? 'hidden' : ''; ?>>
		<button
			type="button"
			class="mailpai-smtp-add-btn"
			aria-expanded="<?php echo $panel_open ? 'true' : 'false'; ?>"
			aria-controls="mailpai-smtp-provider-panel"
		>
			<?php Mailpai_Smtp_Icons::render( 'plus', 18 ); ?>
			<span><?php esc_html_e( 'Add connection', 'smtp-pai' ); ?></span>
		</button>
	</div>

	<div id="mailpai-smtp-provider-panel" class="mailpai-smtp-provider-panel" <?php echo $panel_open ? '' : 'hidden'; ?>>
		<?php
		$picker_embedded = true;
		include __DIR__ . '/partials/provider-picker.php';
		?>
	</div>
</div>

<?php if ( ! empty( $connections ) ) : ?>
	<div class="mailpai-smtp-conn-grid">
		<?php foreach ( $connections as $rec ) :
			$def                = Mailpai_Smtp_Provider_Registry::get( $rec['provider'] ?? '' );
			$st                 = Mailpai_Smtp_Monitor::connection_status( $rec );
			$needs_oauth_signin = Mailpai_Smtp_Connection_Store::needs_oauth_signin( $rec );
			$oauth_signin_url   = $needs_oauth_signin ? Mailpai_Smtp_Oauth::connection_start_url( $rec ) : '';
			$route_labels       = Mailpai_Smtp_Routes::route_labels_for_connection( $rec['id'] ?? '' );
			$labels = array(
				'working'  => __( 'Working', 'smtp-pai' ),
				'failed'   => __( 'Failed', 'smtp-pai' ),
				'untested' => __( 'Not tested', 'smtp-pai' ),
				'disabled' => __( 'Disabled', 'smtp-pai' ),
			);
			?>
			<article class="mailpai-smtp-conn-card mailpai-smtp-conn-card--<?php echo esc_attr( $st ); ?>">
				<?php if ( ! empty( $route_labels ) ) : ?>
					<div class="mailpai-smtp-conn-card__routes" aria-label="<?php esc_attr_e( 'Assigned mail routes', 'smtp-pai' ); ?>">
						<?php foreach ( $route_labels as $route_label ) : ?>
							<span class="mailpai-smtp-route-tag"><?php echo esc_html( $route_label ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<div class="mailpai-smtp-conn-card__head">
					<?php if ( ! empty( $def['logo'] ) ) : ?>
						<img src="<?php echo esc_url( Mailpai_Smtp_Provider_Registry::logo_url( $def['logo'] ) ); ?>" alt="" width="32" height="32" class="mailpai-smtp-conn-card__logo" />
					<?php endif; ?>
					<div>
						<h3><?php echo esc_html( Mailpai_Smtp_Connection_Store::title( $rec ) ); ?></h3>
						<span class="mailpai-smtp-badge mailpai-smtp-badge--<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $labels[ $st ] ?? $st ); ?></span>
					</div>
				</div>
				<p class="mailpai-smtp-conn-card__from"><?php echo esc_html( $rec['from_email'] ?? '' ); ?></p>
				<div class="mailpai-smtp-conn-card__actions">
					<?php if ( '' !== $oauth_signin_url ) : ?>
						<a class="mailpai-smtp-btn mailpai-smtp-oauth-connect-btn" href="<?php echo esc_url( $oauth_signin_url ); ?>">
							<?php echo esc_html( Mailpai_Smtp_Oauth::button_label( $rec['provider'] ?? '' ) ); ?>
						</a>
					<?php else : ?>
						<button type="button" class="mailpai-smtp-btn mailpai-smtp-test-btn" data-connection-id="<?php echo esc_attr( $rec['id'] ); ?>" data-connection-title="<?php echo esc_attr( Mailpai_Smtp_Connection_Store::title( $rec ) ); ?>"><?php esc_html_e( 'Test', 'smtp-pai' ); ?></button>
					<?php endif; ?>
					<a class="mailpai-smtp-btn mailpai-smtp-btn--outline" href="<?php echo esc_url( Mailpai_Smtp_Urls::tab( 'dashboard', array( 'edit' => $rec['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'smtp-pai' ); ?></a>
				</div>
				<?php if ( $needs_oauth_signin && '' === $oauth_signin_url ) : ?>
					<div class="mailpai-smtp-conn-card__alert mailpai-smtp-conn-card__alert--info" role="status">
						<p class="mailpai-smtp-conn-card__error"><?php esc_html_e( 'Add your OAuth app credentials in Edit, then save to continue.', 'smtp-pai' ); ?></p>
					</div>
				<?php elseif ( 'failed' === $st && ! empty( $rec['last_error'] ) ) : ?>
					<div class="mailpai-smtp-conn-card__alert" role="alert">
						<p class="mailpai-smtp-conn-card__error"><?php echo esc_html( $rec['last_error'] ); ?></p>
					</div>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

</div>
<?php require __DIR__ . '/partials/setup-guide.php'; ?>
</div>
