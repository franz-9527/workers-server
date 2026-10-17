<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use Throwable;
use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** M2 第七段：费用争议的正式资金型管理员裁决。 */
final class DatabaseTestCenterDisputeFinanceActionRunner implements TestCenterActionRunner
{
    private Database $db;private TestCenterActionRunner $inner;private OrderApplicationContainer $orders;private TestingDisputeFinanceBoundaryGuard $funds;
    public function __construct(Database $db,TestCenterActionRunner $inner){$this->db=$db;$this->inner=$inner;$this->orders=new OrderApplicationContainer($db);$this->funds=new TestingDisputeFinanceBoundaryGuard($db);}

    public function available(string $instancePublicId): array
    {
        $inner=$this->inner->available($instancePublicId);$context=$this->context($instancePublicId,false);if(!$this->supports((string)$context['scenario_key']))return$inner;
        $order=$context['order'];if(!$order)return$inner;$dispute=$this->latestFeeDispute((int)$order['id']);if(!$dispute||!in_array((string)$dispute['status'],['open','processing'],true))return$inner;
        try{$funds=$this->funds->inspect((int)$context['instance']['id'],$order,true);}catch(TestCenterException $exception){return$inner;}
        $items=(array)($inner['items']??[]);$items['admin_dispute_financial_resolve']=[
            'key'=>'admin_dispute_financial_resolve','label'=>'管理员资金裁决：跑空费 + 余款退款','reference'=>(string)$dispute['public_no'],
            'available_cents'=>(int)$funds['available_cents'],'default_run_empty_cents'=>(int)$funds['default_run_empty_cents'],
            'description'=>'调用正式 admin.dispute.resolve。指定跑空费记入成交师傅余额账本，未分配的预约费由正式领域自动登记为 pending_review 退款；不会执行微信退款。',
        ];
        return['items'=>$items,'message'=>trim((string)($inner['message']??'')).' 当前争议还可验证资金裁决：跑空费进入师傅账本，剩余 TEST_INTERNAL 预约费登记退款。'];
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        if($actionKey!=='admin_dispute_financial_resolve')return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('资金型争议裁决只允许 testing 环境。','TEST_DISPUTE_FINANCE_PRODUCTION_BLOCKED',403);
        if($adminId<1)throw new TestCenterException('管理员身份无效。','TEST_DISPUTE_FINANCE_ADMIN_REQUIRED',403);
        $context=$this->context($instancePublicId,true);if(!$this->supports((string)$context['scenario_key']))throw new TestCenterException('当前场景未开放资金型争议裁决。','TEST_DISPUTE_FINANCE_SCENARIO_UNSUPPORTED',409);
        $instance=(array)$context['instance'];$order=(array)$context['order'];if($requestKey===''||strlen($requestKey)>96||preg_match('/^[A-Za-z0-9._:-]+$/D',$requestKey)!==1)throw new TestCenterException('测试动作请求键无效，请刷新后重试。','TEST_ACTION_REQUEST_KEY_INVALID',422);

        $existing=$this->db->fetch('SELECT * FROM test_runs WHERE instance_id=:instance AND BINARY idempotency_key=BINARY :idem LIMIT 1',['instance'=>(int)$instance['id'],'idem'=>$requestKey]);
        if($existing){
            $beforeStored=$this->decode((string)($existing['before_summary_json']??''));$storedRequest=is_array($beforeStored['request']??null)?(array)$beforeStored['request']:null;
            if(!$storedRequest)throw new TestCenterException('该历史测试动作缺少幂等请求快照，请使用新的请求键重新发起。','TEST_ACTION_REPLAY_METADATA_MISSING',409,['run_public_id'=>$existing['public_id']]);
            $normalized=$this->normalizeReplayInput($input,$storedRequest);$digest=$this->digest(['action'=>$actionKey,'input'=>$normalized,'order_id'=>(int)$order['id'],'admin_id'=>$adminId]);
            if(!hash_equals((string)$existing['request_digest'],$digest))throw new TestCenterException('同一动作请求键已经用于不同参数，请刷新后重试。','TEST_ACTION_IDEMPOTENCY_CONFLICT',409);
            if((string)$existing['result']==='passed')return['replayed'=>true,'run_public_id'=>(string)$existing['public_id'],'result'=>'passed','after'=>$this->decode((string)($existing['after_summary_json']??''))];
            if((string)$existing['result']==='failed')throw new TestCenterException((string)($existing['error_message']?:'该动作此前已经失败，请刷新后重新发起。'),(string)($existing['error_code']?:'TEST_ACTION_ALREADY_FAILED'),409,['run_public_id'=>$existing['public_id']]);
            throw new TestCenterException('相同测试动作正在执行，请刷新后查看结果。','TEST_ACTION_RUNNING',409);
        }

        $normalized=$this->normalizeInput($input,(int)$instance['id'],$order);$digest=$this->digest(['action'=>$actionKey,'input'=>$normalized,'order_id'=>(int)$order['id'],'admin_id'=>$adminId]);
        $this->funds->inspect((int)$instance['id'],$order,true);$before=$this->summary((int)$order['id']);$runPublic=\public_id();$started=microtime(true);
        $runId=$this->db->insert("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at) VALUES(:public_id,:instance,:action_key,'admin','real_service',:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>$runPublic,'instance'=>(int)$instance['id'],'action_key'=>$actionKey,'idem'=>$requestKey,'digest'=>$digest,'request_id'=>\request_id(),'before_json'=>$this->json(['request'=>$normalized,'facts'=>$before]),'admin'=>$adminId]);
        try{
            $business=$this->orders->dispute()->execute('admin.dispute.resolve',['channel'=>'web_admin','admin_user_id'=>$adminId],[
                'order_id'=>(string)$order['public_no'],'resolution'=>(string)$normalized['note'],'responsibility'=>'user','refund_amount_cents'=>0,'run_empty_fee_cents'=>(int)$normalized['run_empty_fee_cents'],
            ]);
            $after=$this->summary((int)$order['id']);$duration=(int)round((microtime(true)-$started)*1000);$this->db->execute("UPDATE test_runs SET result='passed',after_summary_json=:after_json,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['after_json'=>$this->json(['business'=>$business,'facts'=>$after]),'duration'=>$duration,'id'=>$runId]);$this->db->execute("UPDATE test_instances SET verification_status='pending',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>(int)$instance['id']]);return['replayed'=>false,'run_public_id'=>$runPublic,'result'=>'passed','business'=>$business,'before'=>$before,'after'=>$after,'operation_mode'=>'real_service'];
        }catch(TestCenterException $exception){$this->failRun($runId,$started,$exception->businessCode(),$exception->getMessage(),(int)$order['id']);throw$exception;}
        catch(OrderOperationException $exception){$this->failRun($runId,$started,$exception->apiCode(),$exception->getMessage(),(int)$order['id']);$status=(int)$exception->getCode();if($status<400||$status>599)$status=409;throw new TestCenterException($exception->getMessage(),$exception->apiCode(),$status,$exception->data());}
        catch(Throwable $exception){$this->failRun($runId,$started,'TEST_DISPUTE_FINANCE_ACTION_FAILED',$exception->getMessage(),(int)$order['id']);throw new TestCenterException('资金型争议裁决执行失败，请查看测试执行记录。','TEST_DISPUTE_FINANCE_ACTION_FAILED',500,['run_public_id'=>$runPublic]);}
    }

