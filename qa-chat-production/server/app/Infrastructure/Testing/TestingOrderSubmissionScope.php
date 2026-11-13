<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\OrderSubmissionScope;
use WorkerAdmin\Contracts\ProxyOrderCustomerResolver;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 普通代录测试的可信实例域；不创建微信身份，不接收真实客户 ID。 */
final class TestingOrderSubmissionScope implements OrderSubmissionScope,ProxyOrderCustomerResolver
{
    private Database $db;private int $instanceId;
    public function __construct(Database $db,int $instanceId){$this->db=$db;$this->instanceId=$instanceId;$this->instance();}
    public function isDemo(): bool{$this->instance();return true;}
    public function resolve(string $publicId,bool $lock=false): array
    {
        $this->instance();
        $u=$this->db->fetch("SELECT u.id user_id,u.public_id user_public_id,u.nickname,u.mobile_masked FROM test_participants tp JOIN users u ON u.id=tp.user_id WHERE tp.instance_id=:instance AND tp.slot_key='customer' AND tp.actor_type='user' AND BINARY u.public_id=BINARY :public AND u.status='active' LIMIT 1".($lock?' FOR UPDATE':''),['instance'=>$this->instanceId,'public'=>$publicId]);
        if(!$u||!(strpos((string)$u['user_public_id'],'01K2DEMOV')===0||strpos((string)$u['user_public_id'],'01KTEST')===0))throw new TestCenterException('客户不是当前实例登记的合成测试客户。','TEST_PROXY_CUSTOMER_BOUNDARY',403);
        return$u+['real_user_id'=>(int)$u['user_id'],'is_demo_actor'=>true,'demo_role'=>'user','is_admin'=>false,'business_admin'=>false,'admin_proxy_consumer'=>true];
    }
    public function assertActor(array $actor): void
    {
        $this->instance();
        if(empty($actor['is_demo_actor'])||!empty($actor['is_admin'])||!empty($actor['business_admin']))throw new TestCenterException('下单测试客户不得继承管理员或真实付款人身份。','TEST_SUBMISSION_ACTOR_BOUNDARY',403);
        $id=(int)$this->db->fetchValue("SELECT user_id FROM test_participants WHERE instance_id=:instance AND slot_key='customer' AND actor_type='user'",['instance'=>$this->instanceId]);
        if($id<1||$id!==(int)($actor['user_id']??0))throw new TestCenterException('客户不属于当前测试实例。','TEST_SUBMISSION_ACTOR_BOUNDARY',403);
    }
    public function bindDraft(array $actor,int $orderId): void
    {
        $this->assertActor($actor);$i=$this->instance();
        $o=$this->db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>$orderId]);
        if(!$o||(int)$o['is_demo']!==1||(int)$o['user_id']!==(int)$actor['user_id']||strpos((string)$o['public_no'],'BXDEMOHALL')===0||(!empty($i['order_id'])&&(int)$i['order_id']!==$orderId))throw new TestCenterException('测试草稿归属不符。','TEST_SUBMISSION_ORDER_BOUNDARY',409);
        $foreign=(int)$this->db->fetchValue('SELECT COUNT(*) FROM test_instances WHERE order_id=:order AND id<>:instance',['order'=>$orderId,'instance'=>$this->instanceId]);
        if($foreign>0)throw new TestCenterException('草稿已经属于其它测试实例。','TEST_SUBMISSION_ORDER_BOUNDARY',409);
        $this->db->execute('UPDATE test_instances SET order_id=:order,order_no_snapshot=:no,updated_at=UTC_TIMESTAMP() WHERE id=:id',['order'=>$orderId,'no'=>$o['public_no'],'id'=>$this->instanceId]);
    }
    public function assertOrder(array $actor,array $order): void
    {
        $this->assertActor($actor);$i=$this->instance();
        if((int)($order['is_demo']??0)!==1||(int)($order['user_id']??0)!==(int)$actor['user_id']||(int)$order['id']!==(int)($i['order_id']??0)||(string)$order['public_no']!==(string)$i['order_no_snapshot'])throw new TestCenterException('订单不属于当前下单测试实例。','TEST_SUBMISSION_ORDER_BOUNDARY',403);
    }
    private function instance(): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('下单测试域仅允许 testing 环境。','TEST_SUBMISSION_PRODUCTION_BLOCKED',403);
        $i=$this->db->fetch('SELECT * FROM test_instances WHERE id=:id',['id'=>$this->instanceId]);
        if(!$i||!in_array((string)$i['scenario_key'],['ordinary.construction.proxy.draft'],true)||!in_array((string)$i['build_status'],['building','built'],true)||!empty($i['deleted_at'])||!empty($i['archived_at']))throw new TestCenterException('下单测试实例不可写。','TEST_SUBMISSION_INSTANCE_BOUNDARY',409);
        return$i;
    }
}
