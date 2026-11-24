<?php
if(!defined('WP_UNINSTALL_PLUGIN'))exit;
// Preserve authored work by default. A deliberate wp-config.php constant is required.
if(!defined('DAF_STUDIO_DELETE_DATA') || DAF_STUDIO_DELETE_DATA!==true)return;
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}daf_revisions");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}daf_sheets");
foreach(array('daf_studio_schema','daf_studio_pmpro_levels','daf_studio_upgrade_url') as $option)delete_option($option);
