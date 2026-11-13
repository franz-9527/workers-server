<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Media;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\ImageOptimizer;
use WorkerAdmin\Contracts\MediaRepository;
use WorkerAdmin\Contracts\ObjectStorage;
use WorkerAdmin\Contracts\TransactionManager;
use WorkerAdmin\Domain\Media\MediaOperationException;

/** Media 板块用例族：上传、删除、对象存储同步与媒体读取授权。 */
final class MediaOperations
{
    private MediaRepository $repository;
    private ImageOptimizer $optimizer;
    private ObjectStorage $storage;
    private TransactionManager $transactions;

    public function __construct(MediaRepository $repository, ImageOptimizer $optimizer, ObjectStorage $storage, TransactionManager $transactions)
    {
        $this->repository=$repository;$this->optimizer=$optimizer;$this->storage=$storage;$this->transactions=$transactions;
    }

    public function uploadOrder(array $actor,array $input,string $sourcePath): array
    {
        $purpose=(string)($input['purpose']??'');
        if(!in_array($purpose,['product','wall','before_optional','completion','arrival_evidence','collection_receipt','dispute_evidence','repair_evidence'],true))throw new MediaOperationException('图片用途不正确','INVALID_PURPOSE',422);
        $context=$this->repository->prepareOrderUpload($actor,(string)($input['order_id']??''),$purpose);
        $optimized=$this->optimize($sourcePath);$publicId=$this->repository->newPublicId();
        $metadata=[];
        if(in_array($purpose,['completion','arrival_evidence'],true))$metadata=[
            'watermarked'=>!empty($input['watermarked']),
            'captured_at'=>mb_substr((string)($input['captured_at']??''),0,32),
            'captured_lat'=>is_numeric($input['captured_lat']??null)?(float)$input['captured_lat']:null,
            'captured_lng'=>is_numeric($input['captured_lng']??null)?(float)$input['captured_lng']:null,
            'location_text'=>mb_substr((string)($input['location_text']??''),0,255),'purpose'=>$purpose,
        ];
        try{$this->transactions->run(function()use($context,$optimized,$metadata,$publicId):void{$this->repository->storeOrderMedia($context,$optimized,$metadata,$publicId);});}
        catch(Throwable $e){$this->removeLocal($optimized);throw $e;}
        $synced=false;if(empty($context['defer_qiniu']))$synced=$this->publish($publicId,$optimized,(string)$context['qiniu_type']);
        $message=!empty($context['defer_qiniu'])?'图片已无损优化并保存，订单正式提交后同步七牛云':($synced?'图片已无损优化并同步七牛云':'图片已无损优化并保存，七牛云同步暂未完成');
        return ['message'=>$message,'data'=>['id'=>$publicId],'url_context'=>['entity_type'=>'order','entity_id'=>(int)$context['order']['id'],'order_id'=>(int)$context['order']['id']]];
    }

    public function deleteOrder(array $actor,array $input): array
    {
        $mediaId=trim((string)($input['media_id']??''));if($mediaId==='')throw new MediaOperationException('请选择需要删除的图片','MEDIA_REQUIRED',422);
        $purpose=$this->transactions->run(fn():string=>$this->repository->deleteOrderMedia($actor,(string)($input['order_id']??''),$mediaId));
        if($purpose==='')throw new MediaOperationException('该图片不存在、订单已提交或证据已经锁定，不能删除','MEDIA_DELETE_NOT_ALLOWED',409);
        return ['message'=>$purpose==='order_image'?'订单图片已删除':($purpose==='collection_receipt'?'收款凭证已删除，可重新上传':'临时证据已删除')];
    }

    public function uploadMeasurement(array $actor,array $input,string $sourcePath): array
    {
        $purpose=(string)($input['purpose']??'measurement_photo');
        if(!in_array($purpose,['measurement_sheet','measurement_annotated','measurement_photo'],true))throw new MediaOperationException('测量图片用途不正确','INVALID_PURPOSE',422);
        $context=$this->repository->prepareMeasurementUpload($actor,(string)($input['order_id']??''),(string)($input['space_id']??''),$purpose);
        $optimized=$this->optimize($sourcePath);$publicId=$this->repository->newPublicId();
        try{$this->transactions->run(function()use($context,$optimized,$publicId):void{$this->repository->storeMeasurementMedia($context,$optimized,$publicId);});}
        catch(Throwable $e){$this->removeLocal($optimized);throw $e;}
        $synced=$this->publish($publicId,$optimized,'order');
        return ['message'=>$synced?'测量图片已压缩并同步七牛云':'测量图片已压缩保存，七牛云同步暂未完成','data'=>['id'=>$publicId],'url_context'=>['entity_type'=>'order_measurement_space','entity_id'=>(int)$context['space']['id'],'order_id'=>(int)$context['order']['id']]];
    }

    public function deleteMeasurement(array $actor,array $input): array
    {
        $this->transactions->run(function()use($actor,$input):void{$this->repository->deleteMeasurementMedia($actor,(string)($input['order_id']??''),(string)($input['space_id']??''),trim((string)($input['media_id']??'')));});
        return ['message'=>'测量图片已删除'];
    }

