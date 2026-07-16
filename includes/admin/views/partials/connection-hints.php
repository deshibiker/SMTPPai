<?php
/**
 * Helpful Hints sidebar for connection setup.
 *
 * @package Mailpai_Smtp
 *
 * @var array<int,array{title:string,content:string,open?:bool}> $hint_sections
 * @var string $hints_title Optional sidebar heading.
 * @var string $hints_aria_label Optional accessible name.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

if ( empty( $hint_sections ) || ! is_array( $hint_sections ) ) {
	return;
}

$hints_title      = isset( $hints_title ) ? (string) $hints_title : __( 'Helpful Hints', 'smtp-pai' );
$hints_aria_label = isset( $hints_aria_label ) ? (string) $hints_aria_label : $hints_title;
?>
<aside class="mailpai-smtp-hints" aria-label="<?php echo esc_attr( $hints_aria_label ); ?>">
	<h2 class="mailpai-smtp-hints__title"><?php echo esc_html( $hints_title ); ?></h2>
	<div class="mailpai-smtp-hints-accordion">
		<?php foreach ( $hint_sections as $index => $section ) : ?>
			<?php
			$is_open = ! empty( $section['open'] );
			$item_id = 'mailpai-smtp-hint-' . (int) $index;
			?>
			<div class="mailpai-smtp-hints-accordion__item<?php echo $is_open ? ' is-open' : ''; ?>">
				<button
					type="button"
					class="mailpai-smtp-hints-accordion__trigger"
					id="<?php echo esc_attr( $item_id ); ?>-trigger"
					aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>"
					aria-controls="<?php echo esc_attr( $item_id ); ?>-panel"
				>
					<span class="mailpai-smtp-hints-accordion__label"><?php echo esc_html( $section['title'] ?? '' ); ?></span>
					<span class="mailpai-smtp-hints-accordion__chevron" aria-hidden="true"><?php Mailpai_Smtp_Icons::render( 'chevron-down', 18 ); ?></span>
				</button>
				<div
					class="mailpai-smtp-hints-accordion__panel"
					id="<?php echo esc_attr( $item_id ); ?>-panel"
					role="region"
					aria-labelledby="<?php echo esc_attr( $item_id ); ?>-trigger"
					<?php echo $is_open ? '' : 'hidden'; ?>
				>
					<div class="mailpai-smtp-hints-accordion__content">
						<?php echo wp_kses_post( (string) ( $section['content'] ?? '' ) ); ?>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</aside>
