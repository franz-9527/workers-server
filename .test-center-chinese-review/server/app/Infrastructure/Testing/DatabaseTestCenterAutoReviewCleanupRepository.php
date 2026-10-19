<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Persistence\DatabaseReviewRepository;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 只移除当前实例 system_auto_review 明确创建的系统默认五星评价。
 * 其它评价、评价图片或回复一律视为非本适配器所有并阻断。
 */
final class DatabaseTestCenterAutoReviewCleanupRepository implements TestCenterRepository
{
    private Database $db;
    private TestCenterRepository $inner;

    public function __construct(Database $db,TestCenterRepository $inner)
    {
        $this->db=$db;
        $this->inner=$inner;
    }

    public function environmentDiagnostics(): array{return$this->inner->environmentDiagnostics();}
    public function listInstances(array $filters=[]): array{return$this->inner->listInstances($filters);}
    public function instanceDetail(string $publicId): array{return$this->inner->instanceDetail($publicId);}
    public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array{return$this->inner->build($scenario,$params,$adminId,$requestKey,$sourceInstanceId,$rebuiltFromInstanceId);}
    public function verify(string $publicId,int $adminId): array{return$this->inner->verify($publicId,$adminId);}
    public function rebuildSource(string $publicId): array{return$this->inner->rebuildSource($publicId);}

    public function cleanupPreview(string $publicId): array
    {
        $base=$this->inner->cleanupPreview($publicId);
        if(empty($base['plan_digest']))return$base;

        $detail=$this->inner->instanceDetail($publicId);
        $instance=(array)($detail['instance']??[]);
        $order=is_array($detail['order']??null)?$detail['order']:null;
        if(!$order||!$this->hasOwnedRun((int)($instance['id']??0)))return$base;

        $ownership=$this->ownership($instance,$order);
        if(empty($ownership['review_id']))return$base;

        $blocking=(array)($base['blocking_relations']??[]);
        $reviewCount=(int)$ownership['review_count'];
        if(isset($blocking['reviews'])&&(int)$blocking['reviews']===$reviewCount){
            unset($blocking['reviews']);
        }
        foreach((array)$ownership['blocking'] as$key=>$count)$blocking[$key]=$count;

        $allowed=empty($blocking);
        $plan=[
            'inner_plan_digest'=>(string)$base['plan_digest'],
            'instance_id'=>(int)$instance['id'],
            'instance_version'=>(int)($instance['version']??0),
            'order_id'=>(int)$order['id'],
            'review_id'=>(int)$ownership['review_id'],
            'worker_id'=>(int)$ownership['worker_id'],
            'blocking'=>$blocking,
        ];
        $base['allowed']=$allowed;
        $base['blocking_relations']=$blocking;
        $base['auto_review_cleanup']=[
            'review_id'=>(int)$ownership['review_id'],
            'review_count'=>$reviewCount,
            'worker_id'=>(int)$ownership['worker_id'],
        ];
        $base['counts']=array_merge(
            (array)($base['counts']??[]),
            ['auto_review_owned'=>$reviewCount]
        );
        $base['inner_plan_digest']=(string)$base['plan_digest'];
        $base['plan_digest']=$this->digest($plan);
        $base['reason']=$allowed
            ?'系统自动五星评价已证明属于当前 testing 实例，可先删除评价并重算师傅评分后进入原清理链。'
            :'自动评价存在无法证明所有权的评价附件、回复或其它评价，拒绝清理。';
        return$base;
    }

    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array
    {
        if(!TestingEnvironmentPolicy::active()){
            throw new TestCenterException(
                '自动评价测试清理仅允许 testing 环境。',
                'TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',
                403
            );
        }

        $detail=$this->inner->instanceDetail($publicId);
        $instance=(array)($detail['instance']??[]);
        if(!$this->hasOwnedRun((int)($instance['id']??0))){
            return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);
        }

