<?php
// Exit if accessed directly.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete the HubSpot token option.
delete_option('mhg_hubspot_token');

// Delete all stored engagement maps.
global $wpdb;
$options = $wpdb->get_results("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'mhg_engagement_map_%'");

foreach ($options as $option) {
    delete_option($option->option_name);
}
