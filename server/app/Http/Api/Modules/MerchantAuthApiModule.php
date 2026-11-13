<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\MerchantComposition;
use WorkerAdmin\Http\Api\ApiModule;
use WorkerAdmin\Contracts\WorkerOrderDiscountRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 商家板块：认证提交与本人商家档案读取。 */
final class MerchantAuthApiModule implements ApiModule
{
    private object $actors;
    private object $submission;
    private object $profile;
    private WorkerOrderDiscountRepository $workerDiscount;

    public function __construct(Database $db)
    {
        $deps=MerchantComposition::build($db);
        $this->workerDiscount=$deps['worker_discount'];
        $this->actors=$deps['actors'];$this->submission=$deps['submission'];$this->profile=$deps['profile'];
    }

    public function supports(string $route): bool
    {
        return in_array($route,['merchant.submit','merchant.profile','merchant.license.preview','merchant.worker-discount.submit'],true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return false;
    }

    public function dispatch(string $route): void
    {
        $expected=in_array($route,['merchant.profile','merchant.license.preview'],true)?'GET':'POST';
        if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!==$expected)$this->fail('请求方式不正确','METHOD_NOT_ALLOWED',405);
        $token=$this->bearerToken();
        try{
            if($route==='merchant.profile'){
                $actor=$this->actors->resolve($token);
                $data=$this->profile->read($actor);
                $data['worker_order_discount']=$this->workerDiscount->status($actor);
                \json_response(['ok'=>true,'data'=>$data]);
                return;
            }
            if($route==='merchant.license.preview'){
                $actor=$this->actors->resolve($token);
                $data=$this->profile->licensePreview($actor);
                if($data['url']==='')$this->fail('没有可查看的营业执照','MERCHANT_LICENSE_NOT_FOUND',404);
                \json_response(['ok'=>true,'data'=>$data]);
                return;
            }
            if($route==='merchant.worker-discount.submit'){
                $actor=$this->actors->resolve($token);
                $data=$this->workerDiscount->submit($actor,$this->jsonBody());
                \json_response(['ok'=>true,'data'=>$data]);
                return;
            }
            $data=$this->submission->submit($token,$this->jsonBody());
            \json_response([
                'ok'=>true,
                'message'=>!empty($data['already_submitted'])?'商家认证已提交，正在等待平台审核':'商家认证已提交，等待平台审核',
                'data'=>$data,
            ]);
        }catch(OrderOperationException $exception){
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
