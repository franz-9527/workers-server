<?php
declare(strict_types=1);

namespace WorkerAdmin\Application\Chat;

/**
 * Best-effort, local-only Go runtime diagnostics.
 * Diagnostics must never make an admin/API request fail.
 */
final class ChatGoRuntimeStatus
{
    public function __construct(private string $environment=''){}

    public function status():array
    {
        $environment=$this->environment!==''?$this->environment:$this->environmentFromPHP();
        $port=$environment==='production'?9501:19501;
        $fallback=[
            'ready'=>false,
            'reason'=>'CHAT_GO_NOT_READY',
            'environment'=>$environment,
            'public_address'=>'127.0.0.1:'.$port,
            'listening'=>false,
            'probe_supported'=>false,
            'current_sha'=>'',
            'deployed_sha'=>'',
            'running_sha'=>'',
            'versions_match'=>false,
            'runtime_config_exists'=>false,
            'last_error'=>'',
            'deployment_state'=>'unknown',
            'deployment_message'=>'暂时无法读取 Go 运行状态。',
        ];

        try{
            $root=dirname(\base_path());
            $state=$root.'/chat/bin/state';
            $current=$this->readCurrent($root.'/chat/bin/current');
            $deployed=$this->readSha($root.'/chat/bin/deployed-sha');
            $running=$this->readSha($state.'/running-sha');
            $lastError=$this->readCode($state.'/last-error');
            [$supported,$listening]=$this->listening('127.0.0.1',$port);

            $deployment=$this->deploymentState($deployed,$current,$running,$supported,$listening,$lastError);
            $ready=in_array($deployment['state'],['ready','ready_current_unreadable'],true);
            return [
                'ready'=>$ready,
                'reason'=>$ready?'ready':'CHAT_GO_NOT_READY',
                'environment'=>$environment,
                'public_address'=>'127.0.0.1:'.$port,
                'listening'=>$listening,
                'probe_supported'=>$supported,
                'current_sha'=>$current,
                'deployed_sha'=>$deployed,
                'running_sha'=>$running,
                'versions_match'=>$deployed!==''&&$running!==''&&hash_equals($deployed,$running)&&($current===''||hash_equals($current,$running)),
                'runtime_config_exists'=>is_file($state.'/chat.runtime.json'),
                'last_error'=>$lastError,
                'deployment_state'=>$deployment['state'],
                'deployment_message'=>$deployment['message'],
            ];
        }catch(\Throwable $e){
            return $fallback;
        }
    }

    /** @return array{state:string,message:string} */
    private function deploymentState(string $deployed,string $current,string $running,bool $probeSupported,bool $listening,string $lastError):array
    {
        if($lastError!==''&&!($running!==''&&$deployed!==''&&hash_equals($deployed,$running)&&$listening)){
            return ['state'=>'error','message'=>$this->errorMessage($lastError)];
        }
        if($deployed==='')return ['state'=>'not_deployed','message'=>'尚未发现 Go 部署记录，请先执行聊天 Go 部署。'];
        if($running==='')return ['state'=>'not_running','message'=>'Go 二进制已部署，但进程尚未运行，请启动或重启宝塔 Go 项目。'];
        if(!hash_equals($deployed,$running))return ['state'=>'version_mismatch','message'=>'正在运行的 Go 版本与已部署版本不一致，请重启 Go 项目。'];
        if($probeSupported&&!$listening)return ['state'=>'not_listening','message'=>'Go 进程有运行记录，但聊天端口没有监听，请检查启动日志。'];
        if(!$probeSupported)return ['state'=>'probe_limited','message'=>'Go 部署与运行版本一致，但 PHP 无法探测本机端口；请以服务器健康检查为准。'];
        if($current==='')return ['state'=>'ready_current_unreadable','message'=>'Go 已正常运行；PHP 当前无法读取 bin/current 链接，这只影响诊断显示，不影响服务。'];
        if(!hash_equals($deployed,$current))return ['state'=>'ready_current_mismatch','message'=>'Go 当前运行正常，但 bin/current 与运行版本不一致；下次重启前建议重新部署修正链接。'];
        return ['state'=>'ready','message'=>'Go 二进制、运行版本与监听状态正常。'];
    }

    private function errorMessage(string $code):string
    {
        $messages=[
            'CHAT_RUN_NO_COMPILED_RELEASE'=>'未找到可运行的 Go 编译产物，请重新执行 Go 部署。',
            'CHAT_RUN_RUNTIME_CONFIG_FAILED'=>'Go 自动运行配置生成失败，请检查 PHP 已安装配置与聊天后台设置。',
            'CHAT_RUN_TEST_CONFIG_REQUIRED'=>'测试站生成的 Go 配置环境不正确。',
            'CHAT_RUN_CONFIG_INVALID_OR_BINARY_STALE'=>'Go 配置校验失败或当前二进制过旧，请重新部署最新 Go 二进制。',
            'CHAT_RUN_CHILD_EXITED'=>'Go serve/worker 子进程启动后退出，请检查 Go 项目日志。',
            'CHAT_DEPLOY_RUNTIME_CONFIG_FAILED'=>'部署阶段无法生成 Go 自动运行配置。',
            'CHAT_DEPLOY_CONFIG_INVALID'=>'新编译的 Go 二进制未通过当前运行配置校验。',
        ];
        return $messages[$code]??'Go 启动器记录到异常，请检查服务器 Go 项目日志。';
    }

    private function environmentFromPHP():string
    {
        try{
            return ['testing'=>'test','development'=>'development','production'=>'production'][(string)\app_config('app.env','production')]??'production';
        }catch(\Throwable $e){
            return 'production';
        }
    }

    /** @return array{0:bool,1:bool} */
    private function listening(string $host,int $port):array
    {
        if(!function_exists('stream_socket_client'))return [false,false];
        try{
            $errno=0;$error='';
            $socket=@stream_socket_client('tcp://'.$host.':'.$port,$errno,$error,0.15,STREAM_CLIENT_CONNECT);
            if(!is_resource($socket))return [true,false];
            @fclose($socket);
            return [true,true];
        }catch(\Throwable $e){
            return [true,false];
        }
    }

    private function readSha(string $path):string
    {
        $value=$this->readSmall($path,64);
        return preg_match('/^[a-f0-9]{40}$/D',$value)===1?$value:'';
    }

    private function readCurrent(string $path):string
    {
        if(!function_exists('readlink'))return '';
        try{$target=@readlink($path);}catch(\Throwable $e){return '';}
        if(!is_string($target))return '';
        return preg_match('#^releases/([a-f0-9]{40})$#D',$target,$match)===1?$match[1]:'';
    }

    private function readCode(string $path):string
    {
        $value=$this->readSmall($path,128);
        return preg_match('/^CHAT_[A-Z0-9_:-]{1,120}$/D',$value)===1?$value:'';
    }

    private function readSmall(string $path,int $max):string
    {
        try{
            if(!is_file($path))return '';
            $size=@filesize($path);
            if(!is_int($size)||$size<1||$size>$max)return '';
            $value=@file_get_contents($path,false,null,0,$max);
            return is_string($value)?trim($value):'';
        }catch(\Throwable $e){
            return '';
        }
    }
}
