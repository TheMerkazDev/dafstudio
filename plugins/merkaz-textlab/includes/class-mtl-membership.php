<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Vendor-neutral entitlement bridge.
 * Membership/paywall plugins may either assign WordPress roles that are mapped
 * to a TextLab bundle in settings, or hook the filters below.
 */
class MTL_Membership {
    public static function init() {}

    public static function user_capabilities( $user_id ) {
        $caps = array();
        $user = get_userdata( $user_id );
        $mapping = get_option( 'mtl_role_bundle_map', array() );
        if ( $user && is_array( $mapping ) ) {
            foreach ( (array) $user->roles as $role ) {
                $bundle = isset( $mapping[ $role ] ) ? sanitize_key( $mapping[ $role ] ) : '';
                $bundles = MTL_Capabilities::bundles();
                if ( $bundle && isset( $bundles[ $bundle ] ) ) { $caps = array_merge( $caps, $bundles[ $bundle ] ); }
            }
        }

        /**
         * Filter external capabilities from a membership/paywall adapter.
         * Return TextLab capability strings such as mtl_manuscript_read.
         */
        $caps = apply_filters( 'mtl_external_user_capabilities', $caps, (int) $user_id );
        return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $caps ) ) ) );
    }
}
