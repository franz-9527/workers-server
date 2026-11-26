<?php
namespace {
function is_installed(){return true;}
function upgrade_required(){return false;}
function app_config($key,$default=null){return ['app.env'=>'production','app.url'=>'https://fixture.invalid','chat'=>[]][$key]??$default;}
function db(){return new \stdClass();}
function fixtureMode(){return trim(file_get_contents(WORKER_BASE_PATH.'/fixture-mode'));}
function setting($k,$default=null){return $k==='wechat.miniapp_appid'?'wxfixture':($k==='chat.enabled'?(fixtureMode()==='off'?'0':'1'):$default);}
}
namespace WorkerAdmin\Application\Chat {
class ChatSigningKeyManager {function __construct(...$args){} function status($config){return ['configured'=>true];}}
class ChatGoRuntimeConfig {static function environmentFile($values){$out="# fixture\n";foreach($values as $k=>$v)$out.='export '.$k.'='.escapeshellarg($v)."\n";return $out;}}
class FixtureRenderer {
 function productionText($signing,$app,$revision){
  if(\fixtureMode()==='missing')return ['missing'=>['REDIS']];
  return ['missing'=>[],'config'=>['messages'=>['enabled'=>true],'auth'=>['enabled'=>true],'safety'=>['enabled'=>true,'mode'=>'wechat'],'release'=>['file'=>''],'dependencies'=>['mysql'=>['enabled'=>true],'redis'=>['enabled'=>true]]],'environment'=>['CHAT_FIXTURE'=>'base'],'release_content'=>'fixture-base'];
 }
 function productionFeatures($base,$app,$rev){
  if(\fixtureMode()==='optional-error')throw new \RuntimeException('optional fixture');
  $base['config']['images']=['enabled'=>true];$base['environment']['CHAT_FIXTURE']='optional';$base['release_content']='fixture-optional';$base['pending']=[];return $base;
 }
}
}
namespace WorkerAdmin\Foundation {
class ApplicationContainer {function __construct($db){} function privateCipher(){return new \stdClass();}}
class ChatRuntimeComposition {static function build($db,$cipher){return new \WorkerAdmin\Application\Chat\FixtureRenderer();}}
}
