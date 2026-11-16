<?php
declare(strict_types=1);
if(basename(dirname(__DIR__,2))!=='workers-server')throw new RuntimeException('Independent test repository required');
$view=(string)file_get_contents(dirname(__DIR__).'/views/admin/chat-calls.php');
$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{$count++;if(!$ok)throw new RuntimeException($label);};
$check(!str_contains($view,'官方用量数据距上次核对已超过'),'stale usage blocker copy is absent');
$check(!str_contains($view,'官方用量核对有效期（秒）'),'official usage age is not exposed as a validity requirement');
$check(str_contains($view,'刷新核对时间（可选）'),'manual reconfirmation remains optional');
$check(str_contains($view,'不会因超过某个时间自动关闭通话'),'admin explains non-blocking observation age');
$check(str_contains($view,'不作为通话强制有效期'),'usage timestamp is explicitly advisory');
echo 'Chat call optional usage contract: '.$count." assertions passed\n";
