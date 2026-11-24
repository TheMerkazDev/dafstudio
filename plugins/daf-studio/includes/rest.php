<?php
if(!defined('ABSPATH'))exit;
function daf_authenticated(){return get_current_user_id()>0 && current_user_can('read')?true:daf_fail('Log in to use private sheets.',401,'daf_login');}
function daf_owned($id){global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}daf_sheets WHERE id=%d AND owner_id=%d",$id,get_current_user_id()),ARRAY_A);}
function daf_reply($row){return array('id'=>(int)$row['id'],'revision'=>(int)$row['revision'],'document'=>json_decode($row['document'],true),'updated_at'=>$row['updated_at']);}
function daf_require_pro(){return daf_plan(get_current_user_id())==='pro'?true:daf_fail('This cloud feature requires Pro membership. Your own sheets can still be read, exported and deleted.',403,'daf_pro');}
add_action('rest_api_init',function(){
    $auth='daf_authenticated';$ns='daf-studio/v1';
    register_rest_route($ns,'/sheets',array(
      array('methods'=>'GET','permission_callback'=>$auth,'callback'=>'daf_list_sheets'),
      array('methods'=>'POST','permission_callback'=>$auth,'callback'=>'daf_save_sheet')));
    register_rest_route($ns,'/sheets/(?P<id>\d+)',array(
      array('methods'=>'GET','permission_callback'=>$auth,'callback'=>function($r){$s=daf_owned((int)$r['id']);return $s?daf_reply($s):daf_fail('Sheet not found.',404);}),
      array('methods'=>'POST','permission_callback'=>$auth,'callback'=>'daf_save_sheet'),
      array('methods'=>'DELETE','permission_callback'=>$auth,'callback'=>'daf_delete_sheet')));
    register_rest_route($ns,'/sheets/(?P<id>\d+)/revisions',array('methods'=>'GET','permission_callback'=>$auth,'callback'=>'daf_sheet_revisions'));
    register_rest_route($ns,'/sheets/(?P<id>\d+)/share',array(
      array('methods'=>'POST','permission_callback'=>$auth,'callback'=>'daf_share_sheet'),
      array('methods'=>'DELETE','permission_callback'=>$auth,'callback'=>'daf_revoke_share')));
    register_rest_route($ns,'/shared',array('methods'=>'GET','permission_callback'=>'__return_true','callback'=>'daf_shared'));
    register_rest_route($ns,'/sefaria',array('methods'=>'GET','permission_callback'=>$auth,'callback'=>'daf_sefaria'));
    register_rest_route($ns,'/import-pdf',array('methods'=>'POST','permission_callback'=>$auth,'callback'=>'daf_import_pdf'));
});
function daf_list_sheets(){
    global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT id,title,revision,updated_at FROM {$wpdb->prefix}daf_sheets WHERE owner_id=%d ORDER BY updated_at DESC LIMIT 200",get_current_user_id()),ARRAY_A);
    return array('items'=>$rows,'plan'=>daf_plan(get_current_user_id()));
}
function daf_save_sheet($request){
    global $wpdb;$uid=get_current_user_id();$id=(int)($request['id'] ?? 0);
    if(strlen($request->get_body())>6000000)return daf_fail('Request exceeds 6 MB.',413);
    $input=$request->get_json_params();if(!is_array($input))return daf_fail('Expected JSON.');
    $doc=daf_validate_document($input['document'] ?? null,daf_plan($uid));if(is_wp_error($doc))return $doc;
    $json=wp_json_encode($doc);if($json===false)return daf_fail('Document encoding failed.');
    $now=current_time('mysql',true);$table=$wpdb->prefix.'daf_sheets';$revs=$wpdb->prefix.'daf_revisions';
    if(!$id){
        // MySQL advisory lock serializes quota checks for this owner across concurrent requests.
        $lock='daf-create-'.get_current_blog_id().'-'.$uid;
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))!==1)return daf_fail('Another save is in progress. Retry shortly.',503);
        try{
            $max=daf_plan($uid)==='pro'?200:3;
            $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE owner_id=%d",$uid));
            if($count>=$max)return daf_fail('This plan allows '.$max.' saved sheets. Export/delete a sheet or upgrade.',403,'daf_quota');
            $ok=$wpdb->insert($table,array('owner_id'=>$uid,'title'=>$doc['title'],'document'=>$json,'revision'=>1,'created_at'=>$now,'updated_at'=>$now),array('%d','%s','%s','%d','%s','%s'));
            if(!$ok)return daf_fail('The sheet could not be saved.',500);
            return array('id'=>(int)$wpdb->insert_id,'revision'=>1);
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    $current=daf_owned($id);if(!$current)return daf_fail('Sheet not found.',404);
    $expected=$input['revision'] ?? null;
    if(!is_int($expected) || $expected!==(int)$current['revision'])return daf_fail('A newer revision exists. Reload or export your local changes first.',409,'daf_conflict');
    $wpdb->query('START TRANSACTION');
    $changed=$wpdb->query($wpdb->prepare("UPDATE $table SET title=%s,document=%s,revision=revision+1,updated_at=%s WHERE id=%d AND owner_id=%d AND revision=%d",$doc['title'],$json,$now,$id,$uid,$expected));
    if($changed!==1){$wpdb->query('ROLLBACK');return daf_fail('Concurrent edit detected. Your local copy has not been overwritten.',409,'daf_conflict');}
    $backup=$wpdb->insert($revs,array('sheet_id'=>$id,'revision'=>$expected,'document'=>$current['document'],'created_at'=>$current['updated_at']),array('%d','%d','%s','%s'));
    if(!$backup){$wpdb->query('ROLLBACK');return daf_fail('Revision backup failed; the save was rolled back.',500);}
    $wpdb->query('COMMIT');
    $wpdb->query($wpdb->prepare("DELETE FROM $revs WHERE sheet_id=%d AND revision<%d",$id,max(0,$expected-19)));
    return array('id'=>$id,'revision'=>$expected+1);
}
function daf_delete_sheet($r){
    global $wpdb;$id=(int)$r['id'];if(!daf_owned($id))return daf_fail('Sheet not found.',404);
    $wpdb->query('START TRANSACTION');
    $a=$wpdb->delete($wpdb->prefix.'daf_revisions',array('sheet_id'=>$id),array('%d'));
    $b=$wpdb->delete($wpdb->prefix.'daf_sheets',array('id'=>$id,'owner_id'=>get_current_user_id()),array('%d','%d'));
    if($a===false || $b!==1){$wpdb->query('ROLLBACK');return daf_fail('Deletion failed.',500);}$wpdb->query('COMMIT');return array('deleted'=>true);
}
function daf_sheet_revisions($r){
    global $wpdb;$id=(int)$r['id'];if(!daf_owned($id))return daf_fail('Sheet not found.',404);$pro=daf_require_pro();if(is_wp_error($pro))return $pro;
    $rows=$wpdb->get_results($wpdb->prepare("SELECT revision,document,created_at FROM {$wpdb->prefix}daf_revisions WHERE sheet_id=%d ORDER BY revision DESC LIMIT 20",$id),ARRAY_A);
    foreach($rows as &$row)$row['document']=json_decode($row['document'],true);return array('items'=>$rows);
}
function daf_share_sheet($r){
    global $wpdb;$id=(int)$r['id'];$row=daf_owned($id);if(!$row)return daf_fail('Sheet not found.',404);$pro=daf_require_pro();if(is_wp_error($pro))return $pro;
    $body=$r->get_json_params();if(($body['rightsConfirmed'] ?? false)!==true)return daf_fail('Confirm redistribution rights first.');
    $doc=json_decode($row['document'],true);foreach($doc['blocks'] as $b)if(!empty($b['image']) && empty(trim($b['alt'])))return daf_fail('Every image needs alternative text.');
    $token=bin2hex(random_bytes(32));$expires=gmdate('Y-m-d H:i:s',time()+30*DAY_IN_SECONDS);
    $ok=$wpdb->update($wpdb->prefix.'daf_sheets',array('share_hash'=>hash('sha256',$token),'share_expires'=>$expires),array('id'=>$id,'owner_id'=>get_current_user_id()),array('%s','%s'),array('%d','%d'));
    if($ok===false)return daf_fail('Share could not be created.',500);
    return array('token'=>$token,'expires_at'=>$expires);
}
function daf_revoke_share($r){global $wpdb;$id=(int)$r['id'];if(!daf_owned($id))return daf_fail('Sheet not found.',404);$ok=$wpdb->update($wpdb->prefix.'daf_sheets',array('share_hash'=>null,'share_expires'=>null),array('id'=>$id,'owner_id'=>get_current_user_id()));if($ok===false)return daf_fail('Share revocation failed. Please retry.',500);return array('revoked'=>true);}
function daf_shared($r){
    global $wpdb;$token=$r->get_header('x-daf-share');if(!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token))return daf_fail('Share not found.',404);
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}daf_sheets WHERE share_hash=%s AND share_expires>%s LIMIT 1",hash('sha256',$token),gmdate('Y-m-d H:i:s')),ARRAY_A);
    if(!$row || daf_plan((int)$row['owner_id'])!=='pro')return daf_fail('Share not found or expired.',404);
    // Do not disclose owner IDs, private history or internal sheet identifiers.
    return array('document'=>json_decode($row['document'],true));
}
function daf_rate_limit($name,$max){
    $key='daf_rate_'.$name.'_'.get_current_user_id();$n=(int)get_transient($key);
    if($n>=$max)return false;set_transient($key,$n+1,MINUTE_IN_SECONDS);return true;
}
function daf_sefaria($r){
    if(!daf_rate_limit('sefaria',20))return daf_fail('Too many source requests. Please wait a minute.',429);
    $ref=$r->get_param('ref');$ver=$r->get_param('version');
    if(!is_string($ref) || strlen($ref)>200 || !preg_match('/^[\p{L}\p{N}\s.,:()\x{05F3}\x{05F4}\x{0027}\x{0022}_-]+$/u',$ref))return daf_fail('Enter a text reference, not a URL.');
    $ver=in_array($ver,array('source','translation'),true)?$ver:'source';
    $key='daf_sefaria_'.md5($ref.'|'.$ver);$cached=get_transient($key);if($cached!==false)return $cached;
    $url='https://www.sefaria.org/api/v3/texts/'.rawurlencode($ref).'?version='.$ver;
    $res=wp_safe_remote_get($url,array('timeout'=>12,'redirection'=>0,'limit_response_size'=>1200000));
    if(is_wp_error($res) || wp_remote_retrieve_response_code($res)!==200)return daf_fail('Sefaria is unavailable or the reference was not found.',502);
    $data=json_decode(wp_remote_retrieve_body($res),true);if(!is_array($data) || empty($data['versions']))return daf_fail('No versions returned for this reference.',404);
    $out=array('ref'=>$data['ref'] ?? $ref,'url'=>'https://www.sefaria.org/'.rawurlencode(str_replace(' ','_',$data['ref'] ?? $ref)),'versions'=>array());
    foreach(array_slice($data['versions'],0,5) as $v){
        $flatten=function($x) use (&$flatten){if(is_array($x))return implode("\n\n",array_map($flatten,$x));return is_string($x)?html_entity_decode(wp_strip_all_tags(str_replace(array('<br>','<br/>','<br />'),"\n",$x)),ENT_QUOTES|ENT_HTML5,'UTF-8'):'';};
        $text=$flatten($v['text'] ?? '');if(daf_len($text)>100000)return daf_fail('This reference is too long. Select a smaller passage.');
        $out['versions'][]=array('title'=>sanitize_text_field($v['versionTitle'] ?? ''),'language'=>sanitize_text_field($v['language'] ?? ''),'license'=>sanitize_text_field($v['license'] ?? ''),'text'=>$text);
    }
    set_transient($key,$out,6*HOUR_IN_SECONDS);return $out;
}
function daf_import_pdf($r){
    if(!defined('DAF_CONVERTER_URL') || !defined('DAF_CONVERTER_TOKEN') || strlen(DAF_CONVERTER_TOKEN)<32)return daf_fail('PDF conversion is not configured. Enable the private Docker converter, or import DOCX/text instead.',503);
    if(!daf_rate_limit('pdf',5))return daf_fail('Please wait before converting another PDF.',429);
    $files=$r->get_file_params();$f=$files['file'] ?? null;
    if(!$f || $f['error']!==UPLOAD_ERR_OK || $f['size']>8388608 || !is_uploaded_file($f['tmp_name']))return daf_fail('Upload a PDF of at most 8 MB.');
    $bytes=file_get_contents($f['tmp_name']);if(strncmp($bytes,'%PDF-',5)!==0)return daf_fail('This file is not a PDF.');
    // URL and token are server configuration, never supplied by the requesting user.
    $res=wp_remote_post(DAF_CONVERTER_URL,array('timeout'=>25,'redirection'=>0,'limit_response_size'=>1200000,'headers'=>array('Authorization'=>'Bearer '.DAF_CONVERTER_TOKEN,'Content-Type'=>'application/pdf'),'body'=>$bytes));
    if(is_wp_error($res))return daf_fail('The private PDF converter is unavailable.',502);
    $data=json_decode(wp_remote_retrieve_body($res),true);
    if(wp_remote_retrieve_response_code($res)!==200 || !is_array($data) || !isset($data['text']))return daf_fail(is_array($data)?($data['error'] ?? 'Conversion failed.'):'Conversion failed.',422);
    return array('text'=>$data['text'],'notice'=>'PDF text imported via your private converter. Check reading order, Hebrew direction, page breaks and extraction fidelity. Scans need OCR.');
}
