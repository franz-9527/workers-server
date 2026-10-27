<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderDealRepository;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;

/**
 * 使用正式 OrderDealRepository 执行 M1 负向报价探针。
 *
 * 只允许 testing；目标必须是测试中心普通 is_demo 订单；探针预期被正式业务规则拒绝。
 * 若意外成功，调用方必须将实例标为失败并保留产生的业务事实供检查/清理。
 */
final class TestingNegativeQuoteProbe
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db=$db;
    }

    /** @return array{passed:bool,expected_code:string,actual_code:string,message:string,data:array<string,mixed>} */
    public function run(int $instanceId, int $orderId, string $probeKey): array
    {
        if(!TestingEnvironmentPolicy::active()){
            throw new TestCenterException('负向报价探针只允许 testing 环境。','TEST_NEGATIVE_PROBE_PRODUCTION_BLOCKED',403);
        }
        $order=$this->db->fetch('SELECT id,public_no,is_demo,order_source_type FROM orders WHERE id=:id LIMIT 1',['id'=>$orderId]);
        if(!$order||(int)$order['is_demo']!==1||(string)$order['order_source_type']!=='consumer'||strpos((string)$order['public_no'],'BXDEMOHALL')===0){
            throw new TestCenterException('负向报价探针只能作用于测试中心普通演示订单。','TEST_NEGATIVE_PROBE_BOUNDARY',409);
        }

        if($probeKey==='pending_worker'){
            $slot='pending_worker';$expected='WORKER_NOT_APPROVED';
            $body=['order_id'=>(string)$order['public_no'],'amount_cents'=>68000,'description'=>'TEST pending worker negative probe'];
        }elseif($probeKey==='far_distance'){
            $slot='worker_1';$expected='DISTANCE_ACK_REQUIRED';
            // 使用明确的有效测试坐标，保证即使后台阈值调到上限 1000km 仍属于超距。
            $body=['order_id'=>(string)$order['public_no'],'amount_cents'=>68000,'description'=>'TEST far distance negative probe','current_lat'=>50.0,'current_lng'=>0.0,'distance_acknowledged'=>0];
        }else{
            throw new TestCenterException('未知负向报价探针。','TEST_NEGATIVE_PROBE_UNKNOWN',422);
        }

        $participant=$this->db->fetch(
            'SELECT tp.user_id,tp.worker_id,w.lat worker_lat,w.lng worker_lng FROM test_participants tp LEFT JOIN worker_profiles w ON w.id=tp.worker_id WHERE tp.instance_id=:instance AND BINARY tp.slot_key=BINARY :slot LIMIT 1',
            ['instance'=>$instanceId,'slot'=>$slot]
        );
        if(!$participant||empty($participant['user_id'])||empty($participant['worker_id'])){
            throw new TestCenterException('负向报价探针缺少测试参与者。','TEST_NEGATIVE_PROBE_ACTOR_MISSING',409,['slot'=>$slot]);
        }
        $actor=[
            'user_id'=>(int)$participant['user_id'],
            'worker_id'=>(int)$participant['worker_id'],
            'worker_lat'=>$participant['worker_lat']!==null?(float)$participant['worker_lat']:null,
            'worker_lng'=>$participant['worker_lng']!==null?(float)$participant['worker_lng']:null,
        ];
        $repository=new DatabaseOrderDealRepository($this->db,new GlobalSettingsProvider());
        try{
            $context=$repository->context('quotes.create',$actor,$body);
            $repository->execute('quotes.create',$actor,$body,$context,[]);
            return ['passed'=>false,'expected_code'=>$expected,'actual_code'=>'UNEXPECTED_SUCCESS','message'=>'正式报价服务意外接受了本应被拒绝的测试请求。','data'=>[]];
        }catch(OrderOperationException $exception){
            return [
                'passed'=>$exception->apiCode()===$expected,
                'expected_code'=>$expected,
                'actual_code'=>$exception->apiCode(),
                'message'=>$exception->getMessage(),
                'data'=>$exception->data(),
            ];
        }catch(Throwable $exception){
            return ['passed'=>false,'expected_code'=>$expected,'actual_code'=>'UNEXPECTED_EXCEPTION','message'=>$exception->getMessage(),'data'=>[]];
        }
    }
}
