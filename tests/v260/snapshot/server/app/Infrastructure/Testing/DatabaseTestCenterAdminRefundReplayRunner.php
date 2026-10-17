<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;

final class DatabaseTestCenterAdminRefundReplayRunner implements TestCenterActionRunner
{
    private Database $db;private TestCenterActionRunner $inner;private TestingRefundBoundaryGuard $funds;
    public function __construct(Database $db,TestCenterActionRunner $inner){$this->db=$db;$this->inner=$inner;$this->funds=new TestingRefundBoundaryGuard($db);}
    public function available(string $instancePublicId):array{return$this->inner->available($instancePublicId);}
    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey):array
    {
        if($actionKey!=='admin_refund_approve')return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);$instance=$this->db->fetch('SELECT id,order_id FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>trim($instancePublicId)]);if(!$instance||empty($instance['order_id']))return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);$order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]);if(!$order)return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);$existing=$requestKey!==''?$this->db->fetch('SELECT * FROM test_runs WHERE instance_id=:instance AND BINARY idempotency_key=BINARY :idem LIMIT 1',['instance'=>(int)$instance['id'],'idem'=>$requestKey]):null;
        if($existing){$before=$this->decode((string)($existing['before_summary_json']??''));$stored=is_array($before['request']??null)?(array)$before['request']:null;if(!$stored)throw new TestCenterException('该历史管理员退款动作缺少幂等请求快照，请使用新的请求键重新发起。','TEST_ACTION_REPLAY_METADATA_MISSING',409,['run_public_id'=>$existing['public_id']]);$normalized=$this->normalizeReplay($input,$stored);$digest=$this->digest(['action'=>$actionKey,'input'=>$normalized,'order_id'=>(int)$order['id'],'admin_id'=>$adminId]);if(!hash_equals((string)$existing['request_digest'],$digest))throw new TestCenterException('同一动作请求键已经用于不同参数，请刷新后重试。','TEST_ACTION_IDEMPOTENCY_CONFLICT',409);if((string)$existing['result']==='passed')return['replayed'=>true,'run_public_id'=>(string)$existing['public_id'],'result'=>'passed','after'=>$this->decode((string)($existing['after_summary_json']??'')),'operation_mode'=>'real_service'];if((string)$existing['result']==='failed')throw new TestCenterException((string)($existing['error_message']?:'该动作此前已经失败，请刷新后重新发起。'),(string)($existing['error_code']?:'TEST_ACTION_ALREADY_FAILED'),409,['run_public_id'=>$existing['public_id']]);throw new TestCenterException('相同测试动作正在执行，请刷新后查看结果。','TEST_ACTION_RUNNING',409,['run_public_id'=>$existing['public_id']]);}
        $normalized=$this->normalizeCurrent($input,(int)$instance['id'],$order);$result=$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);$runPublic=trim((string)($result['run_public_id']??''));if($runPublic!==''&&empty($result['replayed'])){$row=$this->db->fetch('SELECT before_summary_json FROM test_runs WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>$runPublic]);$before=$row?$this->decode((string)($row['before_summary_json']??'')):[];$before['request']=$normalized;$this->db->execute('UPDATE test_runs SET before_summary_json=:summary WHERE BINARY public_id=BINARY :id',['summary'=>$this->json($before),'id'=>$runPublic]);}return$result;
    }
    private function normalizeCurrent(array $input,int $instanceId,array $order):array{$funds=$this->funds->inspect($instanceId,$order,true);return['note'=>$this->note($input,'测试中心：平台核对后同意按当前可退预约费执行退款登记'),'amount_cents'=>(int)$funds['available_cents']];}
    private function normalizeReplay(array $input,array $stored):array{$fallback=(string)($stored['note']??'测试中心：平台核对后同意按当前可退预约费执行退款登记');return['note'=>$this->note($input,$fallback),'amount_cents'=>(int)($stored['amount_cents']??0)];}
    private function note(array $input,string $fallback):string{$note=mb_substr(trim((string)($input['note']??$fallback)),0,800);if(mb_strlen($note)<4)throw new TestCenterException('请填写至少 4 个字的平台处理说明。','TEST_ADMIN_ADJUDICATION_NOTE_REQUIRED',422);return$note;}
    private function json($value):string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}private function digest($value):string{return hash('sha256',$this->json($value));}private function decode(string $json):array{$value=json_decode($json,true);return is_array($value)?$value:[];}
}
