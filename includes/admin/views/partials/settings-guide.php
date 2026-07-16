<?php
/**
 * Settings sidebar guide.
 *
 * @package Mailpai_Smtp
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template locals.

$hint_sections    = Mailpai_Smtp_Settings_Guide::sections();
$hints_title      = __( 'Settings guide', 'smtp-pai' );
$hints_aria_label = __( 'How to configure plugin settings', 'smtp-pai' );

require MAILPAI_SMTP_PLUGIN_DIR . 'includes/admin/views/partials/connection-hints.php';
