<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Admin\MiniAdminOperationException;
use WorkerAdmin\Foundation\MiniAdminOrderComposition;
use WorkerAdmin\Http\Api\ApiModule;

final class MiniAdminOrderApiModule implements ApiModule
{
    private object $actors;
    private object $operations;

    public function __construct(Database $db)
    {
        $deps=MiniAdminOrderComposition::build($db);$this->actors=$deps['actors'];$this->operations=$deps['operations'];
    }

    public function supports(string $route): bool
    {
        return in_array($route,['admin.order.action','admin.order.quote','admin.order.share'],true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return false;
    }

    public function dispatch(string $route): void
    {
        if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')$this->fail('请求方式不正确','METHOD_NOT_ALLOWED',405);
        try{
            $actor=$this->actors->resolve($this->bearerToken());
            if(!empty($actor['is_demo_actor']))$this->fail('当前为演示身份，请先退出演示身份后处理真实管理员业务','DEMO_ADMIN_ACTION_BLOCKED',403);
            \json_response($this->operations->execute($route,$actor,$this->jsonBody()));
        }catch(MiniAdminOperationException $exception){
            \json_response(['ok'=>false,'code'=>$exception->apiCode(),'message'=>$exception->getMessage(),'data'=>$exception->data(),'request_id'=>\request_id()],(int)$exception->getCode());
        }catch(RuntimeException $exception){
            $status=(int)$exception->getCode();if(!in_array($status,[401,403,404,409,422,500,503],true))$status=409;
            $code=$status===401?'UNAUTHORIZED':($status===403?'ADMIN_REQUIRED':'ADMIN_ORDER_OPERATION_FAILED');
            $this->fail($exception->getMessage(),$code,$status);
        }
    }

    private function bearerToken(): string
    {
        $header=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
        if(preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/',$header,$matches)!==1)$this->fail('请重新登录','UNAUTHORIZED',401);
        return $matches[1];
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw=file_get_contents('php://input');$body=json_decode(is_string($raw)?$raw:'',true);return is_array($body)?$body:[];
    }

    private function fail(string $message,string $code,int $status): void
    {
        \json_response(['ok'=>false,'code'=>$code,'message'=>$message,'request_id'=>\request_id()],$status);
    }
}
