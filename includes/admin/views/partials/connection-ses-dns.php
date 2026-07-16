<?php
/**
 * Amazon SES DNS records and verification checks.
 *
 * @package Mailpai_Smtp
 *
 * @var array       $rec         Connection record.
 * @var string      $cid         Connection id.
 * @var string      $console_url SES console URL.
 * @var array|null  $snapshot    DNS snapshot from SES.
 * @var array|null  $dns_check   DNS check results.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.
?>
<div class="mailpai-smtp-ses-dns-wrap">
<div class="mailpai-smtp-conn-panel mailpai-smtp-ses-dns">
	<div class="mailpai-smtp-section-badge">
		<h3><?php esc_html_e( 'DNS (Amazon SES)', 'smtp-pai' ); ?></h3>
	</div>
	<div class="mailpai-smtp-card mailpai-smtp-card--conn-block">
		<p class="mailpai-smtp-ses-dns__intro description"><?php esc_html_e( 'Records from SES for your DNS. Optional check.', 'smtp-pai' ); ?></p>
		<div class="mailpai-smtp-ses-dns__actions">
			<form method="post" class="mailpai-smtp-ses-dns__form">
				<?php wp_nonce_field( 'mailpai_smtp_ses_dns' ); ?>
				<input type="hidden" name="mailpai_smtp_action" value="ses_dns_refresh" />
				<input type="hidden" name="mailpai_smtp_conn_id" value="<?php echo esc_attr( $cid ); ?>" />
				<button type="submit" class="mailpai-smtp-btn mailpai-smtp-btn--outline mailpai-smtp-btn--sm"><?php esc_html_e( 'Load records from SES', 'smtp-pai' ); ?></button>
			</form>
			<form method="post" class="mailpai-smtp-ses-dns__form">
				<?php wp_nonce_field( 'mailpai_smtp_ses_dns' ); ?>
				<input type="hidden" name="mailpai_smtp_action" value="ses_dns_check" />
				<input type="hidden" name="mailpai_smtp_conn_id" value="<?php echo esc_attr( $cid ); ?>" />
				<button type="submit" class="mailpai-smtp-btn mailpai-smtp-btn--outline mailpai-smtp-btn--sm" <?php disabled( ! $snapshot || empty( $snapshot['records'] ) ); ?>><?php esc_html_e( 'Check DNS', 'smtp-pai' ); ?></button>
			</form>
			<a class="mailpai-smtp-btn mailpai-smtp-btn--outline mailpai-smtp-btn--sm mailpai-smtp-btn--accent" href="<?php echo esc_url( $console_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'SES verified identities', 'smtp-pai' ); ?></a>
		</div>

		<?php if ( $snapshot ) : ?>
			<p class="mailpai-smtp-ses-dns__meta">
				<?php
				if ( ! empty( $snapshot['fetched_at'] ) ) {
					printf(
						/* translators: %s: human-readable time difference */
						esc_html__( 'Loaded %s ago.', 'smtp-pai' ),
						esc_html( human_time_diff( (int) $snapshot['fetched_at'], time() ) )
					);
				}
				$vst = isset( $snapshot['verification_status'] ) ? (string) $snapshot['verification_status'] : '';
				if ( '' !== $vst ) {
					echo ' ';
					printf(
						/* translators: %s: SES identity verification status */
						esc_html__( 'SES identity status: %s', 'smtp-pai' ),
						esc_html( $vst )
					);
				}
				?>
			</p>
			<?php if ( ! empty( $snapshot['records'] ) ) : ?>
				<table class="mailpai-smtp-dns-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Type', 'smtp-pai' ); ?></th>
							<th><?php esc_html_e( 'Name', 'smtp-pai' ); ?></th>
							<th><?php esc_html_e( 'Value', 'smtp-pai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $snapshot['records'] as $row ) : ?>
							<tr>
								<td><code><?php echo esc_html( $row['type'] ?? '' ); ?></code></td>
								<td><code><?php echo esc_html( $row['name'] ?? '' ); ?></code></td>
								<td><code><?php echo esc_html( $row['value'] ?? '' ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="mailpai-smtp-ses-dns__meta"><?php esc_html_e( 'No DKIM tokens yet — verify this domain or address in SES, then load again.', 'smtp-pai' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $dns_check && ! empty( $dns_check['rows'] ) ) : ?>
			<h4 class="mailpai-smtp-ses-dns__results-title"><?php esc_html_e( 'Check results', 'smtp-pai' ); ?></h4>
			<table class="mailpai-smtp-dns-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Check', 'smtp-pai' ); ?></th>
						<th><?php esc_html_e( 'Status', 'smtp-pai' ); ?></th>
						<th><?php esc_html_e( 'Note', 'smtp-pai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $dns_check['rows'] as $row ) :
						$st    = isset( $row['status'] ) ? (string) $row['status'] : '';
						$badge = 'mailpai-smtp-badge--accent';
						if ( 'pass' === $st ) {
							$badge = 'mailpai-smtp-badge--success';
						} elseif ( 'warn' === $st || 'pending' === $st ) {
							$badge = 'mailpai-smtp-badge--warn';
						} elseif ( 'fail' === $st ) {
							$badge = 'mailpai-smtp-badge--failed';
						}
						?>
						<tr>
							<td><?php echo esc_html( $row['label'] ?? '' ); ?></td>
							<td><span class="mailpai-smtp-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( strtoupper( $st ) ); ?></span></td>
							<td><?php echo esc_html( $row['message'] ?? '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
</div>
