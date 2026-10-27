<?php
declare(strict_types=1);
use WorkerAdmin\Services\FormComponentTypes;
require __DIR__.'/../server/app/Services/FormComponentTypes.php';

$aliases=[
    'text_input'=>'text','number_input'=>'number','single'=>'radio',
    'multiple'=>'checkbox','date'=>'date_picker','image'=>'image_upload',
    'link'=>'url_input','address'=>'wechat_address','map'=>'wechat_address',
];
foreach($aliases as $old=>$standard){
    if(FormComponentTypes::canonical($old)!==$standard){
        throw new RuntimeException('Legacy component mapping failed: '.$old);
    }
}
if(FormComponentTypes::canonical('boolean')!=='switch'
    ||FormComponentTypes::canonical('boolean',[['label'=>'是']])!=='radio'){
    throw new RuntimeException('Legacy boolean component mapping failed');
}
if(FormComponentTypes::isSupported('text_input')||!FormComponentTypes::isSupported('date_range')){
    throw new RuntimeException('Component whitelist not canonical');
}

function e($v){return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');}
function admin_url($route='dashboard', array $params=[]){
    return '/admin/?route='.urlencode($route).($params?'&'.http_build_query($params):'');
}
final class FakeCsrf{
    public function input(){return '<input type="hidden" name="_csrf" value="test">';}
}
function csrf(){return new FakeCsrf();}

$schema=['id'=>1];$version=['id'=>1,'version_no'=>1,'status'=>'draft'];
$versions=[['id'=>1,'version_no'=>1,'status'=>'draft','order_count'=>0]];
$schemaId=1;$versionId=1;$versionUsage=0;$fullEditable=true;$locked=false;$draftVersion=$version;
$categories=[];
$steps=[['id'=>1,'step_key'=>'details','title'=>'基本信息','description'=>'','sort_order'=>10,'status'=>'active']];
$fields=[];$i=0;
foreach(array_merge($aliases,['boolean'=>'switch','boolean_with_options'=>'radio']) as $original=>$target){
    $legacy=$original==='boolean_with_options'?'boolean':$original;
    $options=$original==='boolean_with_options'?[['key'=>'yes','label'=>'是','value'=>'true']]:[];
    $fields[]=[
        'id'=>++$i,'step_id'=>1,'sort_order'=>$i*10,'field_key'=>'key_'.$i,
        'label'=>'测试字段'.$i,'component_type'=>$legacy,'data_type'=>'string','placeholder'=>'',
        'help_text'=>'','is_required'=>0,'category_ids'=>[],
        'options_json'=>json_encode($options,JSON_UNESCAPED_UNICODE),
        'display_rule_json'=>'{}','validation_json'=>'{}','ui_config_json'=>'{}',
        'default_value_json'=>'null','status'=>'active',
    ];
}
ob_start();
require __DIR__.'/../server/views/admin/form-designer.php';
$html=ob_get_clean();

if(!preg_match('~<select[^>]*id="df-component"[^>]*>(.*?)</select>~s',$html,$select)){
    throw new RuntimeException('Component selector missing');
}
if(strpos($select[1],'（旧）')!==false){
    throw new RuntimeException('Legacy component options are still visible');
}
foreach(array_keys($aliases) as $old){
    if(preg_match('~<option[^>]*value="'.preg_quote($old,'~').'"~',$select[1])){
        throw new RuntimeException('Legacy option visible: '.$old);
    }
}
if(!preg_match('~<script type="application/json" id="designer-field-data">(.*?)</script>~s',$html,$data)){
    throw new RuntimeException('Initial designer data missing');
}
$decoded=json_decode($data[1],true);
if(!is_array($decoded))throw new RuntimeException('Initial designer JSON invalid');
foreach($fields as $field){
    $type=$decoded[(string)$field['id']]['component_type']??'';
    if(!FormComponentTypes::isSupported($type))throw new RuntimeException('Unmapped historical field: '.$type);
}
if(($decoded[(string)$i]['component_type']??'')!=='radio'){
    throw new RuntimeException('Historical boolean choices changed their UI');
}
if(!preg_match('~<script>\s*(.*?)\s*</script>~s',$html,$js)){
    throw new RuntimeException('Inline designer JavaScript missing');
}
if(strpos($js[1],'updateSelectedControl(type)')===false
    ||strpos($js[1],'readCurrentOptionRows()')===false){
    throw new RuntimeException('Live preview or option preservation missing');
}
file_put_contents('/tmp/designer-inline-smoke.js',$js[1]);
echo 'Form designer: old types normalized; selector clean; preview JS present.'.PHP_EOL;
