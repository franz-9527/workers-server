<?php
declare(strict_types=1);

// Synthetic credentials, in-memory SQLite; no network or installed site is used.
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'WorkerAdmin\\'))require_once $root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
});
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Application\Chat\ChatAsrUsage;
use WorkerAdmin\Application\Chat\ChatGoRuntimeConfig;
use WorkerAdmin\Application\Chat\ChatAsrSelfDiagnostic;

function app_config($key,$fallback=null){return ['app.env'=>'testing','app.url'=>'https://fixture.invalid'][$key]??$fallback;}
function setting($key,$fallback=null){return $fallback;}
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function rejects(callable $fn,string $label):void{try{$fn();}catch(RuntimeException $e){check(true,$label);return;}throw new RuntimeException($label);}

final class AsrFixtureDatabase extends Database
{
    public PDO $fixture;
    public array $settings=[];
    public bool $failUsage=false;
    public string $role='super_admin';
    private ?string $testDatabase=null;
    public function __construct(){
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
        if(getenv('CHAT_ASR_MYSQL_TEST')==='1'){
            $address=(string)getenv('CHAT_INTEGRATION_MYSQL_ADDR');
            if(preg_match('/^127\.0\.0\.1:([0-9]{1,5})$/D',$address,$match)!==1)throw new RuntimeException('Explicit loopback fixture required');
            $this->fixture=new PDO('mysql:host=127.0.0.1;port='.$match[1].';charset=utf8mb4','root',(string)getenv('CHAT_INTEGRATION_ADMIN_PASSWORD'),$options+[PDO::ATTR_EMULATE_PREPARES=>false]);
            $this->testDatabase='bixuan_test_asr_'.bin2hex(random_bytes(6));
            $this->fixture->exec('CREATE DATABASE `'.$this->testDatabase.'` CHARACTER SET utf8mb4');
            $this->fixture->exec('USE `'.$this->testDatabase.'`');
            $this->fixture->exec("SET time_zone='+00:00'");
        }else{
            $this->fixture=new PDO('sqlite::memory:',null,null,$options);
            $this->fixture->sqliteCreateFunction('JSON_UNQUOTE',static fn($v)=>$v,1);
        }
        $this->fixture->exec('CREATE TABLE chat_av_asr_requests(state TEXT,metadata_json TEXT,created_at TEXT)');
    }
    public function __destruct(){if($this->testDatabase!==null)$this->fixture->exec('DROP DATABASE `'.$this->testDatabase.'`');}
    public function fetch($sql,array $params=[]){
        if(str_contains($sql,'FROM admin_users'))return ['role'=>$this->role];
        if($this->failUsage)throw new RuntimeException('fixture read failure');
        $q=$this->fixture->prepare($sql);$q->execute($params);return $q->fetch()?:null;
    }
    public function fetchAll($sql,array $params=[]){return array_map(static fn($k,$v)=>['setting_key'=>$k,'setting_value'=>$v],array_keys($this->settings),array_values($this->settings));}
    public function execute($sql,array $params=[]){$this->settings[$params['key']]=$params['value'];return 1;}
    public function transaction(callable $callback){$before=$this->settings;try{return $callback($this);}catch(Throwable $e){$this->settings=$before;throw $e;}}
}
$db=new AsrFixtureDatabase();
$usage=new ChatAsrUsage($db);$now=new DateTimeImmutable('2026-11-01T00:00:00+08:00');
$add=static function(string $state,?string $meta,string $time)use($db):void{$q=$db->fixture->prepare('INSERT INTO chat_av_asr_requests VALUES(?,?,?)');$q->execute([$state,$meta,$time]);};
$direct='{"service":"tencent_cloud_asr"}';
$add('completed',$direct,'2026-10-31 15:59:59'); // previous month in China
$add('completed',$direct,'2026-10-31 16:00:00'); // inclusive start
$add('completed',$direct,'2026-11-30 15:59:59');
$add('completed',$direct,'2026-11-30 16:00:00'); // exclusive end
$add('failed',$direct,'2026-11-02 00:00:00');
$add('unknown',$direct,'2026-11-02 00:00:00');
$add('started',$direct,'2026-11-02 00:00:00');
$add('completed','{"service":"wxa8386175898e12c9"}','2026-11-02 00:00:00');
$add('completed','invalid json','2026-11-02 00:00:00');
$add('queued',null,'2026-11-02 00:00:00');
$data=$usage->read($now);
check($data['status']==='ready'&&$data['month']==='2026-11'&&$data['success']===2,'Beijing calendar month includes exact UTC boundaries');
check($data['total']===5&&$data['failed']===1&&$data['unknown']===1&&$data['pending']===1,'direct requests exclude legacy, invalid JSON and unassigned queue');
check($data['estimated_free_remaining']===4998&&$data['estimated_free_remaining_min']===4996&&!$data['official_remaining_available'],'unknown charges produce a local range, never an official balance');
$db->fixture->exec('DELETE FROM chat_av_asr_requests');
$data=$usage->read($now);check($data['status']==='ready'&&$data['success']===0&&$data['estimated_free_remaining']===5000,'empty successful query is distinguishable from read failure');
$db->failUsage=true;$data=$usage->read($now);check($data['status']==='read_failed'&&$data['success']===null&&$data['estimated_free_remaining']===null,'database failure never fabricates zero use or a full allowance');$db->failUsage=false;

