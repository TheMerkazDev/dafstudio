<?php
/**
 * Plugin Name: Merkaz TextLab
 * Description: A Sefaria-connected biblical text annotation, outlining, translation, collaboration, import, and publishing workbench for WordPress/BuddyPress.
 * Version: 0.3.2
 * Author: Merkaz TextLab
 * License: GPL-2.0-or-later
 * Text Domain: merkaz-textlab
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'MTL_VERSION', '0.3.2' );
define( 'MTL_FILE', __FILE__ );
define( 'MTL_DIR', plugin_dir_path( __FILE__ ) );
define( 'MTL_URL', plugin_dir_url( __FILE__ ) );

require_once MTL_DIR . 'includes/class-mtl-db.php';
require_once MTL_DIR . 'includes/class-mtl-debug.php';
require_once MTL_DIR . 'includes/class-mtl-capabilities.php';
require_once MTL_DIR . 'includes/class-mtl-membership.php';
require_once MTL_DIR . 'includes/class-mtl-sefaria.php';
require_once MTL_DIR . 'includes/class-mtl-openai.php';
require_once MTL_DIR . 'includes/class-mtl-import.php';
require_once MTL_DIR . 'includes/class-mtl-rest.php';
require_once MTL_DIR . 'includes/class-mtl-pdf.php';
require_once MTL_DIR . 'includes/class-mtl-export.php';
require_once MTL_DIR . 'includes/class-mtl-shortcode.php';
require_once MTL_DIR . 'admin/class-mtl-admin.php';

register_activation_hook( __FILE__, array( 'MTL_DB', 'activate' ) );
register_activation_hook( __FILE__, array( 'MTL_Capabilities', 'activate' ) );
register_activation_hook( __FILE__, array( 'MTL_Admin', 'install_defaults' ) );

add_action( 'plugins_loaded', function () {
    MTL_DB::maybe_upgrade();
    MTL_Debug::init();
    MTL_Debug::debug( 'core', 'plugins_loaded', 'Merkaz TextLab initialized.', array( 'version' => MTL_VERSION, 'is_admin' => is_admin(), 'doing_ajax' => wp_doing_ajax(), 'doing_cron' => wp_doing_cron() ) );
    MTL_Capabilities::maybe_upgrade();
    MTL_Membership::init();
    MTL_REST::init();
    MTL_Export::init();
    MTL_Shortcode::init();
    MTL_Import::init();

    if ( is_admin() ) { MTL_Admin::init(); }
} );
