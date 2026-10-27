<?php

declare(strict_types=1);

// This suite reaches public Kernel/Application/Repository boundaries with recording doubles.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (strpos($class,'WorkerAdmin\\') !== 0) return;
    $file = $root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
    if (is_file($file)) require_once $file;
});
$GLOBALS['retirementSettings'] = [
    'wechat.miniapp_appid'=>'test-app','wechat.admin_openids'=>['admin-openid'],'demo.data_enabled'=>true,
    'demo.actor_user_public_id'=>'demo-user','demo.actor_merchant_public_id'=>'demo-merchant','demo.actor_worker_public_id'=>'demo-worker',
];
function setting($key,$default=null) { return $GLOBALS['retirementSettings'][$key] ?? $default; }
function app_config($key,$default=null) { return $default; }
function request_id() { return 'retirement-test'; }
function admin_url($route,$params=[]) { return '/admin/?route='.$route; }
function flash($type,$message) { $GLOBALS['retirementFlash'][] = [$type,$message]; }
final class RetirementResponse extends Exception
{
    public array $payload; public int $status;
    public function __construct(array $payload,int $status) { parent::__construct('response'); $this->payload=$payload; $this->status=$status; }
}
final class RetirementRedirect extends Exception {}
final class RetirementBackendReached extends LogicException {}
function json_response($payload,$status=200) { throw new RetirementResponse($payload,$status); }
function redirect($url) { throw new RetirementRedirect($url); }
function retirementAssert(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
}
final class RetirementInput
{
    public $context; private int $offset=0;
    public function stream_open($path,$mode,$options,&$opened_path) { return $path==='php://input'; }
    public function stream_read($count) { $result=substr('{}',$this->offset,$count); $this->offset+=strlen($result); return $result; }
    public function stream_eof() { return $this->offset>=2; }
    public function stream_stat() { return []; }
}
final class RetirementDatabase extends \WorkerAdmin\Core\Database
{
    public bool $demo=true; public bool $enabled=true; public bool $locked=false; public bool $busy=false;
    public string $role='user'; public bool $admin=true; public array $log=[];
    public function __construct() { parent::__construct(['database'=>'recording_double']); }
    public function fetch($sql,array $params=[])
    {
        $this->log[]=['fetch',$sql];
        if (strpos($sql,'FROM login_sessions')!==false) {
            return ['session_id'=>1,'user_id'=>10,'demo_actor_user_id'=>$this->demo?501:null,
                'worker_id'=>7,'payer_openid'=>$this->admin?'admin-openid':'other-openid','user_public_id'=>'real-user'];
        }
        if (strpos($sql,'FROM users u')!==false && strpos($sql,'WHERE u.id=:id')!==false) {
            return ['user_id'=>501,'user_public_id'=>'demo-'.$this->role,'worker_id'=>$this->role==='worker'?88:null];
        }
        if (strpos($sql,'SELECT u.public_id')!==false) {
            return isset($params['public_id'])?['public_id'=>$params['public_id']]:null;
        }
        if (strpos($sql,'FROM system_settings')!==false) {
            retirementAssert($this->locked,'fresh setting read holds the shared demo lock');
            return ['setting_value'=>$this->enabled?'true':'false'];
        }
        if (strpos($sql,'SELECT is_demo,public_no FROM orders')!==false) {
            return ['is_demo'=>$this->demo?1:0,'public_no'=>$this->demo?'DEMO-ORDER':'REAL-ORDER'];
        }
        throw new RetirementBackendReached('business read');
    }
    public function fetchValue($sql,array $params=[])
    {
        $this->log[]=['value',$sql];
        if (strpos($sql,'GET_LOCK')!==false) {
            retirementAssert(($params['lock_name']??'')==='wall_worker_admin_demo_guard','same setting-switch lock');
            if ($this->busy) return 0;
            $this->locked=true; return 1;
        }
        if (strpos($sql,'RELEASE_LOCK')!==false) { $this->locked=false; return 1; }
        throw new RetirementBackendReached('business value');
    }
    public function execute($sql,array $params=[])
    {
        $this->log[]=['execute',$sql];
        if (strpos($sql,'UPDATE login_sessions')===0) return 1;
        throw new RetirementBackendReached('business write');
    }
    public function transaction(callable $callback)
    {
        retirementAssert($this->locked,'Web review holds the demo lock before its transaction');
        $this->log[]=['transaction','begin'];
        throw new RetirementBackendReached('transaction boundary');
    }
}
final class RetirementContentGate implements \WorkerAdmin\Contracts\RequestContentSecurityGate
{
    public function inspect(string $route): void {}
}
final class RetirementAuth extends \WorkerAdmin\Core\Auth
{
    public bool $forcePassword=true; public bool $loggedIn=true; public int $checks=0;
    public function requireLogin() { ++$this->checks; if (!$this->loggedIn) redirect(admin_url('login')); }
    public function user() { return ['id'=>1,'must_change_password'=>$this->forcePassword]; }
}
final class RetirementCsrf extends \WorkerAdmin\Core\Csrf
{
    public bool $valid=true; public int $checks=0;
    public function verifyRequest() { ++$this->checks; if (!$this->valid) throw new RuntimeException('页面已过期，请刷新后重试。'); }
}
final class RetirementAdminModule implements \WorkerAdmin\Http\Admin\AdminModule
{
    public int $calls=0;
    public function supports(string $route): bool { return true; }
    public function dispatch(string $route): void { ++$this->calls; }
}
$db=new RetirementDatabase();
$_SERVER=['REQUEST_METHOD'=>'POST','HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('a',64)];
stream_wrapper_unregister('php');
retirementAssert(stream_wrapper_register('php',RetirementInput::class),'recording request body registered');
try {
    $kernel=new \WorkerAdmin\Http\Api\ApiKernel($db,null,new RetirementContentGate());
    $routes=['orders.create'=>'DEMO_CREATE_BLOCKED','orders.finalize'=>'DEMO_CREATE_BLOCKED',
        'orders.nearby-workers.notify'=>'DEMO_EXTERNAL_ACTION_BLOCKED','merchant.submit'=>'DEMO_EXTERNAL_ACTION_BLOCKED',
        'finance.realname.submit'=>'DEMO_EXTERNAL_ACTION_BLOCKED'];
    foreach ($routes as $route=>$code) {
        $db->log=[];
        try { $kernel->dispatch($route); throw new RuntimeException('Expected demo rejection: '.$route); }
        catch (RetirementResponse $response) {
            retirementAssert($response->status===403 && ($response->payload['code']??'')===$code,$route.' stable demo error');
        }
        foreach ($db->log as [$kind,$sql]) {
            retirementAssert($kind!=='transaction','demo never enters transaction: '.$route);
            if ($kind==='execute') retirementAssert(strpos($sql,'UPDATE login_sessions')===0,'demo has no business writes: '.$route);
        }
    }
    // Real actors proceed to the existing business validation/repository boundary.
    $db->demo=false;
    foreach ($routes as $route=>$code) {
        try { $kernel->dispatch($route); throw new RuntimeException('Expected validation boundary: '.$route); }
        catch (RetirementResponse $response) {
            retirementAssert($response->status!==403 || ($response->payload['code']??'')!==$code,'real actor not demo-blocked: '.$route);
        } catch (RetirementBackendReached $exception) {}
    }

    $GLOBALS['retirementSettings']['wechat.miniapp_appid']='';
    foreach (['merchant.submit','finance.realname.submit'] as $route) {
        try { $kernel->dispatch($route); throw new RuntimeException('Expected missing config'); }
        catch (RetirementResponse $response) {
            retirementAssert($response->status===503 && $response->payload['code']==='MINIAPP_CONFIG_MISSING','configuration error preserved for '.$route);
        }
    }
    $GLOBALS['retirementSettings']['wechat.miniapp_appid']='test-app';

} finally { stream_wrapper_restore('php'); }

$resolver=(new \WorkerAdmin\Foundation\ApplicationContainer($db))->apiActorResolver();
$db->demo=true;
foreach (['user','merchant','worker'] as $role) {
    $db->role=$role;
    $actor=$resolver->resolve(str_repeat('a',64));
    retirementAssert($actor['is_demo_actor']===true && $actor['demo_role']===$role,'configured demo role '.$role);
    retirementAssert($actor['real_user_id']===10 && !$actor['is_admin'],'proxy keeps real identity without admin business capability');
}
$db->role='unknown';
retirementAssert($resolver->resolve(str_repeat('a',64))['is_demo_actor']===false,'unregistered proxy cleared');
$db->role='user'; $db->admin=false;
retirementAssert($resolver->resolve(str_repeat('a',64))['is_demo_actor']===false,'non-admin proxy cleared');
$db->admin=true; $GLOBALS['retirementSettings']['demo.data_enabled']=false;
retirementAssert($resolver->resolve(str_repeat('a',64))['is_demo_actor']===false,'disabled proxy cleared');
$GLOBALS['retirementSettings']['demo.data_enabled']=true;

$auth=new RetirementAuth($db); $csrf=new RetirementCsrf(); $module=new RetirementAdminModule();
$admin=new \WorkerAdmin\Http\Admin\AdminKernel($db,$auth,$csrf,[$module]);
$_SERVER=['REQUEST_METHOD'=>'GET'];
foreach (['dashboard','tasks','refund-review','orders','settings','platform-orders'] as $route) {
    try { $admin->dispatch($route); throw new RuntimeException('Expected first password redirect'); }
    catch (RetirementRedirect $redirect) { retirementAssert($redirect->getMessage()===admin_url('profile'),'first password applies to '.$route); }
}
retirementAssert($module->calls===0,'no module runs before required password change');
foreach (['profile','profile.password','logout','login'] as $route) $admin->dispatch($route);
retirementAssert($module->calls===4,'password/logout/login exceptions remain usable');
$auth->forcePassword=false; $auth->loggedIn=false;
try { $admin->dispatch('tasks'); throw new RuntimeException('Expected login redirect'); }
catch (RetirementRedirect $redirect) { retirementAssert($redirect->getMessage()===admin_url('login'),'login is global'); }
$auth->loggedIn=true; $_SERVER['REQUEST_METHOD']='POST'; $csrf->valid=false;
try { $admin->dispatch('tasks.run'); throw new RuntimeException('Expected CSRF failure'); }
catch (RetirementRedirect $redirect) { retirementAssert($redirect->getMessage()===admin_url('dashboard'),'invalid CSRF has safe friendly return'); }
retirementAssert($module->calls===4,'invalid CSRF never enters module');
$csrf->valid=true; $admin->dispatch('tasks.run');
retirementAssert($module->calls===5 && $csrf->checks===2,'valid POST runs after global CSRF');

$db->demo=true; $db->enabled=false;
$guard=new \WorkerAdmin\Infrastructure\Persistence\DatabaseDemoOrderWriteGuard($db);
$executed=false;
try { $guard->run('DEMO-ORDER',static function () use (&$executed): array { $executed=true; return []; }); throw new RuntimeException('Expected disabled demo block'); }
catch (\WorkerAdmin\Domain\Order\OrderOperationException $error) {
    retirementAssert($error->apiCode()==='DEMO_DATA_DISABLED' && $error->getCode()===403,'fresh disabled setting blocks demo writes');
}
retirementAssert(!$executed && !$db->locked,'blocked callback never runs and lock releases');
foreach ([true,false] as $enabled) {
    $db->enabled=$enabled; $db->demo=$enabled;
    $result=$guard->run('ORDER',static function () use ($db): array {
        retirementAssert($db->locked,'lock held throughout write'); return ['committed'=>true];
    });
    retirementAssert($result===['committed'=>true] && !$db->locked,'enabled demo/disabled real write remains usable');
}
$db->busy=true;
try { $guard->run('ORDER',static function (): array { throw new RuntimeException('must not run'); }); }
catch (\WorkerAdmin\Domain\Order\OrderOperationException $error) { retirementAssert($error->apiCode()==='DEMO_SETTING_BUSY','busy lock fails closed'); }
$db->busy=false; $db->enabled=true; $db->demo=true;
$reviews=[
    static function () use ($db): void {
        (new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCancellationRepository($db))->review(
            ['channel'=>'web_admin','admin_user_id'=>1],'DEMO-ORDER','reject','no','unclear','测试审核',0
        );
    },
    static function () use ($db): void {
        (new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderDisputeRepository($db))->resolveByAdmin(
            ['channel'=>'web_admin','admin_user_id'=>1],'DEMO-ORDER','测试裁定','unclear'
        );
    },
];
foreach ($reviews as $review) {
    try { $review(); throw new RuntimeException('Expected recording transaction boundary'); }
    catch (RetirementBackendReached $error) {}
    retirementAssert(!$db->locked,'review rollback/exception releases demo lock');
    $db->enabled=false;
    try { $review(); throw new RuntimeException('Expected disabled review block'); }
    catch (\WorkerAdmin\Domain\Order\OrderOperationException $error) { retirementAssert($error->apiCode()==='DEMO_DATA_DISABLED','review blocks fresh disabled setting'); }
    retirementAssert(!$db->locked,'disabled review lock released');
    $db->enabled=true;
}
echo "Controller retirement guards: 5 API demo boundaries, unified actors, global admin password/CSRF, and both guarded review transactions OK.\n";
