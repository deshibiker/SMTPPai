<?php
/**
 * Setup guide sidebar for the connections dashboard.
 *
 * @package Mailpai_Smtp
 *
 * @var array $connections Dashboard connection records.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$hint_sections    = Mailpai_Smtp_Setup_Guide::sections( is_array( $connections ) ? count( $connections ) : 0 );
$hints_title      = __( 'Setup guide', 'smtp-pai' );
$hints_aria_label = __( 'Plugin setup guide', 'smtp-pai' );

require MAILPAI_SMTP_PLUGIN_DIR . 'includes/admin/views/partials/connection-hints.php';