$cipher=new class implements PrivateValueCipher{
    public bool $broken=false;
    public function hmac(string $p,string $c):string{return hash_hmac('sha256',$p,$c);}
    public function encrypt(string $p,string $c):?string{return $this->broken?$p:'fixture:'.base64_encode($c.'|'.$p);}
    public function decrypt(string $p,string $c):string{return substr((string)base64_decode(substr($p,8)),strlen($c)+1);}
};
$runtime=new ChatGoRuntimeConfig($db,$cipher);
$input=['asr_profile'=>'tencent_sentence','tencent_asr_engine'=>'16k_zh_dialect','tencent_asr_secret_id'=>'AKIDFixture123456789','tencent_asr_secret_key'=>'fixture-secret-0123456789abcdef'];
$runtime->save($input,1,'super_admin');
check($runtime->configuration()['tencent_asr_secret_key_configured']&&$db->settings['chat.tencent_asr_secret_key_ciphertext']!==$input['tencent_asr_secret_key'],'credentials are encrypted before persistence');
check(!str_contains(json_encode($runtime->configuration()),$input['tencent_asr_secret_key']),'configuration exposes credential status only');
check(!array_key_exists('tencent_asr_secret_key',$runtime->adminFormValues())&&!str_contains(json_encode($runtime->adminFormValues()),$input['tencent_asr_secret_key']),'normal administrator form payload never contains saved Tencent credentials');
check($runtime->revealSecret('tencent_asr_secret_id')===$input['tencent_asr_secret_id']&&$runtime->revealSecret('tencent_asr_secret_key')===$input['tencent_asr_secret_key'],'explicit secret reveal can recover saved Tencent credentials server-side');
$saved=$db->settings;$runtime->save(['asr_profile'=>'tencent_sentence'],1,'super_admin');
check($db->settings['chat.tencent_asr_secret_id_ciphertext']===$saved['chat.tencent_asr_secret_id_ciphertext']&&$db->settings['chat.tencent_asr_secret_key_ciphertext']===$saved['chat.tencent_asr_secret_key_ciphertext'],'blank inputs preserve both encrypted credentials');
rejects(fn()=>$runtime->save(['clear_tencent_asr_credentials'=>'1'],1,'super_admin'),'active provider cannot clear its credentials');
rejects(fn()=>$runtime->save(['tencent_asr_engine'=>'unknown-engine'],1,'super_admin'),'unknown models are rejected');
$cipher->broken=true;rejects(fn()=>$runtime->save($input,1,'super_admin'),'broken cipher cannot save plaintext');$cipher->broken=false;
check($db->settings===$saved,'rejected saves leave stored settings intact');
$db->role='admin';rejects(fn()=>$runtime->save($input,2,'admin'),'only super administrators may change credentials');$db->role='super_admin';
$runtime->save(['asr_profile'=>'disabled','clear_tencent_asr_credentials'=>'1'],1,'super_admin');
check(!$runtime->configuration()['tencent_asr_secret_key_configured']&&!$runtime->configuration()['tencent_asr_secret_id_configured'],'disabled provider supports explicit credential removal');
rejects(fn()=>$runtime->save(['asr_profile'=>'tencent_sentence'],1,'super_admin'),'enabling direct ASR requires a complete credential pair');

