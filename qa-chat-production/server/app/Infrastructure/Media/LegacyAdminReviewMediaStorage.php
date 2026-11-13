<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Media;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\AdminReviewMediaStorage;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Media\UploadedImageInput;
use WorkerAdmin\Services\ImageOptimizerService;
use WorkerAdmin\Services\QiniuStorageService;

final class LegacyAdminReviewMediaStorage implements AdminReviewMediaStorage
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function store(UploadedImageInput $image, int $reviewId, int $sortOrder, int $adminId): int
    {
        if ($image->uploadError !== UPLOAD_ERR_OK || $image->temporaryPath === '' || !is_uploaded_file($image->temporaryPath)) throw new RuntimeException('请选择有效图片。');
        if ($image->sizeBytes < 1 || $image->sizeBytes > 10 * 1024 * 1024) throw new RuntimeException('单张图片不能超过 10MB。');
        try{$optimized=(new ImageOptimizerService())->optimize($image->temporaryPath);}catch(Throwable $exception){throw new RuntimeException($exception->getMessage(),0,$exception);}
        $path=(string)$optimized['path'];$storageKey=(string)$optimized['storage_key'];$publicId=\public_id();
        $metadata=json_encode(['compression_mode'=>'lossless-first','compression_method'=>(string)($optimized['method']??'original')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        try{
            $mediaId=$this->db->transaction(function(Database $db)use($reviewId,$sortOrder,$adminId,$optimized,$storageKey,$publicId,$metadata):int{
                $mediaId=$db->insert("INSERT INTO media_files(public_id,storage_disk,storage_key,mime_type,extension,size_bytes,width_px,height_px,sha256,compression_quality,metadata_json,status,reference_count,created_at) VALUES(:public_id,'private_local',:storage_key,:mime_type,:extension,:size,:width,:height,:sha256,:quality,:metadata,'active',1,NOW())",['public_id'=>$publicId,'storage_key'=>$storageKey,'mime_type'=>(string)$optimized['mime_type'],'extension'=>(string)$optimized['extension'],'size'=>(int)$optimized['size_bytes'],'width'=>(int)$optimized['width_px'],'height'=>(int)$optimized['height_px'],'sha256'=>(string)$optimized['sha256'],'quality'=>$optimized['compression_quality'],'metadata'=>$metadata]);
                $db->insert("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at) VALUES(:media_id,'review',:review_id,'review',:sort_order,'admin',:admin_id,NOW())",['media_id'=>$mediaId,'review_id'=>$reviewId,'sort_order'=>$sortOrder,'admin_id'=>$adminId]);
                return (int)$mediaId;
            });
        }catch(Throwable $exception){@unlink($path);throw $exception;}
        try{
            $stored=(new QiniuStorageService())->store($path,$storageKey,'review',(string)$optimized['mime_type']);
            if(($stored['disk']??'private_local')==='qiniu'){
                $updated=$this->db->execute("UPDATE media_files SET storage_disk='qiniu',storage_key=:storage_key WHERE id=:id AND status='active'",['storage_key'=>(string)$stored['key'],'id'=>$mediaId]);
                if($updated!==1)throw new RuntimeException('七牛云已上传，但后台图片记录未能更新，已保留本地副本。');
                @unlink($path);
            }
        }catch(Throwable $exception){\write_log('qiniu-upload','['.date('c').'] admin review media '.$publicId.' '.$exception->getMessage()."\n");}
        return $mediaId;
    }
}
