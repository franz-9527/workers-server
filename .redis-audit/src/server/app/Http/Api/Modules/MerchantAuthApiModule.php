<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\MerchantComposition;
use WorkerAdmin\Http\Api\ApiModule;

/** 商家板块：认证提交与本人商家档案读取。 */
final class MerchantAuthApiModule implements ApiModule
{
    private object $actors;
    private object $submission;
    private object $profile;

    public function __construct(Database $db)
    {
        $deps=MerchantComposition::build($db);
        $this->actors=$deps['actors'];$this->submission=$deps['submission'];$this->profile=$deps['profile'];
    }

    public function supports(string $route): bool
    {
        return in_array($route,['merchant.submit','merchant.profile'],true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return false;
    }

    public function dispatch(string $route): void
    {
        $expected=$route==='merchant.profile'?'GET':'POST';
        if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!==$expected)$this->fail('请求方式不正确','METHOD_NOT_ALLOWED',405);
        $token=$this->bearerToken();
        try{
            if($route==='merchant.profile'){
                $actor=$this->actors->resolve($token);
                \json_response(['ok'=>true,'data'=>$this->profile->read($actor)]);
                return;
            }
            $data=$this->submission->submit($token,$this->jsonBody());
            \json_response([
                'ok'=>true,
                'message'=>!empty($data['already_submitted'])?'商家认证已提交，正在等待平台审核':'商家认证已提交，等待平台审核',
                'data'=>$data,
            ]);
        }catch(\WorkerAdmin\Domain\Order\OrderOperationException $exception){
            $this->fail($exception->getMessage(),$exception->apiCode(),(int)$exception->getCode());
        }catch(RuntimeException $exception){
            $status=(int)$exception->getCode();
            if($status===503)$this->fail($exception->getMessage(),'MINIAPP_CONFIG_MISSING',503);
            if(!in_array($status,[401,409,422,500],true))$status=422;
            $code=$status===401?'UNAUTHORIZED':($route==='merchant.profile'?'MERCHANT_PROFILE_FAILED':($status===409?'MERCHANT_AUTH_CONFLICT':($status===422?'MERCHANT_AUTH_INVALID':'MERCHANT_AUTH_FAILED')));
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
        $raw=file_get_contents('php://input');$body=json_decode(is_string($raw)?$raw:'',true);
        if(!is_array($body))$this->fail('请求数据格式不正确','INVALID_JSON',422);
        return $body;
    }

    private function fail(string $message,string $code,int $status): void
    {
        \json_response(['ok'=>false,'code'=>$code,'message'=>$message,'request_id'=>\request_id()],$status);
    }
}
