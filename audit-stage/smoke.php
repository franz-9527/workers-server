<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts {
    interface AdminOrderProxyActionRepository { public function acceptAssignment(array $operator,string $orderNo,string $note):array; }
    interface MerchantProfileRepository { public function findForUser(int $userId):?array; public function signedLicenseUrl(string $mediaPublicId,array $actor,int $merchantProfileId):string; }
    interface SettingsProvider { public function get(string $key,$default=null); public function bool(string $key,bool $default=false):bool; public function int(string $key,int $default=0):int; }
}
namespace WorkerAdmin\Domain\Order {
    final class OrderOperationException extends \RuntimeException { private string $codeValue; public function __construct(string $message,string $codeValue,int $status=422){parent::__construct($message,$status);$this->codeValue=$codeValue;} public function apiCode():string{return $this->codeValue;} }
}
namespace {
    require __DIR__.'/AdminOrderProxyAction.php';
    require __DIR__.'/MerchantProfileReader.php';
    use WorkerAdmin\Application\Admin\AdminOrderProxyAction;
    use WorkerAdmin\Application\Identity\MerchantProfileReader;
    use WorkerAdmin\Contracts\AdminOrderProxyActionRepository;
    use WorkerAdmin\Contracts\MerchantProfileRepository;
    use WorkerAdmin\Contracts\SettingsProvider;
    use WorkerAdmin\Domain\Order\OrderOperationException;

    $fail=function(string $m){fwrite(STDERR,"FAIL: $m\n");exit(1);};
    $repo=new class implements AdminOrderProxyActionRepository { public int $calls=0; public function acceptAssignment(array $operator,string $orderNo,string $note):array{$this->calls++;return [];} };
    $app=new AdminOrderProxyAction($repo);
    foreach ([['assignment_accept','ADMIN_ASSIGNMENT_PROXY_REMOVED'],['repair_complete','ADMIN_REPAIR_PROXY_REMOVED']] as [$action,$code]) {
        try {$app->execute(['channel'=>'mini_admin','user_id'=>1],['order_id'=>'OD-1','action'=>$action]);$fail("$action not blocked");}
        catch(OrderOperationException $e){if($e->apiCode()!==$code)$fail("$action wrong code");}
    }
    if($repo->calls!==0)$fail('blocked proxy touched repository');

    $merchantRepo=new class implements MerchantProfileRepository {
        public array $row=[];
        public function findForUser(int $userId):?array{return $this->row;}
        public function signedLicenseUrl(string $mediaPublicId,array $actor,int $merchantProfileId):string{return 'signed';}
    };
    $settings=new class implements SettingsProvider { public function get(string $key,$default=null){return $default;} public function bool(string $key,bool $default=false):bool{return $default;} public function int(string $key,int $default=0):int{return $default;} };
    $base=['id'=>1,'verification_status'=>'rejected','recognition_level'=>'verified','review_reason'=>'','business_name'=>'A','business_address'=>'B','license_media_public_id'=>'M1','order_count_7d'=>0,'same_phone_warning_count'=>0,'ocr_payload_ciphertext'=>'x'];
    $merchantRepo->row=$base+['ocr_updated_at'=>'2026-10-16 00:00:00','license_media_created_at'=>'2026-10-16 00:05:00'];
    if(!(new MerchantProfileReader($merchantRepo,$settings))->read(['user_id'=>1])['license_ocr_verified'])$fail('current OCR not restored');
    $merchantRepo->row=$base+['ocr_updated_at'=>'2026-10-15 20:00:00','license_media_created_at'=>'2026-10-16 00:05:00'];
    if((new MerchantProfileReader($merchantRepo,$settings))->read(['user_id'=>1])['license_ocr_verified'])$fail('stale OCR incorrectly restored');
    echo "role audit staged smoke: OK\n";
}
