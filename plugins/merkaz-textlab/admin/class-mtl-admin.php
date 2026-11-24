<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MTL_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'actions' ) );
        add_action( 'admin_post_mtl_debug_clear', array( __CLASS__, 'debug_clear' ) );
        add_action( 'admin_post_mtl_debug_download', array( __CLASS__, 'debug_download' ) );
    }

    public static function install_defaults() {
        $defaults = array(
            'mtl_workspace_page_url' => '',
            'mtl_openai_model' => 'gpt-5.6-terra',
            'mtl_openai_reasoning_effort' => 'medium',
            'mtl_openai_max_output_tokens' => 16000,
            'mtl_openai_import_chunk_chars' => 50000,
            'mtl_openai_baseline_context' => MTL_OpenAI::default_baseline_context(),
            'mtl_role_bundle_map' => array(),
            'mtl_debug_enabled' => false,
            'mtl_debug_retention_days' => 7,
        );
        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key, false ) ) { add_option( $key, $value, '', false ); }
        }
    }

    public static function menu() {
        add_menu_page( 'Merkaz TextLab', 'TextLab', 'manage_options', 'merkaz-textlab', array( __CLASS__, 'page' ), 'dashicons-welcome-write-blog', 58 );
        add_submenu_page( 'merkaz-textlab', 'TextLab Settings', 'Settings & Access', 'manage_options', 'merkaz-textlab', array( __CLASS__, 'page' ) );
        add_submenu_page( 'merkaz-textlab', 'Import TextLab Project', 'Import', 'manage_options', 'merkaz-textlab-import', array( __CLASS__, 'import_page' ) );
        add_submenu_page( 'merkaz-textlab', 'TextLab Debug Log', 'Debug Log', 'manage_options', 'merkaz-textlab-debug', array( __CLASS__, 'debug_page' ) );
    }

    public static function actions() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        if ( isset( $_POST['mtl_save_settings'] ) ) {
            check_admin_referer( 'mtl_settings' );
            update_option( 'mtl_workspace_page_url', esc_url_raw( wp_unslash( $_POST['workspace_page_url'] ?? '' ) ), false );
            update_option( 'mtl_openai_model', sanitize_text_field( wp_unslash( $_POST['openai_model'] ?? 'gpt-5.6-terra' ) ), false );
            $effort = sanitize_key( wp_unslash( $_POST['openai_reasoning_effort'] ?? 'medium' ) );
            if ( ! in_array( $effort, array( 'none','low','medium','high','xhigh','max' ), true ) ) { $effort = 'medium'; }
            update_option( 'mtl_openai_reasoning_effort', $effort, false );
            update_option( 'mtl_openai_max_output_tokens', max( 2000, min( 128000, absint( $_POST['openai_max_output_tokens'] ?? 16000 ) ) ), false );
            update_option( 'mtl_openai_import_chunk_chars', max( 12000, min( 120000, absint( $_POST['openai_import_chunk_chars'] ?? 50000 ) ) ), false );
            update_option( 'mtl_openai_baseline_context', sanitize_textarea_field( wp_unslash( $_POST['openai_baseline_context'] ?? MTL_OpenAI::default_baseline_context() ) ), false );
            if ( ! defined( 'MTL_DEBUG' ) ) { update_option( 'mtl_debug_enabled', ! empty( $_POST['debug_enabled'] ), false ); }
            update_option( 'mtl_debug_retention_days', max( 1, min( 30, absint( $_POST['debug_retention_days'] ?? 7 ) ) ), false );

            if ( ! defined( 'MTL_OPENAI_API_KEY' ) ) {
                if ( ! empty( $_POST['clear_openai_key'] ) ) {
                    delete_option( 'mtl_openai_api_key' );
                } else {
                    $new_key = trim( (string) wp_unslash( $_POST['openai_api_key'] ?? '' ) );
                    if ( $new_key ) { update_option( 'mtl_openai_api_key', sanitize_text_field( $new_key ), false ); }
                }
            }

            $mapping = array();
            $bundles = MTL_Capabilities::bundles();
            foreach ( wp_roles()->roles as $role_slug => $role_data ) {
                $bundle = sanitize_key( wp_unslash( $_POST['role_bundle'][ $role_slug ] ?? '' ) );
                if ( isset( $bundles[ $bundle ] ) ) { $mapping[ sanitize_key( $role_slug ) ] = $bundle; }
            }
            update_option( 'mtl_role_bundle_map', $mapping, false );
            add_settings_error( 'mtl', 'saved', 'TextLab settings saved.', 'updated' );
        }

        if ( isset( $_POST['mtl_test_openai'] ) ) {
            check_admin_referer( 'mtl_settings' );
            $result = MTL_OpenAI::test_connection();
            if ( is_wp_error( $result ) ) {
                add_settings_error( 'mtl', 'openai_test_failed', 'OpenAI connection failed: ' . $result->get_error_message(), 'error' );
            } else {
                add_settings_error( 'mtl', 'openai_test_ok', 'OpenAI API connection succeeded.', 'updated' );
            }
        }

        if ( isset( $_POST['mtl_add_entitlement'] ) ) {
            check_admin_referer( 'mtl_entitlement' );
            global $wpdb;
            $user = self::resolve_user( sanitize_text_field( wp_unslash( $_POST['user'] ?? '' ) ) );
            $cap = sanitize_text_field( wp_unslash( $_POST['capability'] ?? '' ) );
            $caps_to_grant = array();
            if ( 0 === strpos( $cap, 'bundle:' ) ) {
                $bundle = sanitize_key( substr( $cap, 7 ) );
                $bundles = MTL_Capabilities::bundles();
                if ( isset( $bundles[ $bundle ] ) ) { $caps_to_grant = $bundles[ $bundle ]; }
            } elseif ( in_array( sanitize_key( $cap ), MTL_Capabilities::all(), true ) ) {
                $caps_to_grant = array( sanitize_key( $cap ) );
            }
            if ( $user && $caps_to_grant ) {
                $expires = sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) );
                foreach ( array_values( array_unique( $caps_to_grant ) ) as $grant_cap ) {
                    $wpdb->insert( MTL_DB::table( 'entitlements' ), array(
                        'user_id' => $user->ID,
                        'capability' => $grant_cap,
                        'grant_source' => sanitize_text_field( wp_unslash( $_POST['grant_source'] ?? 'admin' ) ),
                        'granted_by' => get_current_user_id(),
                        'starts_at' => current_time( 'mysql' ),
                        'expires_at' => $expires ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $expires ) ) ) : null,
                        'notes' => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
                        'created_at' => current_time( 'mysql' ),
                    ) );
                }
                add_settings_error( 'mtl', 'entitlement', count( $caps_to_grant ) . ' entitlement(s) added.', 'updated' );
            } else {
                add_settings_error( 'mtl', 'entitlement_error', 'Could not find the user, bundle, or capability.', 'error' );
            }
        }

        if ( isset( $_GET['mtl_delete_entitlement'] ) ) {
            $id = absint( $_GET['mtl_delete_entitlement'] );
            check_admin_referer( 'mtl_delete_entitlement_' . $id );
            global $wpdb;
            $wpdb->delete( MTL_DB::table( 'entitlements' ), array( 'id' => $id ), array( '%d' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=merkaz-textlab' ) );
            exit;
        }
    }

    private static function resolve_user( $lookup ) {
        $lookup = trim( (string) $lookup );
        if ( ! $lookup ) { return false; }
        if ( ctype_digit( $lookup ) ) {
            $user = get_user_by( 'id', absint( $lookup ) );
            if ( $user ) { return $user; }
        }
        if ( is_email( $lookup ) ) {
            $user = get_user_by( 'email', $lookup );
            if ( $user ) { return $user; }
        }
        return get_user_by( 'login', $lookup );
    }

    private static function diagnostics() {
        return array(
            'OpenAI key' => defined( 'MTL_OPENAI_API_KEY' ) ? 'Configured in wp-config.php' : ( MTL_OpenAI::has_key() ? 'Configured in WordPress' : 'Not configured' ),
            'PDF engine' => MTL_PDF::status()['label'],
            'ZipArchive' => class_exists( 'ZipArchive' ) ? 'Available' : 'Missing (DOCX/XLSX features need it)',
            'DOMDocument' => class_exists( 'DOMDocument' ) ? 'Available' : 'Missing (DOCX/XLSX import needs it)',
            'mbstring' => function_exists( 'mb_strlen' ) ? 'Available' : 'Missing (Unicode RTF and some search helpers need it)',
            'BuddyPress' => function_exists( 'bp_core_new_nav_item' ) ? 'Detected' : 'Not detected',
            'Debug mode' => MTL_Debug::enabled() ? ( defined( 'MTL_DEBUG' ) ? 'ON (forced by MTL_DEBUG constant)' : 'ON' ) : ( defined( 'MTL_DEBUG' ) ? 'OFF (forced by MTL_DEBUG constant)' : 'OFF' ),
            'Debug retention' => absint( get_option( 'mtl_debug_retention_days', 7 ) ) . ' day(s)',
        );
    }

    public static function page() {
        global $wpdb;
        settings_errors( 'mtl' );
        $entitlements = $wpdb->get_results( 'SELECT e.*,u.user_email,u.display_name FROM ' . MTL_DB::table( 'entitlements' ) . ' e LEFT JOIN ' . $wpdb->users . ' u ON u.ID=e.user_id ORDER BY e.id DESC LIMIT 100', ARRAY_A );
        $role_mapping = (array) get_option( 'mtl_role_bundle_map', array() );
        $bundles = MTL_Capabilities::bundles();
        $key_from_constant = defined( 'MTL_OPENAI_API_KEY' ) && MTL_OPENAI_API_KEY;
        ?>
        <div class="wrap">
            <h1>Merkaz TextLab</h1>
            <p>Use <code>[merkaz_textlab]</code> on a WordPress/Divi page. TextLab keeps source text, user-authored work, permissions, imports, and publication features separate.</p>

            <form method="post">
                <?php wp_nonce_field( 'mtl_settings' ); ?>
                <h2>Workspace / BuddyPress</h2>
                <table class="form-table"><tr><th><label for="workspace_page_url">Workspace page URL</label></th><td><input class="regular-text" type="url" id="workspace_page_url" name="workspace_page_url" value="<?php echo esc_attr( get_option( 'mtl_workspace_page_url', '' ) ); ?>"><p class="description">If set and BuddyPress is active, TextLab can add a “Text Projects” navigation item pointing here.</p></td></tr></table>

                <h2>Debug / troubleshooting</h2>
                <p>Debug mode adds verbose operational logging for TextLab REST requests, Sefaria, OpenAI, imports, exports, PDF generation, and fatal PHP errors. Secrets and large user-authored content fields are redacted before storage.</p>
                <table class="form-table">
                    <tr><th>Debug mode</th><td>
                        <?php if ( defined( 'MTL_DEBUG' ) ) : ?>
                            <strong><?php echo MTL_DEBUG ? 'Enabled' : 'Disabled'; ?> by <code>MTL_DEBUG</code> in wp-config.php.</strong>
                            <p class="description">The wp-config.php constant overrides the WordPress setting. Remove the constant to control debug mode here.</p>
                        <?php else : ?>
                            <label><input type="checkbox" name="debug_enabled" value="1" <?php checked( get_option( 'mtl_debug_enabled', false ) ); ?>> Enable verbose TextLab debug logging</label>
                            <p class="description">Leave this off during normal operation. Turn it on while reproducing a problem, then download the support report and turn it off again.</p>
                        <?php endif; ?>
                    </td></tr>
                    <tr><th>Log retention</th><td><input type="number" min="1" max="30" name="debug_retention_days" value="<?php echo esc_attr( get_option( 'mtl_debug_retention_days', 7 ) ); ?>"> days <p class="description">The log is also capped at 10,000 entries.</p></td></tr>
                </table>

                <h2>AI-assisted import</h2>
                <p>The AI importer is optional and preview-first. It is instructed to organize messy material without silently rewriting, correcting, or filling gaps in user-authored translations, notes, or outline titles.</p>
                <table class="form-table">
                    <tr><th>OpenAI API key</th><td>
                        <?php if ( $key_from_constant ) : ?>
                            <strong>Configured by <code>MTL_OPENAI_API_KEY</code> in wp-config.php.</strong><p class="description">This takes precedence over any database-stored key and is recommended for production.</p>
                        <?php else : ?>
                            <input type="password" autocomplete="new-password" class="regular-text" name="openai_api_key" placeholder="<?php echo MTL_OpenAI::has_key() ? esc_attr( 'Key already saved; leave blank to keep it' ) : esc_attr( 'sk-…' ); ?>">
                            <?php if ( MTL_OpenAI::has_key() ) : ?><label><input type="checkbox" name="clear_openai_key" value="1"> Clear saved key</label><?php endif; ?>
                            <p class="description">The key stays server-side and is never sent to the TextLab browser application. For production, prefer <code>define('MTL_OPENAI_API_KEY', '...');</code> in <code>wp-config.php</code>.</p>
                        <?php endif; ?>
                    </td></tr>
                    <tr><th><label for="openai_model">Model</label></th><td><input class="regular-text" id="openai_model" name="openai_model" value="<?php echo esc_attr( get_option( 'mtl_openai_model', 'gpt-5.6-terra' ) ); ?>"><p class="description">Used only for AI-assisted imports. Keep this configurable so you can change models without updating the plugin.</p></td></tr>
                    <tr><th>Reasoning effort</th><td><select name="openai_reasoning_effort"><?php $current = get_option( 'mtl_openai_reasoning_effort', 'medium' ); foreach ( array( 'none','low','medium','high','xhigh','max' ) as $value ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $value ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Maximum output tokens</th><td><input type="number" min="2000" max="128000" name="openai_max_output_tokens" value="<?php echo esc_attr( get_option( 'mtl_openai_max_output_tokens', 16000 ) ); ?>"></td></tr>
                    <tr><th>Import chunk size</th><td><input type="number" min="12000" max="120000" step="1000" name="openai_import_chunk_chars" value="<?php echo esc_attr( get_option( 'mtl_openai_import_chunk_chars', 50000 ) ); ?>"> characters<p class="description">Large documents are broken into ordered chunks before AI structuring.</p></td></tr>
                    <tr><th><label for="openai_baseline_context">Baseline import context</label></th><td><textarea class="large-text code" rows="10" id="openai_baseline_context" name="openai_baseline_context"><?php echo esc_textarea( get_option( 'mtl_openai_baseline_context', MTL_OpenAI::default_baseline_context() ) ); ?></textarea><p class="description">Persistent instructions about your data model, terminology, and provenance. Each import also has an optional file-specific context field.</p></td></tr>
                </table>

                <h2>Role → feature bundle mapping</h2>
                <p>This is a vendor-neutral bridge for membership/paywall plugins that assign WordPress roles. Durable one-off or legacy access can still be granted below.</p>
                <table class="widefat striped" style="max-width:800px"><thead><tr><th>WordPress role</th><th>TextLab bundle</th></tr></thead><tbody>
                <?php foreach ( wp_roles()->roles as $slug => $data ) : ?>
                    <tr><td><?php echo esc_html( translate_user_role( $data['name'] ) ); ?> <code><?php echo esc_html( $slug ); ?></code></td><td><select name="role_bundle[<?php echo esc_attr( $slug ); ?>]"><option value="">No extra bundle</option><?php foreach ( $bundles as $bundle => $caps ) : ?><option value="<?php echo esc_attr( $bundle ); ?>" <?php selected( $role_mapping[ $slug ] ?? '', $bundle ); ?>><?php echo esc_html( ucfirst( $bundle ) ); ?> (<?php echo esc_html( count( $caps ) ); ?> capabilities)</option><?php endforeach; ?></select></td></tr>
                <?php endforeach; ?>
                </tbody></table>

                <p><button class="button button-primary" name="mtl_save_settings" value="1">Save settings</button> <button class="button" name="mtl_test_openai" value="1" <?php disabled( ! MTL_OpenAI::has_key() ); ?>>Test OpenAI connection</button></p>
            </form>

            <h2>System diagnostics</h2>
            <table class="widefat striped" style="max-width:800px"><tbody><?php foreach ( self::diagnostics() as $label => $value ) : ?><tr><th style="width:220px"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr><?php endforeach; ?></tbody></table>

            <h2>Grant durable access</h2>
            <form method="post">
                <?php wp_nonce_field( 'mtl_entitlement' ); ?>
                <table class="form-table">
                    <tr><th>User</th><td><input name="user" required placeholder="email, username, or user ID" class="regular-text"></td></tr>
                    <tr><th>Capability / bundle</th><td><select name="capability"><optgroup label="Bundles"><option value="bundle:basic">Basic bundle</option><option value="bundle:advanced">Advanced bundle</option><option value="bundle:manuscript">Manuscript/editorial bundle</option></optgroup><optgroup label="Individual capabilities"><?php foreach ( MTL_Capabilities::all() as $cap ) : ?><option value="<?php echo esc_attr( $cap ); ?>"><?php echo esc_html( $cap ); ?></option><?php endforeach; ?></optgroup></select></td></tr>
                    <tr><th>Grant source</th><td><input name="grant_source" value="admin" placeholder="legacy_subscription, collaborator, admin"></td></tr>
                    <tr><th>Expires</th><td><input type="datetime-local" name="expires_at"><p class="description">Leave blank for permanent/legacy access.</p></td></tr>
                    <tr><th>Notes</th><td><textarea name="notes" rows="2" class="large-text"></textarea></td></tr>
                </table>
                <p><button class="button button-primary" name="mtl_add_entitlement" value="1">Grant entitlement</button></p>
            </form>

            <h2>Recent entitlements</h2>
            <table class="widefat striped"><thead><tr><th>User</th><th>Capability</th><th>Source</th><th>Expires</th><th>Notes</th><th></th></tr></thead><tbody>
                <?php foreach ( $entitlements as $entitlement ) : ?>
                    <tr><td><?php echo esc_html( $entitlement['display_name'] . ' <' . $entitlement['user_email'] . '>' ); ?></td><td><code><?php echo esc_html( $entitlement['capability'] ); ?></code></td><td><?php echo esc_html( $entitlement['grant_source'] ); ?></td><td><?php echo esc_html( $entitlement['expires_at'] ?: 'Permanent' ); ?></td><td><?php echo esc_html( $entitlement['notes'] ); ?></td><td><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=merkaz-textlab&mtl_delete_entitlement=' . $entitlement['id'] ), 'mtl_delete_entitlement_' . $entitlement['id'] ) ); ?>">Remove</a></td></tr>
                <?php endforeach; ?>
                <?php if ( ! $entitlements ) : ?><tr><td colspan="6">No durable entitlements yet.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
        <?php
    }

    public static function debug_clear() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Insufficient permission.', 403 ); }
        check_admin_referer( 'mtl_debug_clear' );
        MTL_Debug::clear();
        wp_safe_redirect( admin_url( 'admin.php?page=merkaz-textlab-debug&cleared=1' ) );
        exit;
    }

    public static function debug_download() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Insufficient permission.', 403 ); }
        check_admin_referer( 'mtl_debug_download' );
        $rows = MTL_Debug::recent( 2000 );
        foreach ( $rows as &$row ) {
            $row['context'] = json_decode( $row['context_json'] ?: '{}', true );
            unset( $row['context_json'] );
        }
        unset( $row );
        $report = array(
            'environment' => MTL_Debug::environment_snapshot(),
            'settings' => array(
                'workspace_page_host' => wp_parse_url( (string) get_option( 'mtl_workspace_page_url', '' ), PHP_URL_HOST ),
                'role_bundle_map' => (array) get_option( 'mtl_role_bundle_map', array() ),
                'debug_retention_days' => absint( get_option( 'mtl_debug_retention_days', 7 ) ),
            ),
            'logs' => $rows,
            'privacy_note' => 'API keys, auth values, nonces, and large manuscript/import content fields are redacted by TextLab before logging. Review this report before sharing it outside your team.',
        );
        $filename = 'merkaz-textlab-support-' . gmdate( 'Ymd-His' ) . '.json';
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }

    public static function debug_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $level = sanitize_key( wp_unslash( $_GET['level'] ?? '' ) );
        $component = sanitize_key( wp_unslash( $_GET['component'] ?? '' ) );
        $rows = MTL_Debug::recent( 400, $level, $component );
        $components = array_values( array_unique( array_filter( wp_list_pluck( MTL_Debug::recent( 1000 ), 'component' ) ) ) );
        sort( $components );
        ?>
        <div class="wrap">
            <h1>TextLab Debug Log</h1>
            <?php if ( ! empty( $_GET['cleared'] ) ) : ?><div class="notice notice-success"><p>Debug log cleared.</p></div><?php endif; ?>
            <p><strong>Mode:</strong> <?php echo MTL_Debug::enabled() ? '<span style="color:#008a20">ON</span>' : '<span style="color:#b32d2e">OFF</span>'; ?> · Request IDs let us follow one browser/API action across several components.</p>
            <p>The logger is intentionally verbose when enabled, but it does <strong>not</strong> persist API keys, authorization headers, nonces, passwords, or complete translation/manuscript/import prose. Those fields are redacted or replaced with length/hash metadata.</p>
            <form method="get" style="display:flex;gap:8px;align-items:end;margin:16px 0">
                <input type="hidden" name="page" value="merkaz-textlab-debug">
                <label>Level<br><select name="level"><option value="">All</option><?php foreach ( array( 'debug','info','warning','error','critical' ) as $v ) : ?><option value="<?php echo esc_attr( $v ); ?>" <?php selected( $level, $v ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select></label>
                <label>Component<br><select name="component"><option value="">All</option><?php foreach ( $components as $v ) : ?><option value="<?php echo esc_attr( $v ); ?>" <?php selected( $component, $v ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select></label>
                <button class="button">Filter</button>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=merkaz-textlab-debug' ) ); ?>">Reset</a>
            </form>
            <div style="display:flex;gap:8px;margin-bottom:16px">
                <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mtl_debug_download' ), 'mtl_debug_download' ) ); ?>">Download support report</a>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Clear all TextLab debug log entries?');"><input type="hidden" name="action" value="mtl_debug_clear"><?php wp_nonce_field( 'mtl_debug_clear' ); ?><button class="button">Clear log</button></form>
            </div>
            <table class="widefat striped"><thead><tr><th>UTC time</th><th>Level</th><th>Component / event</th><th>Request</th><th>Message / context</th></tr></thead><tbody>
            <?php foreach ( $rows as $row ) : $ctx = json_decode( $row['context_json'] ?: '{}', true ); ?>
                <tr>
                    <td style="white-space:nowrap"><?php echo esc_html( $row['created_at'] ); ?></td>
                    <td><code><?php echo esc_html( strtoupper( $row['level'] ) ); ?></code></td>
                    <td><strong><?php echo esc_html( $row['component'] ); ?></strong><br><code><?php echo esc_html( $row['event'] ); ?></code></td>
                    <td><code><?php echo esc_html( $row['request_id'] ); ?></code><?php if ( $row['user_id'] ) : ?><br>user #<?php echo absint( $row['user_id'] ); ?><?php endif; ?></td>
                    <td><?php echo esc_html( $row['message'] ); ?><?php if ( $ctx ) : ?><details><summary>Context</summary><pre style="white-space:pre-wrap;max-width:900px;max-height:360px;overflow:auto"><?php echo esc_html( wp_json_encode( $ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre></details><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( ! $rows ) : ?><tr><td colspan="5">No matching debug entries. <?php echo MTL_Debug::enabled() ? 'Reproduce the problem and refresh this page.' : 'Enable debug mode under Settings & Access first.'; ?></td></tr><?php endif; ?>
            </tbody></table>
        </div>
        <?php
    }

    public static function import_page() {
        global $wpdb;
        $error = isset( $_GET['mtl_error'] ) ? sanitize_text_field( wp_unslash( $_GET['mtl_error'] ) ) : '';
        if ( $error ) { echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>'; }
        if ( ! empty( $_GET['applied'] ) ) { echo '<div class="notice notice-success"><p>Import applied to project #' . absint( $_GET['project_id'] ?? 0 ) . '.</p></div>'; }
        $projects = $wpdb->get_results( 'SELECT id,title,owner_user_id FROM ' . MTL_DB::table( 'projects' ) . ' ORDER BY updated_at DESC LIMIT 250', ARRAY_A );
        $job = ! empty( $_GET['import_id'] ) ? MTL_Import::get_job( absint( $_GET['import_id'] ) ) : null;
        ?>
        <div class="wrap">
            <h1>Import into TextLab</h1>
            <p>Imports are always staged as a preview first. Nothing is committed to a project until you press <strong>Apply import</strong>.</p>

            <?php if ( $job ) : self::render_import_preview( $job ); endif; ?>

            <h2>New import</h2>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="mtl_import_preview">
                <?php wp_nonce_field( 'mtl_import_preview' ); ?>
                <table class="form-table">
                    <tr><th>File</th><td><input type="file" name="import_file" accept=".docx,.xlsx,.txt,.md,.json,.csv,.tsv" required><p class="description">Supported: DOCX, XLSX, TXT, Markdown, JSON, CSV, TSV. The known TN3101 side-by-side spreadsheet is imported deterministically without AI.</p></td></tr>
                    <tr><th>Destination</th><td><select name="project_id"><option value="0">Create a new project</option><?php foreach ( $projects as $project ) : ?><option value="<?php echo absint( $project['id'] ); ?>">#<?php echo absint( $project['id'] ); ?> — <?php echo esc_html( $project['title'] ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>New-project title override</th><td><input class="regular-text" name="project_title" placeholder="Optional"></td></tr>
                    <tr><th>AI-assisted structuring</th><td><label><input type="checkbox" name="use_ai" value="1" <?php disabled( ! MTL_OpenAI::has_key() ); ?>> Use OpenAI to infer hierarchy, reference ranges, and note types when deterministic parsing is insufficient</label><?php if ( ! MTL_OpenAI::has_key() ) : ?><p class="description">Configure an OpenAI API key under TextLab → Settings & Access to enable this.</p><?php endif; ?></td></tr>
                    <tr><th>Import-specific context</th><td><textarea class="large-text" rows="6" name="extra_context" placeholder="For example: This document mixes my comments, translations, and outlines out of order. Preserve wording; infer the intended order using biblical references and heading styles."></textarea><p class="description">This is appended to the persistent baseline context only for this import.</p></td></tr>
                </table>
                <p><button class="button button-primary">Build import preview</button></p>
            </form>
        </div>
        <?php
    }

    private static function render_import_preview( $job ) {
        $preview = (array) ( $job['preview'] ?? array() );
        $segments = (array) ( $preview['segments'] ?? array() );
        $annotations = (array) ( $preview['annotations'] ?? array() );
        $outline = (array) ( $preview['outline'] ?? array() );
        $warnings = (array) ( $preview['warnings'] ?? array() );
        $applied = 'applied' === ( $job['status'] ?? '' );
        ?>
        <div class="card" style="max-width:none">
            <h2>Preview: <?php echo esc_html( $job['source_name'] ); ?></h2>
            <p><strong>Mode:</strong> <?php echo $job['ai_used'] ? 'AI-assisted structured import' : 'Deterministic/manual import'; ?> · <strong>Project:</strong> <?php echo $job['project_id'] ? '#' . absint( $job['project_id'] ) : 'new project'; ?> · <strong>Status:</strong> <?php echo esc_html( $job['status'] ); ?></p>
            <p><strong><?php echo count( $segments ); ?></strong> text/translation segments · <strong><?php echo count( $annotations ); ?></strong> annotations · <strong><?php echo count( $outline ); ?></strong> outline nodes</p>
            <?php if ( $warnings ) : ?><div class="notice notice-warning inline"><p><strong>Review warnings</strong></p><ul style="list-style:disc;padding-left:20px"><?php foreach ( $warnings as $warning ) : ?><li><?php echo esc_html( $warning ); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

            <?php if ( $segments ) : ?><h3>Text / translation sample</h3><table class="widefat striped"><thead><tr><th>Ref</th><th>Hebrew segment</th><th>User translation</th></tr></thead><tbody><?php foreach ( array_slice( $segments, 0, 20 ) as $segment ) : ?><tr><td><?php echo esc_html( $segment['sefaria_ref'] ?? '' ); ?></td><td dir="rtl" style="font-size:17px"><?php echo esc_html( $segment['source_hebrew'] ?? '' ); ?></td><td><?php echo wp_kses_post( $segment['translation'] ?? '' ); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>

            <?php if ( $outline ) : ?><h3>Outline sample</h3><table class="widefat striped"><thead><tr><th>Level</th><th>Range</th><th>Title</th></tr></thead><tbody><?php foreach ( array_slice( $outline, 0, 30 ) as $node ) : ?><tr><td><?php echo absint( $node['level'] ?? 1 ); ?></td><td><?php echo esc_html( trim( ( $node['start_ref'] ?? '' ) . ( ! empty( $node['end_ref'] ) ? '–' . $node['end_ref'] : '' ) ) ); ?></td><td><?php echo esc_html( $node['title'] ?? '' ); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>

            <?php if ( $annotations ) : ?><h3>Annotation sample</h3><table class="widefat striped"><thead><tr><th>Type</th><th>Range</th><th>Title</th><th>Body</th></tr></thead><tbody><?php foreach ( array_slice( $annotations, 0, 20 ) as $annotation ) : ?><tr><td><?php echo esc_html( $annotation['annotation_type'] ?? '' ); ?></td><td><?php echo esc_html( $annotation['start_ref'] ?? $annotation['segment_ref'] ?? '' ); ?></td><td><?php echo esc_html( $annotation['title'] ?? '' ); ?></td><td><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $annotation['body'] ?? '' ), 35 ) ); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>

            <?php if ( ! $applied ) : ?><div style="display:flex;gap:8px;margin-top:16px">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="mtl_import_apply"><input type="hidden" name="import_id" value="<?php echo absint( $job['id'] ); ?>"><?php wp_nonce_field( 'mtl_import_apply_' . $job['id'] ); ?><button class="button button-primary">Apply import</button></form>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="mtl_import_discard"><input type="hidden" name="import_id" value="<?php echo absint( $job['id'] ); ?>"><?php wp_nonce_field( 'mtl_import_discard_' . $job['id'] ); ?><button class="button">Discard preview</button></form>
            </div><?php endif; ?>
        </div>
        <?php
    }
}
