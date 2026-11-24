<?php
/**
 * Plugin Name: Daf Studio
 * Description: Adaptive, bilingual Vilna-inspired source sheets. Visual editor, private storage, revision history and membership-aware sharing.
 * Version: 0.4.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: daf-studio
 */
if (!defined('ABSPATH')) { exit; }
define('DAF_STUDIO_VERSION','0.4.0');
define('DAF_STUDIO_PATH',plugin_dir_path(__FILE__));
define('DAF_STUDIO_URL',plugin_dir_url(__FILE__));
require_once DAF_STUDIO_PATH.'includes/schema.php';
require_once DAF_STUDIO_PATH.'includes/rest.php';
require_once DAF_STUDIO_PATH.'includes/settings.php';
function daf_install() {
    global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$collate=$wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$wpdb->prefix}daf_sheets (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        owner_id bigint(20) unsigned NOT NULL,
        title varchar(240) NOT NULL,
        document longtext NOT NULL,
        revision bigint(20) unsigned NOT NULL DEFAULT 1,
        share_hash char(64) DEFAULT NULL,
        share_expires datetime DEFAULT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY owner_id (owner_id),
        KEY share_hash (share_hash)
    ) ENGINE=InnoDB $collate;");
    dbDelta("CREATE TABLE {$wpdb->prefix}daf_revisions (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        sheet_id bigint(20) unsigned NOT NULL,
        revision bigint(20) unsigned NOT NULL,
        document longtext NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY sheet_revision (sheet_id,revision)
    ) ENGINE=InnoDB $collate;");
    update_option('daf_studio_schema',1,false);
}
register_activation_hook(__FILE__,'daf_install');
function daf_plan($uid) {
    if(!$uid)return 'free';
    $plan=user_can($uid,'manage_options') || user_can($uid,'daf_studio_pro')?'pro':'free';
    $ids=array_filter(array_map('absint',explode(',',(string)get_option('daf_studio_pmpro_levels',''))));
    if($ids && function_exists('pmpro_hasMembershipLevel') && pmpro_hasMembershipLevel($ids,$uid))$plan='pro';
    $plan=apply_filters('daf_studio_plan',$plan,$uid);
    return $plan==='pro'?'pro':'free';
}
function daf_app_url($fresh=false) {$url=add_query_arg('daf_studio_app','1',home_url('/'));return $fresh?add_query_arg('daf_fresh','1',$url):$url;}
add_shortcode('daf_studio',function(){
    if(!is_user_logged_in())return '<p>Please <a href="'.esc_url(wp_login_url(get_permalink())).'">log in</a> to use Daf Studio.</p>';
    return '<div class="daf-studio-embed"><p><a href="'.esc_url(daf_app_url()).'" target="_blank" rel="noopener">Open Daf Studio full screen</a></p><iframe title="Daf Studio source sheet editor" src="'.esc_url(daf_app_url()).'" style="width:100%;height:900px;border:1px solid #ddd;border-radius:12px" referrerpolicy="no-referrer" allow="clipboard-write"></iframe></div>';
});
add_action('template_redirect',function(){
    if(!isset($_GET['daf_studio_app']))return;
    nocache_headers();header('X-Frame-Options: SAMEORIGIN',true);header("Content-Security-Policy: frame-ancestors 'self'",true);header('X-Robots-Tag: noindex, nofollow',true);header('Referrer-Policy: no-referrer',true);header('X-Content-Type-Options: nosniff',true);
    // Public shell contains no private sheet data. Shared documents are fetched with a random fragment token.
    $fresh=!empty($_GET['daf_fresh']) && current_user_can('manage_options');
    $config=array('rest'=>esc_url_raw(rest_url('daf-studio/v1/')),'nonce'=>is_user_logged_in()?wp_create_nonce('wp_rest'):'',
      'userId'=>get_current_user_id(),'readOnly'=>!is_user_logged_in(),'plan'=>daf_plan(get_current_user_id()),'appUrl'=>daf_app_url(),
      'upgradeUrl'=>daf_url(get_option('daf_studio_upgrade_url','')),'version'=>DAF_STUDIO_VERSION,
      'defaultSettings'=>daf_studio_get_appearance(),'uiTheme'=>daf_studio_get_ui_theme(),'defaultDocument'=>daf_studio_get_default_document(),'fresh'=>$fresh);
    $html=file_get_contents(DAF_STUDIO_PATH.'assets/index.html');
    $html=str_replace('<!--BOOTSTRAP-->','<script>window.DAF_CONFIG='.wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script>',$html);
    $html=str_replace('<!--BASE-->','<base href="'.esc_url(DAF_STUDIO_URL.'assets/').'">',$html);
    // Bust the browser asset cache on plugin update; document schema/storage keys stay unchanged.
    $html=preg_replace_callback('/(src|href)="([a-z-]+\.(?:js|css))"/',function($match){
        return $match[1].'="'.$match[2].'?ver='.rawurlencode(DAF_STUDIO_VERSION).'"';
    },$html);
    echo $html; // Static application shell; dynamic values are JSON-hex/attribute escaped above.
    exit;
});
add_filter('rest_post_dispatch',function($response,$server,$request){
    if(strpos($request->get_route(),'/daf-studio/v1/')===0 && $response instanceof WP_REST_Response){$response->header('Cache-Control','private, no-store, max-age=0');$response->header('X-Robots-Tag','noindex, nofollow');}
    return $response;
},10,3);
