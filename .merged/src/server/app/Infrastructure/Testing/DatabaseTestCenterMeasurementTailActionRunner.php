<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Application\Testing\TestScenarioCatalog;
use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;

/** 更换测量师傅后的施工另建实例，分别保留预约费、清理边界与运行历史。 */
final class DatabaseTestCenterMeasurementTailActionRunner implements TestCenterActionRunner
{
    private Database $db;private TestCenterActionRunner $inner;private TestCenterRepository $repository;private TestingActionExecution $execution;
    public function __construct(Database $db,TestCenterActionRunner $inner,TestCenterRepository $repository){$this->db=$db;$this->inner=$inner;$this->repository=$repository;$this->execution=new TestingActionExecution($db);}
    public function available(string $public): array
    {
        $result=$this->inner->available($public);[$i,$o]=$this->execution->context($public);
        if($this->eligible($i,$o))$result['items']['customer_create_independent_construction']=['key'=>'customer_create_independent_construction','label'=>'换师傅：创建独立施工测试单','description'=>'新实例独立支付预约费；另一名师傅正式报价，不建立测量优惠关系。原测量关联与费用记录保留，新施工单独立管理。'];
        return$result;
    }
    public function run(string $public,string $action,array $input,int $admin,string $request): array
    {
        if($action!=='customer_create_independent_construction')return$this->inner->run($public,$action,$input,$admin,$request);
        return$this->execution->run($public,$action,[],$admin,$request,'user','preset_plus_real_quote',function(array $i,array $o)use($admin,$request):array{
            if(!$this->eligible($i,$o))throw new TestCenterException('当前测量单不能新建独立施工。','TEST_INDEPENDENT_CONSTRUCTION_STATE',409);
            return$this->db->transaction(function()use($i,$o,$admin,$request):array{
                $retained=$this->db->fetchAll("SELECT * FROM order_relations WHERE parent_order_id=:id AND relation_type='measurement_construction' ORDER BY id",['id'=>(int)$o['id']]);$relationHash=hash('sha256',(string)json_encode($retained));
                $scenario=(new TestScenarioCatalog())->find('ordinary.construction.multi_quote.waiting.zero_quotes');$sourceParams=json_decode((string)$i['params_json'],true);$params=array_replace($scenario['defaults'],array_intersect_key(is_array($sourceParams)?$sourceParams:[],$scenario['defaults']));$params['title']='测试换师傅独立施工单';
                $detail=$this->repository->build($scenario,$params,$admin,'independent:'.substr(hash('sha256',$i['public_id'].':'.$request),0,64),(int)$i['id']);$new=$detail['instance'];
                $child=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$new['order_id']]);
                if(!$child||(int)$child['user_id']!==(int)$o['user_id']||!empty($child['pending_measurement_order_id'])||(int)$child['is_demo']!==1)throw new TestCenterException('独立施工单归属失败。','TEST_INDEPENDENT_CONSTRUCTION_BOUNDARY',409);
                $slot=(string)$this->db->fetchValue("SELECT slot_key FROM test_participants WHERE instance_id=:id AND expected_status='approved' AND worker_id<>:old ORDER BY id LIMIT 1",['id'=>(int)$new['id'],'old'=>(int)$o['assigned_worker_id']]);
                if($slot==='')throw new TestCenterException('没有其它可报价测试师傅。','TEST_ACTION_WORKER_INVALID',409);
                $quote=$this->inner->run($new['public_id'],'worker_quote',['worker_slot'=>$slot,'amount_yuan'=>'520.00'],$admin,'independent-quote:'.substr(hash('sha256',$request),0,50));
                $q=$this->db->fetch('SELECT * FROM quotes WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>(int)$child['id']]);
                $relations=(int)$this->db->fetchValue('SELECT COUNT(*) FROM order_relations WHERE child_order_id=:id OR parent_order_id=:id',['id'=>(int)$child['id']]);
                if(!$q||(int)$q['worker_id']===(int)$o['assigned_worker_id']||(int)$q['amount_cents']!==52000||$relations!==0)throw new TestCenterException('独立报价不得继承测量优惠。','TEST_INDEPENDENT_DISCOUNT_BOUNDARY',500);
                $afterRelations=$this->db->fetchAll("SELECT * FROM order_relations WHERE parent_order_id=:id AND relation_type='measurement_construction' ORDER BY id",['id'=>(int)$o['id']]);if(!hash_equals($relationHash,hash('sha256',(string)json_encode($afterRelations))))throw new TestCenterException('独立施工不得改写原测量关联。','TEST_INDEPENDENT_DISCOUNT_BOUNDARY',500);
                return['retained_relation_hash'=>$relationHash,'retained_relation_count'=>count($retained),'instance_public_id'=>$new['public_id'],'order_no'=>$child['public_no'],'source_instance_id'=>(int)$i['id'],'quote'=>$quote,'measurement_discount_cents'=>0,'relation_count'=>0,'cleanup_independently'=>true];
            });
        });
    }
    private function eligible(array $i,?array $o): bool
    {
        if($i['scenario_key']!=='ordinary.measurement.multi_quote.waiting.zero_quotes'||!$o||$o['lifecycle_status']!=='completed')return false;
        return(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_measurement_states WHERE order_id=:id AND result_status='accepted' AND settlement_status IN ('frozen','released')",['id'=>(int)$o['id']])===1
            &&(int)$this->db->fetchValue("SELECT COUNT(*) FROM test_instances WHERE source_instance_id=:id AND scenario_key='ordinary.construction.multi_quote.waiting.zero_quotes' AND rebuilt_from_instance_id IS NULL",['id'=>(int)$i['id']])===0;
    }
}
