<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use Throwable;
use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Contracts\TestCenterFailureReplay;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 失败动作一键复现。
 *
 * 仅重放当前实例中已经持久化为 failed 且保存了安全请求快照的动作；
 * 当前状态必须仍由 action runner 明确暴露该动作，避免借“复现”绕过状态机。
 */
final class DatabaseTestCenterFailureReplay implements TestCenterFailureReplay
{
    private Database $db;
    private TestCenterActionRunner $runner;

    public function __construct(Database $db, TestCenterActionRunner $runner)
    {
        $this->db=$db;
        $this->runner=$runner;
    }

    public function available(string $instancePublicId): array
    {
        $instance=$this->instance(trim($instancePublicId));
        if(!$instance)return['items'=>[],'message'=>'测试实例不存在。'];
        if(!$this->writable($instance))return['items'=>[],'message'=>'当前实例已清理、归档或不可写，失败动作不能直接重放。'];
        $order=$this->order($instance);
        if(!$order||!$this->ownedOrder($order))return['items'=>[],'message'=>'当前实例不属于可重放的 testing 订单域。'];

        $current=$this->currentActionKeys($instancePublicId);
        $rows=$this->db->fetchAll(
            "SELECT public_id,action_key,operation_mode,error_code,error_message,created_at,before_summary_json
             FROM test_runs
             WHERE instance_id=:instance AND result='failed'
             ORDER BY id DESC LIMIT 20",
            ['instance'=>(int)$instance['id']]
        );
        $items=[];
        foreach($rows as $row){
            $summary=$this->decode((string)($row['before_summary_json']??''));
            $request=is_array($summary['replay_request']??null)?(array)$summary['replay_request']:null;
            $blocked=is_array($summary['replay_blocked_fields']??null)?array_values($summary['replay_blocked_fields']):[];
            $actionKey=(string)($row['action_key']??'');
            $reason='';
            if($request===null)$reason='该历史失败记录没有安全复现参数；请在新版本重新触发一次失败。';
            elseif($blocked)$reason='原请求包含不应持久化的敏感字段，不能一键重放。';
            elseif(!isset($current[$actionKey]))$reason='当前订单状态已变化，该动作不再开放；请重建场景后按原路径复现。';
            else $reason='当前状态仍允许该动作，可使用原安全参数重新调用正式测试动作。';

            $items[]=[
                'run_public_id'=>(string)($row['public_id']??''),
                'action_key'=>$actionKey,
                'operation_mode'=>(string)($row['operation_mode']??''),
                'error_code'=>(string)($row['error_code']??''),
                'error_message'=>(string)($row['error_message']??''),
                'created_at'=>(string)($row['created_at']??''),
                'replayable'=>$request!==null&&!$blocked&&isset($current[$actionKey]),
                'blocked_fields'=>$blocked,
                'reason'=>$reason,
            ];
        }
        return[
            'items'=>$items,
            'message'=>$items?'只允许重放失败动作；当前状态不再允许时必须重建场景，不能强行回退订单状态。':'当前实例没有失败动作记录。',
        ];
    }

    public function replay(string $instancePublicId,string $runPublicId,int $adminId,string $requestKey): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('失败动作复现只允许 testing 环境。','TEST_FAILURE_REPLAY_PRODUCTION_BLOCKED',403);
        if($adminId<1)throw new TestCenterException('管理员身份无效。','TEST_FAILURE_REPLAY_ADMIN_REQUIRED',403);
        $instancePublicId=trim($instancePublicId);$runPublicId=trim($runPublicId);
        if($runPublicId===''||strlen($runPublicId)>64)throw new TestCenterException('失败执行记录无效。','TEST_FAILURE_REPLAY_RUN_REQUIRED',422);
        if($requestKey===''||strlen($requestKey)>96||preg_match('/^[A-Za-z0-9._:-]+$/D',$requestKey)!==1)throw new TestCenterException('失败复现请求键无效，请刷新后重试。','TEST_FAILURE_REPLAY_REQUEST_KEY_INVALID',422);

