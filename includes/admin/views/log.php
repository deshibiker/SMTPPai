<?php
/**
 * Email Log tab.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$status    = isset( $_GET['log_status'] ) ? sanitize_key( wp_unslash( $_GET['log_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$search    = isset( $_GET['log_s'] ) ? sanitize_text_field( wp_unslash( $_GET['log_s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$date_from = isset( $_GET['log_from'] ) ? sanitize_text_field( wp_unslash( $_GET['log_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$date_to   = isset( $_GET['log_to'] ) ? sanitize_text_field( wp_unslash( $_GET['log_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$log_page  = isset( $_GET['log_page'] ) ? max( 1, absint( wp_unslash( $_GET['log_page'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$per       = 20;

$result = Mailpai_Smtp_Log::query(
	array(
		'status'    => $status,
		'search'    => $search,
		'page'      => $log_page,
		'per_page'  => $per,
		'date_from' => $date_from,
		'date_to'   => $date_to,
	)
);
$items       = $result['items'];
$total       = $result['total'];
$log_page    = $result['page'];
$total_pages = $result['pages'];
$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$conn_titles = array();

foreach ( $items as $row ) {
	$conn_id = $row['connection_id'] ?? '';
	if ( '' === $conn_id || isset( $conn_titles[ $conn_id ] ) ) {
		continue;
	}
	$conn = Mailpai_Smtp_Connection_Store::get( $conn_id );
	$conn_titles[ $conn_id ] = $conn ? Mailpai_Smtp_Connection_Store::title( $conn ) : $conn_id;
}

$filter_args = array_filter(
	array(
		'log_s'    => $search,
		'log_from' => $date_from,
		'log_to'   => $date_to,
	)
);

/**
 * Build a paginated log tab URL preserving active filters.
 *
 * @param int    $page        Page number.
 * @param array  $filter_args Filter query args.
 * @param string $status      Status filter.
 * @return string
 */
$mailpai_smtp_log_page_url = static function ( $page, $filter_args, $status ) {
	$args = $filter_args;
	if ( $page > 1 ) {
		$args['log_page'] = $page;
	}
	if ( $status ) {
		$args['log_status'] = $status;
	}
	return Mailpai_Smtp_Urls::tab( 'log', $args );
};

$range_start = 0 === $total ? 0 : ( ( $log_page - 1 ) * $per ) + 1;
$range_end   = min( $total, $log_page * $per );
?>
<div class="mailpai-smtp-page-head">
	<h2><?php esc_html_e( 'Email Log', 'smtp-pai' ); ?></h2>
</div>

