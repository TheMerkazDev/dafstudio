<?php
if(!defined('ABSPATH'))exit;

/** Admin-controlled defaults. Existing saved sheets keep their own document settings. */
function daf_studio_bundled_default_path(){return DAF_STUDIO_PATH.'defaults/default-sheet.json';}
function daf_studio_bundled_default_json(){
    $raw=@file_get_contents(daf_studio_bundled_default_path());
    return is_string($raw)&&$raw!==''?$raw:'{"schemaVersion":1,"title":"A new conversation","subtitle":"Texts in conversation","author":"","authorUrl":"","titleUrl":"","settings":{},"blocks":[]}';
}
function daf_studio_bundled_default_document(){
    static $doc=null;if($doc!==null)return $doc;
    $decoded=json_decode(daf_studio_bundled_default_json(),true);
    $validated=daf_validate_document($decoded,'pro');
    $doc=is_wp_error($validated)?array('schemaVersion'=>1,'title'=>'A new conversation','subtitle'=>'Texts in conversation','author'=>'','authorUrl'=>'','titleUrl'=>'','settings'=>array(),'blocks'=>array()):$validated;
    return $doc;
}
function daf_studio_default_appearance(){return array(
    'paper'=>'letter','layout'=>'stepped','overflow'=>'continue','latin'=>'EB Garamond','hebrew'=>'Noto Serif Hebrew',
    'ink'=>'#25221f','accent'=>'#65513a','paperColor'=>'#fffdf7','margin'=>34,'mainSize'=>16,'noteSize'=>14,'minSize'=>12,
    'leading'=>1.48,'gap'=>15,'headerSize'=>28,'folio'=>2,'side'=>'a','numbering'=>'daf','mirror'=>false,'showSources'=>true,
    'footer'=>'A source sheet for learning and discussion'
);}
function daf_studio_default_ui_theme(){return array('brand'=>'#17655e','brandSoft'=>'#edf5f1','workspace'=>'#e9eeeb','surface'=>'#ffffff','text'=>'#263934','muted'=>'#5d6b65');}
function daf_studio_clean_color($value,$fallback){return is_string($value)&&preg_match('/^#[0-9a-f]{6}$/i',$value)?strtolower($value):$fallback;}
function daf_studio_sanitize_appearance($input){
    $base=daf_studio_default_appearance();$input=is_array($input)?$input:array();$out=$base;
    $enums=array(
      'paper'=>array('halfletter','letter','legal','tabloid','a5','a4','b5','a3'),
      'layout'=>array('stepped','columns','reading'),'overflow'=>array('continue','shrink','endnotes','strict'),
      'latin'=>array('Arial','Times New Roman','Crimson Text','EB Garamond','Roboto','Calibri','Georgia','Verdana'),
      'hebrew'=>array('Ezra SIL','SBL Hebrew','David','Frank Ruhl Libre','Noto Rashi Hebrew','Noto Sans Hebrew','Noto Serif Hebrew'),
      'side'=>array('a','b'),'numbering'=>array('daf','page')
    );
    foreach($enums as $k=>$values)if(in_array($input[$k]??null,$values,true))$out[$k]=$input[$k];
    $numbers=array('margin'=>array(20,65),'mainSize'=>array(12,28),'noteSize'=>array(11,24),'minSize'=>array(10,20),'leading'=>array(1.25,2),'gap'=>array(8,30),'headerSize'=>array(18,38),'folio'=>array(1,999));
    foreach($numbers as $k=>$range)if(isset($input[$k])&&is_numeric($input[$k]))$out[$k]=max($range[0],min($range[1],(float)$input[$k]));
    foreach(array('ink','accent','paperColor') as $k)$out[$k]=daf_studio_clean_color($input[$k]??'',$base[$k]);
    foreach(array('mirror','showSources') as $k)$out[$k]=!empty($input[$k]);
    $footer=daf_plain((string)($input['footer']??$base['footer']),160,'footer');$out['footer']=is_wp_error($footer)?$base['footer']:$footer;
    return $out;
}
function daf_studio_sanitize_ui_theme($input){$base=daf_studio_default_ui_theme();$input=is_array($input)?$input:array();$out=array();foreach($base as $k=>$v)$out[$k]=daf_studio_clean_color($input[$k]??'',$v);return $out;}
function daf_studio_sanitize_seed_json($value){
    $raw=(string)$value;$decoded=json_decode($raw,true);
    if(!is_array($decoded)){
      add_settings_error('daf_studio_default_sheet_json','daf_seed_json','Default sheet JSON is not valid JSON. The previous default was kept.','error');
      return get_option('daf_studio_default_sheet_json',daf_studio_bundled_default_json());
    }
    $validated=daf_validate_document($decoded,'pro');
    if(is_wp_error($validated)){
      add_settings_error('daf_studio_default_sheet_json','daf_seed_schema','Default sheet was not saved: '.$validated->get_error_message(),'error');
      return get_option('daf_studio_default_sheet_json',daf_studio_bundled_default_json());
    }
    return wp_json_encode($validated,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function daf_studio_get_appearance(){return wp_parse_args((array)get_option('daf_studio_defaults',array()),daf_studio_default_appearance());}
function daf_studio_get_ui_theme(){return wp_parse_args((array)get_option('daf_studio_ui_theme',array()),daf_studio_default_ui_theme());}
function daf_studio_get_default_document(){
    $raw=(string)get_option('daf_studio_default_sheet_json','');if($raw==='')return daf_studio_bundled_default_document();
    $decoded=json_decode($raw,true);if(!is_array($decoded))return daf_studio_bundled_default_document();
    $validated=daf_validate_document($decoded,'pro');return is_wp_error($validated)?daf_studio_bundled_default_document():$validated;
}

add_action('admin_menu',function(){add_options_page('Daf Studio','Daf Studio','manage_options','daf-studio','daf_settings_page');});
add_action('admin_init',function(){
    register_setting('daf_studio','daf_studio_pmpro_levels',array('type'=>'string','sanitize_callback'=>function($v){return implode(',',array_unique(array_filter(array_map('absint',explode(',',(string)$v)))));},'default'=>''));
    register_setting('daf_studio','daf_studio_upgrade_url',array('type'=>'string','sanitize_callback'=>'daf_url','default'=>''));
    register_setting('daf_studio','daf_studio_defaults',array('type'=>'array','sanitize_callback'=>'daf_studio_sanitize_appearance','default'=>daf_studio_default_appearance()));
    register_setting('daf_studio','daf_studio_ui_theme',array('type'=>'array','sanitize_callback'=>'daf_studio_sanitize_ui_theme','default'=>daf_studio_default_ui_theme()));
    register_setting('daf_studio','daf_studio_default_sheet_json',array('type'=>'string','sanitize_callback'=>'daf_studio_sanitize_seed_json','default'=>daf_studio_bundled_default_json()));
});
function daf_studio_select($name,$current,$options){echo '<select name="'.esc_attr($name).'">';foreach($options as $value=>$label)echo '<option value="'.esc_attr($value).'" '.selected($current,$value,false).'>'.esc_html($label).'</option>';echo '</select>';}
function daf_studio_number($name,$value,$min,$max,$step='1'){echo '<input type="number" class="small-text" name="'.esc_attr($name).'" value="'.esc_attr($value).'" min="'.esc_attr($min).'" max="'.esc_attr($max).'" step="'.esc_attr($step).'">';}
function daf_settings_page(){
    if(!current_user_can('manage_options'))return;$d=daf_studio_get_appearance();$u=daf_studio_get_ui_theme();$seed=(string)get_option('daf_studio_default_sheet_json','');if($seed==='')$seed=daf_studio_bundled_default_json();$fresh=add_query_arg('daf_fresh','1',daf_app_url());
    $papers=array('halfletter'=>'US Half Letter (5.5 × 8.5 in)','letter'=>'US Letter (8.5 × 11 in)','legal'=>'US Legal (8.5 × 14 in)','tabloid'=>'US Tabloid (11 × 17 in)','a5'=>'A5 (148 × 210 mm)','a4'=>'A4 (210 × 297 mm)','b5'=>'B5 (176 × 250 mm)','a3'=>'A3 (297 × 420 mm)');
    $latin=array_combine(array('Arial','Times New Roman','Crimson Text','EB Garamond','Roboto','Calibri','Georgia','Verdana'),array('Arial','Times New Roman','Crimson Text','EB Garamond','Roboto','Calibri','Georgia','Verdana'));
    $hebrew=array_combine(array('Ezra SIL','SBL Hebrew','David','Frank Ruhl Libre','Noto Rashi Hebrew','Noto Sans Hebrew','Noto Serif Hebrew'),array('Ezra SIL','SBL Hebrew','David','Frank Ruhl Libre','Noto Rashi Hebrew','Noto Sans Hebrew','Noto Serif Hebrew'));
    ?><div class="wrap"><h1>Daf Studio</h1><p>Version <?php echo esc_html(DAF_STUDIO_VERSION); ?>. These settings change the <strong>starting point for new workspaces</strong>; they do not rewrite sheets people already saved.</p>
    <p><a class="button button-primary" href="<?php echo esc_url($fresh); ?>" target="_blank" rel="noopener">Preview site default in editor</a> <a class="button" href="<?php echo esc_url(daf_app_url()); ?>" target="_blank" rel="noopener">Open my current workspace</a></p>
    <?php settings_errors(); ?><form method="post" action="options.php"><?php settings_fields('daf_studio'); ?>
    <style>.daf-admin-grid{display:grid;grid-template-columns:minmax(460px,1fr) minmax(340px,.75fr);gap:24px;max-width:1280px}.daf-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 22px;margin:0 0 20px}.daf-card h2{margin-top:0}.daf-colors{display:grid;grid-template-columns:repeat(3,minmax(120px,1fr));gap:12px}.daf-color label{display:block;font-weight:600;margin-bottom:5px}.daf-color input{width:100%;height:42px}.daf-seed{width:100%;min-height:560px;font:12px/1.45 ui-monospace,SFMono-Regular,Menlo,monospace;direction:ltr}.daf-inline{display:flex;gap:14px;flex-wrap:wrap;align-items:center}.daf-font-note{color:#646970;font-size:12px}@media(max-width:1000px){.daf-admin-grid{grid-template-columns:1fr}.daf-colors{grid-template-columns:1fr 1fr}}</style>
    <div class="daf-admin-grid"><div>
      <section class="daf-card"><h2>Default sheet appearance</h2><p>These values are applied to the bundled/default conversation whenever a fresh workspace is created. Authors can still change them per sheet.</p>
      <table class="form-table" role="presentation">
      <tr><th>Paper</th><td><?php daf_studio_select('daf_studio_defaults[paper]',$d['paper'],$papers); ?></td></tr>
      <tr><th>Layout</th><td><?php daf_studio_select('daf_studio_defaults[layout]',$d['layout'],array('stepped'=>'Adaptive Vilna / stepped','columns'=>'Classic columns','reading'=>'Reading / single column')); ?></td></tr>
      <tr><th>Overflow</th><td><?php daf_studio_select('daf_studio_defaults[overflow]',$d['overflow'],array('continue'=>'Continue to more folios','shrink'=>'Shrink to minimum, then continue','endnotes'=>'Move marginal notes to endnotes','strict'=>'Strict one-folio')); ?></td></tr>
      <tr><th>Latin font</th><td><?php daf_studio_select('daf_studio_defaults[latin]',$d['latin'],$latin); ?><p class="daf-font-note">Font files are not bundled; unavailable local fonts fall back in the browser.</p></td></tr>
      <tr><th>Hebrew font</th><td><?php daf_studio_select('daf_studio_defaults[hebrew]',$d['hebrew'],$hebrew); ?></td></tr>
      <tr><th>Type sizes</th><td class="daf-inline"><label>Main <?php daf_studio_number('daf_studio_defaults[mainSize]',$d['mainSize'],12,28); ?></label><label>Notes <?php daf_studio_number('daf_studio_defaults[noteSize]',$d['noteSize'],11,24); ?></label><label>Header <?php daf_studio_number('daf_studio_defaults[headerSize]',$d['headerSize'],18,38); ?></label><label>Minimum <?php daf_studio_number('daf_studio_defaults[minSize]',$d['minSize'],10,20); ?></label></td></tr>
      <tr><th>Spacing</th><td class="daf-inline"><label>Margin <?php daf_studio_number('daf_studio_defaults[margin]',$d['margin'],20,65); ?></label><label>Gap <?php daf_studio_number('daf_studio_defaults[gap]',$d['gap'],8,30); ?></label><label>Leading <?php daf_studio_number('daf_studio_defaults[leading]',$d['leading'],1.25,2,.01); ?></label></td></tr>
      <tr><th>Sheet colors</th><td><div class="daf-colors"><?php foreach(array('ink'=>'Body text','accent'=>'Headings / citations','paperColor'=>'Paper') as $k=>$label){?><div class="daf-color"><label><?php echo esc_html($label); ?></label><input type="color" name="daf_studio_defaults[<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr($d[$k]); ?>"></div><?php } ?></div></td></tr>
      <tr><th>Footer</th><td><input class="large-text" name="daf_studio_defaults[footer]" value="<?php echo esc_attr($d['footer']); ?>"></td></tr>
      <tr><th>Folio defaults</th><td class="daf-inline"><label>Start <?php daf_studio_number('daf_studio_defaults[folio]',$d['folio'],1,999); ?></label><?php daf_studio_select('daf_studio_defaults[side]',$d['side'],array('a'=>'a','b'=>'b')); ?> <?php daf_studio_select('daf_studio_defaults[numbering]',$d['numbering'],array('daf'=>'Daf (2a, 2b…)','page'=>'Pages (1, 2…)')); ?><label><input type="checkbox" name="daf_studio_defaults[mirror]" value="1" <?php checked($d['mirror']); ?>> Mirror facing margins</label><label><input type="checkbox" name="daf_studio_defaults[showSources]" value="1" <?php checked($d['showSources']); ?>> Show source citations</label></td></tr>
      </table></section>
      <section class="daf-card"><h2>Editor interface colors</h2><p>These affect the editing workspace only, not the printable daf. Use them to match your site branding without editing plugin CSS.</p><div class="daf-colors"><?php foreach(array('brand'=>'Primary / focus','brandSoft'=>'Soft accent','workspace'=>'Canvas surround','surface'=>'Panels','text'=>'UI text','muted'=>'Muted text') as $k=>$label){?><div class="daf-color"><label><?php echo esc_html($label); ?></label><input type="color" name="daf_studio_ui_theme[<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr($u[$k]); ?>"></div><?php } ?></div></section>
      <section class="daf-card"><h2>Membership</h2><table class="form-table"><tr><th><label for="daf-levels">Paid Memberships Pro level IDs</label></th><td><input class="regular-text" id="daf-levels" name="daf_studio_pmpro_levels" value="<?php echo esc_attr(get_option('daf_studio_pmpro_levels','')); ?>"><p class="description">Comma-separated active membership level IDs. Administrators always receive Pro.</p></td></tr><tr><th><label for="daf-upgrade">Membership / billing page URL</label></th><td><input type="url" class="regular-text" id="daf-upgrade" name="daf_studio_upgrade_url" value="<?php echo esc_attr(get_option('daf_studio_upgrade_url','')); ?>"></td></tr></table></section>
    </div><div>
      <section class="daf-card"><h2>Default populated sheet</h2><p>This is the JSON loaded for a visitor who has no saved/recovery workspace. It is also available from <strong>File → Start from site default</strong>. Appearance settings above are applied on top, so you can swap the conversation without restyling it.</p>
      <textarea id="daf-default-json" class="daf-seed" name="daf_studio_default_sheet_json" spellcheck="false"><?php echo esc_textarea($seed); ?></textarea>
      <p><button type="button" class="button" id="daf-restore-bundled">Restore bundled Sefaria example in this field</button></p><p class="description">Save Changes validates this against Daf Studio’s document schema. Invalid JSON is rejected and the last valid default remains active. Existing users’ saved sheets are never overwritten.</p></section>
      <section class="daf-card"><h2>Operational notes</h2><p><strong>PDF import:</strong> <?php echo defined('DAF_CONVERTER_URL')&&defined('DAF_CONVERTER_TOKEN')?'Private converter configured.':'Optional converter not configured. DOCX, text, HTML, JSON, and raster images do not require it.'; ?></p><p>Use HTTPS; exclude the editor URL and <code>/wp-json/daf-studio/v1/*</code> from page/CDN caching; back up the custom tables; and test with a non-admin account before launch.</p></section>
    </div></div><?php submit_button('Save Daf Studio settings'); ?></form>
    <script>(function(){const b=document.getElementById('daf-restore-bundled'),t=document.getElementById('daf-default-json');if(!b||!t)return;const bundled=<?php echo wp_json_encode(daf_studio_bundled_default_json(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;b.addEventListener('click',()=>{if(confirm('Replace the text in the default-sheet field with the bundled example? Nothing changes until you click Save.')){t.value=bundled;t.scrollTop=0;}});})();</script>
    </div><?php
}
function daf_profile_field($user){if(!current_user_can('manage_options'))return;?><h2>Daf Studio access</h2><input type="hidden" name="daf_profile_present" value="1"><label><input type="checkbox" name="daf_studio_pro" value="1" <?php checked(user_can($user,'daf_studio_pro')); ?>> Grant Pro access manually (for collaborators, testing, or lifetime access)</label><p>Granting Pro here is independent of paid membership and remains until unchecked.</p><?php }
add_action('show_user_profile','daf_profile_field');add_action('edit_user_profile','daf_profile_field');
function daf_save_profile($uid){
    if(!current_user_can('manage_options') || !current_user_can('edit_user',$uid) || empty($_POST['daf_profile_present']))return;
    check_admin_referer('update-user_'.$uid);
    $user=get_user_by('id',$uid);if(!$user)return;
    if(!empty($_POST['daf_studio_pro']))$user->add_cap('daf_studio_pro');else $user->remove_cap('daf_studio_pro');
}
add_action('personal_options_update','daf_save_profile');add_action('edit_user_profile_update','daf_save_profile');
add_filter('wp_privacy_personal_data_exporters',function($exporters){$exporters['daf-studio']=array('exporter_friendly_name'=>'Daf Studio source sheets','callback'=>'daf_privacy_export');return $exporters;});
function daf_privacy_export($email,$page=1){
    global $wpdb;$user=get_user_by('email',$email);if(!$user)return array('data'=>array(),'done'=>true);
    $offset=(max(1,(int)$page)-1)*3;
    // Export both current sheets and retained revisions, in small bounded batches.
    $sql="SELECT * FROM (
      SELECT s.id AS sheet_id,s.title,s.document,s.revision,s.updated_at,'current' AS kind FROM {$wpdb->prefix}daf_sheets s WHERE s.owner_id=%d
      UNION ALL
      SELECT s.id AS sheet_id,s.title,r.document,r.revision,r.created_at AS updated_at,'history' AS kind FROM {$wpdb->prefix}daf_revisions r INNER JOIN {$wpdb->prefix}daf_sheets s ON s.id=r.sheet_id WHERE s.owner_id=%d
    ) data_rows ORDER BY sheet_id,kind,revision LIMIT 3 OFFSET %d";
    $rows=$wpdb->get_results($wpdb->prepare($sql,$user->ID,$user->ID,$offset),ARRAY_A);$data=array();
    foreach((array)$rows as $r)$data[]=array('group_id'=>'daf-studio','group_label'=>'Daf Studio','item_id'=>'daf-sheet-'.$r['sheet_id'].'-'.$r['kind'].'-'.$r['revision'],'data'=>array(array('name'=>'Title','value'=>$r['title']),array('name'=>'Version','value'=>$r['kind'].' '.$r['revision']),array('name'=>'Editable document JSON','value'=>$r['document']),array('name'=>'Updated','value'=>$r['updated_at'])));
    return array('data'=>$data,'done'=>count((array)$rows)<3);
}
add_filter('wp_privacy_personal_data_erasers',function($erasers){$erasers['daf-studio']=array('eraser_friendly_name'=>'Daf Studio source sheets','callback'=>'daf_privacy_erase');return $erasers;});
function daf_privacy_erase($email,$page=1){
    global $wpdb;$user=get_user_by('email',$email);if(!$user)return array('items_removed'=>false,'items_retained'=>false,'messages'=>array(),'done'=>true);
    $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}daf_sheets WHERE owner_id=%d ORDER BY id LIMIT 10",$user->ID));
    $removed=false;$failed=false;
    foreach($ids as $id){
      $wpdb->query('START TRANSACTION');
      $a=$wpdb->delete($wpdb->prefix.'daf_revisions',array('sheet_id'=>(int)$id),array('%d'));
      $b=$wpdb->delete($wpdb->prefix.'daf_sheets',array('id'=>(int)$id,'owner_id'=>$user->ID),array('%d','%d'));
      if($a===false || $b!==1){$wpdb->query('ROLLBACK');$failed=true;break;}
      $wpdb->query('COMMIT');$removed=true;
    }
    return array('items_removed'=>$removed,'items_retained'=>$failed,'messages'=>$failed?array('Some Daf Studio data could not be erased. Check database health and retry.'):array(),'done'=>$failed || count($ids)<10);
}
add_action('delete_user',function($uid){global $wpdb;$ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}daf_sheets WHERE owner_id=%d",$uid));foreach($ids as $id)$wpdb->delete($wpdb->prefix.'daf_revisions',array('sheet_id'=>(int)$id),array('%d'));$wpdb->delete($wpdb->prefix.'daf_sheets',array('owner_id'=>$uid),array('%d'));});
