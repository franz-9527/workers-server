<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Identity;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Contracts\IdentityRepository;
use WorkerAdmin\Contracts\WeChatIdentityGateway;
use WorkerAdmin\Contracts\WorkerCloudGateway;
use WorkerAdmin\Domain\Identity\IdentityOperationException;

/** 登录、手机号授权与会员状态统一用例族。 */
final class IdentityAccessOperations
{
    private IdentityRepository $repository;
    private WeChatIdentityGateway $wechat;
    private WorkerCloudGateway $workers;

    public function __construct(IdentityRepository $repository,WeChatIdentityGateway $wechat,WorkerCloudGateway $workers)
    {
        $this->repository=$repository;$this->wechat=$wechat;$this->workers=$workers;
    }

    public function login(string $code,string $clientIp,string $userAgent): array
    {
        $session=$this->wechat->resolveSession($code);
        $appId=(string)($session['app_id']??'');$openid=(string)($session['openid']??'');
        $identity=$this->repository->findWechatIdentity($appId,$openid);
        if(!$identity){
            $cloudUser=[];
            try{$bundle=$this->wechat->fetchUserBundle($openid);$cloudUser=is_array($bundle['user']??null)?$bundle['user']:[];}catch(Throwable $e){$cloudUser=[];}
            $identity=$this->repository->createWechatIdentity($session,$cloudUser);
        }
        $userId=(int)($identity['user_id']??0);
        try{
            $data=$this->workers->invoke('server.owner.get',['openid'=>$openid]);
            $cloudWorker=is_array($data['worker']??null)?$data['worker']:[];
            if($cloudWorker)$this->repository->projectCloudWorker($userId,$openid,$cloudWorker);
        }catch(Throwable $e){/* 登录主链不因旧云师傅资料暂不可用而失败。 */}
        $user=$this->repository->findActiveUser($userId);
        if(!$user)throw new IdentityOperationException('账号已停用，请联系客服','USER_DISABLED',403);
        $plain=bin2hex(random_bytes(32));
        $this->repository->issueLoginSession($userId,hash('sha256',$plain),$clientIp,$userAgent!==''?$userAgent:'wechat-mini-program');
        return ['token'=>$plain,'expires_in'=>2592000];
    }

    public function authorizePhone(array $actor,string $code): array
    {
        $mobile=$this->wechat->resolvePhone($code);
        $this->repository->saveWechatPhone($actor,$mobile);
        return ['message'=>'手机号已授权','data'=>['mobile'=>$this->maskMobile($mobile)]];
    }

    public function membershipSummary(array $actor): array
    {
        if(!empty($actor['is_demo_actor']))return ['active'=>false,'verified'=>false,'source'=>'demo','vip_end_time'=>'','message'=>'演示身份不使用真实会员授权'];
        $userId=(int)($actor['user_id']??0);$openid=trim((string)($actor['payer_openid']??''));
        if($userId<1||$openid==='')return ['active'=>false,'verified'=>false,'source'=>'none','vip_end_time'=>'','message'=>'当前微信登录状态不完整'];
        $this->repository->expireMembershipGrants($userId);$grant=$this->repository->activeMembershipGrant($userId);
        try{
            if($grant){
                $endLocal=(new DateTimeImmutable((string)$grant['expires_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
                $cloud=$this->workers->invoke('server.membershipStatus',['target_openid'=>$openid,'membership_card_id'=>(string)$grant['cloud_card_id']]);
                $cloudEnd=(string)($cloud['vip_end_time']??'');$active=!empty($cloud['member_valid'])&&!empty($cloud['member_bound'])&&$cloudEnd===$endLocal;
                return ['active'=>$active,'verified'=>$active,'source'=>'admin_grant','vip_end_time'=>$cloudEnd?:$endLocal,'message'=>$active?'会员状态已由服务器和云端双重确认':'会员授权状态与云端不一致'];
            }
            $cloud=$this->workers->invoke('server.membershipStatus',['target_openid'=>$openid]);$active=!empty($cloud['member_valid']);
            return ['active'=>$active,'verified'=>false,'source'=>$active?'legacy_cloud':'none','vip_end_time'=>(string)($cloud['vip_end_time']??''),'message'=>$active?'历史会员状态来自云端兼容读取':'未开通会员'];
        }catch(Throwable $e){
            return ['active'=>false,'verified'=>false,'source'=>$grant?'admin_grant':'none','vip_end_time'=>'','message'=>'会员状态暂时无法完成云端校验'];
        }
    }

    private function maskMobile(string $mobile): string
    {
        $digits=preg_replace('/\D+/','',$mobile);if(!is_string($digits)||strlen($digits)!==11)return '';
        return substr($digits,0,3).'****'.substr($digits,-4);
    }
}
