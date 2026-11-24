<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Verbose, privacy-conscious diagnostics for Merkaz TextLab.
 *
 * Debug mode is intentionally opt-in. It records operational metadata and
 * redacts secrets and large/user-authored content fields before persistence.
 */
class MTL_Debug {
    private static $request_id = '';
    private static $rest_started = array();
    private static $fatal_registered = false;

    public static function init() {
        if ( ! self::$request_id ) {
            self::$request_id = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 16 );
        }
        add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_start' ), 9, 3 );
        add_filter( 'rest_post_dispatch', array( __CLASS__, 'rest_finish' ), 10, 3 );
        if ( ! self::$fatal_registered ) {
            self::$fatal_registered = true;
            add_action( 'shutdown', array( __CLASS__, 'capture_fatal' ), PHP_INT_MAX );
        }
    }

    public static function enabled() {
        if ( defined( 'MTL_DEBUG' ) ) { return (bool) MTL_DEBUG; }
        return (bool) get_option( 'mtl_debug_enabled', false );
    }

    public static function request_id() {
        if ( ! self::$request_id ) { self::$request_id = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 16 ); }
        return self::$request_id;
    }

    public static function start_timer() { return microtime( true ); }
    public static function duration_ms( $started ) { return max( 0, round( ( microtime( true ) - (float) $started ) * 1000, 1 ) ); }

    public static function log( $level, $component, $event, $message, $context = array() ) {
        if ( ! self::enabled() ) { return; }
        global $wpdb;

        $level = sanitize_key( $level ?: 'debug' );
        if ( ! in_array( $level, array( 'debug','info','warning','error','critical' ), true ) ) { $level = 'debug'; }
        $component = substr( sanitize_key( $component ?: 'core' ), 0, 80 );
        $event = substr( sanitize_key( $event ?: 'event' ), 0, 120 );
        $message = self::truncate_string( wp_strip_all_tags( (string) $message ), 4000 );
        $safe = self::redact( $context );
        $json = wp_json_encode( $safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $json ) { $json = '{"debug_context":"could_not_encode"}'; }
        if ( strlen( $json ) > 64000 ) { $json = substr( $json, 0, 64000 ) . '...[context truncated]'; }

        $table = MTL_DB::table( 'debug_logs' );
        $ok = $wpdb->insert( $table, array(
            'request_id' => self::request_id(),
            'user_id' => get_current_user_id() ?: null,
            'level' => $level,
            'component' => $component,
            'event' => $event,
            'message' => $message,
            'context_json' => $json,
            'created_at' => current_time( 'mysql', true ),
        ) );

        if ( false === $ok ) {
            error_log( '[Merkaz TextLab debug fallback] ' . strtoupper( $level ) . ' ' . $component . '/' . $event . ': ' . $message );
        }

        // Keep verbose diagnostics bounded without adding work to every request.
        if ( 1 === mt_rand( 1, 40 ) ) { self::prune(); }
    }

    public static function debug( $component, $event, $message, $context = array() ) { self::log( 'debug', $component, $event, $message, $context ); }
    public static function info( $component, $event, $message, $context = array() ) { self::log( 'info', $component, $event, $message, $context ); }
    public static function warning( $component, $event, $message, $context = array() ) { self::log( 'warning', $component, $event, $message, $context ); }
    public static function error( $component, $event, $message, $context = array() ) { self::log( 'error', $component, $event, $message, $context ); }

    public static function wp_error( $component, $event, $error, $context = array() ) {
        if ( ! is_wp_error( $error ) ) { return; }
        $context['wp_error'] = array(
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'data' => $error->get_error_data(),
        );
        self::error( $component, $event, $error->get_error_message(), $context );
    }

    public static function rest_start( $result, $server, $request ) {
        if ( ! self::enabled() || 0 !== strpos( $request->get_route(), '/' . MTL_REST::NS . '/' ) ) { return $result; }
        $key = spl_object_hash( $request );
        self::$rest_started[ $key ] = microtime( true );
        $body = $request->get_body();
        self::debug( 'rest', 'request_start', $request->get_method() . ' ' . $request->get_route(), array(
            'method' => $request->get_method(),
            'route' => $request->get_route(),
            'query_keys' => array_keys( (array) $request->get_query_params() ),
            'body_bytes' => strlen( (string) $body ),
            'content_type' => $request->get_header( 'content-type' ),
            'remote_ip_hash' => ! empty( $_SERVER['REMOTE_ADDR'] ) ? hash( 'sha256', (string) $_SERVER['REMOTE_ADDR'] ) : '',
        ) );
        return $result;
    }

    public static function rest_finish( $response, $server, $request ) {
        if ( ! self::enabled() || 0 !== strpos( $request->get_route(), '/' . MTL_REST::NS . '/' ) ) { return $response; }
        $key = spl_object_hash( $request );
        $started = self::$rest_started[ $key ] ?? microtime( true );
        unset( self::$rest_started[ $key ] );
        if ( is_wp_error( $response ) ) {
            $error_data = $response->get_error_data();
            $status = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 500;
        } else {
            $status = is_object( $response ) && method_exists( $response, 'get_status' ) ? $response->get_status() : 200;
            if ( is_object( $response ) && method_exists( $response, 'header' ) ) { $response->header( 'X-MTL-Request-ID', self::request_id() ); }
        }
        $context = array(
            'method' => $request->get_method(),
            'route' => $request->get_route(),
            'status' => $status,
            'duration_ms' => self::duration_ms( $started ),
        );
        if ( is_wp_error( $response ) ) {
            $context['error_code'] = $response->get_error_code();
            $context['error_message'] = $response->get_error_message();
        }
        self::log( $status >= 500 ? 'error' : ( $status >= 400 ? 'warning' : 'info' ), 'rest', 'request_finish', $request->get_method() . ' ' . $request->get_route() . ' -> ' . $status, $context );
        return $response;
    }

    public static function capture_fatal() {
        if ( ! self::enabled() ) { return; }
        $error = error_get_last();
        if ( ! $error || ! in_array( (int) $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) { return; }
        self::log( 'critical', 'php', 'fatal_error', 'PHP fatal error during TextLab-enabled request.', array(
            'type' => (int) $error['type'],
            'message' => (string) $error['message'],
            'file' => self::relative_path( (string) $error['file'] ),
            'line' => (int) $error['line'],
            'memory_usage' => memory_get_usage( true ),
            'memory_peak' => memory_get_peak_usage( true ),
        ) );
    }

    public static function prune() {
        if ( ! self::enabled() ) { return; }
        global $wpdb;
        $days = max( 1, min( 30, absint( get_option( 'mtl_debug_retention_days', 7 ) ) ) );
        $table = MTL_DB::table( 'debug_logs' );
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", $days ) );
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        $max = 10000;
        if ( $count > $max ) {
            $remove = $count - $max;
            $wpdb->query( $wpdb->prepare( "DELETE FROM $table ORDER BY id ASC LIMIT %d", $remove ) );
        }
    }

    public static function clear() {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . MTL_DB::table( 'debug_logs' ) );
    }

    public static function recent( $limit = 300, $level = '', $component = '' ) {
        global $wpdb;
        $limit = max( 1, min( 2000, absint( $limit ) ) );
        $table = MTL_DB::table( 'debug_logs' );
        $where = array( '1=1' );
        $args = array();
        if ( $level ) { $where[] = 'level=%s'; $args[] = sanitize_key( $level ); }
        if ( $component ) { $where[] = 'component=%s'; $args[] = sanitize_key( $component ); }
        $sql = "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT ' . $limit;
        if ( $args ) { $sql = $wpdb->prepare( $sql, $args ); }
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function environment_snapshot() {
        global $wp_version, $wpdb;
        $theme = wp_get_theme();
        return array(
            'generated_utc' => gmdate( 'c' ),
            'plugin_version' => defined( 'MTL_VERSION' ) ? MTL_VERSION : '',
            'debug_enabled' => self::enabled(),
            'debug_forced_by_constant' => defined( 'MTL_DEBUG' ),
            'wordpress_version' => $wp_version,
            'php_version' => PHP_VERSION,
            'mysql_version' => method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : '',
            'site_url_host' => wp_parse_url( site_url(), PHP_URL_HOST ),
            'home_url_host' => wp_parse_url( home_url(), PHP_URL_HOST ),
            'theme' => array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ) ),
            'multisite' => is_multisite(),
            'memory_limit' => ini_get( 'memory_limit' ),
            'wp_debug' => defined( 'WP_DEBUG' ) ? (bool) WP_DEBUG : false,
            'wp_debug_log' => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false,
            'extensions' => array(
                'ziparchive' => class_exists( 'ZipArchive' ),
                'domdocument' => class_exists( 'DOMDocument' ),
                'mbstring' => function_exists( 'mb_strlen' ),
                'curl' => function_exists( 'curl_version' ),
            ),
            'pdf' => class_exists( 'MTL_PDF' ) ? MTL_PDF::status() : array(),
            'buddypress' => function_exists( 'bp_core_new_nav_item' ),
            'openai' => array(
                'key_configured' => class_exists( 'MTL_OpenAI' ) ? MTL_OpenAI::has_key() : false,
                'key_source' => defined( 'MTL_OPENAI_API_KEY' ) ? 'constant' : ( get_option( 'mtl_openai_api_key', '' ) ? 'wordpress_option' : 'none' ),
                'model' => get_option( 'mtl_openai_model', '' ),
                'reasoning_effort' => get_option( 'mtl_openai_reasoning_effort', '' ),
                'max_output_tokens' => get_option( 'mtl_openai_max_output_tokens', '' ),
                'import_chunk_chars' => get_option( 'mtl_openai_import_chunk_chars', '' ),
            ),
        );
    }

    private static function redact( $value, $key = '' ) {
        if ( is_wp_error( $value ) ) {
            return array( 'wp_error' => array( 'code' => $value->get_error_code(), 'message' => $value->get_error_message(), 'data' => self::redact( $value->get_error_data(), 'error_data' ) ) );
        }
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $k => $v ) { $out[ $k ] = self::redact( $v, (string) $k ); }
            return $out;
        }
        if ( is_object( $value ) ) { return self::redact( get_object_vars( $value ), $key ); }

        $sensitive = '/(^|_)(api_?key|authorization|password|passwd|secret|client_?secret|access_?token|refresh_?token|cookie|nonce)($|_)/i';
        if ( $key && preg_match( $sensitive, $key ) ) { return '[REDACTED]'; }

        // Never persist full manuscript/import prose in a troubleshooting log.
        $content_keys = array( 'source_text','source_hebrew','translation','body','snippet','content','prompt','baseline_context','extra_context','preview_json','snapshot_json','html','xml' );
        if ( in_array( strtolower( $key ), $content_keys, true ) && is_scalar( $value ) ) {
            $text = (string) $value;
            return array( 'redacted_content' => true, 'chars' => self::strlen( $text ), 'bytes' => strlen( $text ), 'sha256' => hash( 'sha256', $text ) );
        }

        if ( is_string( $value ) ) { return self::truncate_string( $value, 2000 ); }
        if ( is_bool( $value ) || is_null( $value ) || is_numeric( $value ) ) { return $value; }
        return self::truncate_string( print_r( $value, true ), 2000 );
    }

    private static function truncate_string( $text, $limit ) {
        $text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $text );
        if ( self::strlen( $text ) <= $limit ) { return $text; }
        $head = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit );
        return $head . '...[truncated; total chars=' . self::strlen( $text ) . ']';
    }

    private static function strlen( $text ) { return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text ); }
    private static function relative_path( $path ) {
        $path = wp_normalize_path( $path );
        $root = wp_normalize_path( ABSPATH );
        return 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : basename( $path );
    }
}
