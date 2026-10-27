<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Identity;

use WorkerAdmin\Contracts\MerchantProfileRepository;
use WorkerAdmin\Contracts\SettingsProvider;

final class MerchantProfileReader
{
    private MerchantProfileRepository $repository;
    private SettingsProvider $settings;

    public function __construct(MerchantProfileRepository $repository, SettingsProvider $settings)
    {
        $this->repository=$repository;$this->settings=$settings;
    }

    /** @return array<string,mixed> */
    public function read(array $actor): array
    {
        $rateBp=max(0,min(10000,(int)$this->settings->get('commission.merchant_verified_rate_bp','800')));
        $profile=$this->repository->findForUser((int)($actor['user_id']??0));
        if(!$profile){
            return ['status'=>'none','recognition_level'=>'consumer','profile'=>null,'license'=>null,'license_ocr_verified'=>false,'service_fee_percent'=>number_format($rateBp/100,2,'.','')];
        }
        $license=null;
        if(!empty($profile['license_media_public_id'])){
            $license=[
                'id'=>(string)$profile['license_media_public_id'],
                'url'=>$this->repository->signedLicenseUrl((string)$profile['license_media_public_id'],$actor,(int)$profile['id']),
            ];
        }

        // OCR 先成功写入 merchant_profiles，随后才上传并绑定这张营业执照。
        // 只有 OCR 与当前执照媒体创建时间足够接近，才把“已识别”状态恢复给前端；
        // 这样刷新/驳回改文字无需重复识别，同时旧 OCR 不能给后来直接替换的执照兜底。
        $ocrVerified=false;
        $ocrAt=!empty($profile['ocr_updated_at'])?strtotime((string)$profile['ocr_updated_at'].' UTC'):false;
        $licenseAt=!empty($profile['license_media_created_at'])?strtotime((string)$profile['license_media_created_at'].' UTC'):false;
        if($license && !empty($profile['ocr_payload_ciphertext']) && $ocrAt!==false && $licenseAt!==false){
            $gap=$licenseAt-$ocrAt;
            $ocrVerified=$gap>=0 && $gap<=1800;
        }

        return [
            'status'=>(string)$profile['verification_status'],
            'recognition_level'=>(string)$profile['recognition_level'],
            'review_reason'=>(string)($profile['review_reason']??''),
            'profile'=>['business_name'=>(string)$profile['business_name'],'business_address'=>(string)$profile['business_address']],
            'license'=>$license,
            'license_ocr_verified'=>$ocrVerified,
            'order_count_7d'=>(int)$profile['order_count_7d'],
            'same_phone_warning_count'=>(int)$profile['same_phone_warning_count'],
            'service_fee_percent'=>number_format($rateBp/100,2,'.',''),
        ];
    }
}