<div class="mailpai-smtp-log-panel">
	<div class="mailpai-smtp-log-toolbar">
		<div class="mailpai-smtp-pills" role="tablist" aria-label="<?php esc_attr_e( 'Log status filter', 'smtp-pai' ); ?>">
			<a class="mailpai-smtp-pill <?php echo '' === $status ? 'is-active' : ''; ?>" href="<?php echo esc_url( Mailpai_Smtp_Urls::tab( 'log', $filter_args ) ); ?>"><?php esc_html_e( 'All', 'smtp-pai' ); ?></a>
			<a class="mailpai-smtp-pill <?php echo 'sent' === $status ? 'is-active' : ''; ?>" href="<?php echo esc_url( Mailpai_Smtp_Urls::tab( 'log', array_merge( $filter_args, array( 'log_status' => 'sent' ) ) ) ); ?>"><?php esc_html_e( 'Sent', 'smtp-pai' ); ?></a>
			<a class="mailpai-smtp-pill <?php echo 'failed' === $status ? 'is-active' : ''; ?>" href="<?php echo esc_url( Mailpai_Smtp_Urls::tab( 'log', array_merge( $filter_args, array( 'log_status' => 'failed' ) ) ) ); ?>"><?php esc_html_e( 'Failed', 'smtp-pai' ); ?></a>
		</div>
		<form method="get" class="mailpai-smtp-log-search">
			<input type="hidden" name="page" value="<?php echo esc_attr( Mailpai_Smtp_Urls::menu_slug() ); ?>" />
			<input type="hidden" name="tab" value="log" />
			<?php if ( $status ) : ?><input type="hidden" name="log_status" value="<?php echo esc_attr( $status ); ?>" /><?php endif; ?>
			<div class="mailpai-smtp-log-search__fields">
				<input type="search" class="mailpai-smtp-input mailpai-smtp-input--search" name="log_s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'smtp-pai' ); ?>" />
				<input type="date" class="mailpai-smtp-input mailpai-smtp-input--date" name="log_from" value="<?php echo esc_attr( $date_from ); ?>" aria-label="<?php esc_attr_e( 'From date', 'smtp-pai' ); ?>" />
				<input type="date" class="mailpai-smtp-input mailpai-smtp-input--date" name="log_to" value="<?php echo esc_attr( $date_to ); ?>" aria-label="<?php esc_attr_e( 'To date', 'smtp-pai' ); ?>" />
			</div>
			<button type="submit" class="mailpai-smtp-btn mailpai-smtp-btn--outline"><?php esc_html_e( 'Filter', 'smtp-pai' ); ?></button>
		</form>
	</div>

	<form method="post" class="mailpai-smtp-log-form">
		<?php wp_nonce_field( 'mailpai_smtp_log_bulk' ); ?>
		<div class="mailpai-smtp-table-wrap">
			<table class="mailpai-smtp-table mailpai-smtp-log-table">
				<thead>
					<tr>
						<th class="mailpai-smtp-table__check" scope="col">
							<input type="checkbox" class="mailpai-smtp-checkbox" id="mailpai-smtp-log-select-all" aria-label="<?php esc_attr_e( 'Select all', 'smtp-pai' ); ?>" />
						</th>
						<th scope="col"><?php esc_html_e( 'Subject', 'smtp-pai' ); ?></th>
						<th scope="col"><?php esc_html_e( 'To', 'smtp-pai' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Route', 'smtp-pai' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Connection', 'smtp-pai' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'smtp-pai' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Time', 'smtp-pai' ); ?></th>
						<th scope="col" class="mailpai-smtp-table__actions"><?php esc_html_e( 'Actions', 'smtp-pai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr>
							<td colspan="8" class="mailpai-smtp-table__empty"><?php esc_html_e( 'No log entries yet.', 'smtp-pai' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $items as $row ) :
							$conn_id    = $row['connection_id'] ?? '';
							$conn_title = isset( $conn_titles[ $conn_id ] ) ? $conn_titles[ $conn_id ] : ( '' !== $conn_id ? $conn_id : '—' );
							$is_failed  = 'failed' === ( $row['status'] ?? '' );
							?>
							<tr class="mailpai-smtp-log-row <?php echo $is_failed ? 'is-failed' : 'is-sent'; ?>">
								<td class="mailpai-smtp-table__check">
									<input type="checkbox" class="mailpai-smtp-checkbox" name="log_ids[]" value="<?php echo esc_attr( $row['id'] ); ?>" aria-label="<?php esc_attr_e( 'Select row', 'smtp-pai' ); ?>" />
								</td>
								<td class="mailpai-smtp-log-table__subject">
									<strong class="mailpai-smtp-log-table__subject-text"><?php echo esc_html( $row['subject'] ); ?></strong>
									<?php if ( $is_failed && ! empty( $row['error_message'] ) ) : ?>
										<div class="mailpai-smtp-log-error"><?php echo esc_html( $row['error_message'] ); ?></div>
									<?php endif; ?>
									<?php if ( ! empty( $row['failover'] ) ) : ?>
										<span class="mailpai-smtp-badge mailpai-smtp-badge--failover"><?php esc_html_e( 'Failover', 'smtp-pai' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $row['recipient'] ); ?></td>
								<td><?php echo esc_html( Mailpai_Smtp_Routes::label( $row['route'] ) ); ?></td>
								<td><?php echo esc_html( $conn_title ); ?></td>
								<td><span class="mailpai-smtp-badge mailpai-smtp-badge--<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( ucfirst( (string) $row['status'] ) ); ?></span></td>
								<td class="mailpai-smtp-log-table__time"><?php echo esc_html( mysql2date( $date_format, $row['created_at'] ) ); ?></td>
								<td class="mailpai-smtp-log-actions">
									<button type="button" class="mailpai-smtp-icon-btn mailpai-smtp-log-view" data-log-id="<?php echo esc_attr( $row['id'] ); ?>" aria-label="<?php esc_attr_e( 'View details', 'smtp-pai' ); ?>"><?php Mailpai_Smtp_Icons::render( 'eye', 16 ); ?></button>
									<?php if ( $is_failed ) : ?>
										<button type="button" class="mailpai-smtp-icon-btn mailpai-smtp-log-retry" data-log-id="<?php echo esc_attr( $row['id'] ); ?>" aria-label="<?php esc_attr_e( 'Retry send', 'smtp-pai' ); ?>"><?php Mailpai_Smtp_Icons::render( 'refresh', 16 ); ?></button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php if ( $total_pages > 1 ) : ?>
			<nav class="mailpai-smtp-log-pagination" aria-label="<?php esc_attr_e( 'Log list pages', 'smtp-pai' ); ?>">
				<?php if ( $log_page > 1 ) : ?>
					<a class="mailpai-smtp-log-pagination__btn" href="<?php echo esc_url( $mailpai_smtp_log_page_url( $log_page - 1, $filter_args, $status ) ); ?>"><?php esc_html_e( 'Previous', 'smtp-pai' ); ?></a>
				<?php else : ?>
					<span class="mailpai-smtp-log-pagination__btn is-disabled" aria-disabled="true"><?php esc_html_e( 'Previous', 'smtp-pai' ); ?></span>
				<?php endif; ?>
				<span class="mailpai-smtp-log-pagination__status">
					<?php
					printf(
						/* translators: 1: current page, 2: total pages */
						esc_html__( 'Page %1$d of %2$d', 'smtp-pai' ),
						(int) $log_page,
						(int) $total_pages
					);
					?>
				</span>
				<?php if ( $log_page < $total_pages ) : ?>
					<a class="mailpai-smtp-log-pagination__btn" href="<?php echo esc_url( $mailpai_smtp_log_page_url( $log_page + 1, $filter_args, $status ) ); ?>"><?php esc_html_e( 'Next', 'smtp-pai' ); ?></a>
				<?php else : ?>
					<span class="mailpai-smtp-log-pagination__btn is-disabled" aria-disabled="true"><?php esc_html_e( 'Next', 'smtp-pai' ); ?></span>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
		<div class="mailpai-smtp-log-footer">
			<button type="submit" class="mailpai-smtp-btn mailpai-smtp-btn--outline" name="mailpai_smtp_action" value="delete_logs"><?php esc_html_e( 'Delete selected', 'smtp-pai' ); ?></button>
			<button type="submit" class="mailpai-smtp-btn mailpai-smtp-btn--outline" name="mailpai_smtp_action" value="delete_all_logs" onclick="return confirm('<?php echo esc_js( __( 'Delete all logs?', 'smtp-pai' ) ); ?>');"><?php esc_html_e( 'Delete all', 'smtp-pai' ); ?></button>
			<span class="mailpai-smtp-log-meta">
				<?php
				if ( $total > 0 ) {
					printf(
						/* translators: 1: first row number, 2: last row number, 3: total log count */
						esc_html__( 'Showing %1$d–%2$d of %3$d', 'smtp-pai' ),
						(int) $range_start,
						(int) $range_end,
						(int) $total
					);
				} else {
					esc_html_e( 'No entries', 'smtp-pai' );
				}
				?>
			</span>
		</div>
	</form>
</div>

<div id="mailpai-smtp-log-modal" class="mailpai-smtp-log-modal" hidden>
	<div class="mailpai-smtp-log-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="mailpai-smtp-log-modal-title">
		<div class="mailpai-smtp-log-modal__toolbar">
			<button type="button" class="mailpai-smtp-log-modal__close" aria-label="<?php esc_attr_e( 'Close', 'smtp-pai' ); ?>">&times;</button>
		</div>
		<div id="mailpai-smtp-log-modal-body" class="mailpai-smtp-log-modal__body"></div>
		<div class="mailpai-smtp-log-modal__footer">
			<button type="button" class="mailpai-smtp-log-modal__nav mailpai-smtp-log-modal__nav--prev" disabled><?php esc_html_e( 'Previous', 'smtp-pai' ); ?></button>
			<button type="button" class="mailpai-smtp-log-modal__nav mailpai-smtp-log-modal__nav--next" disabled><?php esc_html_e( 'Next', 'smtp-pai' ); ?></button>
		</div>
	</div>
</div>
