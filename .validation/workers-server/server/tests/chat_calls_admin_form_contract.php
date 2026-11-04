<?php
declare(strict_types=1);
if(basename(dirname(__DIR__,2))!=='workers-server')throw new RuntimeException('Independent test repository required');
$root=dirname(__DIR__,2);
$view=(string)file_get_contents($root.'/server/views/admin/chat-calls.php');
$module=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/ChatCallsAdminModule.php');
$admin=(string)file_get_contents($root.'/server/app/Application/Chat/ChatCallAdministration.php');
$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{$count++;if(!$ok)throw new RuntimeException($label);};
$form=static function(string $action)use($view):string{
    $needle='<input type="hidden" name="_action" value="'.$action.'">';
    $at=strpos($view,$needle);if($at===false)throw new RuntimeException('missing form action '.$action);
    $start=strrpos(substr($view,0,$at),'<form');$end=strpos($view,'</form>',$at);
    if($start===false||$end===false)throw new RuntimeException('broken form '.$action);
    return substr($view,$start,$end+7-$start);
};
$reverify=$form('reverify');
$check(str_contains($reverify,'name="max_age_seconds"'),'reverify owns max_age_seconds');
$check(str_contains($reverify,'保存资源设置')&&str_contains($reverify,'admin-btn admin-btn--primary'),'reverify has visible primary save');
$check(!str_contains($reverify,'chat-call-support'),'reverify save is not below optional evidence');
foreach(['budget'=>'保存预算','import'=>'导入并对账'] as $action=>$label){$f=$form($action);$check(str_contains($f,$label)&&str_contains($f,'chat-admin-actions')&&str_contains($f,'admin-btn admin-btn--primary'),$action.' has consistent action bar');}
$check(str_contains($view,'官方用量核对有效期（秒）')&&!str_contains($view,'官方用量允许陈旧时间（秒）'),'freshness wording is consistent');
$check(str_contains($view,"asset_url('chat-calls.css')"),'call page stylesheet is loaded');
$check(str_contains($view,"<?php endif; ?></div></section><?php endforeach; ?>\n<?php elseif($section==='register'): ?>"),'resource pool loop closes before register section');
foreach(['budget','import','reverify','disable_pool'] as $action)$check(str_contains($admin,"'".$action."'"),'automatic audit reason covers '.$action);
$check(str_contains($module,"['pool','reverify','budget','import','confirmed_stop','configuration','settings','credentials']"),'failed resource forms preserve input');
$check(str_contains($module,"'reverify'=>'资源设置已保存。'"),'resource success feedback is action-specific');
echo 'Chat calls admin form contract: '.$count." assertions passed\n";
