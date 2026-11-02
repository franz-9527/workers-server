<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\MiniAdminDemoRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;

final class DatabaseMiniAdminDemoRepository implements MiniAdminDemoRepository
{
    private Database $db;
    private SettingsProvider $settings;

    public function __construct(Database $db, SettingsProvider $settings)
    {
        $this->db=$db;$this->settings=$settings;
    }

    public function catalog(): array
    {
        $definitions=[
            'user'=>['label'=>'演示用户','setting'=>'demo.actor_user_public_id','join'=>'','condition'=>"BINARY u.public_id LIKE BINARY '01K2DEMOV%USER%'"],
            'merchant'=>['label'=>'演示商家','setting'=>'demo.actor_merchant_public_id','join'=>' INNER JOIN merchant_profiles m ON m.user_id=u.id','condition'=>"BINARY u.public_id LIKE BINARY '01K2DEMOV%MERCHANT%'"],
            'worker'=>['label'=>'演示师傅','setting'=>'demo.actor_worker_public_id','join'=>' INNER JOIN worker_profiles w ON w.user_id=u.id','condition'=>"BINARY u.public_id LIKE BINARY '01K2DEMOV%WORKER%'"],
        ];
        $catalog=[];
        foreach($definitions as $role=>$definition){
            $publicId='';$configured=trim((string)$this->settings->get($definition['setting'],''));
            if($configured!==''){
                $row=$this->db->fetch("SELECT u.public_id FROM users u".$definition['join']." WHERE u.status='active' AND BINARY u.public_id=BINARY :public_id LIMIT 1",['public_id'=>$configured]);
                if($row)$publicId=(string)$row['public_id'];
            }
            if($publicId===''){
                $row=$this->db->fetch("SELECT u.public_id FROM users u".$definition['join']." WHERE u.status='active' AND ".$definition['condition']." ORDER BY u.id DESC LIMIT 1");
                if($row)$publicId=(string)$row['public_id'];
            }
            $catalog[$role]=['public_id'=>$publicId,'label'=>$definition['label']];
        }
        if (\WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy::active()) {
            foreach ([1=>'A',2=>'B',3=>'C'] as $slot=>$label) {
                $row=$this->db->fetch("SELECT u.public_id FROM users u JOIN worker_profiles w ON w.user_id=u.id WHERE BINARY u.public_id=BINARY :public_id AND u.status='active' AND w.cloud_record_mode='demo_local' LIMIT 1", ['public_id'=>'01KTESTV260WORKER00000000'.$slot]);
                $catalog['test_worker_'.$slot]=['public_id'=>$row?(string)$row['public_id']:'','label'=>'测试师傅 '.$label.'（模拟订单）'];
            }
        }
        return $catalog;
    }

    public function findActorOption(string $publicId): ?array
    {
        if($publicId==='')return null;
        $row=$this->db->fetch("SELECT u.id,u.nickname,CASE WHEN w.id IS NULL THEN 0 ELSE 1 END is_worker,CASE WHEN m.id IS NULL THEN 0 ELSE 1 END is_merchant FROM users u LEFT JOIN worker_profiles w ON w.user_id=u.id LEFT JOIN merchant_profiles m ON m.user_id=u.id WHERE BINARY u.public_id=BINARY :public_id LIMIT 1",['public_id'=>$publicId]);
        return $row?:null;
    }

    public function hasDemoBusiness(): bool
    {
        return (bool)$this->db->fetchValue("SELECT 1 FROM orders WHERE is_demo=1 LIMIT 1");
    }

    public function clearSessionActor(int $sessionId): void
    {
        $this->db->execute("UPDATE login_sessions SET demo_actor_user_id=NULL,test_actor_user_id=NULL,test_instance_id=NULL,test_actor_expires_at=NULL WHERE id=:id",['id'=>$sessionId]);
    }

    public function findActiveTarget(string $publicId): ?array
    {
        if($publicId==='')return null;
        $row=$this->db->fetch("SELECT id,nickname FROM users WHERE BINARY public_id=BINARY :public_id AND status='active' LIMIT 1",['public_id'=>$publicId]);
        return $row?:null;
    }

    public function switchSessionActor(int $sessionId,int $demoUserId): void
    {
        $this->db->execute("UPDATE login_sessions SET demo_actor_user_id=:demo_id,test_actor_user_id=NULL,test_instance_id=NULL,test_actor_expires_at=NULL WHERE id=:id",['demo_id'=>$demoUserId,'id'=>$sessionId]);
    }

    public function auditSwitch(array $actor,int $demoUserId,string $role,string $label): void
    {
        \audit('mini.demo.switch','login_session',(int)($actor['session_id']??0),'管理员当前会话切换为'.$label,[
            'real_user_id'=>(int)($actor['real_user_id']??$actor['user_id']??0),'demo_user_id'=>$demoUserId,'demo_role'=>$role,
        ]);
    }
}
