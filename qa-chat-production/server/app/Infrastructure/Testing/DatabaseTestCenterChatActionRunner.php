<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** Export an allowlisted fixture from the EXISTING instance, never a real identity or provider credential. */
final class DatabaseTestCenterChatActionRunner implements TestCenterActionRunner
{
    public const ACTION='chat_fixture_export';
    public const IMPORT='chat_result_import';
    public const SUITES=[
        'recovery'=>'双游标、进程重启与撤权恢复',
        'media'=>'1/2/4/9 图、部分失败与共享对象',
        'governance'=>'抽检、禁聊、举报与申诉',
        'reminders'=>'任务重放、已读/撤权与渠道故障',
        'retention'=>'留存、清理与安全恢复',
    ];
    private Database $db;
    private TestCenterActionRunner $inner;
    private TestingActionExecution $execution;
    public function __construct(Database $db,TestCenterActionRunner $inner)
    {$this->db=$db;$this->inner=$inner;$this->execution=new TestingActionExecution($db);}

    public function available(string $id): array
    {
        $result=$this->inner->available($id);
        if(!TestingEnvironmentPolicy::active())return$result;
        [$instance,$order]=$this->execution->context($id);
        if(!$order||$instance['build_status']!=='built'||!empty($instance['archived_at'])||!empty($instance['deleted_at']))return$result;
        $result['items'][self::ACTION]=['key'=>self::ACTION,'label'=>'准备隔离聊天回归',
            'description'=>'从本实例导出无身份凭证的夹具，复用独立 MySQL/Redis 回归；准备成功不代表回归通过，不调用真实消息渠道。',
            'suites'=>self::SUITES];
        if(preg_match('/^[a-f0-9]{40}$/D',(string)\app_config('chat.test_report_revision',''))===1){
            $result['items'][self::IMPORT]=['key'=>self::IMPORT,'label'=>'登记隔离聊天回归结果',
                'description'=>'校验回归工具的签名、当前实例夹具及指定源码版本，分别记录回归通过或失败；不代表真机、主机或真实渠道验收。'];
        }
        return$result;
    }
    public function run(string $id,string $action,array $input,int $adminId,string $request): array
    {
        if($action===self::IMPORT)return$this->importReport($id,(string)($input['chat_report']??''),$adminId,$request);
        if($action!==self::ACTION)return$this->inner->run($id,$action,$input,$adminId,$request);
        $suite=(string)($input['chat_suite']??'');
        if(!isset(self::SUITES[$suite]))throw new TestCenterException('请选择聊天回归场景。','TEST_CHAT_SUITE_INVALID',422);
        $result=$this->execution->run($id,$action,['suite'=>$suite],$adminId,$request,'system','isolated_fixture',
            function(array $instance,array $order,array $command):array {
                // Re-read under the existing instance execution boundary. Production orders never qualify.
                $fixture=self::fixture($instance,$order,(string)$command['suite']);
                return ['status'=>'fixture_ready','tests_executed'=>false,'external_delivery_executed'=>false,
                    'fixture_sha256'=>hash('sha256',self::encode($fixture)),'fixture'=>$fixture];
            });
        $result['operation_mode']='isolated_fixture';return$result;
    }

