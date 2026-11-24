<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MTL_Import {
    public static function init() {
        add_action( 'admin_post_mtl_import_preview', array( __CLASS__, 'handle_preview' ) );
        add_action( 'admin_post_mtl_import_apply', array( __CLASS__, 'handle_apply' ) );
        add_action( 'admin_post_mtl_import_discard', array( __CLASS__, 'handle_discard' ) );
    }

    public static function handle_preview() {
        $started = MTL_Debug::start_timer();
        MTL_Debug::debug( 'import', 'preview_start', 'Import preview request started.', array( 'user_id' => get_current_user_id(), 'has_upload' => ! empty( $_FILES['import_file']['tmp_name'] ) ) );
        if ( ! current_user_can( 'manage_options' ) && ! MTL_Capabilities::user_can( 'mtl_import_data' ) ) { wp_die( 'Import access is not enabled.', 403 ); }
        check_admin_referer( 'mtl_import_preview' );
        if ( empty( $_FILES['import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['import_file']['tmp_name'] ) ) {
            self::redirect_error( 'No import file was uploaded.' );
        }

        $name = sanitize_file_name( $_FILES['import_file']['name'] );
        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        $allowed = array( 'docx','xlsx','txt','md','json','csv','tsv' );
        if ( ! in_array( $ext, $allowed, true ) ) { self::redirect_error( 'Supported import files are DOCX, XLSX, TXT, Markdown, JSON, CSV, and TSV.' ); }

        $project_id = absint( $_POST['project_id'] ?? 0 );
        if ( $project_id && ! MTL_DB::role_can_edit( MTL_DB::access_role( $project_id ) ) ) { self::redirect_error( 'You cannot import into that project.' ); }
        $use_ai = ! empty( $_POST['use_ai'] );
        $extra_context = sanitize_textarea_field( wp_unslash( $_POST['extra_context'] ?? '' ) );
        $title_override = sanitize_text_field( wp_unslash( $_POST['project_title'] ?? '' ) );
        MTL_Debug::info( 'import', 'file_validated', 'Import file passed initial validation.', array( 'file_name' => $name, 'extension' => $ext, 'file_size' => absint( $_FILES['import_file']['size'] ?? 0 ), 'project_id' => $project_id, 'use_ai' => $use_ai, 'extra_context_chars' => function_exists( 'mb_strlen' ) ? mb_strlen( $extra_context, 'UTF-8' ) : strlen( $extra_context ) ) );

        $extract_started = MTL_Debug::start_timer();
        $extracted = self::extract( $_FILES['import_file']['tmp_name'], $ext, $name );
        if ( is_wp_error( $extracted ) ) { MTL_Debug::wp_error( 'import', 'extract_error', $extracted, array( 'file_name' => $name, 'extension' => $ext, 'duration_ms' => MTL_Debug::duration_ms( $extract_started ) ) ); self::redirect_error( $extracted->get_error_message() ); }
        MTL_Debug::info( 'import', 'extract_complete', 'Import source extraction completed.', array( 'file_name' => $name, 'extension' => $ext, 'deterministic_complete' => ! empty( $extracted['deterministic_complete'] ), 'has_structured' => ! empty( $extracted['structured'] ), 'text_chars' => function_exists( 'mb_strlen' ) ? mb_strlen( (string) ( $extracted['text'] ?? '' ), 'UTF-8' ) : strlen( (string) ( $extracted['text'] ?? '' ) ), 'warnings' => count( (array) ( $extracted['warnings'] ?? array() ) ), 'duration_ms' => MTL_Debug::duration_ms( $extract_started ) ) );

        $preview = $extracted['structured'] ?? null;
        $warnings = (array) ( $extracted['warnings'] ?? array() );
        $ai_used = 0;

        if ( $use_ai && ! empty( $extracted['text'] ) && empty( $extracted['deterministic_complete'] ) ) {
            if ( ! MTL_OpenAI::has_key() ) { self::redirect_error( 'AI-assisted import was requested, but no OpenAI API key is configured.' ); }
            $chunks = self::chunk_text( $extracted['text'], max( 12000, min( 120000, absint( get_option( 'mtl_openai_import_chunk_chars', 50000 ) ) ) ) );
            MTL_Debug::info( 'import', 'ai_chunking', 'Import text prepared for AI-assisted structuring.', array( 'chunks' => count( $chunks ), 'configured_chunk_chars' => absint( get_option( 'mtl_openai_import_chunk_chars', 50000 ) ) ) );
            $merged = self::empty_preview();
            foreach ( $chunks as $i => $chunk ) {
                MTL_Debug::debug( 'import', 'ai_chunk_start', 'Structuring import chunk.', array( 'chunk' => $i + 1, 'total_chunks' => count( $chunks ), 'chunk_chars' => function_exists( 'mb_strlen' ) ? mb_strlen( $chunk, 'UTF-8' ) : strlen( $chunk ) ) );
                $ctx = trim( $extra_context . "\nThis is import chunk " . ( $i + 1 ) . ' of ' . count( $chunks ) . '. Preserve ordering across chunks using sort_order.' );
                $part = MTL_OpenAI::import_structured( $chunk, $ctx );
                if ( is_wp_error( $part ) ) { MTL_Debug::wp_error( 'import', 'ai_chunk_error', $part, array( 'chunk' => $i + 1, 'total_chunks' => count( $chunks ) ) ); self::redirect_error( 'AI import failed: ' . $part->get_error_message() ); }
                MTL_Debug::debug( 'import', 'ai_chunk_complete', 'Import chunk structured.', array( 'chunk' => $i + 1, 'segments' => count( (array) ( $part['segments'] ?? array() ) ), 'annotations' => count( (array) ( $part['annotations'] ?? array() ) ), 'outline' => count( (array) ( $part['outline'] ?? array() ) ), 'warnings' => count( (array) ( $part['warnings'] ?? array() ) ) ) );
                $merged = self::merge_previews( $merged, $part );
            }
            $preview = $merged;
            $warnings = array_merge( $warnings, (array) ( $preview['warnings'] ?? array() ) );
            $ai_used = 1;
        } elseif ( ! $preview ) {
            $preview = self::raw_preview( $extracted['text'] ?? '', $name );
            $warnings[] = 'The file was extracted without AI structuring. Its raw content is preserved as a single import annotation for manual organization.';
        }

        if ( $title_override ) { $preview['project_title'] = $title_override; }
        $preview = self::sanitize_preview( $preview );
        $preview['warnings'] = array_values( array_unique( array_merge( (array) ( $preview['warnings'] ?? array() ), $warnings ) ) );

        global $wpdb;
        $wpdb->insert( MTL_DB::table( 'imports' ), array(
            'project_id' => $project_id ?: null,
            'user_id' => get_current_user_id(),
            'status' => 'preview',
            'source_name' => $name,
            'source_type' => $ext,
            'ai_used' => $ai_used,
            'preview_json' => wp_json_encode( $preview ),
            'warnings_json' => wp_json_encode( $preview['warnings'] ),
            'created_at' => current_time( 'mysql' ),
        ) );
        $id = (int) $wpdb->insert_id;
        MTL_Debug::info( 'import', 'preview_saved', 'Import preview saved.', array( 'import_id' => $id, 'project_id' => $project_id, 'ai_used' => (bool) $ai_used, 'segments' => count( (array) ( $preview['segments'] ?? array() ) ), 'annotations' => count( (array) ( $preview['annotations'] ?? array() ) ), 'outline' => count( (array) ( $preview['outline'] ?? array() ) ), 'warnings' => count( (array) ( $preview['warnings'] ?? array() ) ), 'duration_ms' => MTL_Debug::duration_ms( $started ) ) );
        wp_safe_redirect( admin_url( 'admin.php?page=merkaz-textlab-import&import_id=' . $id ) );
        exit;
    }

    public static function handle_apply() {
        $started = MTL_Debug::start_timer();
        if ( ! current_user_can( 'manage_options' ) && ! MTL_Capabilities::user_can( 'mtl_import_data' ) ) { wp_die( 'Import access is not enabled.', 403 ); }
        $id = absint( $_POST['import_id'] ?? 0 );
        check_admin_referer( 'mtl_import_apply_' . $id );
        MTL_Debug::info( 'import', 'apply_start', 'Applying staged import.', array( 'import_id' => $id, 'user_id' => get_current_user_id() ) );
        $result = self::apply( $id );
        if ( is_wp_error( $result ) ) { MTL_Debug::wp_error( 'import', 'apply_error', $result, array( 'import_id' => $id, 'duration_ms' => MTL_Debug::duration_ms( $started ) ) ); self::redirect_error( $result->get_error_message(), $id ); }
        MTL_Debug::info( 'import', 'apply_complete', 'Staged import applied.', array( 'import_id' => $id, 'project_id' => $result['project_id'] ?? 0, 'already_applied' => ! empty( $result['already_applied'] ), 'duration_ms' => MTL_Debug::duration_ms( $started ) ) );
        wp_safe_redirect( admin_url( 'admin.php?page=merkaz-textlab-import&import_id=' . $id . '&applied=1&project_id=' . (int) $result['project_id'] ) );
        exit;
    }

    public static function handle_discard() {
        if ( ! current_user_can( 'manage_options' ) && ! MTL_Capabilities::user_can( 'mtl_import_data' ) ) { wp_die( 'Import access is not enabled.', 403 ); }
        $id = absint( $_POST['import_id'] ?? 0 );
        check_admin_referer( 'mtl_import_discard_' . $id );
        global $wpdb;
        $wpdb->delete( MTL_DB::table( 'imports' ), array( 'id' => $id, 'user_id' => get_current_user_id() ) );
        wp_safe_redirect( admin_url( 'admin.php?page=merkaz-textlab-import' ) );
        exit;
    }

    private static function redirect_error( $message, $import_id = 0 ) {
        MTL_Debug::warning( 'import', 'redirect_error', 'Import workflow redirected with an error.', array( 'message' => $message, 'import_id' => $import_id ) );
        $url = admin_url( 'admin.php?page=merkaz-textlab-import&mtl_error=' . rawurlencode( $message ) );
        if ( $import_id ) { $url .= '&import_id=' . absint( $import_id ); }
        wp_safe_redirect( $url ); exit;
    }

    public static function get_job( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MTL_DB::table( 'imports' ) . ' WHERE id=%d', $id ), ARRAY_A );
        if ( ! $row ) { return null; }
        if ( ! current_user_can( 'manage_options' ) && (int) $row['user_id'] !== get_current_user_id() ) { return null; }
        $row['preview'] = json_decode( $row['preview_json'] ?: '{}', true );
        return $row;
    }

    public static function apply( $id ) {
        global $wpdb;
        $job = self::get_job( $id );
        if ( ! $job ) { return new WP_Error( 'mtl_import_missing', 'Import preview not found.' ); }
        if ( 'applied' === $job['status'] ) { return array( 'project_id' => (int) $job['project_id'], 'already_applied' => true ); }
        $data = self::sanitize_preview( $job['preview'] );
        $uid = get_current_user_id();
        $project_id = (int) $job['project_id'];

        if ( ! $project_id ) {
            $now = current_time( 'mysql' );
            $wpdb->insert( MTL_DB::table( 'projects' ), array(
                'owner_user_id' => $uid,
                'title' => $data['project_title'] ?: pathinfo( $job['source_name'], PATHINFO_FILENAME ),
                'description' => $data['project_description'],
                'project_type' => 'user',
                'visibility' => 'private',
                'created_at' => $now,
                'updated_at' => $now,
            ) );
            $project_id = (int) $wpdb->insert_id;
        }
        if ( ! MTL_DB::role_can_edit( MTL_DB::access_role( $project_id ) ) ) { return new WP_Error( 'mtl_import_forbidden', 'You cannot apply this import to the selected project.' ); }

        $now = current_time( 'mysql' );
        foreach ( $data['segments'] as $i => $seg ) {
            if ( ! $seg['sefaria_ref'] ) { continue; }
            $key = $seg['segment_key'] ?: $seg['sefaria_ref'] . '::import-' . ( $i + 1 );
            $existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MTL_DB::table( 'segments' ) . ' WHERE project_id=%d AND segment_key=%s', $project_id, $key ) );
            $row = array(
                'project_id' => $project_id,
                'sefaria_ref' => $seg['sefaria_ref'],
                'segment_key' => $key,
                'segment_order' => (int) $seg['segment_order'],
                'source_hebrew' => $seg['source_hebrew'],
                'translation' => wp_kses_post( $seg['translation'] ),
                'translation_status' => sanitize_key( $seg['translation_status'] ?: 'draft' ),
                'translation_author_user_id' => $uid,
                'metadata_json' => $seg['metadata_json'],
                'updated_at' => $now,
            );
            if ( $existing ) {
                $old = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MTL_DB::table( 'segments' ) . ' WHERE id=%d', $existing ), ARRAY_A );
                MTL_DB::revision( $project_id, 'segment', $existing, 'import_update', $old, $uid );
                $wpdb->update( MTL_DB::table( 'segments' ), $row, array( 'id' => $existing ) );
            } else {
                $row['created_at'] = $now;
                $wpdb->insert( MTL_DB::table( 'segments' ), $row );
            }
        }

        $max_ann = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(sort_order),0) FROM ' . MTL_DB::table( 'annotations' ) . ' WHERE project_id=%d', $project_id ) );
        foreach ( $data['annotations'] as $i => $a ) {
            $wpdb->insert( MTL_DB::table( 'annotations' ), array(
                'project_id' => $project_id, 'user_id' => $uid,
                'segment_ref' => $a['segment_ref'], 'start_ref' => $a['start_ref'], 'end_ref' => $a['end_ref'],
                'annotation_type' => sanitize_key( $a['annotation_type'] ?: 'observation' ),
                'title' => $a['title'], 'body' => wp_kses_post( $a['body'] ),
                'tags_json' => wp_json_encode( $a['tags'] ), 'targets_json' => wp_json_encode( $a['targets'] ),
                'sort_order' => $max_ann + ( $i + 1 ) * 10,
                'created_at' => $now, 'updated_at' => $now,
            ) );
        }

        $max_out = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(sort_order),0) FROM ' . MTL_DB::table( 'outline_nodes' ) . ' WHERE project_id=%d', $project_id ) );
        foreach ( $data['outline'] as $i => $o ) {
            $wpdb->insert( MTL_DB::table( 'outline_nodes' ), array(
                'project_id' => $project_id, 'user_id' => $uid, 'parent_id' => null,
                'level' => max( 1, min( 8, (int) $o['level'] ) ), 'title' => $o['title'],
                'start_ref' => $o['start_ref'], 'end_ref' => $o['end_ref'],
                'sort_order' => $max_out + ( $i + 1 ) * 10,
                'created_at' => $now, 'updated_at' => $now,
            ) );
        }

        $wpdb->update( MTL_DB::table( 'projects' ), array( 'updated_at' => $now ), array( 'id' => $project_id ) );
        $wpdb->update( MTL_DB::table( 'imports' ), array( 'status' => 'applied', 'project_id' => $project_id, 'applied_at' => $now ), array( 'id' => $id ) );
        MTL_DB::revision( $project_id, 'project', $project_id, 'import_apply', array( 'import_id' => $id, 'source_name' => $job['source_name'], 'ai_used' => (bool) $job['ai_used'] ), $uid );
        return array( 'project_id' => $project_id );
    }

    public static function extract( $path, $ext, $name = '' ) {
        if ( 'xlsx' === $ext ) { return self::extract_xlsx( $path ); }
        if ( 'docx' === $ext ) { return self::extract_docx( $path ); }
        $text = file_get_contents( $path );
        if ( false === $text ) { return new WP_Error( 'mtl_import_read', 'Could not read the uploaded file.' ); }
        return array( 'text' => self::limit_text( $text ), 'warnings' => array() );
    }

    private static function extract_docx( $path ) {
        if ( ! class_exists( 'ZipArchive' ) ) { return new WP_Error( 'mtl_import_zip', 'DOCX import requires PHP ZipArchive.' ); }
        $zip = new ZipArchive();
        if ( true !== $zip->open( $path ) ) { return new WP_Error( 'mtl_import_docx', 'Could not open DOCX.' ); }
        $document = $zip->getFromName( 'word/document.xml' );
        if ( ! $document ) { $zip->close(); return new WP_Error( 'mtl_import_docx_xml', 'DOCX document.xml was not found.' ); }
        $styles_xml = $zip->getFromName( 'word/styles.xml' );
        $comments_xml = $zip->getFromName( 'word/comments.xml' );
        $zip->close();

        $styles = self::docx_styles( $styles_xml );
        $comments = self::docx_comments( $comments_xml );
        $dom = new DOMDocument();
        if ( ! @$dom->loadXML( $document ) ) { return new WP_Error( 'mtl_import_docx_parse', 'Could not parse DOCX XML.' ); }
        $xp = new DOMXPath( $dom );
        $xp->registerNamespace( 'w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' );
        $lines = array();
        foreach ( $xp->query( '//w:body//w:p' ) as $p ) {
            $texts = array();
            foreach ( $xp->query( './/w:t', $p ) as $t ) { $texts[] = $t->textContent; }
            $text = trim( implode( '', $texts ) );
            if ( '' === $text ) { continue; }
            $style_id = '';
            $node = $xp->query( './w:pPr/w:pStyle', $p )->item( 0 );
            if ( $node ) { $style_id = $node->getAttributeNS( 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val' ); }
            $level = '';
            $ilvl = $xp->query( './w:pPr/w:numPr/w:ilvl', $p )->item( 0 );
            if ( $ilvl ) { $level = $ilvl->getAttributeNS( 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val' ); }
            $prefix = '[PARAGRAPH';
            if ( $style_id ) { $prefix .= ' style="' . ( $styles[ $style_id ] ?? $style_id ) . '"'; }
            if ( '' !== $level ) { $prefix .= ' list-level="' . $level . '"'; }
            $prefix .= '] ';
            $line = $prefix . $text;
            $ids = array();
            foreach ( $xp->query( './/w:commentRangeStart', $p ) as $c ) {
                $ids[] = $c->getAttributeNS( 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'id' );
            }
            foreach ( array_unique( $ids ) as $cid ) {
                if ( isset( $comments[ $cid ] ) ) { $line .= "\n[WORD COMMENT $cid] " . $comments[ $cid ]; }
            }
            $lines[] = $line;
        }
        return array( 'text' => self::limit_text( implode( "\n", $lines ) ), 'warnings' => array( 'DOCX formatting is represented as paragraph styles/list levels; review the AI-import preview before applying.' ) );
    }

    private static function docx_styles( $xml ) {
        $out = array(); if ( ! $xml ) { return $out; }
        $dom = new DOMDocument(); if ( ! @$dom->loadXML( $xml ) ) { return $out; }
        $xp = new DOMXPath( $dom ); $xp->registerNamespace( 'w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' );
        foreach ( $xp->query( '//w:style' ) as $style ) {
            $id = $style->getAttributeNS( 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'styleId' );
            $name = $xp->query( './w:name', $style )->item( 0 );
            if ( $id && $name ) { $out[ $id ] = $name->getAttributeNS( 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val' ); }
        }
        return $out;
    }

    private static function docx_comments( $xml ) {
        $out = array(); if ( ! $xml ) { return $out; }
        $dom = new DOMDocument(); if ( ! @$dom->loadXML( $xml ) ) { return $out; }
        $xp = new DOMXPath( $dom ); $xp->registerNamespace( 'w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' );
        foreach ( $xp->query( '//w:comment' ) as $c ) {
            $id = $c->getAttributeNS( 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'id' );
            $parts = array(); foreach ( $xp->query( './/w:t', $c ) as $t ) { $parts[] = $t->textContent; }
            if ( '' !== $id ) { $out[ $id ] = trim( implode( '', $parts ) ); }
        }
        return $out;
    }

    private static function extract_xlsx( $path ) {
        if ( ! class_exists( 'ZipArchive' ) ) { return new WP_Error( 'mtl_import_zip', 'XLSX import requires PHP ZipArchive.' ); }
        $zip = new ZipArchive(); if ( true !== $zip->open( $path ) ) { return new WP_Error( 'mtl_import_xlsx', 'Could not open XLSX.' ); }
        $shared = self::xlsx_shared_strings( $zip->getFromName( 'xl/sharedStrings.xml' ) );
        $sheets = self::xlsx_sheet_paths( $zip );
        $chosen = null; $rows = array();
        foreach ( $sheets as $sheet_name => $sheet_path ) {
            $xml = $zip->getFromName( $sheet_path ); if ( ! $xml ) { continue; }
            $candidate = self::xlsx_rows( $xml, $shared );
            foreach ( $candidate as $rnum => $row ) {
                $vals = array_values( $row );
                if ( in_array( 'Swafford Translation', $vals, true ) && in_array( 'Masoretic Hebrew', $vals, true ) ) {
                    $chosen = array( $sheet_name, $rnum ); $rows = $candidate; break 2;
                }
            }
        }
        if ( $chosen ) {
            MTL_Debug::info( 'import', 'xlsx_template_recognized', 'Recognized TextLab side-by-side workbook structure.', array( 'sheet' => $chosen[0], 'header_row' => $chosen[1], 'row_count' => count( $rows ) ) );
            $zip->close();
            return self::xlsx_textlab_preview( $rows, $chosen[1], $chosen[0] );
        }

        MTL_Debug::warning( 'import', 'xlsx_template_unrecognized', 'XLSX did not match the known TextLab side-by-side template; using generic extraction.', array( 'sheets' => array_keys( $sheets ) ) );

        // Generic workbook representation for optional AI structuring.
        $text = array();
        foreach ( $sheets as $sheet_name => $sheet_path ) {
            $xml = $zip->getFromName( $sheet_path ); if ( ! $xml ) { continue; }
            $candidate = self::xlsx_rows( $xml, $shared );
            $text[] = "[SHEET: $sheet_name]";
            foreach ( array_slice( $candidate, 0, 500, true ) as $rnum => $row ) {
                $pairs = array(); foreach ( $row as $col => $v ) { if ( '' !== trim( (string) $v ) ) { $pairs[] = $col . '=' . str_replace( array( "\r", "\n" ), ' ', (string) $v ); } }
                if ( $pairs ) { $text[] = '[ROW ' . $rnum . '] ' . implode( ' | ', $pairs ); }
            }
        }
        $zip->close();
        return array( 'text' => self::limit_text( implode( "\n", $text ) ), 'warnings' => array( 'XLSX structure was not recognized as the TextLab side-by-side template; AI structuring is recommended.' ) );
    }

    private static function xlsx_shared_strings( $xml ) {
        $out = array(); if ( ! $xml ) { return $out; }
        $dom = new DOMDocument(); if ( ! @$dom->loadXML( $xml ) ) { return $out; }
        $xp = new DOMXPath( $dom ); $xp->registerNamespace( 's', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
        foreach ( $xp->query( '//s:si' ) as $si ) {
            $parts = array(); foreach ( $xp->query( './/s:t', $si ) as $t ) { $parts[] = $t->textContent; }
            $out[] = implode( '', $parts );
        }
        return $out;
    }

    private static function xlsx_sheet_paths( $zip ) {
        $workbook = $zip->getFromName( 'xl/workbook.xml' );
        $rels = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
        $out = array(); if ( ! $workbook || ! $rels ) { return $out; }
        $rel_dom = new DOMDocument(); @$rel_dom->loadXML( $rels ); $relxp = new DOMXPath( $rel_dom );
        $relmap = array(); foreach ( $relxp->query( '//*[local-name()="Relationship"]' ) as $r ) { $relmap[ $r->getAttribute( 'Id' ) ] = $r->getAttribute( 'Target' ); }
        $dom = new DOMDocument(); @$dom->loadXML( $workbook ); $xp = new DOMXPath( $dom );
        foreach ( $xp->query( '//*[local-name()="sheet"]' ) as $s ) {
            $name = $s->getAttribute( 'name' ); $rid = $s->getAttributeNS( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id' );
            if ( isset( $relmap[ $rid ] ) ) {
                $target = ltrim( str_replace( '../', '', $relmap[ $rid ] ), '/' );
                if ( 0 !== strpos( $target, 'xl/' ) ) { $target = 'xl/' . $target; }
                $out[ $name ] = $target;
            }
        }
        return $out;
    }

    private static function xlsx_rows( $xml, $shared ) {
        $rows = array(); $dom = new DOMDocument(); if ( ! @$dom->loadXML( $xml ) ) { return $rows; }
        $xp = new DOMXPath( $dom ); $xp->registerNamespace( 's', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
        foreach ( $xp->query( '//s:sheetData/s:row' ) as $row ) {
            $rnum = (int) $row->getAttribute( 'r' ); $vals = array();
            foreach ( $xp->query( './s:c', $row ) as $c ) {
                $ref = $c->getAttribute( 'r' ); preg_match( '/^[A-Z]+/', $ref, $m ); $col = $m[0] ?? $ref;
                $type = $c->getAttribute( 't' ); $value = '';
                if ( 'inlineStr' === $type ) {
                    $parts = array(); foreach ( $xp->query( './/s:is//s:t', $c ) as $t ) { $parts[] = $t->textContent; } $value = implode( '', $parts );
                } else {
                    $v = $xp->query( './s:v', $c )->item( 0 ); $raw = $v ? $v->textContent : '';
                    $value = 's' === $type && isset( $shared[ (int) $raw ] ) ? $shared[ (int) $raw ] : $raw;
                }
                $vals[ $col ] = $value;
            }
            if ( $vals ) { $rows[ $rnum ] = $vals; }
        }
        return $rows;
    }

    private static function xlsx_textlab_preview( $rows, $header_row, $sheet_name ) {
        $headers = array();
        foreach ( $rows[ $header_row ] as $col => $value ) { $headers[ trim( (string) $value ) ] = $col; }
        $need = array( 'Parshah','Traditional Chapter','English Classic','Heading Level','Proposed Chapter','Proposed Verse','Reset Hebrew','Masoretic Hebrew','Swafford Translation' );
        foreach ( $need as $n ) { if ( ! isset( $headers[ $n ] ) ) { return array( 'text' => '', 'warnings' => array( 'Recognized workbook was missing expected column: ' . $n ) ); } }

        // A workbook can label rows by parashah rather than repeating the biblical book.
        // The first parashah of each Torah book is enough to update the carried book context.
        $book_map = array(
            'bereshit'=>'Genesis','bereishit'=>'Genesis','genesis'=>'Genesis',
            'shemot'=>'Exodus','exodus'=>'Exodus',
            'vayikra'=>'Leviticus','leviticus'=>'Leviticus',
            'bamidbar'=>'Numbers','numbers'=>'Numbers',
            'devarim'=>'Deuteronomy','deuteronomy'=>'Deuteronomy',
        );
        $hebrew_value = function( $text ) {
            $values = array( 'א'=>1,'ב'=>2,'ג'=>3,'ד'=>4,'ה'=>5,'ו'=>6,'ז'=>7,'ח'=>8,'ט'=>9,'י'=>10,'כ'=>20,'ך'=>20,'ל'=>30,'מ'=>40,'ם'=>40,'נ'=>50,'ן'=>50,'ס'=>60,'ע'=>70,'פ'=>80,'ף'=>80,'צ'=>90,'ץ'=>90,'ק'=>100,'ר'=>200,'ש'=>300,'ת'=>400 );
            $sum = 0;
            preg_match_all( '/[א-ת]/u', (string) $text, $matches );
            foreach ( $matches[0] as $char ) { $sum += $values[ $char ] ?? 0; }
            return $sum;
        };

        $preview = self::empty_preview();
        $preview['project_title'] = 'Imported Biblical Text Project';
        $counter_by_ref = array();
        $order = 10;
        $current_book = '';
        $current_chapter = 0;
        $current_verse = 0;

        foreach ( $rows as $rnum => $row ) {
            if ( $rnum <= $header_row ) { continue; }
            $get = function( $name ) use ( $row, $headers ) { $col = $headers[ $name ] ?? ''; return $col && isset( $row[ $col ] ) ? trim( (string) $row[ $col ] ) : ''; };
            $parshah = $get( 'Parshah' );
            $chapter = $get( 'Traditional Chapter' );
            $verse = $get( 'English Classic' );
            $translation = $get( 'Swafford Translation' );
            $reset_he = $get( 'Reset Hebrew' );
            $masoretic = $get( 'Masoretic Hebrew' );
            if ( ! $parshah && ! $chapter && ! $verse && ! $translation && ! $reset_he && ! $masoretic ) { continue; }

            $par_key = strtolower( preg_replace( '/[^a-z]/', '', remove_accents( $parshah ) ) );
            if ( isset( $book_map[ $par_key ] ) ) { $current_book = $book_map[ $par_key ]; }
            elseif ( in_array( $parshah, array_values( $book_map ), true ) ) { $current_book = $parshah; }

            if ( is_numeric( $chapter ) ) { $current_chapter = (int) $chapter; }
            if ( is_numeric( $verse ) ) {
                $current_verse = (int) $verse;
            } elseif ( $masoretic && preg_match( '/^\s*\(([א-ת״׳\"\']+)\)/u', $masoretic, $match ) ) {
                $parsed_verse = $hebrew_value( $match[1] );
                if ( $parsed_verse ) { $current_verse = $parsed_verse; }
            }

            // Rows that continue a verse often omit chapter/verse entirely; carry the last explicit reference.
            if ( ! $current_book || ! $current_chapter || ! $current_verse ) { continue; }
            $ref = $current_book . ' ' . $current_chapter . ':' . $current_verse;
            $counter_by_ref[ $ref ] = ( $counter_by_ref[ $ref ] ?? 0 ) + 1;
            $meta = array(
                'import_sheet' => $sheet_name,
                'import_row' => $rnum,
                'parshah' => $parshah,
                'traditional_chapter' => $current_chapter,
                'traditional_verse' => $current_verse,
                'heading_level' => $get( 'Heading Level' ),
                'proposed_chapter' => $get( 'Proposed Chapter' ),
                'proposed_verse' => $get( 'Proposed Verse' ),
                'masoretic_hebrew' => $masoretic,
            );
            $preview['segments'][] = array(
                'sefaria_ref' => $ref,
                'segment_key' => $ref . '::import-' . str_pad( (string) $counter_by_ref[ $ref ], 3, '0', STR_PAD_LEFT ),
                'segment_order' => $order,
                'source_hebrew' => $reset_he ?: $masoretic,
                'translation' => $translation,
                'translation_status' => 'draft',
                'metadata_json' => wp_json_encode( $meta ),
            );
            $order += 10;
        }
        $preview['warnings'][] = 'Imported only the user-created Swafford Translation, user-defined Hebrew segmentation, and structural metadata. Third-party comparison translations in the workbook were intentionally not copied into TextLab.';
        $preview['warnings'][] = 'Traditional chapter/verse values are carried forward on continuation rows; where a Masoretic row begins with a parenthesized Hebrew verse number, that number is used to restore an omitted verse value.';
        return array( 'structured' => $preview, 'deterministic_complete' => true, 'warnings' => $preview['warnings'], 'text' => '' );
    }

    private static function raw_preview( $text, $name ) {
        $p = self::empty_preview();
        $p['project_title'] = pathinfo( $name, PATHINFO_FILENAME );
        $p['annotations'][] = array( 'segment_ref'=>'','start_ref'=>'','end_ref'=>'','annotation_type'=>'imported_raw','title'=>'Raw imported material','body'=>self::limit_text($text,120000),'tags'=>array('imported','needs-review'),'targets'=>array('research'),'sort_order'=>10 );
        return $p;
    }

    public static function empty_preview() { return array( 'project_title'=>'','project_description'=>'','segments'=>array(),'annotations'=>array(),'outline'=>array(),'warnings'=>array() ); }

    private static function merge_previews( $a, $b ) {
        $out = self::empty_preview();
        $out['project_title'] = $a['project_title'] ?: ( $b['project_title'] ?? '' );
        $out['project_description'] = trim( ( $a['project_description'] ?? '' ) . ( ! empty( $b['project_description'] ) ? "\n" . $b['project_description'] : '' ) );
        foreach ( array( 'segments','annotations','outline','warnings' ) as $k ) { $out[ $k ] = array_merge( (array) ( $a[ $k ] ?? array() ), (array) ( $b[ $k ] ?? array() ) ); }
        return $out;
    }

    private static function sanitize_preview( $p ) {
        $out = self::empty_preview();
        $out['project_title'] = sanitize_text_field( $p['project_title'] ?? '' );
        $out['project_description'] = sanitize_textarea_field( $p['project_description'] ?? '' );
        foreach ( (array) ( $p['segments'] ?? array() ) as $i => $s ) {
            $out['segments'][] = array(
                'sefaria_ref'=>sanitize_text_field($s['sefaria_ref']??''), 'segment_key'=>sanitize_text_field($s['segment_key']??''),
                'segment_order'=>(($i+1)*10), 'source_hebrew'=>sanitize_textarea_field($s['source_hebrew']??''),
                'translation'=>wp_kses_post($s['translation']??''), 'translation_status'=>sanitize_key($s['translation_status']??'draft'),
                'metadata_json'=>is_string($s['metadata_json']??null)?wp_kses_post($s['metadata_json']):wp_json_encode($s['metadata_json']??array()),
            );
        }
        foreach ( (array) ( $p['annotations'] ?? array() ) as $i => $a ) {
            $out['annotations'][] = array(
                'segment_ref'=>sanitize_text_field($a['segment_ref']??''),'start_ref'=>sanitize_text_field($a['start_ref']??''),'end_ref'=>sanitize_text_field($a['end_ref']??''),
                'annotation_type'=>sanitize_key($a['annotation_type']??'observation'),'title'=>sanitize_text_field($a['title']??''),'body'=>wp_kses_post($a['body']??''),
                'tags'=>array_values(array_map('sanitize_text_field',(array)($a['tags']??array()))),'targets'=>array_values(array_map('sanitize_key',(array)($a['targets']??array('research')))),
                'sort_order'=>(($i+1)*10),
            );
        }
        foreach ( (array) ( $p['outline'] ?? array() ) as $i => $o ) {
            $out['outline'][] = array( 'level'=>max(1,min(8,(int)($o['level']??1))),'title'=>sanitize_text_field($o['title']??''),'start_ref'=>sanitize_text_field($o['start_ref']??''),'end_ref'=>sanitize_text_field($o['end_ref']??''),'sort_order'=>(($i+1)*10) );
        }
        $out['warnings'] = array_values( array_map( 'sanitize_text_field', (array) ( $p['warnings'] ?? array() ) ) );
        return $out;
    }

    private static function chunk_text( $text, $max_chars ) {
        $text = (string) $text; $chunks = array();
        while ( strlen( $text ) > $max_chars ) {
            $cut = strrpos( substr( $text, 0, $max_chars ), "\n" ); if ( false === $cut || $cut < $max_chars * 0.6 ) { $cut = $max_chars; }
            $chunks[] = substr( $text, 0, $cut ); $text = ltrim( substr( $text, $cut ) );
        }
        if ( '' !== trim( $text ) ) { $chunks[] = $text; }
        return $chunks ?: array( '' );
    }

    private static function limit_text( $text, $max = 400000 ) { $text = (string) $text; return strlen( $text ) > $max ? substr( $text, 0, $max ) . "\n[TRUNCATED BY IMPORTER]" : $text; }
}
