<?php

declare(strict_types=1);

function replayAssert(bool $condition,string $label): void
{
    if(!$condition){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function replayFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return$value;
}

$root=dirname(__DIR__,2);
$contract=replayFile($root,'server/app/Contracts/TestCenterFailureReplay.php');
$capture=replayFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterReplayCaptureRunner.php');
$replay=replayFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterFailureReplay.php');
$center=replayFile($root,'server/app/Application/Testing/TestCenter.php');
$composition=replayFile($root,'server/app/Foundation/TestCenterComposition.php');
$module=replayFile($root,'server/app/Http/Admin/Modules/TestCenterAdminModule.php');
$wrapper=replayFile($root,'server/views/admin/test-center-detail-v260.php');
$view=replayFile($root,'server/views/admin/test-center-failure-replay.php');

replayAssert(strpos($contract,'function available')!==false&&strpos($contract,'function replay')!==false,'failure replay has explicit discovery and execution contract');

replayAssert(strpos($capture,"result=\\'failed\\'")!==false,'capture only enriches persisted failed runs');
replayAssert(strpos($capture,"['id','action_key','request_key','_token','_csrf','csrf','csrf_token']")!==false,'capture excludes transport and csrf fields');
foreach(['password','secret','token','openid','mobile','phone','webhook','private[_-]?key','api[_-]?key','access[_-]?key'] as $needle){
    replayAssert(strpos($capture,$needle)!==false,'capture blocks sensitive key family '.$needle);
}
replayAssert(strpos($capture,"'replay_request'")!==false&&strpos($capture,"'replay_blocked_fields'")!==false,'capture persists only replay metadata inside existing run summary');
replayAssert(strpos($capture,'catch(Throwable $ignored)')!==false,'capture metadata failure cannot replace original business failure');

replayAssert(strpos($replay,'TestingEnvironmentPolicy::active()')!==false,'replay is testing-only');
replayAssert(strpos($replay,"result='failed'")!==false,'replay source must be a failed run');
replayAssert(strpos($replay,'instance_id=:instance')!==false,'failed run is scoped to current instance');
replayAssert(strpos($replay,"is_demo")!==false&&strpos($replay,'BXDEMOHALL')!==false,'replay keeps demo ownership and review-sample boundary');
replayAssert(strpos($replay,'replay_blocked_fields')!==false&&strpos($replay,'TEST_FAILURE_REPLAY_SENSITIVE_INPUT')!==false,'sensitive captured fields block replay');
replayAssert(strpos($replay,'currentActionKeys')!==false&&strpos($replay,'TEST_FAILURE_REPLAY_STATE_CHANGED')!==false,'current business state must still expose the failed action');
replayAssert(strpos($replay,'$this->runner->run')!==false,'replay re-enters normal guarded action runner instead of editing state directly');
replayAssert(strpos($replay,'UPDATE orders')===false&&strpos($replay,'UPDATE test_instances SET')===false,'replay service does not directly mutate order or instance state');

replayAssert(strpos($center,'failure_replays')!==false&&strpos($center,'replayFailure')!==false,'application service exposes replay discovery and execution');
$capturePos=strpos($composition,'new DatabaseTestCenterReplayCaptureRunner');
$safetyPos=strpos($composition,'new DatabaseTestCenterActionSafetyRunner');
replayAssert($capturePos!==false&&$safetyPos!==false&&$capturePos<$safetyPos,'replay capture stays inside shared outer action safety guard');
replayAssert(strpos($composition,'new DatabaseTestCenterFailureReplay($db,$actions)')!==false,'failure replay calls the same outer guarded runner');

replayAssert(strpos($module,"'test-center.replay-failure'")!==false,'admin route explicitly allows failure replay');
replayAssert(strpos($module,'test_center.failure_replay')!==false,'admin replay writes audit event');
replayAssert(strpos($module,'failureReplayRequestKeys')!==false,'admin generates new replay request keys server-side');
replayAssert(strpos($wrapper,"test-center-failure-replay")!==false,'detail wrapper injects failure replay panel');
replayAssert(strpos($view,'一键复现')!==false&&strpos($view,'test-center.replay-failure')!==false,'admin detail renders replay action');
replayAssert(strpos($view,'不会强行回退或改状态')!==false,'admin UI states no state rewind boundary');
replayAssert(strpos($view,'OpenID')!==false&&strpos($view,'手机号')!==false&&strpos($view,'密钥')!==false,'admin UI explains sensitive-field exclusion');

fwrite(STDOUT,"Test-center failure replay contract passed.\n");

// 真实类的行为回归：成功重试、参数冲突和不可变失败快照。
spl_autoload_register(static function(string $class)use($root):void{
    if(strpos($class,'WorkerAdmin\\')!==0)return;
    $file=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
    if(is_file($file))require_once $file;
});
function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
final class ReplayBehaviorDatabase extends \WorkerAdmin\Core\Database
{
    public array $source=['id'=>1,'public_id'=>'FAILED1','action_key'=>'worker_quote','result'=>'failed','before_summary_json'=>'{}'];
    public ?array $executed=null;
    public function __construct(){parent::__construct([]);}
    public function fetch($sql,array $params=[]){
        if(strpos($sql,'FROM test_instances')!==false)return ['id'=>1,'order_id'=>10,'build_status'=>'built','archived_at'=>null,'deleted_at'=>null];
        if(strpos($sql,'FROM orders')!==false)return ['id'=>10,'public_no'=>'BXTEST10','is_demo'=>1];
        if(strpos($sql,'FROM test_runs')!==false){
            if(isset($params['run']))return $this->source;
            return ($params['idem']??'')==='source:1'?$this->source:(($params['idem']??'')==='retry:1'?$this->executed:null);
        }
        throw new RuntimeException('Unexpected replay query');
    }
    public function execute($sql,array $params=[]){$this->source['before_summary_json']=$params['summary'];return 1;}
}
final class ReplayBehaviorRunner implements \WorkerAdmin\Contracts\TestCenterActionRunner
{
    public bool $available=true;public int $calls=0;private ReplayBehaviorDatabase $db;
    public function __construct(ReplayBehaviorDatabase $db){$this->db=$db;}
    public function available(string $id):array{return ['items'=>$this->available?['worker_quote'=>['key'=>'worker_quote']]:[]];}
    public function run(string $id,string $action,array $input,int $admin,string $key):array{
        $this->calls++;
        if($key==='source:1')throw new \WorkerAdmin\Domain\Testing\TestCenterException('Changed request','TEST_ACTION_IDEMPOTENCY_CONFLICT',409);
        $this->db->executed=['id'=>2];$this->available=false;
        return ['replayed'=>$this->calls>1,'run_public_id'=>'PASSED2'];
    }
}
$behaviorDb=new ReplayBehaviorDatabase();$behaviorRunner=new ReplayBehaviorRunner($behaviorDb);
$behaviorDb->source['before_summary_json']=json_encode(['replay_request'=>['amount_yuan'=>'100'],'replay_blocked_fields'=>[]]);
$behaviorReplay=new \WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterFailureReplay($behaviorDb,$behaviorRunner);
$behaviorReplay->replay('INSTANCE','FAILED1',1,'retry:1');
replayAssert($behaviorReplay->replay('INSTANCE','FAILED1',1,'retry:1')['replayed']===true,'same replay key reaches persistent idempotency even after state advances');
$code='';try{$behaviorReplay->replay('INSTANCE','FAILED1',1,'retry:2');}catch(\WorkerAdmin\Domain\Testing\TestCenterException $e){$code=$e->businessCode();}
replayAssert($code==='TEST_FAILURE_REPLAY_STATE_CHANGED','new replay key cannot bypass changed state');
$captureBehavior=new \WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterReplayCaptureRunner($behaviorDb,$behaviorRunner);
$original=$behaviorDb->source['before_summary_json'];
try{$captureBehavior->run('INSTANCE','worker_quote',['amount_yuan'=>'999'],1,'source:1');}catch(\WorkerAdmin\Domain\Testing\TestCenterException $e){}
replayAssert($behaviorDb->source['before_summary_json']===$original,'conflicting retry cannot overwrite original failure replay request');
$captureMethod=new ReflectionMethod($captureBehavior,'capture');$captureMethod->setAccessible(true);
$captureMethod->invoke($captureBehavior,'INSTANCE','worker_quote',['amount_yuan'=>'999'],'source:1');
replayAssert($behaviorDb->source['before_summary_json']===$original,'captured failure request remains immutable on repeated failures');
$behaviorDb->source['before_summary_json']='{}';
$captureMethod->invoke($captureBehavior,'INSTANCE','worker_quote',['note'=>str_repeat('x',2001)],'source:1');
$summary=json_decode($behaviorDb->source['before_summary_json'],true);
replayAssert($summary['replay_blocked_fields']===['note'],'truncated replay input is explicitly blocked instead of silently changed');
fwrite(STDOUT,"Failure replay behavior passed.\n");