    private function importReport(string $id,string $raw,int $adminId,string $request): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('回归登记仅允许测试环境。','TEST_ACTION_PRODUCTION_BLOCKED',403);
        if(strlen($raw)>65536)throw new TestCenterException('回归结果过大。','TEST_CHAT_REPORT_INVALID',422);
        $envelope=json_decode($raw,true);
        if(!is_array($envelope)||count($envelope)!==2||!is_string($envelope['payload']??null)||!is_string($envelope['mac']??null))throw new TestCenterException('回归结果格式无效。','TEST_CHAT_REPORT_INVALID',422);
        $encoded=(string)\getenv((string)\app_config('chat.test_report_key_env','CHAT_TEST_REPORT_KEY'));
        $key=base64_decode($encoded,true);$payload=base64_decode($envelope['payload'],true);
        if(!is_string($key)||strlen($key)!==32||base64_encode($key)!==$encoded||!is_string($payload)||base64_encode($payload)!==$envelope['payload']
            ||!hash_equals(hash_hmac('sha256',$payload,$key),$envelope['mac']))throw new TestCenterException('回归结果签名不匹配。','TEST_CHAT_REPORT_SIGNATURE',422);
        $report=json_decode($payload,true);$expected=(string)\app_config('chat.test_report_revision','');
        $fields=['schema','instance','suite','fixture_sha256','status','tests','failed','skipped','simulated_provider','real_channel','go_version','source_sha256','source_revision'];
        if(!is_array($report)||array_diff(array_keys($report),$fields)||array_diff($fields,array_keys($report))
            ||$report['schema']!==1||$report['instance']!==$id||!isset(self::SUITES[$report['suite']??''])
            ||$report['simulated_provider']!==true||$report['real_channel']!==false||$report['go_version']!=='go1.27.3'
            ||preg_match('/^[a-f0-9]{40}$/D',$expected)!==1||$report['source_revision']!==$expected
            ||preg_match('/^[a-f0-9]{64}$/D',(string)$report['source_sha256'])!==1
            ||!in_array($report['status'],['passed','failed'],true))throw new TestCenterException('实例、源码或回归范围不匹配。','TEST_CHAT_REPORT_BINDING',409);
        foreach(['tests','failed','skipped'] as $list){
            if(!is_array($report[$list])||count($report[$list])>500)throw new TestCenterException('回归条目无效。','TEST_CHAT_REPORT_INVALID',422);
            foreach($report[$list] as $test)if(!is_string($test)||preg_match('#^Test[A-Za-z0-9_./:-]{1,199}$#D',$test)!==1)throw new TestCenterException('回归条目无效。','TEST_CHAT_REPORT_INVALID',422);
        }
        if($report['status']==='passed'&&(!$report['tests']||$report['failed']||$report['skipped']))throw new TestCenterException('通过结果存在缺项。','TEST_CHAT_REPORT_INCOMPLETE',409);
        [$instance,$order]=$this->execution->context($id);
        $fixture=self::fixture($instance,(array)$order,$report['suite']);$digest=hash('sha256',self::encode($fixture));
        if(!hash_equals($digest,(string)$report['fixture_sha256']))throw new TestCenterException('实例夹具已经变化。','TEST_CHAT_REPORT_BINDING',409);
        $prepared=false;
        foreach($this->db->fetchAll("SELECT after_summary_json FROM test_runs WHERE instance_id=:instance AND action_key='chat_fixture_export' AND result='passed' ORDER BY id DESC LIMIT 20",['instance'=>(int)$instance['id']]) as $row){
            $summary=json_decode((string)$row['after_summary_json'],true);
            if(hash_equals($digest,(string)($summary['business']['fixture_sha256']??'')))$prepared=true;
        }
        if(!$prepared)throw new TestCenterException('请先为当前实例准备同一回归夹具。','TEST_CHAT_FIXTURE_REQUIRED',409);
        $result=$this->execution->run($id,self::IMPORT,['report_sha256'=>hash('sha256',$payload)],$adminId,$request,'system','isolated_regression',
            static fn(array $i,array $o,array $command):array=>['status'=>$report['status']==='passed'?'regression_passed':'regression_failed','tests_executed'=>true,'real_channel'=>false,'report'=>$report]);
        $result['operation_mode']='isolated_regression';$result['regression_passed']=$report['status']==='passed';
        if(!$result['regression_passed']){
            $this->db->execute("UPDATE test_runs SET result='failed',error_code='TEST_CHAT_REGRESSION_FAILED',error_message='隔离聊天回归未通过，请查看签名结果。' WHERE BINARY public_id=BINARY :id",['id'=>$result['run_public_id']]);
            $result['result']='failed';
        }
        return$result;
    }

    /** A schema shared with chat/scripts/test-center.py. No source IDs, names, addresses, tokens or content. */
    public static function fixture(array $instance,array $order,string $suite): array
    {
        $id=(string)($instance['public_id']??'');
        $profile=(string)($order['workflow_profile']??'construction');
        $source=(string)($order['order_source_type']??'consumer');
        $dispatch=(string)($order['dispatch_mode']??'multi_quote');
        if(!isset(self::SUITES[$suite])||preg_match('/^[A-Za-z0-9_-]{1,96}$/D',$id)!==1
            ||(int)($order['is_demo']??0)!==1
            ||(string)($instance['order_no_snapshot']??'')!==(string)($order['public_no']??'')
            ||strpos((string)($order['public_no']??''),'BXDEMOHALL')===0
            ||!in_array($profile,['construction','measurement'],true)||!in_array($source,['consumer','platform'],true)
            ||!in_array($dispatch,['multi_quote','single_grab'],true)){
            throw new TestCenterException('夹具必须来自当前测试实例。','TEST_CHAT_INSTANCE_BOUNDARY',403);
        }
        return ['schema'=>1,'instance'=>$id,'suite'=>$suite,'environment'=>'test','provider'=>'simulated',
            'order'=>['workflow'=>$profile,'source'=>$source,'dispatch'=>$dispatch],
            'actors'=>['worker','customer','platform_staff'],'image_counts'=>[1,2,4,9]];
    }
    private static function encode(array $value): string
    {return(string)json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