        $preview=$this->cleanupPreview($publicId);
        if(empty($preview['auto_review_cleanup'])){
            return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);
        }
        if(empty($preview['allowed'])){
            throw new TestCenterException(
                (string)($preview['reason']??'自动评价清理已被阻断。'),
                'TEST_AUTO_REVIEW_CLEANUP_BLOCKED',
                409,
                $preview
            );
        }
        if(!hash_equals((string)$preview['plan_digest'],$expectedPlanDigest)){
            throw new TestCenterException(
                '自动评价清理范围在预览后发生变化，请重新预览。',
                'TEST_AUTO_REVIEW_CLEANUP_PREVIEW_STALE',
                409,
                $preview
            );
        }

        return$this->db->transaction(function(Database $db)use($publicId,$adminId,$expectedPlanDigest):array{
            $fresh=$this->cleanupPreview($publicId);
            if(empty($fresh['allowed'])
                ||empty($fresh['auto_review_cleanup'])
                ||!hash_equals((string)$fresh['plan_digest'],$expectedPlanDigest)){
                throw new TestCenterException(
                    '自动评价清理范围在进入事务后发生变化，请重新预览。',
                    'TEST_AUTO_REVIEW_CLEANUP_PREVIEW_STALE',
                    409,
                    $fresh
                );
            }

            $detail=$this->inner->instanceDetail($publicId);
            $instance=(array)$detail['instance'];
            $order=(array)$detail['order'];
            $instanceId=(int)$instance['id'];
            $orderId=(int)$order['id'];
            $reviewId=(int)$fresh['auto_review_cleanup']['review_id'];
            $workerId=(int)$fresh['auto_review_cleanup']['worker_id'];

            $db->fetch('SELECT id FROM test_instances WHERE id=:id FOR UPDATE',['id'=>$instanceId]);
            $lockedOrder=$db->fetch(
                'SELECT id,public_no,is_demo FROM orders WHERE id=:id FOR UPDATE',
                ['id'=>$orderId]
            );
            $review=$db->fetch(
                'SELECT * FROM reviews WHERE id=:id AND order_id=:order_id FOR UPDATE',
                ['id'=>$reviewId,'order_id'=>$orderId]
            );
            if(!$lockedOrder
                ||(int)$lockedOrder['is_demo']!==1
                ||(string)$lockedOrder['public_no']!==(string)$instance['order_no_snapshot']
                ||strpos((string)$lockedOrder['public_no'],'BXDEMOHALL')===0
                ||!$review
                ||!$this->reviewOwned($review,$order)){
                throw new TestCenterException(
                    '自动评价清理最终所有权校验失败。',
                    'TEST_AUTO_REVIEW_CLEANUP_BOUNDARY',
                    409
                );
            }

            if($db->tableExists('review_media')
                &&(int)$db->fetchValue('SELECT COUNT(*) FROM review_media WHERE review_id=:id',['id'=>$reviewId])>0){
                throw new TestCenterException('自动评价出现评价图片，拒绝删除。','TEST_AUTO_REVIEW_MEDIA_UNOWNED',409);
            }
            if($db->tableExists('review_replies')
                &&(int)$db->fetchValue('SELECT COUNT(*) FROM review_replies WHERE review_id=:id',['id'=>$reviewId])>0){
                throw new TestCenterException('自动评价出现评价回复，拒绝删除。','TEST_AUTO_REVIEW_REPLY_UNOWNED',409);
            }

            $db->execute(
                "DELETE FROM reviews
                 WHERE id=:id AND order_id=:order_id AND is_auto=1
                   AND content='系统自动默认好评'",
                ['id'=>$reviewId,'order_id'=>$orderId]
            );
            if($workerId>0)(new DatabaseReviewRepository($db))->refreshWorkerSummary($workerId);

            $inner=$this->inner->cleanupPreview($publicId);
            if(empty($inner['allowed'])||empty($inner['plan_digest'])){
                throw new TestCenterException(
                    '测试自动评价删除后原清理链仍未通过，已整体回滚。',
                    'TEST_AUTO_REVIEW_BASE_CLEANUP_BLOCKED',
                    409,
                    $inner
                );
            }
            $result=$this->inner->cleanup($publicId,$adminId,(string)$inner['plan_digest']);
            $result['auto_review_cleanup']=[
                'review_id'=>$reviewId,
                'worker_id'=>$workerId,
            ];
            \audit(
                'test_center.auto_review_cleanup',
                'test_instance',
                $instanceId,
                '清理当前实例系统自动五星评价并重算师傅评分'
            );
            return$result;
        });
    }

    /** @return array<string,mixed> */
    private function ownership(array $instance,array $order): array
    {
        $reviews=$this->db->fetchAll(
            'SELECT * FROM reviews WHERE order_id=:id ORDER BY id',
            ['id'=>(int)$order['id']]
        );
        $blocking=[];
        if(count($reviews)!==1)$blocking['reviews_multiple_or_missing']=count($reviews);
        $review=count($reviews)===1?$reviews[0]:null;
        if($review&&!$this->reviewOwned($review,$order))$blocking['review_unowned']=1;

        $reviewId=$review?(int)$review['id']:0;
        if($reviewId>0&&$this->db->tableExists('review_media')){
            $media=(int)$this->db->fetchValue(
                'SELECT COUNT(*) FROM review_media WHERE review_id=:id',
                ['id'=>$reviewId]
            );
            if($media>0)$blocking['review_media_unowned']=$media;
        }
        if($reviewId>0&&$this->db->tableExists('review_replies')){
            $replies=(int)$this->db->fetchValue(
                'SELECT COUNT(*) FROM review_replies WHERE review_id=:id',
                ['id'=>$reviewId]
            );
            if($replies>0)$blocking['review_replies_unowned']=$replies;
        }

        return[
            'review_id'=>$reviewId,
            'review_count'=>count($reviews),
            'worker_id'=>(int)($order['assigned_worker_id']??0),
            'blocking'=>$blocking,
        ];
    }

    private function reviewOwned(array $review,array $order): bool
    {
        return(int)($review['is_auto']??0)===1
            &&(string)($review['content']??'')==='系统自动默认好评'
            &&(int)($review['attitude_rating']??0)===5
            &&(int)($review['craft_rating']??0)===5
            &&(string)($review['status']??'')==='visible'
            &&(int)($review['user_id']??0)===(int)($order['user_id']??0)
            &&(int)($review['worker_id']??0)===(int)($order['assigned_worker_id']??0);
    }

    private function hasOwnedRun(int $instanceId): bool
    {
        if($instanceId<1)return false;
        return(bool)$this->db->fetchValue(
            "SELECT id FROM test_runs
             WHERE instance_id=:instance
               AND action_key='system_auto_review'
               AND actor_role='system'
               AND operation_mode='real_service'
               AND result='passed'
             ORDER BY id DESC LIMIT 1",
            ['instance'=>$instanceId]
        );
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        return hash(
            'sha256',
            (string)json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        );
    }
}
