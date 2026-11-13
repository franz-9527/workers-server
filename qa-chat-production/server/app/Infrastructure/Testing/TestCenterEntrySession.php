<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\TestCenterEntryGateway;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 测试中心一次性小程序入口。
 *
 * 明文 token 只在签发响应中返回；数据库仅保存 SHA-256。
 * 兑换后把真实管理员当前 login_session 临时绑定到当前测试实例中的客户/师傅 actor。
 */
final class TestCenterEntrySession implements TestCenterEntryGateway
{
    private const TTL_OPTIONS=[5,10,20,30];

    private Database $db;

    public function __construct(Database $db){$this->db=$db;}

    /** @return array<string,mixed> */
    public function options(string $instancePublicId): array
    {
        try{
            $context=$this->context($instancePublicId);
        }catch(TestCenterException $exception){
            if(!in_array($exception->businessCode(),['TEST_ENTRY_INSTANCE_NOT_WRITABLE','TEST_ENTRY_PRODUCTION_BLOCKED'],true))throw $exception;
            return['enabled'=>false,'message'=>$exception->getMessage(),'workers'=>[],'user'=>['available'=>false]];
        }
        $workers=$this->db->fetchAll(
            "SELECT tp.slot_key,tp.user_id,tp.worker_id,tp.expected_status,w.display_name,w.verification_status
             FROM test_participants tp
             JOIN users u ON u.id=tp.user_id AND u.status='active'
             JOIN worker_profiles w ON w.id=tp.worker_id AND w.user_id=tp.user_id
             WHERE tp.instance_id=:instance
               AND tp.actor_type='worker'
               AND tp.worker_id IS NOT NULL
               AND w.cloud_record_mode='demo_local'
             ORDER BY tp.id",
            ['instance'=>(int)$context['instance']['id']]
        );

        return[
            'enabled'=>true,
            'ttl_options'=>self::TTL_OPTIONS,
            'default_ttl'=>10,
            'user'=>[
                'available'=>$this->activeUser((int)$context['order']['user_id']),
                'role'=>'user',
                'page_key'=>'user_order_detail',
                'label'=>'客户订单详情',
            ],
            'workers'=>array_map(static function(array $row):array{
                return[
                    'slot_key'=>(string)$row['slot_key'],
                    'user_id'=>(int)$row['user_id'],
                    'worker_id'=>(int)$row['worker_id'],
                    'label'=>(string)($row['display_name']?:$row['slot_key']),
                    'expected_status'=>(string)($row['expected_status']??''),
                    'verification_status'=>(string)($row['verification_status']??''),
                    'page_key'=>'worker_order_detail',
                ];
            },$workers),
        ];
    }

