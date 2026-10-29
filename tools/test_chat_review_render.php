<?php
declare(strict_types=1);
set_error_handler(function(int $l,string $m,string $f,int $n):bool{throw new ErrorException($m,0,$l,$f,$n);});
spl_autoload_register(function(string $class):void{if(strpos($class,'WorkerAdmin\\')!==0)return;$p=dirname(__DIR__).'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require $p;});
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function admin_url($r,$p=[]):string{return '/admin/?'.http_build_query(['route'=>$r]+$p);}
function csrf(){return new class{function token(){return 'fixture-csrf';}};}
function view($name,$data):string{$GLOBALS['view_data']=$data;extract($data);ob_start();require dirname(__DIR__).'/server/views/'.$name.'.php';return ob_get_clean();}
final class FixtureRedirect extends RuntimeException {}
function redirect($url):void{throw new FixtureRedirect($url);}
class FixtureAuth extends WorkerAdmin\Core\Auth{
 public function __construct(public string $role){}
 public function user(){return ['id'=>1,'role'=>$this->role];}
 public function requirePermission($permission){if(!$this->can($permission))throw new RuntimeException('permission denied');}
}
$db=new WorkerAdmin\Core\Database([]);$csrf=new WorkerAdmin\Core\Csrf();
$operations=new FixtureAuth('operations');$finance=new FixtureAuth('finance');
check($operations->can('audit.view')&&$operations->can('chat.images.review')&&$operations->can('chat.media.review'),'existing administrator roles must work without a mini grant');
$module=new WorkerAdmin\Http\Admin\Modules\ChatGovernanceAdminModule($db,$operations,$csrf);
$_SERVER['REQUEST_METHOD']='GET';$_GET=[];
ob_start();$module->dispatch('chat.governance');$html=ob_get_clean();
check(substr_count($html,'data-audit-tab=')===3&&str_contains($html,'无需另行申请抽检授权'),'three unified sections');
check(!str_contains($html,'name="user_id"')&&!str_contains($html,'保存独立授权'),'obsolete grants UI removed');
check(str_contains($html,'id="cg-search"')&&str_contains($html,'id="cg-review-list"'),'query and consolidated queue present');
libxml_use_internal_errors(true);$doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($doc);
check($xp->query('//form//form')->length===0,'nested forms');
check($xp->query('//*[@id="cg-review-type"]/option')->length===3,'unified type filters');
$css=file_get_contents(dirname(__DIR__).'/server/assets/admin.css');$js=file_get_contents(dirname(__DIR__).'/server/assets/chat-governance.js');
if(!is_dir('test-artifacts'))mkdir('test-artifacts',0775,true);
file_put_contents('test-artifacts/chat-review.html','<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'.$css.'</style></head><body class="admin-body"><main style="max-width:1280px;margin:auto;padding:20px">'.$html.'</main><script>'.$js.'</script></body></html>');
$financeModule=new WorkerAdmin\Http\Admin\Modules\ChatGovernanceAdminModule($db,$finance,$csrf);
ob_start();$financeModule->dispatch('chat.governance');$financeHTML=ob_get_clean();
check(substr_count($financeHTML,'data-audit-tab=')===1,'read-only finance has records without review actions');
foreach([
 [$module,'chat.auditors','section='],
 [new WorkerAdmin\Http\Admin\Modules\ChatImageReviewAdminModule($db,$operations,$csrf),'chat.image-review','type=image'],
 [new WorkerAdmin\Http\Admin\Modules\ChatAVReviewAdminModule($db,$operations,$csrf),'chat.av-review','type=av'],
] as [$instance,$route,$match]){
 try{$instance->dispatch($route);throw new LogicException('old entry not redirected');}
 catch(FixtureRedirect $r){check(str_contains($r->getMessage(),'chat.governance'),'unified redirect');if($route!=='chat.auditors')check(str_contains($r->getMessage(),$match),'type filter preserved');}
}
$_SERVER['REQUEST_METHOD']='POST';
try{$module->dispatch('chat.auditors');throw new LogicException('retired grant accepted POST');}catch(FixtureRedirect $r){throw new LogicException('retired write silently redirected');}catch(RuntimeException $e){check(http_response_code()===405,'retired grant POST must not mutate');}
$_SERVER['REQUEST_METHOD']='GET';
$viewer=new WorkerAdmin\Http\Admin\Modules\ChatGovernanceAdminModule($db,new FixtureAuth('viewer'),$csrf);
try{$viewer->dispatch('chat.governance');throw new LogicException('unprivileged viewer received audit');}catch(RuntimeException $e){check($e->getMessage()==='permission denied','viewer restriction retained');}
$layout=file_get_contents(dirname(__DIR__).'/server/views/layouts/admin.php');
foreach(["['chat.auditors',","['chat.image-review',","['chat.av-review',"] as $old)check(!str_contains($layout,$old),'duplicate sidebar entry remains');
check(str_contains($layout,"['chat.governance', '聊天审核'"),'single sidebar entry');
echo "真实统一审核页面、角色默认权限、旧入口重定向、退役授权写入拒绝和单一菜单通过\n";
