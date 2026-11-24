<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MTL_Sefaria {
    const BASE = 'https://www.sefaria.org';

    public static function get( $path, $query = array(), $ttl = 21600 ) {
        $url = self::BASE . $path;
        if ( $query ) { $url = add_query_arg( $query, $url ); }
        return self::fetch_url( $url, $ttl );
    }

    private static function fetch_url( $url, $ttl = 21600 ) {
        $key = 'mtl_sef_' . md5( $url );
        $cached = get_transient( $key );
        if ( false !== $cached ) { MTL_Debug::debug( 'sefaria', 'cache_hit', 'Sefaria response served from transient cache.', array( 'url' => $url, 'cache_key' => $key ) ); return $cached; }

        $started = MTL_Debug::start_timer();
        MTL_Debug::debug( 'sefaria', 'request_start', 'Requesting Sefaria API.', array( 'url' => $url, 'ttl' => $ttl ) );

        $response = wp_remote_get( $url, array(
            'timeout' => 18,
            'redirection' => 3,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'Merkaz-TextLab/' . MTL_VERSION . '; ' . home_url( '/' ),
            ),
        ) );

        if ( is_wp_error( $response ) ) { MTL_Debug::wp_error( 'sefaria', 'transport_error', $response, array( 'url' => $url, 'duration_ms' => MTL_Debug::duration_ms( $started ) ) ); return $response; }
        $status = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $status < 200 || $status >= 300 || null === $data ) {
            MTL_Debug::error( 'sefaria', 'http_error', 'Sefaria request failed.', array( 'url' => $url, 'remote_status' => $status, 'body_bytes' => strlen( $body ), 'json_decoded' => null !== $data, 'duration_ms' => MTL_Debug::duration_ms( $started ) ) );
            return new WP_Error( 'mtl_sefaria_error', 'Sefaria request failed.', array( 'status' => 502, 'remote_status' => $status ) );
        }

        MTL_Debug::info( 'sefaria', 'request_success', 'Sefaria request completed.', array( 'url' => $url, 'remote_status' => $status, 'body_bytes' => strlen( $body ), 'duration_ms' => MTL_Debug::duration_ms( $started ) ) );

        set_transient( $key, $data, $ttl );
        return $data;
    }

    public static function text( $ref, $version = array() ) {
        $url = self::BASE . '/api/v3/texts/' . rawurlencode( $ref ) . '?return_format=text_only';
        foreach ( (array) $version as $v ) {
            $url .= '&version=' . rawurlencode( $v );
        }
        return self::fetch_url( $url, 6 * HOUR_IN_SECONDS );
    }

    public static function ref_info( $ref ) {
        return self::get( '/api/ref/' . rawurlencode( $ref ), array(), DAY_IN_SECONDS );
    }

    public static function versions( $index ) {
        return self::get( '/api/texts/versions/' . rawurlencode( $index ), array(), DAY_IN_SECONDS );
    }

    public static function related( $ref ) {
        return self::get( '/api/related/' . rawurlencode( $ref ), array(), HOUR_IN_SECONDS );
    }

    public static function links( $ref, $categories = '' ) {
        $query = array( 'with_text' => 1 );
        if ( $categories ) { $query['categories'] = $categories; }
        return self::get( '/api/links/' . rawurlencode( $ref ), $query, HOUR_IN_SECONDS );
    }
}
