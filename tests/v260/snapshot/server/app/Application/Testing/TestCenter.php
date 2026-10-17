<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Testing;

use Throwable;
use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Domain\Testing\TestCenterException;

final class TestCenter
{
    private TestCenterRepository $repository;
    private TestScenarioCatalog $catalog;

    public function __construct(TestCenterRepository $repository, ?TestScenarioCatalog $catalog = null)
    {
        $this->repository = $repository;
        $this->catalog = $catalog ?: new TestScenarioCatalog();
    }
    public function dashboard(array $filters = []): array
    {
        return ['diagnostics'=>$this->repository->environmentDiagnostics(),'scenarios'=>$this->catalog->all(),'instances'=>$this->repository->listInstances($filters)];
    }
    public function detail(string $publicId): array
    {
        $publicId = trim($publicId);if ($publicId === '') throw new TestCenterException('测试实例不存在。', 'TEST_INSTANCE_REQUIRED', 422);return $this->repository->instanceDetail($publicId);
    }
    public function create(array $input, int $adminId): array
    {
        $key = trim((string)($input['scenario_key'] ?? ''));$scenario = $this->catalog->find($key);
        if (!$scenario) throw new TestCenterException('测试场景不存在或已经下线。', 'TEST_SCENARIO_NOT_FOUND', 404);
        if (empty($scenario['buildable'])) throw new TestCenterException((string)($scenario['blocked_reason'] ?? '该场景当前不可生成。'), 'TEST_SCENARIO_NOT_BUILDABLE', 409);
        $requestKey = trim((string)($input['request_key'] ?? ''));
        if ($requestKey === '' || strlen($requestKey) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/D', $requestKey) !== 1) throw new TestCenterException('场景创建请求键无效，请刷新页面后重试。', 'TEST_REQUEST_KEY_INVALID', 422);
        return $this->repository->build($scenario, $this->normalizeParams($scenario, $input), $adminId, $requestKey);
    }
    public function verify(string $publicId, int $adminId): array{return $this->repository->verify(trim($publicId), $adminId);}
    public function copy(string $publicId, int $adminId, string $requestKey): array
    {
        $source=$this->repository->rebuildSource(trim($publicId));$scenario=$this->catalog->find((string)$source['scenario_key']);
        if(!$scenario||empty($scenario['buildable']))throw new TestCenterException('原场景当前版本已不可生成。','TEST_SCENARIO_NOT_BUILDABLE',409);
        return $this->repository->build($scenario,(array)$source['params'],$adminId,$this->requestKey($requestKey),(int)$source['id'],null);
    }
    public function rebuild(string $publicId, int $adminId, string $requestKey): array
    {
        $source=$this->repository->rebuildSource(trim($publicId));$scenario=$this->catalog->find((string)$source['scenario_key']);
        if(!$scenario||empty($scenario['buildable']))throw new TestCenterException('原场景当前版本已不可重建。','TEST_SCENARIO_NOT_BUILDABLE',409);
        return $this->repository->build($scenario,(array)$source['params'],$adminId,$this->requestKey($requestKey),null,(int)$source['id']);
    }
    public function cleanupPreview(string $publicId): array{return $this->repository->cleanupPreview(trim($publicId));}
    public function cleanup(string $publicId,int $adminId,string $planDigest): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$planDigest))throw new TestCenterException('清理预览已失效，请重新预览。','TEST_CLEANUP_PREVIEW_REQUIRED',409);
        return $this->repository->cleanup(trim($publicId),$adminId,$planDigest);
    }
    public function batchCleanupPreview(string $batchPublicId): array
    {
        $batchPublicId=trim($batchPublicId);if($batchPublicId==='')throw new TestCenterException('测试批次不存在。','TEST_BATCH_REQUIRED',422);
        $instances=[];foreach($this->repository->listInstances([]) as $row){if((string)($row['batch_public_id']??'')===$batchPublicId)$instances[]=$row;}
        if(!$instances)throw new TestCenterException('测试批次不存在，或不在当前测试中心可见范围。','TEST_BATCH_NOT_FOUND',404);
        $items=[];$counts=[];$blocked=0;$active=0;
        foreach($instances as $row){
            $publicId=(string)($row['public_id']??'');
            if($publicId===''||empty($row['order_id'])){$items[]=['public_id'=>$publicId,'order_no'=>(string)($row['order_no']??''),'status'=>'already_cleaned','allowed'=>true,'plan_digest'=>'','counts'=>[],'reason'=>'实例业务数据已清理；保留 tombstone。'];continue;}
            $active++;$preview=$this->repository->cleanupPreview($publicId);$allowed=!empty($preview['allowed']);if(!$allowed)$blocked++;
            foreach((array)($preview['counts']??[]) as $table=>$count)$counts[$table]=($counts[$table]??0)+(int)$count;
            $items[]=['public_id'=>$publicId,'order_no'=>(string)($row['order_no']??''),'status'=>$allowed?'ready':'blocked','allowed'=>$allowed,'plan_digest'=>(string)($preview['plan_digest']??''),'counts'=>(array)($preview['counts']??[]),'reason'=>(string)($preview['reason']??''),'blocking_relations'=>(array)($preview['blocking_relations']??[])];
        }
        $digestSource=[];foreach($items as $item){if(($item['status']??'')==='already_cleaned')continue;$digestSource[]=[(string)$item['public_id'],(string)$item['plan_digest']];}
        $digest=hash('sha256',(string)json_encode(['batch'=>$batchPublicId,'items'=>$digestSource],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return ['batch_public_id'=>$batchPublicId,'allowed'=>$active>0&&$blocked===0,'active_instances'=>$active,'blocked_instances'=>$blocked,'total_instances'=>count($instances),'counts'=>$counts,'items'=>$items,'plan_digest'=>$digest,'reason'=>$active===0?'本批次业务数据已经全部清理。':($blocked>0?'批次中存在不可证明安全归属的实例；先处理阻断项。':'批次内全部活动实例均通过单实例所有权清理预检。')];
    }
    public function cleanupBatch(string $batchPublicId,int $adminId,string $planDigest): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$planDigest))throw new TestCenterException('批次清理预览已失效，请重新预览。','TEST_BATCH_CLEANUP_PREVIEW_REQUIRED',409);
        $preview=$this->batchCleanupPreview($batchPublicId);if(empty($preview['allowed']))throw new TestCenterException((string)$preview['reason'],'TEST_BATCH_CLEANUP_BLOCKED',409,$preview);
        if(!hash_equals((string)$preview['plan_digest'],$planDigest))throw new TestCenterException('批次内容在预览后发生变化，请重新预览。','TEST_BATCH_CLEANUP_PREVIEW_STALE',409,$preview);
        $results=[];$failed=0;$succeeded=0;
        foreach((array)$preview['items'] as $item){
            if(($item['status']??'')==='already_cleaned'){$results[]=['public_id'=>$item['public_id'],'status'=>'already_cleaned','message'=>'已清理'];continue;}
            try{$result=$this->repository->cleanup((string)$item['public_id'],$adminId,(string)$item['plan_digest']);$succeeded++;$results[]=['public_id'=>$item['public_id'],'order_no'=>$item['order_no'],'status'=>'cleaned','message'=>'已清理','result'=>$result];}
            catch(Throwable $exception){$failed++;$results[]=['public_id'=>$item['public_id'],'order_no'=>$item['order_no'],'status'=>'failed','message'=>$exception->getMessage()];}
        }
        return ['batch_public_id'=>$batchPublicId,'succeeded'=>$succeeded,'failed'=>$failed,'results'=>$results,'complete'=>$failed===0];
    }
    private function normalizeParams(array $scenario,array $input): array
    {
        $params=is_array($scenario['defaults']??null)?$scenario['defaults']:[];$editable=array_fill_keys(is_array($scenario['editable']??null)?$scenario['editable']:[],true);
        foreach($editable as $key=>$_){if(!array_key_exists($key,$input)||is_array($input[$key]))continue;$value=trim((string)$input[$key]);if(in_array($key,['lat','lng'],true)){if(!is_numeric($value))throw new TestCenterException('测试坐标格式不正确。','TEST_COORDINATE_INVALID',422);$params[$key]=(float)$value;}elseif($key==='customer_total_cents'){$amount=(int)$value;if($amount<100||$amount>100000000)throw new TestCenterException('测试客户总价必须在 1 元到 100 万元之间。','TEST_AMOUNT_INVALID',422);$params[$key]=$amount;}else{$params[$key]=mb_substr($value,0,$key==='description'?1000:255);}}
        $params['scenario_key']=(string)$scenario['key'];$params['scenario_version']=(int)$scenario['version'];return$params;
    }
    private function requestKey(string $requestKey): string
    {
        $requestKey=trim($requestKey);if($requestKey===''||strlen($requestKey)>80||preg_match('/^[A-Za-z0-9._:-]+$/D',$requestKey)!==1)throw new TestCenterException('请求键无效，请刷新后重试。','TEST_REQUEST_KEY_INVALID',422);return$requestKey;
    }
}
