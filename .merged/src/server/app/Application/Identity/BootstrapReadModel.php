<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Identity;

use WorkerAdmin\Contracts\BootstrapRepository;
use WorkerAdmin\Contracts\IdentityRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\WorkerCloudGateway;

final class BootstrapReadModel
{
    private BootstrapRepository $repository;
    private IdentityRepository $identityRepository;
    private WorkerCloudGateway $workerCloud;
    private SettingsProvider $settings;
    private ActorCapabilityResolver $capabilities;

    public function __construct(
        BootstrapRepository $repository,
        IdentityRepository $identityRepository,
        WorkerCloudGateway $workerCloud,
        SettingsProvider $settings,
        ActorCapabilityResolver $capabilities
    ) {
        $this->repository=$repository;$this->identityRepository=$identityRepository;$this->workerCloud=$workerCloud;
        $this->settings=$settings;$this->capabilities=$capabilities;
    }

    /** @return array<string,mixed> */
    public function read(array $actor): array
    {
        $actor=$this->refreshWorkerProjection($actor);
        $data=$this->repository->read($actor);
        $workerId=(int)($actor['worker_id']??0);
        $commissionBlocked=$workerId>0&&$this->repository->hasOverdueCommission($workerId);
        $businessAdmin=$this->capabilities->isMiniAdmin($actor);
        $realAdmin=$this->capabilities->isRealMiniAdmin($actor);
        $workerStatus=$workerId>0?$this->normalizedWorkerStatus($actor):'none';
        $invitedOnly=$this->workerIsInvitedOnly($actor);
        $quotesPaused=$this->boolSetting('development.pause_quotes');
        $identity=[
            'id'=>(string)($actor['user_public_id']??''),'nickname'=>(string)($actor['nickname']??''),'avatar_url'=>$actor['avatar_url']??null,
            'is_worker'=>$workerId>0,'worker_status'=>$workerStatus,'worker_name'=>(string)($actor['worker_name']??''),
            'worker_status_reason'=>(string)($actor['worker_status_reason']??''),'invited_only'=>$invitedOnly,
            'can_view_hall'=>true,'hall_view_mode'=>$businessAdmin?'admin':($workerId>0?'worker':'public'),
            'can_quote_hall'=>!$quotesPaused&&$workerStatus==='approved'&&!$invitedOnly&&!$commissionBlocked,
            'commission_overdue'=>$commissionBlocked,
            'can_quote'=>!$quotesPaused&&$workerStatus==='approved'&&!$invitedOnly&&!$commissionBlocked,
            'hall_paused'=>$this->boolSetting('development.pause_hall'),'quotes_paused'=>$quotesPaused,
            'is_admin'=>$realAdmin,'business_admin'=>$businessAdmin,
            'demo_mode'=>!empty($actor['is_demo_actor']),'demo_role'=>(string)($actor['demo_role']??''),
        ];
        $data['identity']=$identity;
        $data['distance_warning_km']=(int)$this->settings->get('hall.distance_warning_km',50);
        $data['page_size']=20;
        $data['maintenance']=[
            'hall_paused'=>$this->boolSetting('development.pause_hall'),
            'quotes_paused'=>$quotesPaused,
            'orders_paused'=>$this->boolSetting('development.pause_orders'),
        ];
        return $data;
    }

    /** @return array<string,mixed> */
    private function refreshWorkerProjection(array $actor): array
    {
        if(!empty($actor['is_demo_actor']))return $actor;
        $userId=(int)($actor['user_id']??0);$openid=trim((string)($actor['payer_openid']??''));
        if($userId<1||$openid==='')return $actor;
        try{
            $data=$this->workerCloud->invoke('server.owner.get',['openid'=>$openid]);
            $cloudWorker=is_array($data['worker']??null)?$data['worker']:[];
            if($cloudWorker)$this->identityRepository->projectCloudWorker($userId,$openid,$cloudWorker);
        }catch(\Throwable $exception){
            \write_log('cloud-worker-runtime-sync','['.date('c').'] user='.$userId.' '.mb_substr($exception->getMessage(),0,300)."\n");
            return $actor;
        }
        $fresh=$this->repository->freshWorkerActor($userId);
        if(!$fresh)return $actor;
        foreach($fresh as $key=>$value)$actor[$key]=$value;
        $actor['worker_id']=$actor['worker_id']===null?null:(int)$actor['worker_id'];
        return $actor;
    }

    private function normalizedWorkerStatus(array $actor): string
    {
        $status=strtolower(trim((string)($actor['worker_status']??'')));
        if($status===''||in_array($status,['reviewing','submitted','waiting','待审核','审核中'],true))return 'pending';
        if(in_array($status,['通过','已通过','verified'],true))return 'approved';
        return $status;
    }

    private function workerIsInvitedOnly(array $actor): bool
    {
        $value=$actor['invited_only']??false;
        if(is_bool($value))return $value;
        if(is_numeric($value))return (int)$value>0;
        return in_array(strtolower(trim((string)$value)),['1','true','yes','on'],true);
    }

    private function boolSetting(string $key): bool
    {
        $value=$this->settings->get($key,false);
        if(is_bool($value))return $value;
        if(is_numeric($value))return (int)$value>0;
        return in_array(strtolower(trim((string)$value)),['1','true','yes','on','enabled'],true);
    }
}