    public function uploadReview(array $actor,array $input,string $sourcePath): array
    {
        $context=$this->repository->prepareReviewUpload($actor,trim((string)($input['review_id']??'')));
        $optimized=$this->optimize($sourcePath);$publicId=$this->repository->newPublicId();
        try{$this->transactions->run(function()use($context,$optimized,$publicId):void{$this->repository->storeReviewMedia($context,$optimized,$publicId);});}catch(Throwable $e){$this->removeLocal($optimized);throw $e;}
        $this->publish($publicId,$optimized,'review');
        return ['message'=>'评价图片已无损优化上传','data'=>['id'=>$publicId],'url_context'=>['entity_type'=>'review','entity_id'=>(int)$context['review']['id'],'order_id'=>(int)$context['review']['order_id']]];
    }

    public function uploadRealName(array $actor,array $input,string $sourcePath): array
    {
        $context=$this->transactions->run(fn():array=>$this->repository->prepareRealNameUpload($actor,(string)($input['purpose']??''),!empty($input['watermark_applied'])));
        $context['ocr_client_msg_id']=trim((string)($input['ocr_client_msg_id']??''));
        $optimized=$this->optimize($sourcePath);$publicId=$this->repository->newPublicId();
        try{$this->transactions->run(function()use($context,$optimized,$publicId):void{$this->repository->storeRealNameMedia($context,$optimized,$publicId);});}catch(Throwable $e){$this->removeLocal($optimized);throw $e;}
        $this->publish($publicId,$optimized,'worker_identity');
        return ['message'=>'证件图片已无损优化上传','data'=>['id'=>$publicId],'url_context'=>['entity_type'=>'worker_verification','entity_id'=>(int)$context['entity_id'],'order_id'=>0]];
    }

    public function uploadCollectionCode(array $actor,string $sourcePath): array
    {
        $context=$this->transactions->run(fn():array=>$this->repository->prepareCollectionCodeUpload($actor));
        $optimized=$this->optimize($sourcePath);$publicId=$this->repository->newPublicId();
        try{$this->transactions->run(function()use($context,$optimized,$publicId,$actor):void{$this->repository->storeCollectionCodeMedia($context,$optimized,$publicId,$actor);});}catch(Throwable $e){$this->removeLocal($optimized);throw $e;}
        $this->publish($publicId,$optimized,'worker_collection_code');
        return ['message'=>'收款码已保存，可用于商家订单收款','data'=>['id'=>$publicId,'status'=>'auto_approved','status_text'=>'已启用'],'url_context'=>['entity_type'=>'worker_collection_code','entity_id'=>(int)$context['entity_id'],'order_id'=>0]];
    }

    public function uploadMerchantLicense(array $actor,string $sourcePath): array
    {
        $context=$this->transactions->run(fn():array=>$this->repository->prepareMerchantLicenseUpload($actor));
        $optimized=$this->optimize($sourcePath);$publicId=$this->repository->newPublicId();
        try{$this->transactions->run(function()use($context,$optimized,$publicId):void{$this->repository->storeMerchantLicenseMedia($context,$optimized,$publicId);});}catch(Throwable $e){$this->removeLocal($optimized);throw $e;}
        $this->publish($publicId,$optimized,'merchant_license');
        return ['message'=>'营业执照已无损优化上传','data'=>['id'=>$publicId],'url_context'=>['entity_type'=>'merchant_profile','entity_id'=>(int)$context['entity_id'],'order_id'=>0]];
    }

    public function privateAsset(string $publicId,array $token): array
    {
        if($publicId===''||(string)($token['media_id']??'')!==$publicId||(int)($token['expires']??0)<time())throw new MediaOperationException('图片链接已失效','MEDIA_LINK_EXPIRED',403);
        $media=$this->repository->findPrivateMedia($publicId);
        if(!$media||strpos((string)($media['storage_key']??''),'..')!==false||!$this->repository->canAccessPrivateMedia($media,$token))throw new MediaOperationException('图片不存在','MEDIA_NOT_FOUND',404);
        if(!in_array(strtolower((string)($media['mime_type']??'')),['image/jpeg','image/png','image/webp'],true))throw new MediaOperationException('图片格式不受支持','MEDIA_NOT_FOUND',404);
        return $media;
    }

    public function publicWorkerAsset(string $publicId): array
    {
        if(preg_match('/^[A-Za-z0-9_-]{12,64}$/',$publicId)!==1)throw new MediaOperationException('图片不存在','MEDIA_NOT_FOUND',404);
        $media=$this->repository->findPublicWorkerMedia($publicId);
        if(!$media||strpos((string)($media['storage_key']??''),'..')!==false)throw new MediaOperationException('图片不存在','MEDIA_NOT_FOUND',404);
        return $media;
    }

    private function optimize(string $sourcePath): array
    {
        try{return $this->optimizer->optimize($sourcePath);}catch(Throwable $e){throw new MediaOperationException($e->getMessage(),'IMAGE_COMPRESS_FAILED',422);}
    }

    private function publish(string $publicId,array $optimized,string $businessType): bool
    {
        $path=(string)$optimized['path'];if(!$this->storage->enabled()||!is_file($path))return false;
        try{
            $stored=$this->storage->store($path,(string)$optimized['storage_key'],$businessType,(string)$optimized['mime_type']);
            if(($stored['disk']??'private_local')!=='qiniu'||empty($stored['key']))return false;
            if(!$this->repository->markStoredOnQiniu($publicId,(string)$stored['key']))throw new RuntimeException('七牛云已上传，但媒体记录未能更新，已保留本站副本。');
            @unlink($path);return true;
        }catch(Throwable $e){\write_log('qiniu-upload','['.date('c').'] media '.$publicId.' '.$e->getMessage()."\n");return false;}
    }

    private function removeLocal(array $optimized): void { $path=(string)($optimized['path']??'');if($path!==''&&is_file($path))@unlink($path); }
}
