<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MTL_Capabilities {
    public static function basic() {
        return array(
            'mtl_use_workbench',
            'mtl_create_projects',
            'mtl_outline',
            'mtl_annotate',
            'mtl_export_homework',
        );
    }

    public static function advanced() {
        return array(
            'mtl_translate',
            'mtl_sources',
            'mtl_advanced_annotations',
            'mtl_manuscript_read',
            'mtl_manuscript_edit',
            'mtl_publish',
            'mtl_advanced_export',
            'mtl_collaborate',
            'mtl_import_data',
            'mtl_manage_projects',
        );
    }

    public static function bundles() {
        return array(
            'basic' => self::basic(),
            'advanced' => array_merge( self::basic(), array( 'mtl_translate','mtl_sources','mtl_advanced_annotations','mtl_advanced_export','mtl_collaborate','mtl_import_data' ) ),
            'manuscript' => self::all(),
        );
    }

    public static function all() { return array_values( array_unique( array_merge( self::basic(), self::advanced() ) ) ); }

    public static function maybe_upgrade() {
        if ( get_option( 'mtl_cap_version' ) !== MTL_VERSION ) { self::activate(); }
    }

    public static function activate() {
        foreach ( array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' ) as $role_name ) {
            $role = get_role( $role_name );
            if ( ! $role ) { continue; }
            foreach ( self::basic() as $cap ) { $role->add_cap( $cap ); }
        }
        $admin = get_role( 'administrator' );
        if ( $admin ) { foreach ( self::advanced() as $cap ) { $admin->add_cap( $cap ); } }
        update_option( 'mtl_cap_version', MTL_VERSION );
    }

    public static function user_can( $capability, $user_id = 0 ) {
        global $wpdb;
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if ( ! $user_id ) { return false; }
        if ( user_can( $user_id, $capability ) ) { return true; }

        $external = MTL_Membership::user_capabilities( $user_id );
        if ( in_array( $capability, $external, true ) ) { return true; }

        $now = current_time( 'mysql' );
        $sql = 'SELECT COUNT(*) FROM ' . MTL_DB::table( 'entitlements' ) .
            ' WHERE user_id = %d AND capability = %s' .
            ' AND (starts_at IS NULL OR starts_at <= %s)' .
            ' AND (expires_at IS NULL OR expires_at >= %s)';
        return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $user_id, $capability, $now, $now ) );
    }

    public static function feature_map( $user_id = 0 ) {
        $out = array();
        foreach ( self::all() as $cap ) { $out[ str_replace( 'mtl_', '', $cap ) ] = self::user_can( $cap, $user_id ); }
        return $out;
    }
}