        $instance=$this->instance($instancePublicId);
        if(!$instance)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
        if(!$this->writable($instance))throw new TestCenterException('当前测试实例已清理、归档或不可写，不能重放失败动作。','TEST_FAILURE_REPLAY_INSTANCE_NOT_WRITABLE',409);
        $order=$this->order($instance);
        if(!$order||!$this->ownedOrder($order))throw new TestCenterException('目标订单不属于可重放的 testing 数据域。','TEST_FAILURE_REPLAY_ORDER_BOUNDARY',409);

        $run=$this->db->fetch(
            "SELECT * FROM test_runs
             WHERE instance_id=:instance AND BINARY public_id=BINARY :run AND result='failed'
             LIMIT 1",
            ['instance'=>(int)$instance['id'],'run'=>$runPublicId]
        );
        if(!$run)throw new TestCenterException('失败执行记录不存在，或不属于当前实例。','TEST_FAILURE_REPLAY_RUN_NOT_FOUND',404);

        $summary=$this->decode((string)($run['before_summary_json']??''));
        $request=is_array($summary['replay_request']??null)?(array)$summary['replay_request']:null;
        if($request===null)throw new TestCenterException('该失败记录没有安全复现参数，请重新触发一次失败后再试。','TEST_FAILURE_REPLAY_METADATA_MISSING',409);
        $blocked=is_array($summary['replay_blocked_fields']??null)?array_values($summary['replay_blocked_fields']):[];
        if($blocked)throw new TestCenterException('原请求包含敏感字段，测试中心不会持久化或自动重放这些字段。','TEST_FAILURE_REPLAY_SENSITIVE_INPUT',409,['blocked_fields'=>$blocked]);

        $actionKey=(string)($run['action_key']??'');
        $current=$this->currentActionKeys($instancePublicId);
        if($actionKey===''||!isset($current[$actionKey])){
            throw new TestCenterException('当前订单状态已经变化，该失败动作不再允许直接重放；请使用“重建”创建新实例后按原路径复现。','TEST_FAILURE_REPLAY_STATE_CHANGED',409,['action_key'=>$actionKey]);
        }

        $input=$request;
        $input['id']=$instancePublicId;
        $input['action_key']=$actionKey;
        $input['request_key']=$requestKey;
        $result=$this->runner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
        $result['source_failed_run']=$runPublicId;
        return$result;
    }

    /** @return array<string,bool> */
    private function currentActionKeys(string $instancePublicId): array
    {
        try{$available=$this->runner->available($instancePublicId);}catch(Throwable $exception){return[];}
        $keys=[];
        foreach((array)($available['items']??[]) as $key=>$item){
            $actionKey=(string)($item['key']??$key);
            if($actionKey!=='')$keys[$actionKey]=true;
        }
        return$keys;
    }

    private function instance(string $publicId): ?array
    {
        if($publicId==='')return null;
        $row=$this->db->fetch(
            'SELECT id,order_id,build_status,archived_at,deleted_at FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',
            ['id'=>$publicId]
        );
        return$row?:null;
    }

    private function order(array $instance): ?array
    {
        $orderId=(int)($instance['order_id']??0);
        if($orderId<1)return null;
        $row=$this->db->fetch('SELECT id,public_no,is_demo FROM orders WHERE id=:id LIMIT 1',['id'=>$orderId]);
        return$row?:null;
    }

    private function writable(array $instance): bool
    {
        return(int)($instance['order_id']??0)>0
            &&(string)($instance['build_status']??'')==='built'
            &&empty($instance['archived_at'])
            &&empty($instance['deleted_at']);
    }

    private function ownedOrder(array $order): bool
    {
        return(int)($order['is_demo']??0)===1&&strpos((string)($order['public_no']??''),'BXDEMOHALL')!==0;
    }

    private function decode(string $json): array
    {
        $value=json_decode($json,true);
        return is_array($value)?$value:[];
    }
}
