<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Identity;

use WorkerAdmin\Contracts\MiniAdminDemoRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Domain\Admin\MiniAdminOperationException;

final class MiniAdminDemoOperations
{
    private MiniAdminDemoRepository $repository;
    private SettingsProvider $settings;

    public function __construct(MiniAdminDemoRepository $repository, SettingsProvider $settings)
    {
        $this->repository=$repository;$this->settings=$settings;
    }

    /** @return array<string,mixed> */
    public function status(array $actor): array
    {
        $catalog=$this->repository->catalog();$options=[];
        foreach($catalog as $role=>$item){
            $row=$this->repository->findActorOption((string)$item['public_id']);
            $options[]=['role'=>$role,'label'=>$item['label'],'available'=>(bool)$row,'nickname'=>$row?(string)($row['nickname']??''):''];
        }
        $hasDemoBusiness=$this->repository->hasDemoBusiness();
        $activeRole=!empty($actor['is_demo_actor'])?(string)($actor['demo_role']??''):'real';
        $isTestActor=!empty($actor['is_test_actor']);
        $activeLabel='真实管理员';
        if($isTestActor)$activeLabel=$activeRole==='worker'?'测试实例师傅':'测试实例客户';
        elseif(!empty($actor['is_demo_actor']))$activeLabel=(string)($catalog[$activeRole]['label']??'演示身份');
        return ['ok'=>true,'data'=>[
            'enabled'=>$this->settings->bool('demo.data_enabled',false),
            'active_role'=>$activeRole,
            'active_label'=>$activeLabel,
            'is_test_actor'=>$isTestActor,
            'test_instance_id'=>$isTestActor?(int)($actor['test_instance_id']??0):null,
            'test_actor_expires_at'=>$isTestActor?(string)($actor['test_actor_expires_at']??''):null,
            'options'=>$options,
            'seed_version'=>$hasDemoBusiness?(string)$this->settings->get('demo.seed_version',''):'',
            'seed_ready'=>$hasDemoBusiness,
        ]];
    }

    /** @return array<string,mixed> */
    public function switch(array $actor,array $input): array
    {
        $role=strtolower(trim((string)($input['role']??'real')));
        $sessionId=(int)($actor['session_id']??0);
        if($sessionId<1)throw new MiniAdminOperationException('登录已过期，请重新进入小程序','UNAUTHORIZED',401);
        if($role==='real'){
            $this->repository->clearSessionActor($sessionId);
            return ['ok'=>true,'message'=>'已退出演示身份，恢复真实管理员','data'=>['role'=>'real']];
        }
        if(!$this->settings->bool('demo.data_enabled',false))throw new MiniAdminOperationException('演示数据已关闭，请先在后台开启','DEMO_DISABLED',409);
        $catalog=$this->repository->catalog();
        if(!isset($catalog[$role]))throw new MiniAdminOperationException('演示身份不存在','INVALID_DEMO_ROLE',422);
        $target=$this->repository->findActiveTarget((string)$catalog[$role]['public_id']);
        if(!$target)throw new MiniAdminOperationException('演示账号尚未配置，请按当前《演示数据配置与版本兼容》文档同步演示身份','DEMO_ACTOR_MISSING',409);
        $this->repository->switchSessionActor($sessionId,(int)$target['id']);
        $this->repository->auditSwitch($actor,(int)$target['id'],$role,(string)$catalog[$role]['label']);
        return ['ok'=>true,'message'=>'已切换为'.$catalog[$role]['label'],'data'=>['role'=>$role,'nickname'=>(string)($target['nickname']??'')]];
    }
}
