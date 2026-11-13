<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use Throwable;
use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Infrastructure\Agreements\PlatformWorkerAgreementVersionProvider;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * M2 受控真实动作执行器。
 *
 * 只允许测试中心明确登记的普通施工多师傅报价实例，业务动作仍调用生产
 * OrderDeal / OrderQuoteSelection；管理员只负责发起测试，不继承业务 actor 权限。
 */
final class DatabaseTestCenterActionRunner implements TestCenterActionRunner
{
    private Database $db;
    private OrderApplicationContainer $orders;
    private PlatformWorkerAgreementVersionProvider $agreements;

    public function __construct(Database $db)
    {
        $this->db=$db;
        $this->orders=new OrderApplicationContainer($db);
        $this->agreements=new PlatformWorkerAgreementVersionProvider($db);
    }

    public function available(string $instancePublicId): array
    {
        $context=$this->context($instancePublicId,false);
        if(!$this->supportsPositiveScenario((string)$context['scenario_key'])) {
            return ['items'=>[],'message'=>'当前 M2 真实动作只开放普通施工多师傅报价正向场景。'];
        }
        $order=$context['order'];
        if(!$order) return ['items'=>[],'message'=>'测试实例业务数据已清理。'];
        if(!$this->waitingQuoteState($order)) {
            return ['items'=>[],'message'=>'当前实例已经离开待报价阶段；M2 后续预约/履约动作尚未接入。'];
        }

        $items=[];
        $workers=$this->approvedParticipants((int)$context['instance']['id'],(int)$order['id']);
        if($workers){
            $default=$workers[0];
            foreach($workers as $worker){if(empty($worker['active_quote_id'])){$default=$worker;break;}}
            $items['worker_quote']=[
                'key'=>'worker_quote',
                'label'=>empty($default['active_quote_id'])?'真实师傅报价':'真实修改师傅报价',
                'description'=>'调用正式 OrderDeal::quotes.create；会产生真实 quotes / quote_versions / 订单事件，但演示订单不会进入外部消息发件箱。',
                'workers'=>$workers,
                'default_worker_slot'=>(string)$default['slot_key'],
                'default_amount_cents'=>max((int)($order['booking_fee_cents']??0),52000),
            ];
        }

        if(empty($order['selected_quote_id']) && empty($order['current_assignment_id'])){
            $quotes=$this->selectableQuotes((int)$order['id']);
            if($quotes){
                $items['customer_select_quote']=[
                    'key'=>'customer_select_quote',
                    'label'=>'真实客户选择报价',
                    'description'=>'调用正式 OrderQuoteSelection；普通施工单会确认报价、生成指派并按现有规则自动接单。',
                    'quotes'=>$quotes,
                ];
            }
        }
        return ['items'=>$items,'message'=>$items?'以下动作会真实推进当前测试订单，请用重建获得新的待报价起点。':'当前状态没有可执行的 M2 动作。'];
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('真实测试动作仅允许 testing 环境。','TEST_ACTION_PRODUCTION_BLOCKED',403);
        $context=$this->context($instancePublicId,true);
        if(!$this->supportsPositiveScenario((string)$context['scenario_key']))throw new TestCenterException('当前场景未开放真实流程推进。','TEST_ACTION_SCENARIO_UNSUPPORTED',409);
        $instance=(array)$context['instance'];$order=(array)$context['order'];
        if(!in_array($actionKey,['worker_quote','customer_select_quote'],true))throw new TestCenterException('测试动作不存在或尚未开放。','TEST_ACTION_NOT_FOUND',404);
        if($requestKey===''||strlen($requestKey)>96||preg_match('/^[A-Za-z0-9._:-]+$/D',$requestKey)!==1)throw new TestCenterException('测试动作请求键无效，请刷新后重试。','TEST_ACTION_REQUEST_KEY_INVALID',422);

        $normalized=$this->normalizeInput($actionKey,$input,$order);
        $digest=$this->digest(['action'=>$actionKey,'input'=>$normalized,'order_id'=>(int)$order['id']]);
        $existing=$this->db->fetch('SELECT * FROM test_runs WHERE instance_id=:instance AND BINARY idempotency_key=BINARY :idem LIMIT 1',['instance'=>(int)$instance['id'],'idem'=>$requestKey]);
        if($existing){
            if(!hash_equals((string)$existing['request_digest'],$digest))throw new TestCenterException('同一动作请求键已经用于不同参数，请刷新后重试。','TEST_ACTION_IDEMPOTENCY_CONFLICT',409);
            if((string)$existing['result']==='passed')return ['replayed'=>true,'run_public_id'=>(string)$existing['public_id'],'result'=>'passed','after'=>$this->decode((string)($existing['after_summary_json']??''))];
            if((string)$existing['result']==='failed')throw new TestCenterException((string)($existing['error_message']?:'该动作此前已经失败，请刷新后重新发起。'),(string)($existing['error_code']?:'TEST_ACTION_ALREADY_FAILED'),409,['run_public_id'=>$existing['public_id']]);
            throw new TestCenterException('相同测试动作正在执行，请刷新后查看结果。','TEST_ACTION_RUNNING',409,['run_public_id'=>$existing['public_id']]);
        }
        if(!$this->waitingQuoteState($order))throw new TestCenterException('当前订单已经离开待报价阶段，不能执行该动作。','TEST_ACTION_STATE_CHANGED',409);

        $before=$this->summary((int)$order['id']);$runPublic=\public_id();$started=microtime(true);
        $runId=$this->db->insert(
            "INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at)
             VALUES(:public_id,:instance,:action_key,:actor_role,'real_service',:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_id'=>$runPublic,'instance'=>(int)$instance['id'],'action_key'=>$actionKey,
                'actor_role'=>$actionKey==='worker_quote'?(string)$normalized['worker_slot']:'user',
                'idem'=>$requestKey,'digest'=>$digest,'request_id'=>\request_id(),'before_json'=>$this->json($before),'admin'=>$adminId,
            ]
        );

        try{
            $business=$actionKey==='worker_quote'
                ? $this->workerQuote((int)$instance['id'],$order,$normalized)
                : $this->customerSelectQuote($order,$normalized);
            $after=$this->summary((int)$order['id']);
            $duration=(int)round((microtime(true)-$started)*1000);
            $this->db->execute(
                "UPDATE test_runs SET result='passed',after_summary_json=:after_json,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",
                ['after_json'=>$this->json(['business'=>$business,'facts'=>$after]),'duration'=>$duration,'id'=>$runId]
            );
            $this->db->execute("UPDATE test_instances SET verification_status='pending',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>(int)$instance['id']]);
            return ['replayed'=>false,'run_public_id'=>$runPublic,'result'=>'passed','business'=>$business,'before'=>$before,'after'=>$after];
        }catch(OrderOperationException $exception){
            $this->failRun($runId,$started,$exception->apiCode(),$exception->getMessage(),(int)$order['id']);
            $status=(int)$exception->getCode();if($status<400||$status>599)$status=409;
            throw new TestCenterException($exception->getMessage(),$exception->apiCode(),$status,$exception->data());
        }catch(Throwable $exception){
            $this->failRun($runId,$started,'TEST_ACTION_FAILED',$exception->getMessage(),(int)$order['id']);
            throw new TestCenterException('真实测试动作执行失败，请查看测试执行记录和运行日志。','TEST_ACTION_FAILED',500,['run_public_id'=>$runPublic]);
        }
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $input @return array<string,mixed> */
    private function workerQuote(int $instanceId,array $order,array $input): array
    {
        $slot=(string)$input['worker_slot'];
        $worker=$this->db->fetch(
            "SELECT tp.slot_key,tp.user_id,tp.worker_id,tp.expected_status,u.public_id user_public_id,u.nickname,
                    w.public_id worker_public_id,w.display_name,w.verification_status,w.cloud_record_mode,w.lat worker_lat,w.lng worker_lng,w.city worker_city,w.district worker_district
             FROM test_participants tp JOIN users u ON u.id=tp.user_id JOIN worker_profiles w ON w.id=tp.worker_id
             WHERE tp.instance_id=:instance AND BINARY tp.slot_key=BINARY :slot LIMIT 1",
            ['instance'=>$instanceId,'slot'=>$slot]
        );
        if(!$worker||(string)$worker['expected_status']!=='approved'||(string)$worker['verification_status']!=='approved'||(string)$worker['cloud_record_mode']!=='demo_local')throw new TestCenterException('所选测试师傅不是当前实例登记的已审核 testing 参与者。','TEST_ACTION_WORKER_INVALID',409);
        $versions=$this->agreements->currentVersions();
        if(!$versions)throw new TestCenterException('当前没有可确认的师傅协议版本，无法执行真实报价。','TEST_ACTION_AGREEMENTS_UNAVAILABLE',409);
        $actor=[
            'user_id'=>(int)$worker['user_id'],'real_user_id'=>(int)$worker['user_id'],'user_public_id'=>(string)$worker['user_public_id'],
            'worker_id'=>(int)$worker['worker_id'],'worker_public_id'=>(string)$worker['worker_public_id'],'worker_name'=>(string)$worker['display_name'],
            'worker_status'=>(string)$worker['verification_status'],'worker_lat'=>$worker['worker_lat'],'worker_lng'=>$worker['worker_lng'],
            'worker_city'=>(string)($worker['worker_city']??''),'worker_district'=>(string)($worker['worker_district']??''),
            'is_demo_actor'=>true,'demo_role'=>'worker','is_admin'=>false,'business_admin'=>false,
        ];
        $body=[
            'order_id'=>(string)$order['public_no'],'amount_cents'=>(int)$input['amount_cents'],'description'=>(string)$input['description'],
            'quote_details'=>['included'=>'测试中心 M2 真实报价','excluded'=>'','available'=>'按订单预约','conditions'=>'仅 testing 场景'],
            'rule_acknowledged'=>true,'agreement_versions'=>$versions,'distance_acknowledged'=>false,
        ];
        if(is_numeric($worker['worker_lat']??null)&&is_numeric($worker['worker_lng']??null)){$body['current_lat']=(float)$worker['worker_lat'];$body['current_lng']=(float)$worker['worker_lng'];}
        return $this->orders->deal()->execute('quotes.create',$actor,$body);
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $input @return array<string,mixed> */
    private function customerSelectQuote(array $order,array $input): array
    {
        $quote=$this->db->fetch("SELECT public_id,status FROM quotes WHERE order_id=:order AND BINARY public_id=BINARY :quote LIMIT 1",['order'=>(int)$order['id'],'quote'=>(string)$input['quote_id']]);
        if(!$quote||(string)$quote['status']!=='active')throw new TestCenterException('所选报价已不存在或当前不可选择。','TEST_ACTION_QUOTE_INVALID',409);
        $actor=[
            'user_id'=>(int)$order['user_id'],'real_user_id'=>(int)$order['user_id'],'worker_id'=>null,
            'is_demo_actor'=>true,'demo_role'=>'user','is_admin'=>false,'business_admin'=>false,
        ];
        return $this->orders->quoteSelection()->execute($actor,['quote_id'=>(string)$quote['public_id']]);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $order @return array<string,mixed> */
    private function normalizeInput(string $actionKey,array $input,array $order): array
    {
        if($actionKey==='worker_quote'){
            $slot=trim((string)($input['worker_slot']??''));
            if($slot===''||preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$slot)!==1)throw new TestCenterException('请选择测试师傅。','TEST_ACTION_WORKER_SLOT_REQUIRED',422);
            $amount=$this->moneyToCents((string)($input['amount_yuan']??''));
            $minimum=max(1,(int)($order['booking_fee_cents']??0));
            if($amount<$minimum||$amount>100000000)throw new TestCenterException('真实测试报价不能低于预约费，且不能超过 100 万元。','TEST_ACTION_AMOUNT_INVALID',422,['minimum_cents'=>$minimum]);
            return ['worker_slot'=>$slot,'amount_cents'=>$amount,'description'=>mb_substr(trim((string)($input['description']??'测试中心 M2 真实报价')),0,300)];
        }
        $quoteId=trim((string)($input['quote_id']??''));
        if($quoteId===''||strlen($quoteId)>80)throw new TestCenterException('请选择要接受的测试报价。','TEST_ACTION_QUOTE_REQUIRED',422);
        return ['quote_id'=>$quoteId];
    }

    /** @return array<string,mixed> */
    private function context(string $publicId,bool $mustHaveOrder): array
    {
        $publicId=trim($publicId);if($publicId==='')throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_REQUIRED',422);
        $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>$publicId]);
        if(!$instance)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
        $order=null;
        if(!empty($instance['order_id']))$order=$this->db->fetch('SELECT * FROM orders WHERE id=:id LIMIT 1',['id'=>(int)$instance['order_id']]);
        if($mustHaveOrder&&!$order)throw new TestCenterException('测试实例业务数据已经清理。','TEST_INSTANCE_DELETED',409);
        if($order&&((int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0))throw new TestCenterException('目标订单不属于可执行真实测试动作的数据域。','TEST_ACTION_ORDER_BOUNDARY',409);
        return ['instance'=>$instance,'scenario_key'=>(string)$instance['scenario_key'],'order'=>$order];
    }

    /** @param array<string,mixed> $order */
    private function waitingQuoteState(array $order): bool
    {
        return (string)($order['order_source_type']??'')==='consumer'
            && (string)($order['workflow_profile']??'construction')==='construction'
            && (string)($order['dispatch_mode']??'multi_quote')==='multi_quote'
            && (string)($order['audit_status']??'')==='approved'
            && (string)($order['publish_status']??'')==='published'
            && (string)($order['payment_status']??'')==='paid'
            && in_array((string)($order['quote_status']??''),['waiting','quoting','collecting'],true)
            && (string)($order['assignment_status']??'unassigned')==='unassigned'
            && (string)($order['lifecycle_status']??'')==='active';
    }

    private function supportsPositiveScenario(string $key): bool
    {
        return in_array($key,[
            'ordinary.construction.multi_quote.waiting.zero_quotes',
            'ordinary.construction.multi_quote.waiting.one_quote',
            'ordinary.construction.multi_quote.waiting.three_quotes','ordinary.construction.proxy.draft',
        ],true);
    }

    /** @return array<int,array<string,mixed>> */
    private function approvedParticipants(int $instanceId,int $orderId): array
    {
        return $this->db->fetchAll(
            "SELECT tp.slot_key,tp.user_id,tp.worker_id,w.display_name,w.verification_status,
                    (SELECT q.public_id FROM quotes q WHERE q.order_id=:order_id AND q.worker_id=tp.worker_id AND q.quote_type='worker' AND q.status='active' ORDER BY q.id DESC LIMIT 1) active_quote_id
             FROM test_participants tp JOIN worker_profiles w ON w.id=tp.worker_id
             WHERE tp.instance_id=:instance AND tp.expected_status='approved' AND w.verification_status='approved'
             ORDER BY tp.id",
            ['order_id'=>$orderId,'instance'=>$instanceId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function selectableQuotes(int $orderId): array
    {
        return $this->db->fetchAll(
            "SELECT q.public_id,q.worker_id,q.amount_cents,w.display_name worker_name,q.current_version
             FROM quotes q LEFT JOIN worker_profiles w ON w.id=q.worker_id
             WHERE q.order_id=:order_id AND q.quote_type='worker' AND q.status='active'
             ORDER BY q.amount_cents,q.id",
            ['order_id'=>$orderId]
        );
    }

    /** @return array<string,mixed> */
    private function summary(int $orderId): array
    {
        $order=$this->db->fetch('SELECT public_no,audit_status,publish_status,quote_status,assignment_status,fulfillment_status,payment_status,lifecycle_status,selected_quote_id,assigned_worker_id,current_assignment_id,final_amount_cents,version FROM orders WHERE id=:id',['id'=>$orderId]);
        return [
            'order'=>$order?:[],
            'active_quotes'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND status='active'",['id'=>$orderId]),
            'selected_quotes'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND status='selected'",['id'=>$orderId]),
            'quote_versions'=>(int)$this->db->fetchValue('SELECT COUNT(*) FROM quote_versions qv JOIN quotes q ON q.id=qv.quote_id WHERE q.order_id=:id',['id'=>$orderId]),
            'assignments'=>(int)$this->db->fetchValue('SELECT COUNT(*) FROM assignments WHERE order_id=:id',['id'=>$orderId]),
            'accepted_assignments'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM assignments WHERE order_id=:id AND status='accepted'",['id'=>$orderId]),
            'quote_selections'=>(int)$this->db->fetchValue('SELECT COUNT(*) FROM quote_selections WHERE order_id=:id',['id'=>$orderId]),
        ];
    }

    private function failRun(int $runId,float $started,string $code,string $message,int $orderId): void
    {
        $duration=(int)round((microtime(true)-$started)*1000);
        $this->db->execute(
            "UPDATE test_runs SET result='failed',error_code=:code,error_message=:message,after_summary_json=:after_json,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",
            ['code'=>mb_substr($code,0,80),'message'=>mb_substr($message,0,1000),'after_json'=>$this->json(['facts'=>$this->summary($orderId)]),'duration'=>$duration,'id'=>$runId]
        );
    }

    private function moneyToCents(string $value): int
    {
        $value=trim($value);
        if(preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D',$value)!==1)throw new TestCenterException('报价金额格式不正确，最多保留两位小数。','TEST_ACTION_AMOUNT_INVALID',422);
        [$yuan,$decimal]=array_pad(explode('.',$value,2),2,'');
        $decimal=str_pad($decimal,2,'0');
        return (int)$yuan*100+(int)substr($decimal,0,2);
    }

    /** @param mixed $value */
    private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
    /** @return array<string,mixed> */
    private function decode(string $value): array{$decoded=$value!==''?json_decode($value,true):null;return is_array($decoded)?$decoded:[];}
    /** @param mixed $value */
    private function digest($value): string{return hash('sha256',$this->json($value));}
}
