<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Admin\MiniAdminOperationException;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Foundation\MiniAdminDemoComposition;
use WorkerAdmin\Http\Api\ApiModule;

final class MiniAdminDemoApiModule implements ApiModule
{
    private object $actors;
    private object $operations;
    private object $testEntry;

    public function __construct(Database $db)
    {
        $deps=MiniAdminDemoComposition::build($db);$this->actors=$deps['actors'];$this->operations=$deps['operations'];$this->testEntry=$deps['test_entry'];
    }

    public function supports(string $route): bool
    {
        return in_array($route,['admin.demo.status','admin.demo.switch','admin.test-entry.redeem'],true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return false;
    }

    public function dispatch(string $route): void
    {
        $expected=$route==='admin.demo.status'?'GET':'POST';
        if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!==$expected)$this->fail('请求方式不正确','METHOD_NOT_ALLOWED',405);
        try{
            $actor=$this->actors->resolve($this->bearerToken());
            if(!$this->actors->isRealMiniAdmin($actor))$this->fail('仅真实管理员可使用演示/测试身份','FORBIDDEN',403);
            if($route==='admin.demo.status')$result=$this->operations->status($actor);
            elseif($route==='admin.test-entry.redeem')$result=['ok'=>true,'message'=>'测试入口已生效','data'=>$this->testEntry->redeem($actor,(string)($this->jsonBody()['token']??''))];
            else$result=$this->operations->switch($actor,$this->jsonBody());
            \json_response($result);
        }catch(MiniAdminOperationException $exception){
            \json_response(['ok'=>false,'code'=>$exception->apiCode(),'message'=>$exception->getMessage(),'data'=>$exception->data(),'request_id'=>\request_id()],(int)$exception->getCode());
        }catch(TestCenterException $exception){
            \json_response(['ok'=>false,'code'=>$exception->businessCode(),'message'=>$exception->getMessage(),'data'=>$exception->details(),'request_id'=>\request_id()],$exception->httpStatus());
        }catch(RuntimeException $exception){
            $status=(int)$exception->getCode();if(!in_array($status,[401,403,409,422,500,503],true))$status=422;
            $this->fail($exception->getMessage(),$status===401?'UNAUTHORIZED':'DEMO_OPERATION_FAILED',$status);
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
