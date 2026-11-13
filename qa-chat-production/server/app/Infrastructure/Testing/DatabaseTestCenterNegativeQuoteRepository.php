<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;

/**
 * M1 负向报价场景装饰器。
 *
 * 数据起点仍由普通测试工厂生成；检查阶段调用正式 OrderDealRepository，
 * 只把“按预期被拒绝”记录为通过，绝不以 preset 伪装业务拒绝。
 */
final class DatabaseTestCenterNegativeQuoteRepository implements TestCenterRepository
{
    private Database $db;
    private TestCenterRepository $inner;

    public function __construct(Database $db, TestCenterRepository $inner)
    {
        $this->db=$db;$this->inner=$inner;
    }

    public function environmentDiagnostics(): array{return $this->inner->environmentDiagnostics();}
    public function listInstances(array $filters=[]): array{return $this->inner->listInstances($filters);}
    public function instanceDetail(string $publicId): array{return $this->inner->instanceDetail($publicId);}
    public function cleanupPreview(string $publicId): array{return $this->inner->cleanupPreview($publicId);}
    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array{return $this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);}
    public function rebuildSource(string $publicId): array{return $this->inner->rebuildSource($publicId);}

    public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array
    {
        $detail=$this->inner->build($scenario,$params,$adminId,$requestKey,$sourceInstanceId,$rebuiltFromInstanceId);
        if($this->probeKey((string)($scenario['key']??''))!==''){
            $publicId=(string)($detail['instance']['public_id']??'');
            if($publicId!=='')$this->verify($publicId,$adminId);
            return $this->inner->instanceDetail($publicId);
        }
        return $detail;
    }

    public function verify(string $publicId,int $adminId): array
    {
        $detail=$this->inner->instanceDetail($publicId);
        $scenarioKey=(string)($detail['instance']['scenario_key']??'');
        $probeKey=$this->probeKey($scenarioKey);
        if($probeKey==='')return $this->inner->verify($publicId,$adminId);

        $base=$this->inner->verify($publicId,$adminId);
        $detail=$this->inner->instanceDetail($publicId);
        $instanceId=(int)($detail['instance']['id']??0);
        $orderId=(int)($detail['order']['id']??0);
        $started=microtime(true);
        $probe=(new TestingNegativeQuoteProbe($this->db))->run($instanceId,$orderId,$probeKey);
        $quoteCount=(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM quotes WHERE order_id=:order AND quote_type='worker' AND status='active'",
            ['order'=>$orderId]
        );
        $probePassed=!empty($probe['passed']);
        $noMutation=$quoteCount===0;
        $failed=(int)($base['failed']??0)+($probePassed?0:1)+($noMutation?0:1);
        $status=$failed===0?'passed':'failed';
        $runPublic=\public_id();
        $runId=$this->db->insert(
            "INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,after_summary_json,result,error_code,error_message,duration_ms,created_by_admin_id,started_at,finished_at,created_at)
             VALUES(:public_id,:instance,'negative_quote_probe','worker','real_service',:idem,:digest,:request_id,:summary,:result,:error_code,:error_message,:duration,:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_id'=>$runPublic,'instance'=>$instanceId,'idem'=>'negative-probe:'.bin2hex(random_bytes(8)),
                'digest'=>hash('sha256',$scenarioKey.'|'.$probeKey),'request_id'=>\request_id(),
                'summary'=>json_encode(['probe'=>$probeKey,'expected_code'=>$probe['expected_code'],'actual_code'=>$probe['actual_code'],'quote_count_after'=>$quoteCount],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'result'=>$probePassed&&$noMutation?'passed':'failed',
                'error_code'=>$probePassed?null:(string)$probe['actual_code'],
                'error_message'=>$probePassed?null:(string)$probe['message'],
                'duration'=>(int)round((microtime(true)-$started)*1000),'admin'=>$adminId,
            ]
        );
        $this->db->insert(
            "INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at)
             VALUES(:instance,:run,'api',:assertion_key,:status,:expected,:actual,:details,UTC_TIMESTAMP())",
            [
                'instance'=>$instanceId,'run'=>$runId,'assertion_key'=>$probeKey.'_rejected',
                'status'=>$probePassed?'passed':'failed','expected'=>(string)$probe['expected_code'],'actual'=>(string)$probe['actual_code'],
                'details'=>json_encode(['message'=>$probe['message'],'data'=>$probe['data']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
        $this->db->insert(
            "INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at)
             VALUES(:instance,:run,'api','negative_probe_no_quote_mutation',:status,'active worker quotes=0',:actual,NULL,UTC_TIMESTAMP())",
            ['instance'=>$instanceId,'run'=>$runId,'status'=>$noMutation?'passed':'failed','actual'=>'active worker quotes='.$quoteCount]
        );
        $this->db->execute(
            "UPDATE test_instances SET verification_status=:status,build_status=IF(:failed=0,IF(build_status='build_invalid','built',build_status),'build_invalid'),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
            ['status'=>$status,'failed'=>$failed,'id'=>$instanceId]
        );
        return [
            'status'=>$status,
            'total'=>(int)($base['total']??0)+2,
            'failed'=>$failed,
            'run_public_id'=>$runPublic,
            'negative_probe'=>$probe,
        ];
    }

    private function probeKey(string $scenarioKey): string
    {
        if(strpos($scenarioKey,'.far_distance')!==false)return 'far_distance';
        if(strpos($scenarioKey,'.pending_worker')!==false)return 'pending_worker';
        return '';
    }
}
