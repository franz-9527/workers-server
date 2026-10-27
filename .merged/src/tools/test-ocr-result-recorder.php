<?php

declare(strict_types=1);

$base = dirname(__DIR__) . '/server/app';
spl_autoload_register(static function (string $class) use ($base): void {
    $prefix = 'WorkerAdmin\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = $base . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require_once $file;
});

use WorkerAdmin\Application\Identity\OcrResultRecorder;
use WorkerAdmin\Contracts\OcrResultRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\TransactionManager;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};

final class OcrTestRepository implements OcrResultRepository
{
    public array $actor=['session_id'=>1,'user_id'=>8,'worker_id'=>12];
    public array $usage=[]; public ?array $realName=null; public ?array $merchant=null; public array $merchantFields=[]; public int $usageWrites=0;
    public function findActorByTokenHash(string $tokenHash): ?array{return $this->actor;}
    public function touchSession(int $sessionId): void{}
    public function findUsage(string $clientMsgId): ?array{return $this->usage[$clientMsgId]??null;}
    public function countSuccessfulUsage(string $quotaDay): int{return count($this->usage);}
    public function findRealNameForUpdate(int $workerId): ?array{return $this->realName;}
    public function createRealNameDraft(int $workerId): array{return $this->realName=['id'=>21,'status'=>'draft','ocr_payload_ciphertext'=>null];}
    public function updateRealNameOcr(int $verificationId,string $ciphertext): void{$this->realName['ocr_payload_ciphertext']=$ciphertext;}
    public function findMerchantForUpdate(int $userId): ?array{return $this->merchant;}
    public function createMerchantDraft(int $userId): array{return $this->merchant=['id'=>31,'user_id'=>$userId,'verification_status'=>'draft','recognition_level'=>'consumer','business_name'=>'','business_address'=>'','legal_representative'=>'','business_scope'=>'','credit_code_masked'=>'','credit_code_ciphertext'=>null,'credit_code_hmac'=>null];}
    public function updateMerchantOcr(int $merchantId,array $fields): void{$this->merchantFields=$fields;foreach(['name'=>'business_name','address'=>'business_address','legal'=>'legal_representative','scope'=>'business_scope','credit_masked'=>'credit_code_masked','credit_cipher'=>'credit_code_ciphertext','credit_hmac'=>'credit_code_hmac','payload'=>'ocr_payload_ciphertext']as$s=>$t)$this->merchant[$t]=$fields[$s]??null;}
    public function appendUsage(array $entry): bool{if(isset($this->usage[$entry['client_msg_id']]))return false;$this->usage[$entry['client_msg_id']]=['user_id'=>$entry['user_id'],'scene'=>$entry['scene'],'ocr_type'=>$entry['ocr_type'],'result_digest'=>$entry['result_digest']];$this->usageWrites++;return true;}
}
final class OcrTestSettings implements SettingsProvider{public function get(string $key,$default=null){return $key==='wechat.ocr.daily_quota'?100:$default;}public function bool(string $key,bool $default=false):bool{return $default;}public function int(string $key,int $default=0):int{return $key==='wechat.ocr.daily_quota'?100:$default;}}
final class OcrTestTransaction implements TransactionManager{public function run(callable $callback){return $callback();}}
final class OcrTestCipher implements PrivateValueCipher{public function hmac(string $plainText,string $context):string{return hash('sha256',$context.'|'.$plainText);}public function encrypt(string $plainText,string $context):?string{return $plainText===''?null:$context.':'.base64_encode($plainText);}public function decrypt(string $cipherText,string $context):string{$prefix=$context.':';if(strpos($cipherText,$prefix)!==0)throw new RuntimeException('cipher context mismatch');$plain=base64_decode(substr($cipherText,strlen($prefix)),true);if(!is_string($plain))throw new RuntimeException('cipher decode failed');return $plain;}}

$repository=new OcrTestRepository();$cipher=new OcrTestCipher();$recorder=new OcrResultRecorder($repository,new OcrTestSettings(),new OcrTestTransaction(),$cipher);$token=str_repeat('a',64);
$merchantBody=['scene'=>'merchant_license','client_msg_id'=>'ocr-merchant-1','ocr_type'=>7,'normalized'=>['reg_num'=>'TESTREG001','legal_representative'=>'测试法人','enterprise_name'=>'测试商家','address'=>'测试地址','type_of_enterprise'=>'有限责任公司','business_scope'=>'测试范围','registered_capital'=>'100','registered_date'=>'2020-01-01','registrationAuthority'=>'不应保存'],'raw'=>['biz_license_res'=>['provider_extra'=>['text'=>'原始回包保留']]]];
$result=$recorder->record($token,$merchantBody);$assert($result['quota']['used']===1,'quota should count once');$assert($repository->merchantFields['legal']==='测试法人','documented legal representative should persist');$payload=json_decode($cipher->decrypt($repository->merchantFields['payload'],'merchant-license-ocr'),true);$normalized=$payload['license']['normalized']??[];$assert(($normalized['type_of_enterprise']??'')==='有限责任公司','documented field should persist');$assert(!array_key_exists('registrationAuthority',$normalized),'custom business field must be removed');$assert(($payload['license']['raw']['biz_license_res']['provider_extra']['text']??'')==='原始回包保留','raw response should persist');
$recorder->record($token,$merchantBody);$assert($repository->usageWrites===1,'replay must be idempotent');
$second=['scene'=>'merchant_license','client_msg_id'=>'ocr-merchant-2','ocr_type'=>7,'normalized'=>['enterprise_name'=>'新商家','address'=>'新地址'],'raw'=>[]];$recorder->record($token,$second);$assert($repository->merchantFields['legal']===''&&$repository->merchantFields['scope']===''&&$repository->merchantFields['credit_cipher']===null,'new license must clear old OCR-only values');
$idValue=str_repeat('1',17).'X';
$front=['scene'=>'realname_front','client_msg_id'=>'ocr-id-front','ocr_type'=>1,'normalized'=>['type'=>0,'name'=>'测试用户','gender'=>'男','nationality'=>'汉','birth'=>'1990-01-01','address'=>'测试地址','id'=>$idValue,'authority'=>'不应保存'],'raw'=>['idcard_res'=>['provider_extra'=>'raw']]];
$back=['scene'=>'realname_back','client_msg_id'=>'ocr-id-back','ocr_type'=>1,'normalized'=>['valid_date'=>'2020-2040','authority'=>'不应保存'],'raw'=>[]];
$recorder->record($token,$front);$recorder->record($token,$back);$real=json_decode($cipher->decrypt((string)$repository->realName['ocr_payload_ciphertext'],'worker-realname-ocr'),true);$assert(isset($real['front'],$real['back']),'ID front/back should merge');$assert(!array_key_exists('authority',$real['front']['normalized']??[])&&!array_key_exists('authority',$real['back']['normalized']??[]),'undocumented authority must not persist');$assert(($real['back']['normalized']['valid_date']??'')==='2020-2040','valid_date should persist');
echo "OCR documented-field regression passed.\n";
