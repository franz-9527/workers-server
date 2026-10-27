<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use WorkerAdmin\Application\Identity\ApiActorResolver;
use WorkerAdmin\Application\Media\MediaOperations;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Media\MediaOperationException;
use WorkerAdmin\Foundation\MediaComposition;
use WorkerAdmin\Http\Api\ApiModule;

/** 认证媒体写接口统一 HTTP 边界。 */
final class MediaApiModule implements ApiModule
{
    private ApiActorResolver $actors;
    private MediaOperations $operations;

    /** @var array<string,string> */
    private const ROUTES=[
        'media.upload'=>'POST','media.delete'=>'POST',
        'orders.measurement.media.upload'=>'POST','orders.measurement.media.delete'=>'POST',
        'reviews.media.upload'=>'POST','finance.realname.upload'=>'POST',
        'finance.collection-code.upload'=>'POST','merchant.license.upload'=>'POST',
    ];

    /** 与旧 demo side-effect block 完全一致；删除 demo 自有媒体仍允许。 */
    private const DEMO_BLOCKED=[
        'media.upload','orders.measurement.media.upload','reviews.media.upload',
        'finance.realname.upload','finance.collection-code.upload','merchant.license.upload',
    ];

    public function __construct(Database $db)
    {
        $services=MediaComposition::build($db);$this->actors=$services['actors'];$this->operations=$services['operations'];
    }

    public function supports(string $route): bool { return isset(self::ROUTES[$route]); }
    public function requiresContentSecurity(string $route): bool { return true; }

