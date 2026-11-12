<?php
declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Chat\ChatGoRuntimeConfig;
use WorkerAdmin\Application\Chat\ChatGoRuntimeStatus;
use WorkerAdmin\Application\Chat\ChatRuntimeControl;
use WorkerAdmin\Contracts\PrivateValueCipher;

$errors=[];
set_error_handler(static function (int $level,string $message) use (&$errors):bool {
    if (str_contains($message,'open_basedir') || (error_reporting()&$level)) $errors[]=$message;
    return true;
});
spl_autoload_register(static function (string $class):void {
    if (!str_starts_with($class,'WorkerAdmin\\')) return;
    require __DIR__.'/app/'.str_replace('\\','/',substr($class,12)).'.php';
});
function base_path($path=''){return __DIR__.($path===''?'':'/'.$path);}
function app_config($key,$default=null){
    return [
        'app.env'=>getenv('CHAT_LAYOUT_ENV'),'app.url'=>'https://fixture.example',
        'database'=>['host'=>'127.0.0.1','database'=>'fixture_db','username'=>'fixture','password'=>'fixture-only'],
    ][$key]??$default;
}
function setting($key,$default=null){return $default;}
function e($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function admin_url($route,$params=[]){return '/admin/?'.http_build_query(['route'=>$route]+$params);}
function csrf(){return new class {public function input(){return '';}};}
$db=new class extends Database {
    public function __construct(){}
    public function fetchAll($sql,array $params=[]){return [];}
    public function fetchValue($sql,array $params=[]){return null;}
};
$cipher=new class implements PrivateValueCipher {
    public function hmac(string $p,string $c):string{return '';}
    public function encrypt(string $p,string $c):?string{return null;}
    public function decrypt(string $p,string $c):string{return '';}
};
$runtime=new ChatGoRuntimeConfig($db,$cipher);
$status=new ChatGoRuntimeStatus();
$control=new ChatRuntimeControl();
$goRuntime=$runtime->status([]);
$goStatus=$status->status();
$runtimeControl=$control->status();
$enabled=false;$readiness=['ready'=>false,'reason'=>'CHAT_GO_NOT_READY'];$notice='';$canManage=false;$secretValues=[];$signing=[];
ob_start();
require __DIR__.'/views/admin/chat-settings.php';
$html=ob_get_clean();
$data=[
    'environment'=>$goRuntime['environment'],
    'diagnostic'=>$goStatus['deployment_message'],
    'go_state'=>$goStatus['deployment_state'],
    'runtime_config'=>$goStatus['runtime_config_exists'],
    'deployed'=>$goStatus['deployed_sha'],'running'=>$goStatus['running_sha'],
    'calls'=>$status->callsEnabled(),
    'reload'=>$runtimeControl['state'],
    'rendered'=>str_contains($html,'聊天设置')&&str_contains($html,$goStatus['deployment_message']),
];
if (isset($_GET['config'])) {
    try {
        $rendered=$runtime->render([]);
        $data['php_script']=$rendered['config']['images']['php_script'];
        $data['python_script']=$rendered['config']['images']['python_script'];
    } catch (RuntimeException $error) {
        $data['config_error']=$error->getMessage();
    }
}
if (isset($_GET['apply'])) $data['apply']=$control->request()['requested'];
$data['errors']=$errors;
header('Content-Type: application/json');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
