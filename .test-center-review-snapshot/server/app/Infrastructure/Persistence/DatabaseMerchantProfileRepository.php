<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\MerchantProfileRepository;
use WorkerAdmin\Core\Database;

final class DatabaseMerchantProfileRepository implements MerchantProfileRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db=$db;
    }

    public function findForUser(int $userId): ?array
    {
        if($userId<1)return null;
        $row=$this->db->fetch(
            "SELECT mp.*,mf.public_id license_media_public_id,mf.created_at license_media_created_at
             FROM merchant_profiles mp
             LEFT JOIN media_files mf ON mf.id=mp.license_media_file_id AND mf.status='active'
             WHERE mp.user_id=:user_id LIMIT 1",
            ['user_id'=>$userId]
        );
        return $row?:null;
    }

    public function signedLicenseUrl(string $mediaPublicId,array $actor,int $merchantProfileId): string
    {
        if($mediaPublicId===''||$merchantProfileId<1)return '';
        $userId=(int)($actor['user_id']??0);$workerId=(int)($actor['worker_id']??0);
        $isAdmin=!empty($actor['is_admin'])||!empty($actor['business_admin']);
        $actorType=$isAdmin?'admin':($workerId>0?'worker':'user');
        $actorId=$actorType==='worker'?$workerId:$userId;
        if($actorId<1||$userId<1)return '';
        $payload=[
            'v'=>2,'scope'=>'media','media_id'=>$mediaPublicId,'actor_type'=>$actorType,'actor_id'=>$actorId,'user_id'=>$userId,
            'entity_type'=>'merchant_profile','entity_id'=>$merchantProfileId,'order_id'=>0,'expires'=>time()+600,'nonce'=>bin2hex(random_bytes(8)),
        ];
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($json))return '';
        $encoded=rtrim(strtr(base64_encode($json),'+/','-_'),'=');
        $token=$encoded.'.'.hash_hmac('sha256',$encoded,(string)\app_config('app.key',''));
        return \app_url('api/?'.http_build_query(['route'=>'media.order','id'=>$mediaPublicId,'access_token'=>$token]));
    }
}