    public function dispatch(string $route): void
    {
        if(!$this->supports($route))throw new RuntimeException('接口不存在');
        if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!==self::ROUTES[$route])$this->fail('请求方式不正确','METHOD_NOT_ALLOWED',405);
        $actor=$this->resolveActor($this->bearerToken());
        if(!empty($actor['is_demo_actor'])&&in_array($route,self::DEMO_BLOCKED,true))$this->fail('演示身份禁止真实支付、提现、实名、云资料或文件上传操作','DEMO_EXTERNAL_ACTION_BLOCKED',403);
        try{
            if($route==='media.upload'){
                $file=$this->uploadedFile();
                $result=$this->operations->uploadOrder($actor,[
                    'order_id'=>(string)($_POST['order_id']??''),'purpose'=>(string)($_POST['purpose']??''),
                    'watermarked'=>(int)($_POST['watermarked']??0)===1,
                    'captured_at'=>(string)($_POST['captured_at']??''),'captured_lat'=>$_POST['captured_lat']??null,
                    'captured_lng'=>$_POST['captured_lng']??null,'location_text'=>(string)($_POST['location_text']??''),
                ],$file);
                $this->respondUpload($result,$actor);return;
            }
            if($route==='media.delete'){
                $result=$this->operations->deleteOrder($actor,$this->jsonBody());
                \json_response(['ok'=>true,'message'=>$result['message']]);return;
            }
            if($route==='orders.measurement.media.upload'){
                $file=$this->uploadedFile();
                $result=$this->operations->uploadMeasurement($actor,['order_id'=>(string)($_POST['order_id']??''),'space_id'=>(string)($_POST['space_id']??''),'purpose'=>(string)($_POST['purpose']??'measurement_photo')],$file);
                $this->respondUpload($result,$actor);return;
            }
            if($route==='orders.measurement.media.delete'){
                $result=$this->operations->deleteMeasurement($actor,$this->jsonBody());
                \json_response(['ok'=>true,'message'=>$result['message']]);return;
            }
            if($route==='reviews.media.upload'){
                $file=$this->uploadedFile();$result=$this->operations->uploadReview($actor,['review_id'=>(string)($_POST['review_id']??'')],$file);$this->respondUpload($result,$actor);return;
            }
            if($route==='finance.realname.upload'){
                $file=$this->uploadedFile();$result=$this->operations->uploadRealName($actor,['purpose'=>(string)($_POST['purpose']??''),'watermark_applied'=>(string)($_POST['watermark_applied']??'')==='1'],$file);$this->respondUpload($result,$actor);return;
            }
            if($route==='finance.collection-code.upload'){
                $file=$this->uploadedFile();$result=$this->operations->uploadCollectionCode($actor,$file);$this->respondUpload($result,$actor);return;
            }
            if($route==='merchant.license.upload'){
                $file=$this->uploadedFile();$result=$this->operations->uploadMerchantLicense($actor,$file);$this->respondUpload($result,$actor);return;
            }
            throw new RuntimeException('接口不存在');
        }catch(MediaOperationException $e){$this->fail($e->getMessage(),$e->apiCode(),(int)$e->getCode());}
    }

    private function respondUpload(array $result,array $actor): void
    {
        $context=$result['url_context'];$data=$result['data'];
        $data['url']=$this->signedMediaUrl((string)$data['id'],$actor,(string)$context['entity_type'],(int)$context['entity_id'],(int)$context['order_id']);
        \json_response(['ok'=>true,'message'=>$result['message'],'data'=>$data]);
    }

    private function signedMediaUrl(string $mediaId,array $actor,string $entityType,int $entityId,int $orderId): string
    {
        $userId=(int)($actor['user_id']??0);$workerId=(int)($actor['worker_id']??0);$isAdmin=!empty($actor['is_admin']);
        $actorType=$isAdmin?'admin':($workerId>0?'worker':'user');$actorId=$isAdmin?$userId:($workerId>0?$workerId:$userId);
        $payload=['v'=>2,'scope'=>'media','media_id'=>$mediaId,'actor_type'=>$actorType,'actor_id'=>$actorId,'user_id'=>$userId,'entity_type'=>$entityType,'entity_id'=>$entityId,'order_id'=>$orderId,'expires'=>time()+600,'nonce'=>bin2hex(random_bytes(8))];
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$encoded=rtrim(strtr(base64_encode(is_string($json)?$json:''),'+/','-_'),'=');
        $token=$encoded.'.'.hash_hmac('sha256',$encoded,(string)\app_config('app.key',''));
        return \app_url('api/?'.http_build_query(['route'=>'media.order','id'=>$mediaId,'access_token'=>$token]));
    }

    private function uploadedFile(): string
    {
        if(empty($_FILES['file'])||(int)($_FILES['file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($_FILES['file']['tmp_name']??'')))$this->fail('图片上传失败，请重试','UPLOAD_FAILED',422);
        if((int)($_FILES['file']['size']??0)>10*1024*1024)$this->fail('单张图片不能超过 10MB','FILE_TOO_LARGE',422);
        return (string)$_FILES['file']['tmp_name'];
    }

    private function resolveActor(string $token): array
    {
        try{return $this->actors->resolve($token);}catch(RuntimeException $e){$status=(int)$e->getCode();if(!in_array($status,[401,403,503],true))$status=401;$this->fail($e->getMessage(),$status===503?'MINIAPP_APPID_MISSING':'UNAUTHORIZED',$status);}return [];
    }

    private function bearerToken(): string
    {
        $header=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));if($header===''&&function_exists('getallheaders')){$headers=getallheaders();$header=trim((string)($headers['Authorization']??$headers['authorization']??''));}
        if(preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/',$header,$matches)!==1)$this->fail('请重新登录','UNAUTHORIZED',401);return $matches[1];
    }

    private function jsonBody(): array
    {
        $raw=(string)file_get_contents('php://input');$body=json_decode($raw,true);if(!is_array($body))$this->fail('请求数据格式不正确','INVALID_JSON',422);return $body;
    }

    private function fail(string $message,string $code,int $status): void { \json_response(['ok'=>false,'code'=>$code,'message'=>$message,'request_id'=>\request_id()],$status); }
}
