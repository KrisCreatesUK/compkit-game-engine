<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

// Global settings
delete_option('giveaways_enabled_modules');
delete_option('giveaways_license_keys');
delete_option('giveaways_license_status');
delete_option('giveaways_license_grants');
delete_option('giveaways_license_expiry');

// Spin the Wheel
delete_option('spinbolt_global_settings');
delete_option('spinbolt_global_sfx_spin');
delete_option('spinbolt_global_sfx_ticket_lock');
delete_option('spinbolt_global_sfx_win');

// Instant Reveal opt-in leads
delete_option('eiwr_premium_leads');

// Scratchcard global settings
delete_option('scratchcard_global_settings');
delete_option('scratchcard_global_theme');
delete_option('scratchcard_global_sfx_scratch');
delete_option('scratchcard_global_sfx_win');

// Scratchcard per-product post meta
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_sc_%'");

// Transient cache
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct DELETE required during uninstall; no caching needed
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_giveaways_license_cache_%'");
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_giveaways_license_cache_%'");

// Review prompt state (see giveaways-review-prompt.php)
delete_option('compkit_reveal_count');
delete_option('compkit_first_reveal_at');
delete_option('compkit_review_prompt_state');
delete_option('compkit_review_snoozed_until');

// Scheduled events
$giveaways_timestamp = wp_next_scheduled('giveaways_daily_license_validation'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if ($giveaways_timestamp) {
    wp_unschedule_event($giveaways_timestamp, 'giveaways_daily_license_validation');
}

// One pending release per held order, so clear the hook rather than an event.
wp_unschedule_hook('compkit_release_order_emails');
