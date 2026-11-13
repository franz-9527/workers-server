<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use WorkerAdmin\Application\Agreements\AgreementAcknowledgementRecorder;
use WorkerAdmin\Application\Agreements\PlatformAgreements;
use WorkerAdmin\Application\Identity\ApiActorResolver;
use WorkerAdmin\Application\Identity\IdentityAccessOperations;
use WorkerAdmin\Application\Identity\WorkerDirectoryOperations;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Identity\IdentityOperationException;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Foundation\IdentityWorkerComposition;
use WorkerAdmin\Http\Api\ApiModule;

/** Identity / Worker 前端统一 HTTP 边界。 */
final class IdentityWorkerApiModule implements ApiModule
{
    private ApiActorResolver $actors;
    private IdentityAccessOperations $identity;
    private WorkerDirectoryOperations $workers;
    private PlatformAgreements $agreements;
    private AgreementAcknowledgementRecorder $agreementAcknowledgements;

    /** @var array<string,string> */
    private const ROUTES=[
        'auth.wechat'=>'POST',
        'auth.phone'=>'POST',
        'membership.summary'=>'GET',
        'workers.map'=>'GET',
        'workers.detail'=>'GET',
        'workers.phone-reveal.policy'=>'GET',
        'workers.phone-reveal.authorize'=>'POST',
        'workers.phone-reveal'=>'POST',
        'workers.search'=>'GET',
        'worker.profile'=>'GET',
        'worker.profile.save'=>'POST',
    ];

    /** @var array<int,string> */
    private const WORKER_ONBOARDING_AGREEMENT_KEYS=[
        'worker_cooperation_agreement',
        'worker_safety_agreement',
        'worker_safety_commitment',
    ];

    public function __construct(Database $db)
    {
        $services=IdentityWorkerComposition::build($db);
        $this->actors=$services['actors'];
        $this->identity=$services['identity'];
        $this->workers=$services['workers'];
        $this->agreementAcknowledgements=$services['agreement_acknowledgements'];
        $this->agreements=(new ApplicationContainer($db))->agreements();
    }

    public function supports(string $route): bool { return isset(self::ROUTES[$route]); }
    public function requiresContentSecurity(string $route): bool { return true; }

    public function dispatch(string $route): void
    {
        if(!$this->supports($route))throw new RuntimeException('接口不存在');
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if($method!==self::ROUTES[$route])$this->fail('请求方式不正确','METHOD_NOT_ALLOWED',405);
        try{
            if($route==='auth.wechat'){
                $body=$this->jsonBody();
                $data=$this->identity->login((string)($body['code']??''),\request_ip(),(string)($_SERVER['HTTP_USER_AGENT']??'wechat-mini-program'));
                \json_response(['ok'=>true,'data'=>$data]);return;
            }
            $actor=$this->resolveActor($this->bearerToken());
            if(!empty($actor['is_demo_actor'])&&in_array($route,['auth.phone','worker.profile.save'],true)){
                $this->fail('演示身份禁止真实支付、提现、实名、云资料或文件上传操作','DEMO_EXTERNAL_ACTION_BLOCKED',403);
            }
            switch($route){
                case 'auth.phone':
                    $result=$this->identity->authorizePhone($actor,(string)($this->jsonBody()['code']??''));
                    \json_response(['ok'=>true,'message'=>$result['message'],'data'=>$result['data']]);return;
                case 'membership.summary':
                    \json_response(['ok'=>true,'data'=>$this->identity->membershipSummary($actor)]);return;
                case 'workers.map':
                    \json_response(['ok'=>true,'data'=>$this->workers->map()]);return;
                case 'workers.detail':
                    \json_response(['ok'=>true,'data'=>$this->workers->detail($actor,$_GET)]);return;
                case 'workers.search':
                    \json_response(['ok'=>true,'data'=>$this->workers->search($_GET)]);return;
                case 'worker.profile':
                    \json_response(['ok'=>true,'data'=>$this->workers->profile($actor)]);return;
                case 'worker.profile.save':
                    $body=$this->jsonBody();
                    $agreementVersions=$this->requireWorkerOnboardingAgreements($body);
                    $result=$this->workers->saveProfile($actor,$body);
                    $this->recordWorkerOnboardingAgreementAudit($actor,$agreementVersions,$result['data']??[]);
                    \json_response(['ok'=>true,'message'=>$result['message'],'data'=>$result['data']]);return;
                case 'workers.phone-reveal.policy':
                    $policy=$this->workers->phonePolicy($actor,(string)($_GET['cloud_worker_id']??''));unset($policy['_membership_card_id']);$this->noStore();
                    \json_response(['ok'=>true,'data'=>$policy]);return;
                case 'workers.phone-reveal.authorize':
                    $body=$this->jsonBody();$data=$this->workers->authorizePhoneReveal($actor,(string)($body['cloud_worker_id']??''));$this->noStore();
                    \json_response(['ok'=>true,'data'=>$data]);return;
                case 'workers.phone-reveal':
                    $data=$this->workers->revealPhone($actor,$this->jsonBody(),\request_ip());$this->noStore();
                    \json_response(['ok'=>true,'data'=>$data]);return;
            }
        }catch(IdentityOperationException $exception){
            $response=['ok'=>false,'code'=>$exception->apiCode(),'message'=>$exception->getMessage(),'request_id'=>\request_id()];
            if($exception->data())$response['data']=$exception->data();
            \json_response($response,(int)$exception->getCode());
        }
    }

