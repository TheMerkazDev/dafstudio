<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

// User research data and settings are intentionally preserved on uninstall.
// Define MTL_DELETE_DATA_ON_UNINSTALL as true in wp-config.php before uninstalling to remove them.
if ( ! defined( 'MTL_DELETE_DATA_ON_UNINSTALL' ) || ! MTL_DELETE_DATA_ON_UNINSTALL ) { return; }

global $wpdb;
foreach ( array(
    'debug_logs','annotation_sources','revisions','imports','projects','project_members','segments','annotations','outline_nodes','sources','entitlements','export_profiles'
) as $name ) {
    $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mtl_' . $name );
}
foreach ( array(
    'mtl_db_version','mtl_cap_version','mtl_workspace_page_url','mtl_openai_api_key','mtl_openai_model','mtl_openai_reasoning_effort',
    'mtl_openai_max_output_tokens','mtl_openai_import_chunk_chars','mtl_openai_baseline_context','mtl_role_bundle_map','mtl_debug_enabled','mtl_debug_retention_days'
) as $option ) { delete_option( $option ); }
