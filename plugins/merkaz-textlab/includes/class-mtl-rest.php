<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MTL_REST {
    const NS = 'merkaz-textlab/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }

    public static function routes() {
        register_rest_route( self::NS, '/me', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'me' ),
            'permission_callback' => array( __CLASS__, 'can_use' ),
        ) );

        register_rest_route( self::NS, '/projects', array(
            array(
                'methods' => 'GET',
                'callback' => array( __CLASS__, 'projects' ),
                'permission_callback' => array( __CLASS__, 'can_use' ),
            ),
            array(
                'methods' => 'POST',
                'callback' => array( __CLASS__, 'create_project' ),
                'permission_callback' => function() { return MTL_Capabilities::user_can( 'mtl_create_projects' ); },
            ),
        ) );

        register_rest_route( self::NS, '/projects/(?P<id>\d+)', array(
            array(
                'methods' => 'GET',
                'callback' => array( __CLASS__, 'project' ),
                'permission_callback' => array( __CLASS__, 'can_view_project' ),
            ),
            array(
                'methods' => 'PUT,PATCH',
                'callback' => array( __CLASS__, 'update_project' ),
                'permission_callback' => array( __CLASS__, 'can_admin_project' ),
            ),
            array(
                'methods' => 'DELETE',
                'callback' => array( __CLASS__, 'delete_project' ),
                'permission_callback' => array( __CLASS__, 'can_own_project' ),
            ),
        ) );

        register_rest_route( self::NS, '/projects/(?P<id>\d+)/workspace', array(
            array(
                'methods' => 'GET',
                'callback' => array( __CLASS__, 'workspace' ),
                'permission_callback' => array( __CLASS__, 'can_view_project' ),
            ),
            array(
                'methods' => 'PUT',
                'callback' => array( __CLASS__, 'save_workspace' ),
                'permission_callback' => array( __CLASS__, 'can_write_project' ),
            ),
        ) );

        register_rest_route( self::NS, '/projects/(?P<id>\d+)/source', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'save_source' ),
            'permission_callback' => function( $request ) {
                return self::can_edit_project( $request ) && MTL_Capabilities::user_can( 'mtl_sources' );
            },
        ) );

        register_rest_route( self::NS, '/projects/(?P<id>\d+)/members', array(
            array(
                'methods' => 'GET',
                'callback' => array( __CLASS__, 'members' ),
                'permission_callback' => array( __CLASS__, 'can_manage_project' ),
            ),
            array(
                'methods' => 'POST',
                'callback' => array( __CLASS__, 'add_member' ),
                'permission_callback' => array( __CLASS__, 'can_manage_project' ),
            ),
        ) );

        register_rest_route( self::NS, '/projects/(?P<id>\d+)/members/(?P<user_id>\d+)', array(
            array(
                'methods' => 'PUT,PATCH',
                'callback' => array( __CLASS__, 'update_member' ),
                'permission_callback' => array( __CLASS__, 'can_manage_project' ),
            ),
            array(
                'methods' => 'DELETE',
                'callback' => array( __CLASS__, 'delete_member' ),
                'permission_callback' => array( __CLASS__, 'can_manage_project' ),
            ),
        ) );

        register_rest_route( self::NS, '/projects/(?P<id>\d+)/revisions', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'revisions' ),
            'permission_callback' => array( __CLASS__, 'can_manage_project' ),
        ) );

        register_rest_route( self::NS, '/projects/(?P<id>\d+)/annotations/(?P<annotation_id>\d+)/sources', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'attach_source' ),
            'permission_callback' => function( $request ) {
                return self::can_write_project( $request ) && ( MTL_Capabilities::user_can( 'mtl_sources' ) || MTL_Capabilities::user_can( 'mtl_manuscript_read' ) );
            },
        ) );

        register_rest_route( self::NS, '/users/search', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'user_search' ),
            'permission_callback' => function() { return MTL_Capabilities::user_can( 'mtl_collaborate' ); },
        ) );

        foreach ( array( 'text','ref','versions','related','links' ) as $endpoint ) {
            register_rest_route( self::NS, '/sefaria/' . $endpoint, array(
                'methods' => 'GET',
                'callback' => array( __CLASS__, 'sefaria_' . $endpoint ),
                'permission_callback' => array( __CLASS__, 'can_use' ),
            ) );
        }
    }

    public static function can_use() { return MTL_Capabilities::user_can( 'mtl_use_workbench' ); }
    public static function can_view_project( $request ) { return (bool) MTL_DB::access_role( (int) $request['id'] ); }
    public static function can_write_project( $request ) { return MTL_DB::role_can_comment( MTL_DB::access_role( (int) $request['id'] ) ); }
    public static function can_edit_project( $request ) { return MTL_DB::role_can_edit( MTL_DB::access_role( (int) $request['id'] ) ); }
    public static function can_admin_project( $request ) { return MTL_DB::role_can_manage( MTL_DB::access_role( (int) $request['id'] ) ); }
    public static function can_manage_project( $request ) {
        $role = MTL_DB::access_role( (int) $request['id'] );
        return MTL_DB::role_can_manage( $role ) && MTL_Capabilities::user_can( 'mtl_collaborate' );
    }
    public static function can_own_project( $request ) { return 'owner' === MTL_DB::access_role( (int) $request['id'] ); }

    public static function me() {
        $user = wp_get_current_user();
        return rest_ensure_response( array(
            'id' => $user->ID,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'features' => MTL_Capabilities::feature_map( $user->ID ),
            'pdf' => MTL_PDF::status(),
        ) );
    }

    public static function projects() {
        global $wpdb;
        $user_id = get_current_user_id();
        $projects = MTL_DB::table( 'projects' );
        $members = MTL_DB::table( 'project_members' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT p.* FROM $projects p LEFT JOIN $members m ON m.project_id=p.id WHERE p.owner_user_id=%d OR m.user_id=%d OR p.visibility='members' OR p.visibility='public' ORDER BY p.updated_at DESC",
            $user_id,
            $user_id
        ), ARRAY_A );

        if ( function_exists( 'groups_is_user_member' ) ) {
            $group_rows = $wpdb->get_results( "SELECT * FROM $projects WHERE visibility='group' AND buddy_group_id IS NOT NULL ORDER BY updated_at DESC", ARRAY_A );
            foreach ( $group_rows as $row ) {
                if ( groups_is_user_member( $user_id, (int) $row['buddy_group_id'] ) ) { $rows[] = $row; }
            }
        }

        $unique = array();
        foreach ( $rows as $row ) {
            $row['access_role'] = MTL_DB::access_role( (int) $row['id'], $user_id );
            $unique[ $row['id'] ] = $row;
        }
        return rest_ensure_response( array_values( $unique ) );
    }

    public static function create_project( $request ) {
        global $wpdb;
        $data = (array) $request->get_json_params();
        $type = sanitize_key( $data['project_type'] ?? 'user' );
        if ( in_array( $type, array( 'publication','manuscript' ), true ) && ! MTL_Capabilities::user_can( 'mtl_manuscript_edit' ) ) { $type = 'user'; }
        if ( ! in_array( $type, array( 'user','shared','publication','manuscript' ), true ) ) { $type = 'user'; }
        $now = current_time( 'mysql' );
        $wpdb->insert( MTL_DB::table( 'projects' ), array(
            'owner_user_id' => get_current_user_id(),
            'title' => sanitize_text_field( $data['title'] ?? 'Untitled Text Project' ),
            'description' => sanitize_textarea_field( $data['description'] ?? '' ),
            'project_type' => $type,
            'visibility' => 'private',
            'created_at' => $now,
            'updated_at' => $now,
        ) );
        return rest_ensure_response( MTL_DB::project( $wpdb->insert_id ) );
    }

    public static function project( $request ) {
        return rest_ensure_response( MTL_DB::project( (int) $request['id'] ) );
    }

    public static function update_project( $request ) {
        global $wpdb;
        $id = (int) $request['id'];
        $data = (array) $request->get_json_params();
        $old = MTL_DB::project( $id );
        $update = array( 'updated_at' => current_time( 'mysql' ) );
        if ( isset( $data['title'] ) ) { $update['title'] = sanitize_text_field( $data['title'] ); }
        if ( isset( $data['description'] ) ) { $update['description'] = sanitize_textarea_field( $data['description'] ); }
        $allowed_visibility = array( 'private','shared','group','members','public' );
        if ( isset( $data['visibility'] ) && in_array( $data['visibility'], $allowed_visibility, true ) ) { $update['visibility'] = $data['visibility']; }
        if ( isset( $data['buddy_group_id'] ) ) { $update['buddy_group_id'] = $data['buddy_group_id'] ? absint( $data['buddy_group_id'] ) : null; }
        $wpdb->update( MTL_DB::table( 'projects' ), $update, array( 'id' => $id ) );
        MTL_DB::revision( $id, 'project', $id, 'update', $old );
        return rest_ensure_response( MTL_DB::project( $id ) );
    }

    public static function delete_project( $request ) {
        global $wpdb;
        $id = (int) $request['id'];
        $project = MTL_DB::project( $id );
        MTL_DB::revision( $id, 'project', $id, 'delete', $project );

        // Delete annotation-source joins for project annotations before the annotations themselves.
        $annotation_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . MTL_DB::table( 'annotations' ) . ' WHERE project_id=%d', $id ) );
        if ( $annotation_ids ) {
            $ids = implode( ',', array_map( 'absint', $annotation_ids ) );
            $wpdb->query( 'DELETE FROM ' . MTL_DB::table( 'annotation_sources' ) . " WHERE annotation_id IN ($ids)" );
        }
        foreach ( array( 'project_members','segments','annotations','outline_nodes','sources','revisions','imports' ) as $table ) {
            $wpdb->delete( MTL_DB::table( $table ), array( 'project_id' => $id ) );
        }
        $wpdb->delete( MTL_DB::table( 'projects' ), array( 'id' => $id ) );
        return rest_ensure_response( array( 'deleted' => true ) );
    }

    public static function workspace( $request ) {
        global $wpdb;
        $id = (int) $request['id'];
        $role = MTL_DB::access_role( $id );
        $annotations = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . MTL_DB::table( 'annotations' ) . ' WHERE project_id=%d ORDER BY sort_order,id', $id ), ARRAY_A );
        $can_read_advanced_notes = MTL_Capabilities::user_can( 'mtl_advanced_annotations' ) || MTL_Capabilities::user_can( 'mtl_manuscript_read' );
        if ( ! $can_read_advanced_notes ) {
            $annotations = array_values( array_filter( $annotations, function( $annotation ) {
                $targets = self::json_array( $annotation['targets_json'] ?? '' );
                if ( ! $targets ) { $targets = array( 'homework' ); }
                return in_array( 'homework', $targets, true );
            } ) );
        }
        $annotation_ids = wp_list_pluck( $annotations, 'id' );
        $links = array();
        if ( $annotation_ids ) {
            $ids = implode( ',', array_map( 'absint', $annotation_ids ) );
            $rows = $wpdb->get_results( 'SELECT annotation_id,source_id FROM ' . MTL_DB::table( 'annotation_sources' ) . " WHERE annotation_id IN ($ids)", ARRAY_A );
            foreach ( $rows as $row ) { $links[ (int) $row['annotation_id'] ][] = (int) $row['source_id']; }
        }
        foreach ( $annotations as &$annotation ) {
            $annotation['tags'] = self::json_array( $annotation['tags_json'] ?? '' );
            $annotation['targets'] = self::json_array( $annotation['targets_json'] ?? '' );
            $annotation['source_ids'] = $links[ (int) $annotation['id'] ] ?? array();
        }
        unset( $annotation );

        return rest_ensure_response( array(
            'project' => MTL_DB::project( $id ),
            'role' => $role,
            'permissions' => array(
                'comment' => MTL_DB::role_can_comment( $role ),
                'edit' => MTL_DB::role_can_edit( $role ),
                'admin' => MTL_DB::role_can_manage( $role ),
                'manage' => self::can_manage_project( $request ),
            ),
            'segments' => ( MTL_Capabilities::user_can( 'mtl_translate' ) || MTL_Capabilities::user_can( 'mtl_manuscript_read' ) ) ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . MTL_DB::table( 'segments' ) . ' WHERE project_id=%d ORDER BY segment_order,id', $id ), ARRAY_A ) : array(),
            'annotations' => $annotations,
            'outline' => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . MTL_DB::table( 'outline_nodes' ) . ' WHERE project_id=%d ORDER BY sort_order,id', $id ), ARRAY_A ),
            'sources' => ( MTL_Capabilities::user_can( 'mtl_sources' ) || MTL_Capabilities::user_can( 'mtl_manuscript_read' ) ) ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . MTL_DB::table( 'sources' ) . ' WHERE project_id=%d ORDER BY id DESC', $id ), ARRAY_A ) : array(),
        ) );
    }

    public static function save_workspace( $request ) {
        global $wpdb;
        $id = (int) $request['id'];
        $user_id = get_current_user_id();
        $role = MTL_DB::access_role( $id );
        $body = (array) $request->get_json_params();
        $now = current_time( 'mysql' );

        if ( MTL_DB::role_can_edit( $role ) && MTL_Capabilities::user_can( 'mtl_translate' ) && isset( $body['segments'] ) && is_array( $body['segments'] ) ) {
            foreach ( $body['segments'] as $i => $segment ) {
                $ref = sanitize_text_field( $segment['sefaria_ref'] ?? '' );
                if ( ! $ref ) { continue; }
                $row_id = absint( $segment['id'] ?? 0 );
                $key = sanitize_text_field( $segment['segment_key'] ?? '' );
                if ( ! $key ) { $key = $ref . '::user-' . ( $i + 1 ); }
                $row = array(
                    'project_id' => $id,
                    'sefaria_ref' => $ref,
                    'segment_key' => $key,
                    'segment_order' => (int) ( $segment['segment_order'] ?? ( ( $i + 1 ) * 10 ) ),
                    'source_hebrew' => sanitize_textarea_field( $segment['source_hebrew'] ?? '' ),
                    'translation' => wp_kses_post( $segment['translation'] ?? '' ),
                    'translation_status' => sanitize_key( $segment['translation_status'] ?? 'draft' ),
                    'translation_author_user_id' => $user_id,
                    'metadata_json' => wp_json_encode( $segment['metadata'] ?? self::json_array( $segment['metadata_json'] ?? '' ) ),
                    'updated_at' => $now,
                );
                if ( $row_id ) {
                    $old = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MTL_DB::table( 'segments' ) . ' WHERE id=%d AND project_id=%d', $row_id, $id ), ARRAY_A );
                    if ( $old ) {
                        MTL_DB::revision( $id, 'segment', $row_id, 'update', $old, $user_id );
                        $wpdb->update( MTL_DB::table( 'segments' ), $row, array( 'id' => $row_id ) );
                    }
                } else {
                    $existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MTL_DB::table( 'segments' ) . ' WHERE project_id=%d AND segment_key=%s', $id, $key ) );
                    if ( $existing ) {
                        $wpdb->update( MTL_DB::table( 'segments' ), $row, array( 'id' => $existing ) );
                    } else {
                        $row['created_at'] = $now;
                        $wpdb->insert( MTL_DB::table( 'segments' ), $row );
                    }
                }
            }

            // A client may explicitly request deletion of its own translation segments.
            foreach ( (array) ( $body['delete_segment_ids'] ?? array() ) as $delete_id ) {
                $delete_id = absint( $delete_id );
                if ( ! $delete_id ) { continue; }
                $old = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MTL_DB::table( 'segments' ) . ' WHERE id=%d AND project_id=%d', $delete_id, $id ), ARRAY_A );
                if ( $old ) {
                    MTL_DB::revision( $id, 'segment', $delete_id, 'delete', $old, $user_id );
                    $wpdb->delete( MTL_DB::table( 'segments' ), array( 'id' => $delete_id, 'project_id' => $id ) );
                }
            }
        }

        if ( MTL_DB::role_can_comment( $role ) && MTL_Capabilities::user_can( 'mtl_annotate' ) && isset( $body['annotations'] ) && is_array( $body['annotations'] ) ) {
            $advanced_annotations = MTL_Capabilities::user_can( 'mtl_advanced_annotations' );
            self::sync_authored_rows( 'annotations', $id, $user_id, $body['annotations'], function( $annotation, $order ) use ( $id, $user_id, $now, $advanced_annotations ) {
                $targets = $advanced_annotations ? array_values( array_map( 'sanitize_key', (array) ( $annotation['targets'] ?? array( 'homework' ) ) ) ) : array( 'homework' );
                if ( ! $targets ) { $targets = array( 'homework' ); }
                return array(
                    'project_id' => $id,
                    'user_id' => $user_id,
                    'segment_ref' => sanitize_text_field( $annotation['segment_ref'] ?? '' ),
                    'start_ref' => sanitize_text_field( $annotation['start_ref'] ?? '' ),
                    'end_ref' => sanitize_text_field( $annotation['end_ref'] ?? '' ),
                    'annotation_type' => sanitize_key( $annotation['annotation_type'] ?? 'observation' ),
                    'title' => sanitize_text_field( $annotation['title'] ?? '' ),
                    'body' => wp_kses_post( $annotation['body'] ?? '' ),
                    'tags_json' => wp_json_encode( array_values( array_map( 'sanitize_text_field', (array) ( $annotation['tags'] ?? array() ) ) ) ),
                    'targets_json' => wp_json_encode( $targets ),
                    'sort_order' => (int) ( $annotation['sort_order'] ?? ( $order * 10 ) ),
                    'created_at' => $now,
                    'updated_at' => $now,
                );
            } );
        }

        if ( MTL_DB::role_can_edit( $role ) && MTL_Capabilities::user_can( 'mtl_outline' ) && isset( $body['outline'] ) && is_array( $body['outline'] ) ) {
            self::sync_authored_rows( 'outline_nodes', $id, $user_id, $body['outline'], function( $outline, $order ) use ( $id, $user_id, $now ) {
                return array(
                    'project_id' => $id,
                    'user_id' => $user_id,
                    'parent_id' => empty( $outline['parent_id'] ) ? null : absint( $outline['parent_id'] ),
                    'level' => max( 1, min( 8, (int) ( $outline['level'] ?? 1 ) ) ),
                    'title' => sanitize_text_field( $outline['title'] ?? '' ),
                    'start_ref' => sanitize_text_field( $outline['start_ref'] ?? '' ),
                    'end_ref' => sanitize_text_field( $outline['end_ref'] ?? '' ),
                    'sort_order' => (int) ( $outline['sort_order'] ?? ( $order * 10 ) ),
                    'created_at' => $now,
                    'updated_at' => $now,
                );
            } );
        }

        $wpdb->update( MTL_DB::table( 'projects' ), array( 'updated_at' => $now ), array( 'id' => $id ) );
        return self::workspace( $request );
    }

    private static function json_array( $value ) {
        if ( is_array( $value ) ) { return $value; }
        $parsed = json_decode( (string) $value, true );
        return is_array( $parsed ) ? $parsed : array();
    }

    private static function sync_authored_rows( $table_name, $project_id, $user_id, $incoming, $builder ) {
        global $wpdb;
        $table = MTL_DB::table( $table_name );
        $kept = array();
        foreach ( $incoming as $i => $item ) {
            $row_id = absint( $item['id'] ?? 0 );
            $row = call_user_func( $builder, $item, $i + 1 );
            if ( $row_id ) {
                $old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d AND project_id=%d AND user_id=%d", $row_id, $project_id, $user_id ), ARRAY_A );
                if ( $old ) {
                    MTL_DB::revision( $project_id, $table_name, $row_id, 'update', $old, $user_id );
                    unset( $row['created_at'] );
                    $wpdb->update( $table, $row, array( 'id' => $row_id ) );
                    $kept[] = $row_id;
                }
            } else {
                $wpdb->insert( $table, $row );
                if ( $wpdb->insert_id ) { $kept[] = (int) $wpdb->insert_id; }
            }
        }

        $owned = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE project_id=%d AND user_id=%d", $project_id, $user_id ), ARRAY_A );
        foreach ( $owned as $old ) {
            if ( ! in_array( (int) $old['id'], $kept, true ) ) { MTL_DB::revision( $project_id, $table_name, (int) $old['id'], 'delete', $old, $user_id ); }
        }

        $sql = "DELETE FROM $table WHERE project_id=%d AND user_id=%d";
        $args = array( $project_id, $user_id );
        if ( $kept ) {
            $placeholders = implode( ',', array_fill( 0, count( $kept ), '%d' ) );
            $sql .= " AND id NOT IN ($placeholders)";
            $args = array_merge( $args, $kept );
        }
        $wpdb->query( $wpdb->prepare( $sql, $args ) );
    }

    public static function save_source( $request ) {
        global $wpdb;
        $id = (int) $request['id'];
        $data = (array) $request->get_json_params();
        $wpdb->insert( MTL_DB::table( 'sources' ), array(
            'project_id' => $id,
            'user_id' => get_current_user_id(),
            'sefaria_ref' => sanitize_text_field( $data['sefaria_ref'] ?? '' ),
            'source_ref' => sanitize_text_field( $data['source_ref'] ?? '' ),
            'source_type' => sanitize_text_field( $data['source_type'] ?? '' ),
            'version_title' => sanitize_text_field( $data['version_title'] ?? '' ),
            'license' => sanitize_text_field( $data['license'] ?? '' ),
            'title' => sanitize_text_field( $data['title'] ?? '' ),
            'snippet' => wp_kses_post( $data['snippet'] ?? '' ),
            'metadata_json' => wp_json_encode( $data['metadata'] ?? array() ),
            'created_at' => current_time( 'mysql' ),
        ) );
        $source_id = (int) $wpdb->insert_id;
        MTL_DB::revision( $id, 'source', $source_id, 'create', array( 'source_ref' => sanitize_text_field( $data['source_ref'] ?? '' ) ) );
        return rest_ensure_response( array( 'id' => $source_id ) );
    }

    public static function members( $request ) {
        global $wpdb;
        $id = (int) $request['id'];
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT m.*,u.display_name,u.user_email,u.user_login FROM ' . MTL_DB::table( 'project_members' ) . ' m LEFT JOIN ' . $wpdb->users . ' u ON u.ID=m.user_id WHERE m.project_id=%d ORDER BY m.created_at',
            $id
        ), ARRAY_A );
        return rest_ensure_response( $rows );
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

    private static function valid_member_role( $role ) {
        return in_array( $role, array( 'viewer','commenter','contributor','editor' ), true );
    }

    public static function add_member( $request ) {
        global $wpdb;
        $project_id = (int) $request['id'];
        $data = (array) $request->get_json_params();
        $user = self::resolve_user( $data['user'] ?? $data['lookup'] ?? '' );
        if ( ! $user ) { return new WP_Error( 'mtl_user_not_found', 'No WordPress/BuddyPress user matched that email, username, or ID.', array( 'status' => 404 ) ); }
        $project = MTL_DB::project( $project_id );
        if ( ! $project ) { return new WP_Error( 'mtl_project_missing', 'Project not found.', array( 'status' => 404 ) ); }
        if ( (int) $project['owner_user_id'] === (int) $user->ID ) { return new WP_Error( 'mtl_owner_member', 'The project owner already has full access.', array( 'status' => 400 ) ); }
        $role = sanitize_key( $data['role'] ?? 'viewer' );
        if ( ! self::valid_member_role( $role ) ) { $role = 'viewer'; }
        $now = current_time( 'mysql' );
        $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MTL_DB::table( 'project_members' ) . ' WHERE project_id=%d AND user_id=%d', $project_id, $user->ID ), ARRAY_A );
        $row = array(
            'project_id' => $project_id,
            'user_id' => $user->ID,
            'role' => $role,
            'invited_by' => get_current_user_id(),
            'created_at' => $existing ? $existing['created_at'] : $now,
            'updated_at' => $now,
        );
        if ( $existing ) {
            MTL_DB::revision( $project_id, 'project_member', (int) $existing['id'], 'update', $existing );
            $wpdb->update( MTL_DB::table( 'project_members' ), $row, array( 'id' => (int) $existing['id'] ) );
        } else {
            $wpdb->insert( MTL_DB::table( 'project_members' ), $row );
            MTL_DB::revision( $project_id, 'project_member', (int) $wpdb->insert_id, 'create', array( 'user_id' => $user->ID, 'role' => $role ) );
        }
        return self::members( $request );
    }

    public static function update_member( $request ) {
        global $wpdb;
        $project_id = (int) $request['id'];
        $user_id = (int) $request['user_id'];
        $data = (array) $request->get_json_params();
        $role = sanitize_key( $data['role'] ?? 'viewer' );
        if ( ! self::valid_member_role( $role ) ) { return new WP_Error( 'mtl_bad_role', 'Invalid collaborator role.', array( 'status' => 400 ) ); }
        $old = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MTL_DB::table( 'project_members' ) . ' WHERE project_id=%d AND user_id=%d', $project_id, $user_id ), ARRAY_A );
        if ( ! $old ) { return new WP_Error( 'mtl_member_missing', 'Collaborator not found.', array( 'status' => 404 ) ); }
        MTL_DB::revision( $project_id, 'project_member', (int) $old['id'], 'update', $old );
        $wpdb->update( MTL_DB::table( 'project_members' ), array( 'role' => $role, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $old['id'] ) );
        return self::members( $request );
    }

    public static function delete_member( $request ) {
        global $wpdb;
        $project_id = (int) $request['id'];
        $user_id = (int) $request['user_id'];
        $old = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MTL_DB::table( 'project_members' ) . ' WHERE project_id=%d AND user_id=%d', $project_id, $user_id ), ARRAY_A );
        if ( $old ) {
            MTL_DB::revision( $project_id, 'project_member', (int) $old['id'], 'delete', $old );
            $wpdb->delete( MTL_DB::table( 'project_members' ), array( 'id' => (int) $old['id'] ) );
        }
        return self::members( $request );
    }

    public static function user_search( $request ) {
        $search = sanitize_text_field( $request->get_param( 'q' ) );
        if ( strlen( $search ) < 2 ) { return rest_ensure_response( array() ); }
        $query = new WP_User_Query( array(
            'number' => 12,
            'search' => '*' . $search . '*',
            'search_columns' => array( 'user_login','user_nicename','user_email','display_name' ),
            'fields' => array( 'ID','display_name','user_email','user_login' ),
        ) );
        $rows = array();
        foreach ( $query->get_results() as $user ) {
            $rows[] = array(
                'id' => (int) $user->ID,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
                'login' => $user->user_login,
            );
        }
        return rest_ensure_response( $rows );
    }

    public static function revisions( $request ) {
        global $wpdb;
        $project_id = (int) $request['id'];
        $limit = max( 1, min( 200, absint( $request->get_param( 'limit' ) ?: 50 ) ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT r.*,u.display_name FROM ' . MTL_DB::table( 'revisions' ) . ' r LEFT JOIN ' . $wpdb->users . ' u ON u.ID=r.user_id WHERE r.project_id=%d ORDER BY r.id DESC LIMIT %d',
            $project_id,
            $limit
        ), ARRAY_A );
        return rest_ensure_response( $rows );
    }

    public static function attach_source( $request ) {
        global $wpdb;
        $project_id = (int) $request['id'];
        $annotation_id = (int) $request['annotation_id'];
        $data = (array) $request->get_json_params();
        $source_id = absint( $data['source_id'] ?? 0 );
        $annotation = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MTL_DB::table( 'annotations' ) . ' WHERE id=%d AND project_id=%d', $annotation_id, $project_id ), ARRAY_A );
        $source = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MTL_DB::table( 'sources' ) . ' WHERE id=%d AND project_id=%d', $source_id, $project_id ), ARRAY_A );
        if ( ! $annotation || ! $source ) { return new WP_Error( 'mtl_link_missing', 'The annotation or source does not belong to this project.', array( 'status' => 404 ) ); }
        if ( (int) $annotation['user_id'] !== get_current_user_id() && ! MTL_DB::role_can_manage( MTL_DB::access_role( $project_id ) ) ) {
            return new WP_Error( 'mtl_link_forbidden', 'You may only attach sources to your own annotations unless you are an editor.', array( 'status' => 403 ) );
        }
        $wpdb->query( $wpdb->prepare(
            'INSERT IGNORE INTO ' . MTL_DB::table( 'annotation_sources' ) . ' (annotation_id,source_id,created_at) VALUES (%d,%d,%s)',
            $annotation_id,
            $source_id,
            current_time( 'mysql' )
        ) );
        return rest_ensure_response( array( 'attached' => true, 'annotation_id' => $annotation_id, 'source_id' => $source_id ) );
    }

    private static function sefaria_response( $value ) {
        if ( is_wp_error( $value ) ) { return $value; }
        return rest_ensure_response( $value );
    }

    public static function sefaria_text( $request ) {
        $ref = sanitize_text_field( $request->get_param( 'ref' ) );
        if ( ! $ref ) { return new WP_Error( 'mtl_ref_required', 'A Sefaria reference is required.', array( 'status' => 400 ) ); }
        $version = $request->get_param( 'version' );
        if ( is_string( $version ) ) { $version = array_filter( array_map( 'trim', explode( '|~|', $version ) ) ); }
        if ( ! is_array( $version ) ) { $version = array(); }
        return self::sefaria_response( MTL_Sefaria::text( $ref, array_map( 'sanitize_text_field', $version ) ) );
    }

    public static function sefaria_ref( $request ) {
        $ref = sanitize_text_field( $request->get_param( 'ref' ) );
        if ( ! $ref ) { return new WP_Error( 'mtl_ref_required', 'A Sefaria reference is required.', array( 'status' => 400 ) ); }
        return self::sefaria_response( MTL_Sefaria::ref_info( $ref ) );
    }

    public static function sefaria_versions( $request ) {
        $index = sanitize_text_field( $request->get_param( 'index' ) );
        if ( ! $index ) { return new WP_Error( 'mtl_index_required', 'A Sefaria index title is required.', array( 'status' => 400 ) ); }
        return self::sefaria_response( MTL_Sefaria::versions( $index ) );
    }

    public static function sefaria_related( $request ) {
        $ref = sanitize_text_field( $request->get_param( 'ref' ) );
        if ( ! $ref ) { return new WP_Error( 'mtl_ref_required', 'A Sefaria reference is required.', array( 'status' => 400 ) ); }
        return self::sefaria_response( MTL_Sefaria::related( $ref ) );
    }

    public static function sefaria_links( $request ) {
        $ref = sanitize_text_field( $request->get_param( 'ref' ) );
        $categories = sanitize_text_field( $request->get_param( 'categories' ) );
        if ( ! $ref ) { return new WP_Error( 'mtl_ref_required', 'A Sefaria reference is required.', array( 'status' => 400 ) ); }
        return self::sefaria_response( MTL_Sefaria::links( $ref, $categories ) );
    }
}