function e($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function admin_url($route,$params=[]):string{return '/admin/'.$route;}
function asset_url($path):string{return '/assets/'.$path;}
function base_path($path=''):string{return $root.'/server'.($path!==''?'/'.ltrim($path,'/'):'');}
function csrf(){return new class{public function input():string{return '<input name="_csrf" value="fixture">';}};}
function local_datetime($value,$format=''):string{return (string)$value;}
$render=static function(array $usage,array $diagnostic=[],array $status=[])use($root):string{
    $enabled=false;$notice='';$canManage=true;$readiness=['ready'=>true];$signing=['configured'=>false];
    $goRuntime=['asr_profile'=>'tencent_sentence','tencent_asr_engine'=>'16k_zh_dialect','tencent_asr_secret_id_configured'=>true,'tencent_asr_secret_key_configured'=>true];$goStatus=$status;$runtimeControl=[];$secretValues=['asr_usage'=>$usage,'asr_diagnostic'=>$diagnostic];
    ob_start();require $root.'/server/views/admin/chat-settings.php';return ob_get_clean();
};
// Exercise a failed read independently from an empty healthy database.
$db->failUsage=true;$failed=$render($usage->read());$db->failUsage=false;
check(str_contains($failed,'用量暂不可读取，剩余额度未知')&&!str_contains($failed,'5,000 / 5,000 次'),'rendered read failure never displays a full allowance');
$ready=$render($usage->read());
check(str_contains($ready,'5,000 / 5,000 次')&&str_contains($ready,'北京时间')&&str_contains($ready,'https://console.cloud.tencent.com/asr'),'rendered healthy usage includes period, local estimate and official balance link');
check(str_contains($ready,'name="tencent_asr_secret_key" value=""')&&!str_contains($ready,'fixture-displayed-secret'),'normal administrator page never renders saved credentials into HTML');
check(str_contains($ready,'data-secret-name="tencent_asr_secret_key"'),'configured secret offers explicit reveal control');

$diagnostic=['status'=>'diagnostic_ready','request_state'=>'completed','provider_request_id'=>'fixture-request-id','service'=>'tencent_cloud_asr','api'=>'SentenceRecognition','engine'=>'16k_zh_dialect','data_encoding'=>'json','url_host'=>'test.20m.cc','url_length'=>105,'source_preflight'=>'passed','latest_intent_id'=>'40','intent_state'=>'accepted','intent_error'=>'','intent_updated_at'=>'2026-11-16 19:31:25','job_phase'=>'published','job_error'=>'','job_submitted_at'=>'2026-11-16 19:31:22','job_next_attempt_at'=>'2026-11-16 19:31:22','asr_intent_id'=>'40','asr_created_at'=>'2026-11-16 19:31:25'];
$go=['versions_match'=>true,'deployed_sha'=>str_repeat('b',40),'running_sha'=>str_repeat('b',40),'current_sha'=>''];
$successHtml=$render($usage->read(),$diagnostic,$go);
$start=strpos($successHtml,'ASR 最近调用');$end=strpos($successHtml,'通话 PHP 端点',$start);$block=substr($successHtml,max(0,$start-120),$end-$start+120);
check(str_contains($block,'admin-alert--success')&&str_contains($block,'ASR 最近调用 · 正常'),'completed Tencent call renders as success, not warning');
check(str_contains($block,'引擎 16k_zh_dialect')&&str_contains($block,'URL 长度 105'),'Tencent diagnostics show current direct-call fields');
check(!str_contains($block,' · Key ')&&!str_contains($block,'长度 105/0'),'retired WeChat service-market key and encoded-length fields are hidden');
check(str_contains($block,'error <code>无</code>'),'empty intent/job errors render as none');
check(str_contains($block,'current 元数据未提供，不影响运行一致性判断')&&!str_contains($block,'Go current <code>—</code>'),'missing current metadata is explicitly non-blocking');

$failedDiagnostic=$diagnostic;$failedDiagnostic['request_state']='failed';$failedDiagnostic['provider_request_id']='';$failedDiagnostic['provider_code']='AuthFailure.SignatureFailure';
$failedHtml=$render($usage->read(),$failedDiagnostic,$go);$fstart=strpos($failedHtml,'ASR 最近调用');$fend=strpos($failedHtml,'通话 PHP 端点',$fstart);$failedBlock=substr($failedHtml,max(0,$fstart-120),$fend-$fstart+120);
check(str_contains($failedBlock,'admin-alert--danger')&&str_contains($failedBlock,'ASR 最近调用 · 失败'),'failed Tencent call remains a danger state');

$self=(new ChatAsrSelfDiagnostic())->build(['runtime'=>['asr_profile'=>'tencent_sentence','asr_api_name'=>'SentenceRecognition','asr_data_encoding'=>'json','tencent_asr_engine'=>'16k_zh_dialect','tencent_asr_secret_id_configured'=>true,'tencent_asr_secret_key_configured'=>true],'go_status'=>$go,'asr'=>$diagnostic,'plugin'=>[]]);
$release=array_values(array_filter($self['checks'],static fn(array $v):bool=>$v['key']==='go_release'))[0]??[];
check(($self['tone']??'')==='success'&&($self['status']??'')==='healthy','completed Tencent call keeps self-diagnostic healthy');
check(str_contains((string)($release['detail']??''),'deployed / running 已确认一致')&&str_contains((string)($release['detail']??''),'current 元数据未提供'),'self-diagnostic does not falsely claim current metadata was checked');

echo "Tencent ASR configuration and usage checks passed\n";
