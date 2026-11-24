<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MTL_Export {
    public static function init() { add_action( 'admin_post_mtl_export', array( __CLASS__, 'handle' ) ); }

    public static function handle() {
        $started = MTL_Debug::start_timer();
        if ( ! is_user_logged_in() ) { auth_redirect(); }
        check_admin_referer( 'mtl_export', '_wpnonce' );
        $project_id = absint( $_GET['project_id'] ?? 0 );
        if ( ! MTL_DB::access_role( $project_id ) ) { wp_die( 'You do not have access to this project.', 403 ); }
        $format = sanitize_key( $_GET['format'] ?? 'docx' );
        $profile = sanitize_key( $_GET['profile'] ?? 'homework' );
        $advanced = 'manuscript' === $profile;
        if ( $advanced && ( ! MTL_Capabilities::user_can( 'mtl_advanced_export' ) || ! MTL_Capabilities::user_can( 'mtl_manuscript_read' ) ) ) { wp_die( 'Manuscript export is not enabled for this account.', 403 ); }
        if ( ! $advanced && ! MTL_Capabilities::user_can( 'mtl_export_homework' ) ) { wp_die( 'Export is not enabled for this account.', 403 ); }
        $options = array(
            'profile'=>$profile,
            'include_outline'=>!isset($_GET['outline']) || '0'!==$_GET['outline'],
            'include_annotations'=>isset($_GET['annotations']) && '1'===$_GET['annotations'],
            'include_translation'=>isset($_GET['translation']) && '1'===$_GET['translation'] && ( MTL_Capabilities::user_can( 'mtl_translate' ) || MTL_Capabilities::user_can( 'mtl_manuscript_read' ) ),
            'include_hebrew'=>isset($_GET['hebrew']) && '1'===$_GET['hebrew'] && ( MTL_Capabilities::user_can( 'mtl_translate' ) || MTL_Capabilities::user_can( 'mtl_manuscript_read' ) ),
            'include_sources'=>isset($_GET['sources']) && '1'===$_GET['sources'] && ( MTL_Capabilities::user_can( 'mtl_sources' ) || MTL_Capabilities::user_can( 'mtl_manuscript_read' ) ),
        );
        MTL_Debug::info( 'export', 'request_start', 'Export requested.', array( 'project_id' => $project_id, 'format' => $format, 'profile' => $profile, 'options' => $options ) );
        $doc = self::document_model( $project_id, $options );
        MTL_Debug::debug( 'export', 'document_model_ready', 'Export document model assembled.', array( 'project_id' => $project_id, 'outline_nodes' => count( $doc['outline'] ), 'segments' => count( $doc['segments'] ), 'annotations' => count( $doc['annotations'] ), 'sources' => count( $doc['sources'] ), 'duration_ms' => MTL_Debug::duration_ms( $started ) ) );
        $slug = sanitize_file_name( $doc['title'] ?: 'textlab-export' );
        if ( 'rtf' === $format ) { MTL_Debug::info( 'export', 'rtf_start', 'Generating RTF export.', array( 'project_id' => $project_id, 'filename' => $slug . '.rtf' ) ); self::send_rtf( $doc, $slug . '.rtf' ); }
        if ( 'pdf' === $format ) {
            $html = self::document_html( $doc, false );
            MTL_Debug::info( 'export', 'pdf_start', 'Generating PDF export.', array( 'project_id' => $project_id, 'filename' => $slug . '.pdf', 'engine' => MTL_PDF::detect_engine() ) );
            if ( MTL_PDF::send_pdf( $html, $slug . '.pdf' ) ) { exit; }
            MTL_Debug::warning( 'export', 'pdf_fallback', 'No direct PDF engine available; using browser print fallback.', array( 'project_id' => $project_id ) );
            self::send_print_html( $doc );
        }
        if ( 'print' === $format ) { MTL_Debug::info( 'export', 'print_start', 'Generating print view export.', array( 'project_id' => $project_id ) ); self::send_print_html( $doc ); }
        MTL_Debug::info( 'export', 'docx_start', 'Generating DOCX export.', array( 'project_id' => $project_id, 'filename' => $slug . '.docx' ) );
        self::send_docx( $doc, $slug . '.docx' );
    }

    public static function document_model( $project_id, $options ) {
        global $wpdb;
        $project = MTL_DB::project( $project_id );
        $outline = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM '.MTL_DB::table('outline_nodes').' WHERE project_id=%d ORDER BY sort_order,id', $project_id ), ARRAY_A );
        $segments = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM '.MTL_DB::table('segments').' WHERE project_id=%d ORDER BY segment_order,id', $project_id ), ARRAY_A );
        $annotations_raw = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM '.MTL_DB::table('annotations').' WHERE project_id=%d ORDER BY sort_order,id', $project_id ), ARRAY_A );
        $target = 'manuscript' === ( $options['profile'] ?? 'homework' ) ? 'book' : 'homework';
        $annotations = array();
        foreach ( $annotations_raw as $annotation ) {
            $targets = json_decode( (string) ( $annotation['targets_json'] ?? '' ), true );
            if ( ! is_array( $targets ) || ! $targets ) { $targets = array( 'homework' ); }
            if ( in_array( $target, $targets, true ) ) { $annotations[] = $annotation; }
        }
        $sources = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM '.MTL_DB::table('sources').' WHERE project_id=%d ORDER BY id', $project_id ), ARRAY_A );
        return array( 'title'=>$project['title'],'description'=>$project['description'],'options'=>$options,'outline'=>$outline,'segments'=>$segments,'annotations'=>$annotations,'sources'=>$sources );
    }

    private static function roman( $n ) { $map=array(1000=>'M',900=>'CM',500=>'D',400=>'CD',100=>'C',90=>'XC',50=>'L',40=>'XL',10=>'X',9=>'IX',5=>'V',4=>'IV',1=>'I');$r='';foreach($map as $v=>$s){while($n>=$v){$r.=$s;$n-=$v;}}return $r; }
    private static function label_for( $level, $n ) { if(1===$level)return self::roman($n).'.';if(2===$level)return chr(64+(($n-1)%26)+1).'.';if(3===$level)return $n.'.';if(4===$level)return chr(96+(($n-1)%26)+1).'.';return strtolower(self::roman($n)).'.'; }
    private static function numbered_outline( $rows ) { $c=array_fill(0,10,0);$out=array();foreach($rows as $row){$l=max(1,min(8,(int)$row['level']));$c[$l]++;for($i=$l+1;$i<10;$i++)$c[$i]=0;$range=trim(($row['start_ref']?:'').($row['end_ref']&&$row['end_ref']!==$row['start_ref']?'–'.$row['end_ref']:''));$out[]=array_merge($row,array('label'=>self::label_for($l,$c[$l]),'range'=>$range));}return $out; }
    private static function xml( $s ) { return htmlspecialchars( wp_strip_all_tags( (string)$s ), ENT_XML1|ENT_QUOTES, 'UTF-8' ); }

    private static function docx_p( $text, $style='', $rtl=false, $indent=0, $bold=false ) {
        $ppr='';if($style)$ppr.='<w:pStyle w:val="'.self::xml($style).'"/>';if($indent)$ppr.='<w:ind w:left="'.(int)$indent.'"/>';if($rtl)$ppr.='<w:bidi/>';
        $rpr=$bold?'<w:b/>':'';if($rtl)$rpr.='<w:rtl/>';
        return '<w:p><w:pPr>'.$ppr.'</w:pPr><w:r><w:rPr>'.$rpr.'</w:rPr><w:t xml:space="preserve">'.self::xml($text).'</w:t></w:r></w:p>';
    }

    private static function docx_body( $doc ) {
        $o=$doc['options'];$xml=self::docx_p($doc['title'],'Title',false,0,true);if($doc['description'])$xml.=self::docx_p($doc['description'],'Subtitle');
        if($o['include_outline']){$xml.=self::docx_p('Outline','Heading1',false,0,true);foreach(self::numbered_outline($doc['outline']) as $row){$text=$row['label'].' '.$row['title'].($row['range']?' ('.$row['range'].')':'');$xml.=self::docx_p($text,'Outline'.min(5,(int)$row['level']),false,((int)$row['level']-1)*360);}}
        if($o['include_hebrew']||$o['include_translation']){$xml.=self::docx_p('Text and Translation','Heading1',false,0,true);$last='';foreach($doc['segments'] as $seg){if($seg['sefaria_ref']!==$last){$xml.=self::docx_p($seg['sefaria_ref'],'Heading2',false,0,true);$last=$seg['sefaria_ref'];}if($o['include_hebrew']&&$seg['source_hebrew'])$xml.=self::docx_p($seg['source_hebrew'],'Hebrew',true);if($o['include_translation']&&$seg['translation'])$xml.=self::docx_p($seg['translation'],'Translation');}}
        if($o['include_annotations']){$xml.=self::docx_p('Commentary and Notes','Heading1',false,0,true);foreach($doc['annotations'] as $a){$head=trim(($a['title']?:ucfirst($a['annotation_type'])).($a['start_ref']?' — '.$a['start_ref']:''));$xml.=self::docx_p($head,'Heading3',false,0,true);$xml.=self::docx_p($a['body'],'Annotation');}}
        if($o['include_sources']){$xml.=self::docx_p('Sources','Heading1',false,0,true);foreach($doc['sources'] as $s){$line=trim(($s['source_ref']?:$s['title']).($s['version_title']?' — '.$s['version_title']:'').($s['license']?' ['.$s['license'].']':''));$xml.=self::docx_p($line,'Bibliography');}}
        return $xml;
    }

    private static function send_docx( $doc, $filename ) {
        if(!class_exists('ZipArchive'))wp_die('DOCX export requires PHP ZipArchive.');
        $tmp=wp_tempnam($filename);$zip=new ZipArchive();if(true!==$zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE))wp_die('Could not create DOCX archive.');
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:sz w:val="24"/></w:rPr></w:style>'
        .'<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:style>'
        .'<w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:rPr><w:i/><w:color w:val="666666"/></w:rPr></w:style>'
        .'<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:rPr><w:b/><w:sz w:val="30"/></w:rPr><w:pPr><w:keepNext/></w:pPr></w:style>'
        .'<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:rPr><w:b/><w:sz w:val="26"/></w:rPr><w:pPr><w:keepNext/></w:pPr></w:style>'
        .'<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:rPr><w:b/></w:rPr><w:pPr><w:keepNext/></w:pPr></w:style>'
        .'<w:style w:type="paragraph" w:styleId="Translation"><w:name w:val="Translation"/></w:style>'
        .'<w:style w:type="paragraph" w:styleId="Hebrew"><w:name w:val="Biblical Hebrew"/><w:rPr><w:rtl/><w:sz w:val="28"/></w:rPr></w:style>'
        .'<w:style w:type="paragraph" w:styleId="Annotation"><w:name w:val="Annotation"/></w:style>'
        .'<w:style w:type="paragraph" w:styleId="Bibliography"><w:name w:val="Bibliography"/><w:pPr><w:ind w:hanging="360"/></w:pPr></w:style>';
        for($i=1;$i<=5;$i++)$styles.='<w:style w:type="paragraph" w:styleId="Outline'.$i.'"><w:name w:val="Outline Level '.$i.'"/><w:pPr><w:keepNext/></w:pPr></w:style>';$styles.='</w:styles>';
        $zip->addFromString('word/styles.xml',$styles);
        $body=self::docx_body($doc);$document='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr></w:body></w:document>';
        $zip->addFromString('word/document.xml',$document);$zip->close();
        MTL_Debug::info( 'export', 'docx_ready', 'DOCX archive generated.', array( 'filename' => $filename, 'bytes' => file_exists( $tmp ) ? filesize( $tmp ) : 0 ) );
        nocache_headers();header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');header('Content-Disposition: attachment; filename="'.rawurlencode($filename).'"');header('Content-Length: '.filesize($tmp));readfile($tmp);@unlink($tmp);exit;
    }

    private static function utf8_rtf( $text ) {
        $text=wp_strip_all_tags((string)$text);$out='';$len=mb_strlen($text,'UTF-8');for($i=0;$i<$len;$i++){$ch=mb_substr($text,$i,1,'UTF-8');$cp=mb_ord($ch,'UTF-8');if($cp===92||$cp===123||$cp===125)$out.='\\'.$ch;elseif($cp>=32&&$cp<=126)$out.=$ch;elseif($cp<=65535){$signed=$cp>32767?$cp-65536:$cp;$out.='\\u'.$signed.'?';}else{$u=$cp-0x10000;$hi=0xD800+($u>>10);$lo=0xDC00+($u&0x3FF);$out.='\\u'.($hi>32767?$hi-65536:$hi).'?\\u'.($lo>32767?$lo-65536:$lo).'?';}}return $out;
    }
    private static function send_rtf( $doc, $filename ) {
        if(!function_exists('mb_strlen')||!function_exists('mb_ord')){ MTL_Debug::error( 'export', 'rtf_missing_mbstring', 'RTF export requires mbstring.' ); wp_die('RTF Unicode export requires PHP mbstring.'); }$o=$doc['options'];$rtf='{\\rtf1\\ansi\\deff0{\\fonttbl{\\f0 Times New Roman;}{\\f1 Arial;}}\\paperw12240\\paperh15840\\margl1440\\margr1440\\margt1440\\margb1440\n';$rtf.='\\fs36\\b '.self::utf8_rtf($doc['title']).'\\b0\\fs24\\par\n';if($doc['description'])$rtf.='\\i '.self::utf8_rtf($doc['description']).'\\i0\\par\n';if($o['include_outline']){$rtf.='\\fs30\\b Outline\\b0\\fs24\\par\n';foreach(self::numbered_outline($doc['outline']) as $row){$indent=((int)$row['level']-1)*360;$line=$row['label'].' '.$row['title'].($row['range']?' ('.$row['range'].')':'');$rtf.='\\li'.$indent.' '.self::utf8_rtf($line).'\\par\n';}}if($o['include_hebrew']||$o['include_translation']){$rtf.='\\fs30\\b Text and Translation\\b0\\fs24\\par\n';$last='';foreach($doc['segments'] as $seg){if($seg['sefaria_ref']!==$last){$rtf.='\\b '.self::utf8_rtf($seg['sefaria_ref']).'\\b0\\par\n';$last=$seg['sefaria_ref'];}if($o['include_hebrew']&&$seg['source_hebrew'])$rtf.='\\rtlpar '.self::utf8_rtf($seg['source_hebrew']).'\\ltrpar\\par\n';if($o['include_translation']&&$seg['translation'])$rtf.=self::utf8_rtf($seg['translation']).'\\par\n';}}if($o['include_annotations']){$rtf.='\\fs30\\b Commentary and Notes\\b0\\fs24\\par\n';foreach($doc['annotations'] as $a)$rtf.='\\b '.self::utf8_rtf($a['title']?:ucfirst($a['annotation_type'])).'\\b0\\par '.self::utf8_rtf($a['body']).'\\par\n';}$rtf.='}';nocache_headers();header('Content-Type: application/rtf; charset=UTF-8');header('Content-Disposition: attachment; filename="'.rawurlencode($filename).'"');echo $rtf;exit;
    }

    public static function document_html( $doc, $with_toolbar=false ) {
        $o=$doc['options'];ob_start();?><!doctype html><html><head><meta charset="utf-8"><style>@page{size:Letter;margin:1in}body{font:12pt/1.45 Georgia,"Times New Roman",serif;color:#111}h1{font-size:20pt}h2{font-size:15pt;break-after:avoid}h3{font-size:12pt;break-after:avoid}.outline{margin:.18em 0;break-inside:avoid}.he{direction:rtl;text-align:right;font-size:16pt;line-height:1.65;font-family:"Noto Serif Hebrew","DejaVu Sans",serif}.ref{font:9pt Arial,sans-serif;color:#666;text-transform:uppercase;margin-top:1em}.note{margin:.5em 0 1em;border-left:2px solid #999;padding-left:.7em}.toolbar{position:sticky;top:0;background:#fff;padding:10px;border-bottom:1px solid #ddd;font:10pt Arial,sans-serif}@media print{.toolbar{display:none}}</style></head><body><?php if($with_toolbar):?><div class="toolbar"><button onclick="window.print()">Print / Save as PDF</button></div><?php endif;?><h1><?php echo esc_html($doc['title']);?></h1><?php if($doc['description']):?><p><?php echo esc_html($doc['description']);?></p><?php endif;?><?php if($o['include_outline']):?><h2>Outline</h2><?php foreach(self::numbered_outline($doc['outline']) as $row):?><div class="outline" style="margin-left:<?php echo esc_attr(((int)$row['level']-1)*1.35);?>em"><?php echo esc_html($row['label'].' '.$row['title'].($row['range']?' ('.$row['range'].')':''));?></div><?php endforeach;?><?php endif;?><?php if($o['include_hebrew']||$o['include_translation']):?><h2>Text and Translation</h2><?php $last='';foreach($doc['segments'] as $seg):if($seg['sefaria_ref']!==$last):$last=$seg['sefaria_ref'];?><div class="ref"><?php echo esc_html($seg['sefaria_ref']);?></div><?php endif;if($o['include_hebrew']&&$seg['source_hebrew']):?><div class="he"><?php echo esc_html($seg['source_hebrew']);?></div><?php endif;if($o['include_translation']&&$seg['translation']):?><p><?php echo wp_kses_post($seg['translation']);?></p><?php endif;endforeach;?><?php endif;?><?php if($o['include_annotations']):?><h2>Commentary and Notes</h2><?php foreach($doc['annotations'] as $a):?><div class="note"><strong><?php echo esc_html($a['title']?:ucfirst($a['annotation_type']));?></strong><?php if($a['start_ref']):?> <small><?php echo esc_html($a['start_ref']);?></small><?php endif;?><div><?php echo wp_kses_post($a['body']);?></div></div><?php endforeach;?><?php endif;?><?php if($o['include_sources']):?><h2>Sources</h2><?php foreach($doc['sources'] as $s):?><p><?php echo esc_html(trim(($s['source_ref']?:$s['title']).($s['version_title']?' — '.$s['version_title']:'').($s['license']?' ['.$s['license'].']':'')));?></p><?php endforeach;?><?php endif;?></body></html><?php return ob_get_clean();
    }
    private static function send_print_html( $doc ) { echo self::document_html($doc,true);exit; }
}
