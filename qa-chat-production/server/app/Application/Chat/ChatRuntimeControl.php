<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use RuntimeException;

/** The web process only queues a fixed test-runtime reload; it never executes shell commands. */
final class ChatRuntimeControl
{
    private function token(string $path):string
    {
        if(@is_link($path)||!@is_file($path)||@filesize($path)>128)return '';
        $value=trim((string)@file_get_contents($path));
        return preg_match('/^[a-f0-9]{64}$/D',$value)===1?$value:'';
    }
    public function status():array
    {
        $state=ChatRuntimePaths::stateDirectory();
        if ($state===null) return ['state'=>'idle'];
        $requested=$this->token($state.'/runtime-reload.request');
        $applied=$this->token($state.'/runtime-reload.applied');
        return ['state'=>$requested===''?'idle':($requested===$applied?'applied':'pending')];
    }
    public function request():array
    {
        $environment=(string)\app_config('app.env','production');
        if(!in_array($environment,['production','testing'],true))return ['requested'=>false,'message'=>'当前环境不支持自动应用。'];
        $state=ChatRuntimePaths::stateDirectory();
        if($environment==='production'&&$state!==null&&!is_link($state)&&!is_dir($state))@mkdir($state,0700,true);
        if($state===null||@is_link($state)||!@is_dir($state)||!@is_writable($state))return ['requested'=>false,'message'=>'配置已保存；启动器状态目录尚不可写，请先完成一次 Go 部署，再点击“应用已保存配置”。'];
        $path=$state.'/runtime-reload.request';$tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';$body=bin2hex(random_bytes(32))."\n";
        if(@is_link($path)||@file_put_contents($tmp,$body,LOCK_EX)!==strlen($body)){@unlink($tmp);throw new RuntimeException('配置已保存，但未能提交应用请求。');}
        @chmod($tmp,0600);
        if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('配置已保存，但未能提交应用请求。');}
        return ['requested'=>true,'message'=>'已提交应用请求；Go 启动器会自动重载配置，刷新本页查看结果。'];
    }
}
