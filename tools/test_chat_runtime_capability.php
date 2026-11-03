<?php
declare(strict_types=1);
if(basename(dirname(__DIR__))!=='workers-server')throw new RuntimeException('Independent repository required');
$fixtureRoot=sys_get_temp_dir().'/chat-capability-'.bin2hex(random_bytes(6));mkdir($fixtureRoot.'/chat/bin/state',0700,true);
function base_path(){return $GLOBALS['fixtureRoot'].'/server';}
require dirname(__DIR__).'/server/app/Application/Chat/ChatGoRuntimeStatus.php';
$status=new \WorkerAdmin\Application\Chat\ChatGoRuntimeStatus('test');$file=$fixtureRoot.'/chat/bin/state/chat.runtime.json';$checks=0;
function ck($value,$name){if(!$value)throw new RuntimeException($name);$GLOBALS['checks']++;echo "PASS $name\n";}
try{
 ck(!$status->callsEnabled(),'missing runtime never claims calls enabled');
 foreach([['env'=>'test','calls'=>['enabled'=>false]],['env'=>'production','calls'=>['enabled'=>true]],['env'=>'test','calls'=>['enabled'=>'true']]] as $config){file_put_contents($file,json_encode($config));ck(!$status->callsEnabled(),'disabled, wrong environment and wrong boolean remain closed');}
 file_put_contents($file,json_encode(['env'=>'test','calls'=>['enabled'=>true]]));ck($status->callsEnabled(),'real test runtime capability enables frontend polling');
 file_put_contents($file,'invalid');ck(!$status->callsEnabled(),'malformed runtime remains closed');
 echo "PASS runtime capability checks $checks\n";
}finally{if(is_file($file))unlink($file);rmdir(dirname($file));rmdir(dirname(dirname($file)));rmdir($fixtureRoot.'/chat');rmdir($fixtureRoot);}
