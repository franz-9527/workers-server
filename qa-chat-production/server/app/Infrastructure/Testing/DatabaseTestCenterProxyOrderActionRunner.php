<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Application\Order\MiniOrdinaryOrderCreation;
use WorkerAdmin\Application\Order\OrderSubmission;
use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Agreements\PlatformOrderAgreementProvider;
use WorkerAdmin\Infrastructure\External\QiniuObjectStorage;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMiniOrdinaryOrderCreationRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderSubmissionRepository;
use WorkerAdmin\Infrastructure\Persistence\PolicyAwareOrderSubmissionRepository;
use WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;

/** 普通代下单测试：管理员只代录；测试客户独立确认协议并登记内部预约费。 */
final class DatabaseTestCenterProxyOrderActionRunner implements TestCenterActionRunner
{
    public const SCENARIO='ordinary.construction.proxy.draft';
    private Database $db;private TestCenterActionRunner $inner;private TestingActionExecution $execution;
    public function __construct(Database $db,TestCenterActionRunner $inner){$this->db=$db;$this->inner=$inner;$this->execution=new TestingActionExecution($db);}
    public function available(string $public): array
    {
        [$i,$o]=$this->execution->context($public);if($i['scenario_key']!==self::SCENARIO)return$this->inner->available($public);if(!$o)return['items'=>[]];
        $items=[];
        if($o['audit_status']==='draft'&&$o['payment_status']==='unpaid'){
            $ready=(bool)$this->db->fetchValue("SELECT id FROM order_events WHERE order_id=:id AND event_type='mini_ordinary_ready'",['id'=>(int)$o['id']]);
            $key=$ready?'customer_proxy_confirm_terms':'admin_proxy_ready';$items[$key]=['key'=>$key,'label'=>$ready?'测试客户本人确认协议':'管理员完成代录并交给客户'];
        }elseif($o['audit_status']==='draft'&&$o['payment_status']==='awaiting_payment')$items['customer_proxy_pay_booking']=['key'=>'customer_proxy_pay_booking','label'=>'测试客户内部支付预约费'];
        elseif($o['payment_status']==='paid'&&$o['audit_status']==='pending'){
            $items['admin_proxy_approve']=['key'=>'admin_proxy_approve','label'=>'管理员正式审核已付款普通单'];
            $items['admin_proxy_supplement']=['key'=>'admin_proxy_supplement','label'=>'管理员要求补资料','description'=>'调用正式 WebAdminOrder::audit(supplement)，生成真实 audit_supplement_timeout 期限，供 scheduler scoped 测试继续推进。'];
        }
        return$items?['items'=>$items,'message'=>'管理员代录不代表客户同意协议或已经付款。']: $this->inner->available($public);
    }
    public function run(string $public,string $key,array $input,int $admin,string $request): array
    {
        if(!in_array($key,['admin_proxy_ready','customer_proxy_confirm_terms','customer_proxy_pay_booking','admin_proxy_approve','admin_proxy_supplement'],true))return$this->inner->run($public,$key,$input,$admin,$request);
        [$i]=$this->execution->context($public);if($i['scenario_key']!==self::SCENARIO)throw new TestCenterException('场景不支持普通代录。','TEST_ACTION_SCENARIO_UNSUPPORTED',409);
        return$this->execution->run($public,$key,[],$admin,$request,strpos($key,'admin_')===0?'admin':'user',$key==='customer_proxy_pay_booking'?'internal_payment':'real_service',function(array $i,array $o)use($key,$admin):array{
            $available=$this->available((string)$i['public_id']);if(!isset($available['items'][$key]))throw new TestCenterException('代录状态已经变化。','TEST_ACTION_STATE_CHANGED',409);
            [$mini,$submission,$scope]=$this->services((int)$i['id']);$operator=$this->operator((int)$i['id']);
            if($key==='admin_proxy_ready')return$mini->ready($operator,['order_id'=>$o['public_no']]);
            if($key==='admin_proxy_approve'||$key==='admin_proxy_supplement'){
                $repo=new \WorkerAdmin\Infrastructure\Persistence\DatabaseAdminOrderRepository($this->db,new \WorkerAdmin\Infrastructure\Messaging\DatabaseAdminOrderEventRepository($this->db,new \WorkerAdmin\Infrastructure\Messaging\LegacyOrderNotificationAdapter($this->db)));
                $decision=$key==='admin_proxy_supplement'?'supplement':'approve';
                $reason=$decision==='supplement'
                    ?'测试中心：请补充现场情况说明与必要图片，用于验证补资料期限和超时取消'
                    :'测试中心：预约费已内部到账，按正式审核流程处理';
                return(new \WorkerAdmin\Application\Order\WebAdminOrder($repo,$submission))->audit((int)$o['id'],$decision,$reason,$admin);
            }
            $customer=$scope->resolve((string)$this->db->fetchValue('SELECT public_id FROM users WHERE id=:id',['id'=>(int)$o['user_id']]));
            if($key==='customer_proxy_confirm_terms'){
                $documents=(new PlatformOrderAgreementProvider($this->db))->orderSubmitAgreements();$versions=[];
                foreach($documents as$doc)if(is_array($doc)&&!empty($doc['key'])&&!empty($doc['version']))$versions[(string)$doc['key']]=(string)$doc['version'];
                return$submission->finalize($customer,['order_id'=>$o['public_no'],'agreements'=>['accepted'=>true,'versions'=>$versions]]);
            }
            $scope->assertOrder($customer,$o);
            return$this->db->transaction(function()use($i,$o,$admin):array{return(new TestingOrderBookingPaymentSimulator($this->db))->settle((int)$i['id'],(int)$o['id'],(int)$o['user_id'],(int)$o['booking_fee_cents'],$admin,true);});
        });
    }
    /** 只在场景生成事务内调用，参数由当前表单夹具提供。 */
    public function createDraft(int $instance,array $input): array
    {
        [$mini]=$this->services($instance);
        return$mini->create($this->operator($instance),$input);
    }
    public function services(int $instance): array
    {
        $scope=new TestingOrderSubmissionScope($this->db,$instance);$settings=new GlobalSettingsProvider();$cipher=new GlobalPrivateValueCipher();
        $base=new DatabaseOrderSubmissionRepository($this->db,$settings,$cipher,new QiniuObjectStorage(),(string)\app_config('app.timezone','Asia/Shanghai'),$scope);
        $submission=new OrderSubmission(new PolicyAwareOrderSubmissionRepository($base,$this->db,$settings,$cipher),new PlatformOrderAgreementProvider($this->db),$settings,null,$scope);
        return[new MiniOrdinaryOrderCreation(new DatabaseMiniOrdinaryOrderCreationRepository($this->db,$cipher,$submission,$scope)),$submission,$scope];
    }
    private function operator(int $instance): array
    {
        $id=(int)$this->db->fetchValue("SELECT tp.user_id FROM test_participants tp JOIN users u ON u.id=tp.user_id WHERE tp.instance_id=:id AND tp.slot_key='proxy_operator' AND tp.actor_type='admin' AND u.status='active' AND u.public_id LIKE '01KTEST%'",['id'=>$instance]);
        if($id<1)throw new TestCenterException('测试管理员参与者缺失。','TEST_PROXY_OPERATOR_BOUNDARY',409);
        return['user_id'=>$id,'real_user_id'=>$id,'is_admin'=>true,'is_demo_actor'=>false];
    }
}
