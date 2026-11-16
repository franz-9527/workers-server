<?php
declare(strict_types=1);
if(basename(dirname(__DIR__,2))!=='workers-server')throw new RuntimeException('Independent test repository required');
$view=(string)file_get_contents(dirname(__DIR__).'/views/admin/chat-settings.php');
$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{$count++;if(!$ok)throw new RuntimeException($label);};
$check(str_contains($view,"==='wechat'"),'wechat safety mode is rendered explicitly');
$check(str_contains($view,'微信内容安全 + 本地规则'),'active wechat safety is not displayed as disabled');
$check(str_contains($view,'本地风控（测试站）'),'local fallback remains visible');
echo 'Chat settings safety contract: '.$count." assertions passed\n";