    /** @return array<string,mixed> */
    public function issue(string $instancePublicId,string $role,string $workerSlot,int $ttlMinutes,int $adminId): array
    {
        $this->assertTesting();
        if($adminId<1)throw new TestCenterException('管理员身份无效。','TEST_ENTRY_ADMIN_REQUIRED',403);

        $role=strtolower(trim($role));
        if(!in_array($role,['user','worker'],true))throw new TestCenterException('短期入口只支持客户或师傅角色。','TEST_ENTRY_ROLE_INVALID',422);
        if(!in_array($ttlMinutes,self::TTL_OPTIONS,true))throw new TestCenterException('短期入口有效期无效。','TEST_ENTRY_TTL_INVALID',422);

        $context=$this->context($instancePublicId);
        $instance=$context['instance'];$order=$context['order'];
        $actorUserId=0;$actorWorkerId=null;$slot=null;$pageKey='';

        if($role==='user'){
            $actorUserId=(int)$order['user_id'];
            if(!$this->activeUser($actorUserId))throw new TestCenterException('当前测试订单客户主档不可用。','TEST_ENTRY_USER_UNAVAILABLE',409);
            $pageKey='user_order_detail';
        }else{
            $slot=trim($workerSlot);
            if($slot===''||preg_match('/^[A-Za-z0-9._:-]{1,64}$/D',$slot)!==1)throw new TestCenterException('请选择当前实例中的测试师傅。','TEST_ENTRY_WORKER_SLOT_INVALID',422);
            $worker=$this->workerTarget((int)$instance['id'],$slot);
            if(!$worker)throw new TestCenterException('该测试师傅不属于当前实例，或没有受控小程序会话资格。','TEST_ENTRY_WORKER_NOT_AVAILABLE',409);
            $actorUserId=(int)$worker['user_id'];$actorWorkerId=(int)$worker['worker_id'];$pageKey='worker_order_detail';
        }

        $plain=bin2hex(random_bytes(24));
        $hash=hash('sha256',$plain);
        $public=\public_id();
        $this->db->execute("UPDATE test_entry_tokens SET status='expired' WHERE status='active' AND expires_at<=UTC_TIMESTAMP()");
        $this->db->insert(
            "INSERT INTO test_entry_tokens(
                public_id,token_hash,instance_id,actor_role,actor_user_id,actor_worker_id,worker_slot,page_key,status,
                expires_at,created_by_admin_id,created_at
             ) VALUES(
                :public,:hash,:instance,:role,:user_id,:worker_id,:slot,:page_key,'active',
                DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".$ttlMinutes." MINUTE),:admin,UTC_TIMESTAMP()
             )",
            [
                'public'=>$public,'hash'=>$hash,'instance'=>(int)$instance['id'],'role'=>$role,'user_id'=>$actorUserId,
                'worker_id'=>$actorWorkerId,'slot'=>$slot,'page_key'=>$pageKey,'admin'=>$adminId,
            ]
        );
        $expires=(string)$this->db->fetchValue('SELECT expires_at FROM test_entry_tokens WHERE BINARY public_id=BINARY :public',['public'=>$public]);
        $orderNo=(string)$order['public_no'];
        $pageUrl=$this->pageUrl($pageKey,$orderNo);
        return[
            'public_id'=>$public,
            'token'=>$plain,
            'entry_path'=>'pages/testEntry/index?token='.$plain,
            'instance_public_id'=>(string)$instance['public_id'],
            'order_no'=>$orderNo,
            'actor_role'=>$role,
            'worker_slot'=>$slot,
            'page_key'=>$pageKey,
            'page_url'=>$pageUrl,
            'expires_at'=>$expires,
            'ttl_minutes'=>$ttlMinutes,
        ];
    }

    /** @return array<string,mixed> */
    public function redeem(array $realAdminActor,string $plainToken): array
    {
        $this->assertTesting();
        $sessionId=(int)($realAdminActor['session_id']??0);
        $realUserId=(int)($realAdminActor['real_user_id']??$realAdminActor['user_id']??0);
        if($sessionId<1||$realUserId<1)throw new TestCenterException('管理员登录会话已失效，请重新进入小程序。','TEST_ENTRY_SESSION_REQUIRED',401);
        $plainToken=trim($plainToken);
        if(preg_match('/^[a-f0-9]{48}$/iD',$plainToken)!==1)throw new TestCenterException('测试入口无效。','TEST_ENTRY_TOKEN_INVALID',422);
        $hash=hash('sha256',$plainToken);

        $result=$this->db->transaction(function(Database $db)use($hash,$sessionId,$realUserId):array{
            $row=$db->fetch(
                "SELECT et.*,ti.public_id instance_public_id,ti.order_no_snapshot,ti.build_status,ti.archived_at,ti.deleted_at,
                        o.id order_id,o.public_no order_no,o.user_id order_user_id,o.is_demo
                 FROM test_entry_tokens et
                 JOIN test_instances ti ON ti.id=et.instance_id
                 JOIN orders o ON o.id=ti.order_id
                 WHERE BINARY et.token_hash=BINARY :hash
                 LIMIT 1 FOR UPDATE",
                ['hash'=>$hash]
            );
            if(!$row)throw new TestCenterException('测试入口不存在或已经失效。','TEST_ENTRY_NOT_FOUND',404);
            $status=(string)$row['status'];
            if(!in_array($status,['active','used'],true))throw new TestCenterException('该测试入口已经失效，请从后台重新生成。','TEST_ENTRY_ALREADY_USED',409);
            if(strtotime((string)$row['expires_at'].' UTC')<=time()){
                throw new TestCenterException('测试入口已过期，请从后台重新生成。','TEST_ENTRY_EXPIRED',410);
            }
            if($status==='used'&&(int)($row['used_by_session_id']??0)!==$sessionId){
                throw new TestCenterException('该测试入口已经由其它会话使用，请从后台重新生成。','TEST_ENTRY_ALREADY_USED',409);
            }
            $this->assertRowBoundary($row);
            $role=(string)$row['actor_role'];
            if($role==='user'){
                if((int)$row['actor_user_id']!==(int)$row['order_user_id']||!$this->activeUser((int)$row['actor_user_id'])){
                    throw new TestCenterException('测试客户与当前实例已经不一致。','TEST_ENTRY_ACTOR_CHANGED',409);
                }
            }elseif($role==='worker'){
                $worker=$this->workerTarget((int)$row['instance_id'],(string)$row['worker_slot']);
                if(!$worker||(int)$worker['user_id']!==(int)$row['actor_user_id']||(int)$worker['worker_id']!==(int)$row['actor_worker_id']){
                    throw new TestCenterException('测试师傅与当前实例已经不一致。','TEST_ENTRY_ACTOR_CHANGED',409);
                }
            }else{
                throw new TestCenterException('测试入口角色已经失效。','TEST_ENTRY_ROLE_INVALID',409);
            }

            $replayed=$status==='used';
            if($replayed){
                $session=$db->fetch(
                    "SELECT user_id,status,expires_at,test_actor_user_id,test_instance_id,test_actor_expires_at
                     FROM login_sessions WHERE id=:id FOR UPDATE",
                    ['id'=>$sessionId]
                );
                $bindingValid=$session
                    &&(int)$session['user_id']===$realUserId
                    &&(string)$session['status']==='active'
                    &&strtotime((string)$session['expires_at'].' UTC')>time()
                    &&(int)($session['test_actor_user_id']??0)===(int)$row['actor_user_id']
                    &&(int)($session['test_instance_id']??0)===(int)$row['instance_id']
                    &&(string)($session['test_actor_expires_at']??'')===(string)$row['expires_at'];
                if(!$bindingValid)throw new TestCenterException('该一次性入口已经使用，当前会话绑定也已结束，请重新生成。','TEST_ENTRY_ALREADY_USED',409);
            }else{
                $claimed=$db->execute(
                    "UPDATE test_entry_tokens
                     SET status='used',used_at=UTC_TIMESTAMP(),used_by_session_id=:session
                     WHERE id=:id AND status='active' AND expires_at>UTC_TIMESTAMP()",
                    ['session'=>$sessionId,'id'=>(int)$row['id']]
                );
                if($claimed!==1)throw new TestCenterException('测试入口已被使用，请重新生成。','TEST_ENTRY_ALREADY_USED',409);

                $bound=$db->execute(
                    "UPDATE login_sessions
                     SET demo_actor_user_id=NULL,test_actor_user_id=:actor_user,test_instance_id=:instance,
                         test_actor_expires_at=:expires,last_seen_at=UTC_TIMESTAMP()
                     WHERE id=:session AND user_id=:real_user AND status='active' AND expires_at>UTC_TIMESTAMP()",
                    [
                        'actor_user'=>(int)$row['actor_user_id'],'instance'=>(int)$row['instance_id'],
                        'expires'=>(string)$row['expires_at'],'session'=>$sessionId,'real_user'=>$realUserId,
                    ]
                );
                if($bound!==1)throw new TestCenterException('管理员登录会话已失效，请重新登录。','TEST_ENTRY_SESSION_REQUIRED',401);
            }

            return[
                'replayed'=>$replayed,
                'entry_public_id'=>(string)$row['public_id'],
                'instance_public_id'=>(string)$row['instance_public_id'],
                'order_no'=>(string)$row['order_no'],
                'actor_role'=>$role,
                'worker_slot'=>$row['worker_slot']!==null?(string)$row['worker_slot']:null,
                'page_key'=>(string)$row['page_key'],
                'page_url'=>$this->pageUrl((string)$row['page_key'],(string)$row['order_no']),
                'expires_at'=>(string)$row['expires_at'],
            ];
        });

        if(empty($result['replayed'])){
            \audit('test_center.entry_redeem','login_session',$sessionId,'管理员小程序兑换测试中心短期入口',[
                'entry_public_id'=>$result['entry_public_id'],'instance_public_id'=>$result['instance_public_id'],
                'actor_role'=>$result['actor_role'],'worker_slot'=>$result['worker_slot'],
            ]);
        }
        return$result;
    }

    /** @param array<string,mixed> $row */
    private function assertRowBoundary(array $row): void
    {
        if((string)$row['build_status']!=='built'||!empty($row['archived_at'])||!empty($row['deleted_at'])){
            throw new TestCenterException('测试实例当前不可进入。','TEST_ENTRY_INSTANCE_NOT_WRITABLE',409);
        }
        if((int)$row['is_demo']!==1||(string)$row['order_no']!==(string)$row['order_no_snapshot']||strpos((string)$row['order_no'],'BXDEMOHALL')===0){
            throw new TestCenterException('测试入口不属于可写测试实例。','TEST_ENTRY_ORDER_BOUNDARY',403);
        }
    }

    /** @return array{instance:array<string,mixed>,order:array<string,mixed>} */
    private function context(string $instancePublicId): array
    {
        $this->assertTesting();
        $instance=$this->db->fetch(
            "SELECT * FROM test_instances WHERE BINARY public_id=BINARY :public LIMIT 1",
            ['public'=>trim($instancePublicId)]
        );
        if(!$instance)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
        if((string)$instance['build_status']!=='built'||!empty($instance['archived_at'])||!empty($instance['deleted_at'])||empty($instance['order_id'])){
            throw new TestCenterException('测试实例当前不能生成小程序入口。','TEST_ENTRY_INSTANCE_NOT_WRITABLE',409);
        }
        $order=$this->db->fetch('SELECT * FROM orders WHERE id=:id LIMIT 1',['id'=>(int)$instance['order_id']]);
        if(!$order||(int)$order['is_demo']!==1||(string)$order['public_no']!==(string)$instance['order_no_snapshot']||strpos((string)$order['public_no'],'BXDEMOHALL')===0){
            throw new TestCenterException('订单不属于当前测试实例。','TEST_ENTRY_ORDER_BOUNDARY',403);
        }
        return['instance'=>$instance,'order'=>$order];
    }


    private function assertTesting(): void
    {
        if(!TestingEnvironmentPolicy::active()){
            throw new TestCenterException('测试中心短期入口仅允许 testing 环境。','TEST_ENTRY_PRODUCTION_BLOCKED',403);
        }
    }

    private function activeUser(int $userId): bool
    {
        return$userId>0&&(bool)$this->db->fetchValue("SELECT 1 FROM users WHERE id=:id AND status='active' LIMIT 1",['id'=>$userId]);
    }

    /** @return array<string,mixed>|null */
    private function workerTarget(int $instanceId,string $slot): ?array
    {
        $row=$this->db->fetch(
            "SELECT tp.slot_key,tp.user_id,tp.worker_id,tp.expected_status,w.display_name,w.verification_status
             FROM test_participants tp
             JOIN users u ON u.id=tp.user_id AND u.status='active'
             JOIN worker_profiles w ON w.id=tp.worker_id AND w.user_id=tp.user_id
             WHERE tp.instance_id=:instance
               AND BINARY tp.slot_key=BINARY :slot
               AND tp.actor_type='worker'
               AND tp.worker_id IS NOT NULL
               AND w.cloud_record_mode='demo_local'
             LIMIT 1",
            ['instance'=>$instanceId,'slot'=>$slot]
        );
        return$row?:null;
    }

    private function pageUrl(string $pageKey,string $orderNo): string
    {
        if($pageKey==='user_order_detail')return'pages/userOrder/detail?id='.rawurlencode($orderNo);
        if($pageKey==='worker_order_detail')return'pages/workerOrder/detail?id='.rawurlencode($orderNo);
        throw new TestCenterException('测试入口目标页面不受支持。','TEST_ENTRY_PAGE_INVALID',409);
    }
}