    private function normalizeInput(array $input,int $instanceId,array $order): array
    {
        $funds=$this->funds->inspect($instanceId,$order,true);$available=(int)$funds['available_cents'];$raw=trim((string)($input['run_empty_fee_yuan']??number_format(((int)$funds['default_run_empty_cents'])/100,2,'.','')));
        return$this->normalizeWithAvailable($input,$raw,$available);
    }

    private function normalizeReplayInput(array $input,array $storedRequest): array
    {
        $available=max(0,(int)($storedRequest['available_cents']??0));$storedCents=max(0,(int)($storedRequest['run_empty_fee_cents']??0));
        $raw=trim((string)($input['run_empty_fee_yuan']??number_format($storedCents/100,2,'.','')));
        return$this->normalizeWithAvailable($input,$raw,$available);
    }

    private function normalizeWithAvailable(array $input,string $raw,int $available): array
    {
        if(!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D',$raw))throw new TestCenterException('跑空费金额格式无效，最多保留两位小数。','TEST_DISPUTE_FINANCE_AMOUNT_INVALID',422);
        $parts=explode('.',$raw,2);$cents=((int)$parts[0])*100+(int)str_pad($parts[1]??'',2,'0');
        if($available<2||$cents<1||$cents>=$available)throw new TestCenterException('跑空费必须大于 0 且小于当前可分配预约费，需保留至少 0.01 元退款用于验证资金拆分。','TEST_DISPUTE_FINANCE_AMOUNT_OUT_OF_RANGE',422,['available_cents'=>$available]);
        $note=mb_substr(trim((string)($input['note']??'测试中心：平台认定客户承担本次跑空责任，跑空费支付师傅，其余预约费原路退款登记')),0,800);if(mb_strlen($note)<4)throw new TestCenterException('请填写至少 4 个字的平台资金裁决说明。','TEST_DISPUTE_FINANCE_NOTE_REQUIRED',422);
        return['note'=>$note,'run_empty_fee_cents'=>$cents,'expected_refund_cents'=>$available-$cents,'available_cents'=>$available];
    }

    private function context(string $publicId,bool $strict): array{$instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>trim($publicId)]);if(!$instance)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);$order=!empty($instance['order_id'])?$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]):null;if($strict&&(!$order||(int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0))throw new TestCenterException('目标订单不属于可写测试数据域。','TEST_DISPUTE_FINANCE_BOUNDARY',409);return['instance'=>$instance,'order'=>$order,'scenario_key'=>(string)$instance['scenario_key']];}
    private function supports(string $key): bool{return strpos($key,'ordinary.construction.multi_quote.waiting.')===0;}
    private function latestFeeDispute(int $orderId): ?array{$row=$this->db->fetch("SELECT * FROM disputes WHERE order_id=:id AND dispute_type='fee_negotiation' ORDER BY id DESC LIMIT 1",['id'=>$orderId]);return$row?:null;}
    private function summary(int $orderId): array{$order=$this->db->fetch('SELECT public_no,lifecycle_status,refund_status,dispute_status,assignment_status,fulfillment_status FROM orders WHERE id=:id',['id'=>$orderId]);$dispute=$this->latestFeeDispute($orderId);$refund=(int)$this->db->fetchValue('SELECT COALESCE(SUM(amount_cents),0) FROM refunds WHERE order_id=:id',['id'=>$orderId]);$runner=(int)$this->db->fetchValue("SELECT COALESCE(SUM(l.amount_cents),0) FROM worker_balance_ledger l JOIN disputes d ON d.id=l.business_id WHERE d.order_id=:id AND l.business_type='dispute' AND l.entry_type='run_empty_fee' AND l.status='effective'",['id'=>$orderId]);return['order'=>$order,'dispute'=>$dispute?['public_no'=>$dispute['public_no'],'status'=>$dispute['status'],'refund_amount_cents'=>(int)$dispute['refund_amount_cents'],'run_empty_fee_cents'=>(int)$dispute['run_empty_fee_cents'],'handled_by'=>(int)($dispute['handled_by']??0)]:null,'refund_cents'=>$refund,'run_empty_fee_cents'=>$runner];}
    private function failRun(int $runId,float $started,string $code,string $message,int $orderId): void{$duration=(int)round((microtime(true)-$started)*1000);$this->db->execute("UPDATE test_runs SET result='failed',error_code=:code,error_message=:message,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['code'=>$code,'message'=>mb_substr($message,0,1000),'summary'=>$this->json($this->summary($orderId)),'duration'=>$duration,'id'=>$runId]);}
    /** @param mixed $value */ private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
    /** @param mixed $value */ private function digest($value): string{return hash('sha256',$this->json($value));}
    private function decode(string $json): array{$value=json_decode($json,true);return is_array($value)?$value:[];}
}