    /** @param array<string,mixed> $body @return array<string,string> */
    private function requireWorkerOnboardingAgreements(array $body): array
    {
        if(empty($body['worker_agreements_accepted'])){
            $this->fail('请先阅读并同意师傅服务协议与安全协议','WORKER_AGREEMENT_REQUIRED',422);
        }
        if(empty($body['safety_commitment_accepted'])){
            $this->fail('请先阅读并确认师傅安全承诺书','WORKER_SAFETY_COMMITMENT_REQUIRED',422);
        }

        $submitted=is_array($body['agreement_versions']??null)?$body['agreement_versions']:[];
        $versions=[];
        foreach($submitted as $key=>$value){
            $key=trim((string)$key);$value=trim((string)$value);
            if($key!==''&&$value!=='')$versions[$key]=$value;
        }

        $expected=[];
        foreach($this->agreements->publicDocuments('agreement_center','worker') as $item){
            $key=trim((string)($item['key']??''));
            if(!in_array($key,self::WORKER_ONBOARDING_AGREEMENT_KEYS,true))continue;
            $expected[$key]=trim((string)($item['published_version']??$item['version']??''));
        }
        foreach(self::WORKER_ONBOARDING_AGREEMENT_KEYS as $key){
            if(!isset($expected[$key])||$expected[$key]===''){
                $this->fail('师傅入驻协议暂时无法读取，请稍后重试','WORKER_AGREEMENT_UNAVAILABLE',503);
            }
            if(!isset($versions[$key])||$versions[$key]===''){
                $this->fail('入驻协议版本缺失，请重新打开入驻页面后再提交','WORKER_AGREEMENT_VERSION_REQUIRED',409);
            }
            if(!hash_equals($expected[$key],$versions[$key])){
                $this->fail('入驻协议已经更新，请重新阅读并确认后提交','WORKER_AGREEMENT_VERSION_CHANGED',409);
            }
        }
        return array_intersect_key($versions,$expected);
    }

    /** @param array<string,mixed> $actor @param array<string,string> $versions @param mixed $resultData */
    private function recordWorkerOnboardingAgreementAudit(array $actor,array $versions,$resultData): void
    {
        try{
            $this->agreementAcknowledgements->recordWorkerOnboarding(
                $actor,
                $versions,
                $resultData,
                \request_ip(),
                (string)($_SERVER['HTTP_USER_AGENT']??'')
            );
        }catch(\Throwable $exception){
            \write_log('agreement-audit','['.date('c').'] worker_onboarding request_id='.\request_id().' '.$exception->getMessage()."\n");
        }
    }

    private function resolveActor(string $token): array
    {
        try{return $this->actors->resolve($token);}catch(RuntimeException $exception){
            $status=(int)$exception->getCode();if(!in_array($status,[401,403,503],true))$status=401;
            $this->fail($exception->getMessage(),$status===503?'MINIAPP_APPID_MISSING':'UNAUTHORIZED',$status);
        }
    }

    private function bearerToken(): string
    {
        $header=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
        if($header===''&&function_exists('getallheaders')){$headers=getallheaders();$header=trim((string)($headers['Authorization']??$headers['authorization']??''));}
        if(preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/',$header,$matches)!==1)$this->fail('请重新登录','UNAUTHORIZED',401);
        return $matches[1];
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw=(string)file_get_contents('php://input');if($raw==='')return [];$body=json_decode($raw,true);
        if(!is_array($body))$this->fail('请求数据格式不正确','INVALID_JSON',422);return $body;
    }

    private function noStore(): void
    {
        header('Cache-Control: no-store, private, max-age=0');header('Pragma: no-cache');
    }

    private function fail(string $message,string $code,int $status): void
    {
        \json_response(['ok'=>false,'code'=>$code,'message'=>$message,'request_id'=>\request_id()],$status);
    }
}
