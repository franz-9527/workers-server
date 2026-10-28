<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use Throwable;
use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Foundation\AdminWorkbenchActionComposition;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** M2 第六段：平台介入后的正式管理员裁决。 */
final class DatabaseTestCenterAdminAdjudicationActionRunner implements TestCenterActionRunner
{
    private Database $db;
    private TestCenterActionRunner $inner;
    private OrderApplicationContainer $orders;
    private AdminWorkbenchActionComposition $adminActions;
    private TestingRefundBoundaryGuard $funds;

    public function __construct(Database $db,TestCenterActionRunner $inner)
    {
        $this->db=$db;$this->inner=$inner;$this->orders=new OrderApplicationContainer($db);
        $this->adminActions=new AdminWorkbenchActionComposition($db);$this->funds=new TestingRefundBoundaryGuard($db);
    }

    public function available(string $instancePublicId): array
    {
        $inner=$this->inner->available($instancePublicId);
        $context=$this->context($instancePublicId,false);
        if(!$this->supports((string)$context['scenario_key']))return$inner;
        $order=$context['order'];if(!$order)return['items'=>[],'message'=>'测试实例业务数据已清理。'];
        $instance=(array)$context['instance'];$orderId=(int)$order['id'];

        $repair=$this->latestRepair($orderId);
        if($repair&&in_array((string)$repair['status'],['open','processing'],true)&&$this->repairIntervened($orderId,$repair)){
            $continued=strpos((string)($repair['resolution']??''),'平台介入：要求继续维修：')===0;
            $items=[];
            if(!$continued)$items['admin_repair_continue']=['key'=>'admin_repair_continue','label'=>'平台要求继续维修','reference'=>(string)$repair['public_no'],'description'=>'以 Web 管理员身份调用正式 AdminRepairResolution(continue)；售后保持处理中，不代替师傅确认完成。'];
            $items['admin_repair_resolved']=['key'=>'admin_repair_resolved','label'=>'平台确认维修问题已解决','reference'=>(string)$repair['public_no'],'description'=>'调用正式 AdminRepairResolution(resolved) 结案；不代表退款或赔付。'];
            $items['admin_repair_closed']=['key'=>'admin_repair_closed','label'=>'平台关闭本次维修售后','reference'=>(string)$repair['public_no'],'description'=>'调用正式 AdminRepairResolution(closed)；只关闭售后，不混入资金处理。'];
            return['items'=>$items,'message'=>'维修已进入平台介入，当前动作均是正式管理员裁决；资金补偿仍必须另走退款/争议领域。'];
        }

        $request=$this->latestRefundRequest($orderId);
        if($request&&(string)$request['status']==='pending'&&(string)$request['reason_code']==='bilateral_refund_escalated'){
            $items=['admin_refund_reject'=>['key'=>'admin_refund_reject','label'=>'管理员拒绝退款','reference'=>(string)$request['public_id'],'description'=>'调用正式 admin.refund.review(reject)，恢复服务端允许恢复的订单状态并记录管理员决定。']];
            try{
                $funds=$this->funds->inspect((int)$instance['id'],$order,true);
                if((int)($funds['available_cents']??0)>0)$items['admin_refund_approve']=['key'=>'admin_refund_approve','label'=>'管理员通过退款','reference'=>(string)$request['public_id'],'amount_cents'=>(int)$funds['available_cents'],'description'=>'调用正式 admin.refund.review(approve)，只登记 testing 内部 refunds.pending_review；绝不伪造微信退款成功。'];
            }catch(TestCenterException $exception){/* 拒绝动作仍可验证，批准保持资金边界关闭。 */}
            return['items'=>$items,'message'=>'退款协商已由双方升级平台。管理员可以正式审核；通过后只验证退款登记与订单终止，不执行微信退款。'];
        }

        $dispute=$this->latestFeeDispute($orderId);
        if($dispute&&in_array((string)$dispute['status'],['open','processing'],true)){
            return['items'=>['admin_dispute_resolve'=>['key'=>'admin_dispute_resolve','label'=>'管理员 0 元结案争议','reference'=>(string)$dispute['public_no'],'description'=>'调用正式 admin.dispute.resolve；本轮固定 refund=0、run_empty_fee=0，只验证平台责任裁定与结案，不混入资金分配。']], 'message'=>'费用协商已升级平台；本轮覆盖无资金争议结案，退款和跑空费金额裁定留到独立资金阶段。'];
        }
        return$inner;
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        $allowed=['admin_refund_approve','admin_refund_reject','admin_dispute_resolve','admin_repair_continue','admin_repair_resolved','admin_repair_closed'];
        if(!in_array($actionKey,$allowed,true))return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('管理员裁决测试动作仅允许 testing 环境。','TEST_ADMIN_ADJUDICATION_PRODUCTION_BLOCKED',403);
        if($adminId<1)throw new TestCenterException('管理员身份无效。','TEST_ADMIN_ADJUDICATION_ADMIN_REQUIRED',403);
        $context=$this->context($instancePublicId,true);if(!$this->supports((string)$context['scenario_key']))throw new TestCenterException('当前场景未开放管理员裁决测试。','TEST_ADMIN_ADJUDICATION_SCENARIO_UNSUPPORTED',409);
        $instance=(array)$context['instance'];$order=(array)$context['order'];
        if($requestKey===''||strlen($requestKey)>96||preg_match('/^[A-Za-z0-9._:-]+$/D',$requestKey)!==1)throw new TestCenterException('测试动作请求键无效，请刷新后重试。','TEST_ACTION_REQUEST_KEY_INVALID',422);
        $normalized=$this->normalizeInput($actionKey,$input,(int)$instance['id'],$order);
        $digest=$this->digest(['action'=>$actionKey,'input'=>$normalized,'order_id'=>(int)$order['id'],'admin_id'=>$adminId]);
        $existing=$this->db->fetch('SELECT * FROM test_runs WHERE instance_id=:instance AND BINARY idempotency_key=BINARY :idem LIMIT 1',['instance'=>(int)$instance['id'],'idem'=>$requestKey]);
        if($existing){
            if(!hash_equals((string)$existing['request_digest'],$digest))throw new TestCenterException('同一动作请求键已经用于不同参数，请刷新后重试。','TEST_ACTION_IDEMPOTENCY_CONFLICT',409);
            if((string)$existing['result']==='passed')return['replayed'=>true,'run_public_id'=>(string)$existing['public_id'],'result'=>'passed','after'=>$this->decode((string)($existing['after_summary_json']??''))];
            if((string)$existing['result']==='failed')throw new TestCenterException((string)($existing['error_message']?:'该动作此前已经失败，请刷新后重新发起。'),(string)($existing['error_code']?:'TEST_ACTION_ALREADY_FAILED'),409,['run_public_id'=>$existing['public_id']]);
            throw new TestCenterException('相同测试动作正在执行，请刷新后查看结果。','TEST_ACTION_RUNNING',409,['run_public_id'=>$existing['public_id']]);
        }
        $this->assertState($actionKey,(int)$instance['id'],$order);
        $before=$this->summary((int)$order['id']);$runPublic=\public_id();$started=microtime(true);
        $runId=$this->db->insert("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at) VALUES(:public_id,:instance,:action_key,'admin','real_service',:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>$runPublic,'instance'=>(int)$instance['id'],'action_key'=>$actionKey,'idem'=>$requestKey,'digest'=>$digest,'request_id'=>\request_id(),'before_json'=>$this->json($before),'admin'=>$adminId]);
        try{
            $business=$this->execute($actionKey,(int)$instance['id'],$order,$normalized,$adminId);$after=$this->summary((int)$order['id']);$duration=(int)round((microtime(true)-$started)*1000);
            $this->db->execute("UPDATE test_runs SET result='passed',after_summary_json=:after_json,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['after_json'=>$this->json(['business'=>$business,'facts'=>$after]),'duration'=>$duration,'id'=>$runId]);
            $this->db->execute("UPDATE test_instances SET verification_status='pending',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>(int)$instance['id']]);
            return['replayed'=>false,'run_public_id'=>$runPublic,'result'=>'passed','business'=>$business,'before'=>$before,'after'=>$after,'operation_mode'=>'real_service'];
        }catch(TestCenterException $exception){$this->failRun($runId,$started,$exception->businessCode(),$exception->getMessage(),(int)$order['id']);throw$exception;}
        catch(OrderOperationException $exception){$this->failRun($runId,$started,$exception->apiCode(),$exception->getMessage(),(int)$order['id']);$status=(int)$exception->getCode();if($status<400||$status>599)$status=409;throw new TestCenterException($exception->getMessage(),$exception->apiCode(),$status,$exception->data());}
        catch(Throwable $exception){$this->failRun($runId,$started,'TEST_ADMIN_ADJUDICATION_ACTION_FAILED',$exception->getMessage(),(int)$order['id']);throw new TestCenterException('管理员裁决测试动作执行失败，请查看测试执行记录。','TEST_ADMIN_ADJUDICATION_ACTION_FAILED',500,['run_public_id'=>$runPublic]);}
    }

    private function execute(string $actionKey,int $instanceId,array $order,array $input,int $adminId): array
    {
        if($actionKey==='admin_refund_approve'||$actionKey==='admin_refund_reject'){
            $amount=0;if($actionKey==='admin_refund_approve'){$funds=$this->funds->inspect($instanceId,$order,true);$amount=(int)$funds['available_cents'];if($amount<1)throw new TestCenterException('当前没有可由 testing 内部支付登记的退款金额。','TEST_ADMIN_REFUND_AMOUNT_MISSING',409);}
            return$this->orders->cancellation()->review(['channel'=>'web_admin','admin_user_id'=>$adminId],[
                'order_id'=>(string)$order['public_no'],'decision'=>$actionKey==='admin_refund_approve'?'approve':'reject','worker_arrived'=>'no',
                'responsibility'=>$actionKey==='admin_refund_approve'?'worker':'user','reason'=>(string)$input['note'],'refund_amount_cents'=>$amount,
            ]);
        }
        if($actionKey==='admin_dispute_resolve'){
            return$this->orders->dispute()->execute('admin.dispute.resolve',['channel'=>'web_admin','admin_user_id'=>$adminId],[
                'order_id'=>(string)$order['public_no'],'resolution'=>(string)$input['note'],'responsibility'=>'shared','refund_amount_cents'=>0,'run_empty_fee_cents'=>0,
            ]);
        }
        $repair=$this->latestRepair((int)$order['id']);if(!$repair)throw new TestCenterException('维修售后不存在。','TEST_ADMIN_REPAIR_NOT_FOUND',409);
        $decision=$actionKey==='admin_repair_continue'?'continue':($actionKey==='admin_repair_resolved'?'resolved':'closed');
        return$this->adminActions->repairResolution()->execute(['channel'=>'web_admin','user_id'=>$adminId],[
            'order_id'=>(string)$order['public_no'],'after_sale_id'=>(string)$repair['public_no'],'decision'=>$decision,'note'=>(string)$input['note'],
        ]);
    }

    private function normalizeInput(string $actionKey,array $input,int $instanceId,array $order): array
    {
        $defaults=[
            'admin_refund_approve'=>'测试中心：平台核对后同意按当前可退预约费执行退款登记',
            'admin_refund_reject'=>'测试中心：平台核对后认为当前退款理由不足，拒绝本次退款申请',
            'admin_dispute_resolve'=>'测试中心：平台已核对双方记录，本次费用争议按双方责任结案，不作资金分配',
            'admin_repair_continue'=>'测试中心：平台核对后要求原成交师傅继续履行本次保修维修',
            'admin_repair_resolved'=>'测试中心：平台核对双方反馈，确认本次维修问题已经解决',
            'admin_repair_closed'=>'测试中心：平台核对后关闭本次维修售后，资金事项如有需要另行处理',
        ];
        $note=mb_substr(trim((string)($input['note']??$defaults[$actionKey])),0,800);if(mb_strlen($note)<4)throw new TestCenterException('请填写至少 4 个字的平台处理说明。','TEST_ADMIN_ADJUDICATION_NOTE_REQUIRED',422);
        $result=['note'=>$note];if($actionKey==='admin_refund_approve'){$funds=$this->funds->inspect($instanceId,$order,true);$result['amount_cents']=(int)$funds['available_cents'];}return$result;
    }

    private function assertState(string $actionKey,int $instanceId,array $order): void
    {
        $orderId=(int)$order['id'];$ok=false;
        if($actionKey==='admin_refund_approve'||$actionKey==='admin_refund_reject'){$r=$this->latestRefundRequest($orderId);$ok=$r&&(string)$r['status']==='pending'&&(string)$r['reason_code']==='bilateral_refund_escalated';if($ok&&$actionKey==='admin_refund_approve')$this->funds->inspect($instanceId,$order,true);}
        elseif($actionKey==='admin_dispute_resolve'){$d=$this->latestFeeDispute($orderId);$ok=$d&&in_array((string)$d['status'],['open','processing'],true);}
        else{$repair=$this->latestRepair($orderId);$ok=$repair&&in_array((string)$repair['status'],['open','processing'],true)&&$this->repairIntervened($orderId,$repair);if($ok&&$actionKey==='admin_repair_continue'&&strpos((string)($repair['resolution']??''),'平台介入：要求继续维修：')===0)$ok=false;}
        if(!$ok)throw new TestCenterException('当前平台介入事项状态已经变化，请刷新测试订单后重试。','TEST_ADMIN_ADJUDICATION_STATE_CHANGED',409);
    }

    private function context(string $publicId,bool $strict): array
    {
        $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>trim($publicId)]);if(!$instance)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
        $order=!empty($instance['order_id'])?$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]):null;
        if($strict&&(!$order||(int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0))throw new TestCenterException('目标订单不属于可写测试数据域。','TEST_ADMIN_ADJUDICATION_BOUNDARY',409);
        return['instance'=>$instance,'order'=>$order,'scenario_key'=>(string)$instance['scenario_key']];
    }
    private function supports(string $key): bool{return strpos($key,'ordinary.construction.multi_quote.waiting.')===0;}
    private function latestRefundRequest(int $orderId): ?array{$row=$this->db->fetch('SELECT * FROM order_cancel_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>$orderId]);return$row?:null;}
    private function latestFeeDispute(int $orderId): ?array{$row=$this->db->fetch("SELECT * FROM disputes WHERE order_id=:id AND dispute_type='fee_negotiation' ORDER BY id DESC LIMIT 1",['id'=>$orderId]);return$row?:null;}
    private function latestRepair(int $orderId): ?array{$row=$this->db->fetch("SELECT * FROM after_sales WHERE order_id=:id AND type='warranty_repair' ORDER BY id DESC LIMIT 1",['id'=>$orderId]);return$row?:null;}
    private function repairIntervened(int $orderId,array $repair): bool
    {
        $resolution=(string)($repair['resolution']??'');if(strpos($resolution,'用户申请平台介入')===0||strpos($resolution,'平台主动介入')===0||strpos($resolution,'平台介入：要求继续维修：')===0)return true;
        return(bool)$this->db->fetchValue("SELECT 1 FROM order_events WHERE order_id=:order_id AND event_type='repair_platform_intervention_requested' AND payload_json LIKE :needle LIMIT 1",['order_id'=>$orderId,'needle'=>'%\"after_sale_id\":\"'.(string)$repair['public_no'].'\"%']);
    }
    private function summary(int $orderId): array
    {
        $order=$this->db->fetch('SELECT public_no,lifecycle_status,fulfillment_status,refund_status,dispute_status,publish_status,quote_status,assignment_status FROM orders WHERE id=:id',['id'=>$orderId]);$request=$this->latestRefundRequest($orderId);$dispute=$this->latestFeeDispute($orderId);$repair=$this->latestRepair($orderId);
        return['order'=>$order,'cancel_request'=>$request?['public_id'=>$request['public_id'],'status'=>$request['status'],'reason_code'=>$request['reason_code'],'requested_refund_cents'=>(int)$request['requested_refund_cents']]:null,'dispute'=>$dispute?['public_no'=>$dispute['public_no'],'status'=>$dispute['status'],'responsibility'=>$dispute['responsibility'],'refund_amount_cents'=>(int)$dispute['refund_amount_cents'],'run_empty_fee_cents'=>(int)$dispute['run_empty_fee_cents']]:null,'repair'=>$repair?['public_no'=>$repair['public_no'],'status'=>$repair['status'],'resolution'=>$repair['resolution'],'closed_at'=>$repair['closed_at']]:null,'refund_rows'=>(int)$this->db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=:id',['id'=>$orderId])];
    }
    private function failRun(int $runId,float $started,string $code,string $message,int $orderId): void{$duration=(int)round((microtime(true)-$started)*1000);$this->db->execute("UPDATE test_runs SET result='failed',error_code=:code,error_message=:message,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['code'=>$code,'message'=>mb_substr($message,0,1000),'summary'=>$this->json($this->summary($orderId)),'duration'=>$duration,'id'=>$runId]);}
    /** @param mixed $value */ private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
    /** @param mixed $value */ private function digest($value): string{return hash('sha256',$this->json($value));}
    private function decode(string $json): array{$value=json_decode($json,true);return is_array($value)?$value:[];}
}
