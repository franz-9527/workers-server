<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\BootstrapComposition;
use WorkerAdmin\Http\Api\ApiModule;

final class BootstrapApiModule implements ApiModule
{
    private object $actors;
    private object $reader;

    public function __construct(Database $db)
    {
        $deps=BootstrapComposition::build($db);$this->actors=$deps['actors'];$this->reader=$deps['reader'];
    }

    public function supports(string $route): bool
    {
        return $route==='bootstrap';
    }

    public function requiresContentSecurity(string $route): bool
    {
        return false;
    }

    public function dispatch(string $route): void
    {
        if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')$this->fail('请求方式不正确','METHOD_NOT_ALLOWED',405);
        try{
            $actor=$this->actors->resolve($this->bearerToken());
            \json_response(['ok'=>true,'data'=>$this->reader->read($actor)]);
        }catch(RuntimeException $exception){
            $status=(int)$exception->getCode();if(!in_array($status,[401,403,409,422,500,503],true))$status=500;
            $this->fail($exception->getMessage(),$status===401?'UNAUTHORIZED':'BOOTSTRAP_FAILED',$status);
        }
    }

    private function bearerToken(): string
    {
        $header=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
        if(preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/',$header,$matches)!==1)$this->fail('请重新登录','UNAUTHORIZED',401);
        return $matches[1];
    }

    private function fail(string $message,string $code,int $status): void
    {
        \json_response(['ok'=>false,'code'=>$code,'message'=>$message,'request_id'=>\request_id()],$status);
    }
}
