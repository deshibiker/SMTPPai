<?php
/**
 * Single route row on Specify Connections.
 *
 * @package Mailpai_Smtp
 *
 * @var string               $slug Route slug.
 * @var string               $selected Selected connection id.
 * @var array<string,string> $choices Connection id => title.
 * @var array<string,string> $choice_logos Connection id => logo URL.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$meta = Mailpai_Smtp_Routes::route_meta( $slug );
$field_name = ( 'all' === $slug ) ? 'route_use_one_connection' : 'route_' . $slug;
$field_id   = ( 'all' === $slug ) ? 'route_use_one_connection' : 'route_' . $slug;
?>
<div class="mailpai-smtp-route-row">
	<div class="mailpai-smtp-route-row__info">
		<img
			class="mailpai-smtp-route-row__icon"
			src="<?php echo esc_url( $meta['icon'] ); ?>"
			alt=""
			width="40"
			height="40"
			loading="lazy"
			decoding="async"
		/>
		<div class="mailpai-smtp-route-row__text">
			<span class="mailpai-smtp-route-row__title" id="route_label_<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $meta['title'] ); ?></span>
			<?php if ( ! empty( $meta['description'] ) ) : ?>
				<span class="mailpai-smtp-route-row__desc"><?php echo esc_html( $meta['description'] ); ?></span>
			<?php endif; ?>
		</div>
	</div>
	<div class="mailpai-smtp-route-row__control">
		<div class="mailpai-smtp-select-wrap mailpai-smtp-route-select">
			<img class="mailpai-smtp-route-select__logo" alt="" width="18" height="18" hidden decoding="async" />
			<select
				name="<?php echo esc_attr( $field_name ); ?>"
				id="<?php echo esc_attr( $field_id ); ?>"
				class="mailpai-smtp-route-row__select"
				aria-labelledby="route_label_<?php echo esc_attr( $slug ); ?>"
			>
				<?php foreach ( $choices as $id => $title ) : ?>
					<option
						value="<?php echo esc_attr( $id ); ?>"
						<?php selected( $selected, $id ); ?>
						<?php if ( ! empty( $choice_logos[ $id ] ) ) : ?>
							data-logo="<?php echo esc_url( $choice_logos[ $id ] ); ?>"
						<?php endif; ?>
					><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>
</div>
