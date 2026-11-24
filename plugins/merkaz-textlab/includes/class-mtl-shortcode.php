<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MTL_Shortcode {
    public static function init() {
        add_shortcode( 'merkaz_textlab', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
        add_action( 'bp_setup_nav', array( __CLASS__, 'buddy_nav' ), 100 );
    }

    public static function register_assets() {
        wp_register_style( 'merkaz-textlab', MTL_URL . 'assets/styles.css', array(), MTL_VERSION );
        wp_register_script( 'merkaz-textlab', MTL_URL . 'assets/app.js', array(), MTL_VERSION, true );
    }

    public static function render( $atts = array() ) {
        if ( ! is_user_logged_in() ) {
            return '<div class="mtl-login-required">Please log in to use TextLab.</div>';
        }
        if ( ! MTL_Capabilities::user_can( 'mtl_use_workbench' ) ) {
            return '<div class="mtl-login-required">Your account does not currently include TextLab access.</div>';
        }
        $atts = shortcode_atts( array( 'project' => '' ), $atts, 'merkaz_textlab' );
        wp_enqueue_style( 'merkaz-textlab' );
        wp_enqueue_script( 'merkaz-textlab' );
        wp_localize_script( 'merkaz-textlab', 'MTL_CONFIG', array(
            'restUrl' => esc_url_raw( rest_url( MTL_REST::NS . '/' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'exportNonce' => wp_create_nonce( 'mtl_export' ),
            'adminPostUrl' => admin_url( 'admin-post.php' ),
            'initialProject' => absint( $atts['project'] ),
            'sefariaBase' => 'https://www.sefaria.org/',
            'debug' => MTL_Debug::enabled(),
            'debugRequestId' => MTL_Debug::request_id(),
        ) );
        ob_start();
        ?>
        <div id="mtl-app" class="mtl-app">
            <div class="mtl-loading">Loading TextLab…</div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function buddy_nav() {
        if ( ! function_exists( 'bp_core_new_nav_item' ) ) { return; }
        $url = trim( (string) get_option( 'mtl_workspace_page_url', '' ) );
        if ( ! $url ) { return; }
        bp_core_new_nav_item( array(
            'name' => 'Text Projects',
            'slug' => 'text-projects',
            'position' => 80,
            'screen_function' => function() use ( $url ) { wp_safe_redirect( $url ); exit; },
            'default_subnav_slug' => 'text-projects',
        ) );
    }
}
