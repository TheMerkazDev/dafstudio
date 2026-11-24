<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MTL_DB {
    public static function table( $name ) {
        global $wpdb;
        return $wpdb->prefix . 'mtl_' . $name;
    }

    public static function maybe_upgrade() {
        if ( get_option( 'mtl_db_version' ) !== MTL_VERSION ) { self::activate(); }
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $projects = self::table( 'projects' );
        $members = self::table( 'project_members' );
        $segments = self::table( 'segments' );
        $annotations = self::table( 'annotations' );
        $outline = self::table( 'outline_nodes' );
        $sources = self::table( 'sources' );
        $annotation_sources = self::table( 'annotation_sources' );
        $entitlements = self::table( 'entitlements' );
        $profiles = self::table( 'export_profiles' );
        $revisions = self::table( 'revisions' );
        $imports = self::table( 'imports' );
        $debug_logs = self::table( 'debug_logs' );

        $sql = array();
        $sql[] = "CREATE TABLE $projects (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            owner_user_id bigint(20) unsigned NOT NULL,
            title varchar(255) NOT NULL,
            description text NULL,
            project_type varchar(40) NOT NULL DEFAULT 'user',
            visibility varchar(30) NOT NULL DEFAULT 'private',
            buddy_group_id bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY owner_user_id (owner_user_id),
            KEY visibility (visibility),
            KEY buddy_group_id (buddy_group_id)
        ) $charset;";

        $sql[] = "CREATE TABLE $members (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            role varchar(30) NOT NULL DEFAULT 'viewer',
            invited_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY project_user (project_id,user_id),
            KEY user_id (user_id)
        ) $charset;";

        // A Sefaria Ref is verse-level, while segment_key may represent a user-defined clause.
        $sql[] = "CREATE TABLE $segments (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL,
            sefaria_ref varchar(255) NOT NULL,
            segment_key varchar(255) NOT NULL,
            segment_order int NOT NULL DEFAULT 0,
            source_hebrew longtext NULL,
            translation longtext NULL,
            translation_status varchar(30) NOT NULL DEFAULT 'draft',
            translation_author_user_id bigint(20) unsigned NULL,
            metadata_json longtext NULL,
            created_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY project_segment (project_id,segment_key),
            KEY project_ref (project_id,sefaria_ref(160)),
            KEY project_order (project_id,segment_order)
        ) $charset;";

        $sql[] = "CREATE TABLE $annotations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            segment_ref varchar(255) NULL,
            start_ref varchar(255) NULL,
            end_ref varchar(255) NULL,
            annotation_type varchar(50) NOT NULL DEFAULT 'observation',
            title varchar(255) NULL,
            body longtext NULL,
            tags_json longtext NULL,
            targets_json longtext NULL,
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY project_order (project_id,sort_order),
            KEY user_id (user_id),
            KEY segment_ref (segment_ref(191))
        ) $charset;";

        $sql[] = "CREATE TABLE $outline (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            parent_id bigint(20) unsigned NULL,
            level tinyint unsigned NOT NULL DEFAULT 1,
            title varchar(500) NOT NULL,
            start_ref varchar(255) NULL,
            end_ref varchar(255) NULL,
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY project_order (project_id,sort_order),
            KEY parent_id (parent_id),
            KEY user_id (user_id)
        ) $charset;";

        $sql[] = "CREATE TABLE $sources (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            sefaria_ref varchar(255) NULL,
            source_ref varchar(255) NULL,
            source_type varchar(80) NULL,
            version_title varchar(255) NULL,
            license varchar(255) NULL,
            title varchar(500) NULL,
            snippet longtext NULL,
            metadata_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id),
            KEY user_id (user_id),
            KEY sefaria_ref (sefaria_ref(191))
        ) $charset;";

        $sql[] = "CREATE TABLE $annotation_sources (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            annotation_id bigint(20) unsigned NOT NULL,
            source_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY annotation_source (annotation_id,source_id),
            KEY source_id (source_id)
        ) $charset;";

        $sql[] = "CREATE TABLE $entitlements (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            capability varchar(100) NOT NULL,
            grant_source varchar(80) NOT NULL DEFAULT 'admin',
            granted_by bigint(20) unsigned NULL,
            starts_at datetime NULL,
            expires_at datetime NULL,
            notes text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_capability (user_id,capability),
            KEY expires_at (expires_at)
        ) $charset;";

        $sql[] = "CREATE TABLE $profiles (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            owner_user_id bigint(20) unsigned NOT NULL,
            name varchar(255) NOT NULL,
            profile_json longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY owner_user_id (owner_user_id)
        ) $charset;";

        $sql[] = "CREATE TABLE $revisions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            object_type varchar(50) NOT NULL,
            object_id bigint(20) unsigned NULL,
            action varchar(30) NOT NULL DEFAULT 'update',
            snapshot_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY project_created (project_id,created_at),
            KEY object_lookup (object_type,object_id)
        ) $charset;";

        $sql[] = "CREATE TABLE $imports (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NULL,
            user_id bigint(20) unsigned NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'preview',
            source_name varchar(500) NULL,
            source_type varchar(50) NULL,
            ai_used tinyint(1) NOT NULL DEFAULT 0,
            preview_json longtext NULL,
            warnings_json longtext NULL,
            created_at datetime NOT NULL,
            applied_at datetime NULL,
            PRIMARY KEY  (id),
            KEY user_status (user_id,status),
            KEY project_id (project_id)
        ) $charset;";


        $sql[] = "CREATE TABLE $debug_logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id varchar(32) NULL,
            user_id bigint(20) unsigned NULL,
            level varchar(20) NOT NULL DEFAULT 'debug',
            component varchar(80) NOT NULL DEFAULT 'core',
            event varchar(120) NOT NULL DEFAULT 'event',
            message text NULL,
            context_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY level_created (level,created_at),
            KEY component_created (component,created_at),
            KEY request_id (request_id),
            KEY user_id (user_id)
        ) $charset;";

        foreach ( $sql as $statement ) { dbDelta( $statement ); }
        self::migrate_segments_indexes();
        update_option( 'mtl_db_version', MTL_VERSION );
    }

    private static function migrate_segments_indexes() {
        global $wpdb;
        $table = self::table( 'segments' );
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM $table", 0 );
        if ( ! in_array( 'segment_key', $columns, true ) ) { return; }

        // Fill keys for rows upgraded from 0.2 before replacing the old unique constraint.
        $rows = $wpdb->get_results( "SELECT id,sefaria_ref,segment_key FROM $table ORDER BY id", ARRAY_A );
        foreach ( $rows as $row ) {
            if ( empty( $row['segment_key'] ) ) {
                $wpdb->update( $table, array( 'segment_key' => $row['sefaria_ref'] . '::legacy-' . $row['id'] ), array( 'id' => $row['id'] ) );
            }
        }

        $indexes = $wpdb->get_results( "SHOW INDEX FROM $table", ARRAY_A );
        $by_name = array();
        foreach ( $indexes as $idx ) { $by_name[ $idx['Key_name'] ] = true; }
        if ( isset( $by_name['project_ref'] ) ) {
            // 0.2 used project_ref as a UNIQUE key; drop only if it is unique.
            foreach ( $indexes as $idx ) {
                if ( 'project_ref' === $idx['Key_name'] && 0 === (int) $idx['Non_unique'] ) {
                    $wpdb->query( "ALTER TABLE $table DROP INDEX project_ref" );
                    break;
                }
            }
        }
        $indexes = $wpdb->get_results( "SHOW INDEX FROM $table", ARRAY_A );
        $names = wp_list_pluck( $indexes, 'Key_name' );
        if ( ! in_array( 'project_segment', $names, true ) ) { $wpdb->query( "ALTER TABLE $table ADD UNIQUE KEY project_segment (project_id,segment_key)" ); }
        if ( ! in_array( 'project_ref', $names, true ) ) { $wpdb->query( "ALTER TABLE $table ADD KEY project_ref (project_id,sefaria_ref(160))" ); }
    }

    public static function project( $project_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'projects' ) . ' WHERE id = %d', $project_id ), ARRAY_A );
    }

    public static function access_role( $project_id, $user_id = 0 ) {
        global $wpdb;
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        $project = self::project( $project_id );
        if ( ! $project ) { return false; }
        if ( $user_id && (int) $project['owner_user_id'] === $user_id ) { return 'owner'; }

        if ( $user_id ) {
            $role = $wpdb->get_var( $wpdb->prepare( 'SELECT role FROM ' . self::table( 'project_members' ) . ' WHERE project_id = %d AND user_id = %d', $project_id, $user_id ) );
            if ( $role ) { return $role; }
        }
        if ( 'public' === $project['visibility'] ) { return 'viewer'; }
        if ( 'members' === $project['visibility'] && $user_id ) { return 'viewer'; }
        if ( 'group' === $project['visibility'] && $user_id && ! empty( $project['buddy_group_id'] ) && function_exists( 'groups_is_user_member' ) ) {
            if ( groups_is_user_member( $user_id, (int) $project['buddy_group_id'] ) ) { return 'viewer'; }
        }
        return false;
    }

    public static function role_can_manage( $role ) { return in_array( $role, array( 'owner','editor' ), true ); }
    public static function role_can_edit( $role ) { return in_array( $role, array( 'owner','editor','contributor' ), true ); }
    public static function role_can_comment( $role ) { return in_array( $role, array( 'owner','editor','contributor','commenter' ), true ); }

    public static function revision( $project_id, $object_type, $object_id, $action, $snapshot, $user_id = 0 ) {
        global $wpdb;
        $wpdb->insert( self::table( 'revisions' ), array(
            'project_id' => (int) $project_id,
            'user_id' => $user_id ? (int) $user_id : get_current_user_id(),
            'object_type' => sanitize_key( $object_type ),
            'object_id' => $object_id ? (int) $object_id : null,
            'action' => sanitize_key( $action ),
            'snapshot_json' => wp_json_encode( $snapshot ),
            'created_at' => current_time( 'mysql' ),
        ) );
    }
}
