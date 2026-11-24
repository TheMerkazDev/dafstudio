<?php
/** Strict data boundary. Text is stored as plain text plus validated inline-format ranges, never executable HTML. */
if (!defined('ABSPATH')) { exit; }
function daf_fail($message, $status = 400, $code = 'daf_invalid') {
    return new WP_Error($code, $message, array('status' => $status));
}
function daf_len($value) {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : preg_match_all('/./us', $value);
}
function daf_plain($value, $max, $name) {
    if (!is_string($value)) { return daf_fail($name . ' must be text.'); }
    if (!preg_match('//u', $value)) { return daf_fail('Invalid UTF-8 in ' . $name); }
    $value = str_replace(array("\r\n", "\r"), "\n", $value);
    $value = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{202A}-\x{202E}]/u', '', $value);
    if (daf_len($value) > $max) { return daf_fail($name . ' is too long. Nothing has been saved.'); }
    return $value;
}
function daf_url($value) {
    if ($value === '' || $value === null) { return ''; }
    if (!is_string($value) || strlen($value) > 2000 || !filter_var($value, FILTER_VALIDATE_URL)) { return ''; }
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    return in_array($scheme, array('https', 'http'), true) ? $value : '';
}
function daf_validate_document($input, $plan) {
    if (!is_array($input) || ($input['schemaVersion'] ?? null) !== 1 || !isset($input['blocks']) || !is_array($input['blocks'])) {
        return daf_fail('Expected a Daf Studio schemaVersion 1 document.');
    }
    $max_blocks = $plan === 'pro' ? 100 : 20;
    $max_chars = $plan === 'pro' ? 300000 : 60000;
    if (count($input['blocks']) > $max_blocks) { return daf_fail('This plan allows ' . $max_blocks . ' sources per sheet.', 403, 'daf_plan_limit'); }
    $out = array('schemaVersion' => 1, 'blocks' => array());
    foreach (array('title', 'subtitle', 'author') as $key) {
        $out[$key] = daf_plain($input[$key] ?? '', 240, $key);
        if (is_wp_error($out[$key])) { return $out[$key]; }
    }
    foreach (array('titleUrl', 'authorUrl') as $key) { $out[$key] = daf_url($input[$key] ?? ''); }
    $s = is_array($input['settings'] ?? null) ? $input['settings'] : array();
    $settings = array();
    $enums = array('paper'=>array('halfletter','letter','legal','tabloid','a5','a4','b5','a3'), 'layout'=>array('stepped','columns','reading'),
        'overflow'=>array('continue','shrink','endnotes','strict'), 'side'=>array('a','b'), 'numbering'=>array('daf','page'),
        'latin'=>array('Arial','Times New Roman','Crimson Text','EB Garamond','Roboto','Calibri','Georgia','Verdana'),
        'hebrew'=>array('Ezra SIL','SBL Hebrew','David','Frank Ruhl Libre','Noto Rashi Hebrew','Noto Sans Hebrew','Noto Serif Hebrew'));
    foreach ($enums as $key=>$values) { $settings[$key] = in_array($s[$key] ?? null, $values, true) ? $s[$key] : $values[0]; }
    $numbers = array('margin'=>array(20,65,34), 'mainSize'=>array(12,28,16), 'noteSize'=>array(11,24,14), 'minSize'=>array(10,20,12),
        'leading'=>array(1.25,2,1.48), 'gap'=>array(8,30,15), 'headerSize'=>array(18,38,28), 'folio'=>array(1,999,2));
    foreach ($numbers as $k=>$r) { $settings[$k] = isset($s[$k]) && is_numeric($s[$k]) ? max($r[0], min($r[1], (float)$s[$k])) : $r[2]; }
    foreach (array('mirror'=>false,'showSources'=>true,'debug'=>false) as $k=>$v) { $settings[$k] = is_bool($s[$k] ?? null) ? $s[$k] : $v; }
    foreach (array('ink'=>'#25221f','accent'=>'#65513a','paperColor'=>'#fffdf7') as $k=>$v) { $settings[$k] = is_string($s[$k] ?? null) && preg_match('/^#[a-f0-9]{6}$/i',$s[$k]) ? $s[$k] : $v; }
    $settings['footer'] = daf_plain($s['footer'] ?? '',160,'footer');
    if (is_wp_error($settings['footer'])) { return $settings['footer']; }
    $out['settings'] = $settings; $ids=array();$chars=0;$image_bytes=0;
    foreach ($input['blocks'] as $raw) {
        if (!is_array($raw) || !is_string($raw['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/',$raw['id']) || isset($ids[$raw['id']])) { return daf_fail('Source IDs must be unique and valid.'); }
        $b=array('id'=>$raw['id']);$ids[$b['id']]=true;
        foreach (array('title'=>240,'text'=>100000,'byline'=>240,'source'=>500,'license'=>240,'alt'=>240) as $k=>$max) {
            $b[$k]=daf_plain($raw[$k] ?? '',$max,$k);if(is_wp_error($b[$k]))return $b[$k];
        }
        $chars+=daf_len($b['text']);
        foreach(array('url','authorUrl') as $k)$b[$k]=daf_url($raw[$k] ?? '');
        foreach(array('zone'=>array('auto','main','left','right','end'), 'role'=>array('commentary','primary','mishnah','gemara','rashi','tosafot','question','sources','conclusion'), 'direction'=>array('auto','ltr','rtl')) as $k=>$values)$b[$k]=in_array($raw[$k] ?? null,$values,true)?$raw[$k]:$values[0];
        $b['size']=isset($raw['size']) && is_numeric($raw['size'])?max(0,min(32,(float)$raw['size'])):0;if($b['size']>0 && $b['size']<11)$b['size']=11;
        $b['width']=isset($raw['width']) && is_numeric($raw['width'])?max(.7,min(1.4,(float)$raw['width'])):1;
        $b['color']=is_string($raw['color'] ?? null) && preg_match('/^#[a-f0-9]{6}$/i',$raw['color'])?$raw['color']:'';
        foreach(array('keepTogether','breakBefore') as $k)$b[$k]=($raw[$k] ?? false)===true;
        $b['parentId']=is_string($raw['parentId'] ?? null) && preg_match('/^[a-zA-Z0-9_-]{1,80}$/',$raw['parentId'])?$raw['parentId']:'';
        $b['marks']=array();
        if(isset($raw['marks']) && is_array($raw['marks'])){
            if(count($raw['marks'])>4000)return daf_fail('A source contains too many formatting ranges.');
            foreach($raw['marks'] as $m){
                if(!is_array($m) || !in_array($m['type'] ?? '',array('bold','italic','underline'),true) || !is_numeric($m['start'] ?? null) || !is_numeric($m['end'] ?? null))continue;
                $start=max(0,(int)$m['start']);$end=max(0,(int)$m['end']);
                if($end>$start && $start<=200000 && $end<=200000)$b['marks'][]=array('start'=>$start,'end'=>$end,'type'=>$m['type']);
            }
        }
        $b['image']='';
        if (!empty($raw['image'])) {
            if(!is_string($raw['image']) || strlen($raw['image'])>700000 || !preg_match('#^data:image/(png|jpeg|webp);base64,([a-zA-Z0-9+/=]+)$#D',$raw['image'],$m))return daf_fail('Invalid image payload.');
            $bytes=base64_decode($m[2],true);if($bytes===false || strlen($bytes)>525000)return daf_fail('Image exceeds 500 KB.');
            if(!function_exists('getimagesizefromstring'))return daf_fail('Image validation is unavailable on this host. Save without the image or ask your host to enable it.',503);
            $info=@getimagesizefromstring($bytes);if(!$info || !in_array($info['mime'],array('image/png','image/jpeg','image/webp'),true) || $info[0]*$info[1]>4000000)return daf_fail('Invalid or oversized raster image.');
            $b['image']=$raw['image'];$image_bytes+=strlen($bytes);
        }
        $out['blocks'][]=$b;
    }
    if($chars>$max_chars)return daf_fail('This plan allows '.$max_chars.' text characters per sheet.',403,'daf_plan_limit');
    if($image_bytes>3000000)return daf_fail('A sheet can contain at most 3 MB of imported image data.');
    foreach($out['blocks'] as &$b)if(!isset($ids[$b['parentId']]) || $b['parentId']===$b['id'])$b['parentId']='';
    return $out;
}
