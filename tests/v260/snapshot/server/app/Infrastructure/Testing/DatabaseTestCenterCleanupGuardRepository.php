<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;

/**
 * 测试中心统一清理所有权门禁。
 *
 * 底层普通/平台仓储仍各自负责实际删除；本层专门补充跨订单图与消息边界，
 * 防止 order_relations(parent/child) 这类没有 order_id 字段的关系漏过通用扫描。
 */
final class DatabaseTestCenterCleanupGuardRepository implements TestCenterRepository
{
    private Database $db;
    private TestCenterRepository $inner;

    public function __construct(Database $db, TestCenterRepository $inner)
    {
        $this->db=$db;$this->inner=$inner;
    }

    public function environmentDiagnostics(): array{return $this->inner->environmentDiagnostics();}
    public function listInstances(array $filters=[]): array{return $this->inner->listInstances($filters);}

    public function instanceDetail(string $publicId): array
    {
        $detail=$this->inner->instanceDetail($publicId);
        $detail['cleanup']=$this->cleanupPreview($publicId);
        return $detail;
    }

    public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array
    {
        $detail=$this->inner->build($scenario,$params,$adminId,$requestKey,$sourceInstanceId,$rebuiltFromInstanceId);
        if(!empty($detail['instance']['public_id']))$detail['cleanup']=$this->cleanupPreview((string)$detail['instance']['public_id']);
        return $detail;
    }

    public function verify(string $publicId,int $adminId): array{return $this->inner->verify($publicId,$adminId);}

    public function cleanupPreview(string $publicId): array
    {
        $base=$this->inner->cleanupPreview($publicId);
        if(empty($base['plan_digest']))return $base;
        $detail=$this->inner->instanceDetail($publicId);
        $order=is_array($detail['order']??null)?$detail['order']:null;
        if(!$order)return $base;
        $orderId=(int)($order['id']??0);$orderNo=(string)($order['public_no']??'');
        $blocking=(array)($base['blocking_relations']??[]);
        $extra=[];

        if($this->db->tableExists('order_relations')&&$orderId>0){
            $relations=(int)$this->db->fetchValue(
                'SELECT COUNT(*) FROM order_relations WHERE parent_order_id=:parent OR child_order_id=:child',
                ['parent'=>$orderId,'child'=>$orderId]
            );
            if($relations>0){$blocking['order_relations']=$relations;$extra['order_relations']=$relations;}
        }
        if($this->db->tableExists('message_outbox')&&$orderId>0){
            $outbox=(int)$this->db->fetchValue(
                'SELECT COUNT(*) FROM message_outbox WHERE payload_json LIKE :id_needle OR payload_json LIKE :no_needle OR business_key LIKE :business_needle',
                ['id_needle'=>'%"order_id":'.$orderId.'%','no_needle'=>'%'.$orderNo.'%','business_needle'=>'%'.$orderNo.'%']
            );
            if($outbox>0){$blocking['message_outbox']=$outbox;$extra['message_outbox']=$outbox;}
        }

        if((string)($order['order_source_type']??'')==='platform'){
            if($this->db->tableExists('media_links')){
                $media=(int)$this->db->fetchValue("SELECT COUNT(*) FROM media_links WHERE entity_type='order' AND entity_id=:id",['id'=>$orderId]);
                if($media>0){$blocking['media_links_unowned']=$media;$extra['media_links_unowned']=$media;}
            }
            if($this->db->tableExists('notifications')){
                $notifications=(int)$this->db->fetchValue(
                    'SELECT COUNT(*) FROM notifications WHERE data_json LIKE :id_needle OR data_json LIKE :no_needle',
                    ['id_needle'=>'%"order_id":'.$orderId.'%','no_needle'=>'%'.$orderNo.'%']
                );
                if($notifications>0){$blocking['notifications_unowned']=$notifications;$extra['notifications_unowned']=$notifications;}
            }
        }

        $allowed=empty($blocking)&&!empty($base['allowed']);
        $digest=$this->digest([
            'inner_plan_digest'=>(string)$base['plan_digest'],
            'order_id'=>$orderId,
            'extra'=>$extra,
            'allowed'=>$allowed,
        ]);
        $base['allowed']=$allowed;
        $base['blocking_relations']=$blocking;
        $base['guard_plan_digest']=$digest;
        $base['inner_plan_digest']=(string)$base['plan_digest'];
        $base['plan_digest']=$digest;
        if(!$allowed&&$extra)$base['reason']='发现跨订单关系、外部消息或平台未声明所有权的数据，统一清理门禁已阻断删除。';
        return $base;
    }

    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array
    {
        $guard=$this->cleanupPreview($publicId);
        if(empty($guard['allowed']))throw new TestCenterException((string)($guard['reason']??'清理已被统一所有权门禁阻断。'),'TEST_CLEANUP_GUARD_BLOCKED',409,$guard);
        if(!hash_equals((string)$guard['plan_digest'],$expectedPlanDigest))throw new TestCenterException('统一清理范围在预览后发生变化，请重新预览。','TEST_CLEANUP_GUARD_PREVIEW_STALE',409,$guard);
        $inner=$this->inner->cleanupPreview($publicId);
        if(empty($inner['allowed'])||empty($inner['plan_digest']))throw new TestCenterException('底层清理计划已变化，请重新预览。','TEST_CLEANUP_PREVIEW_STALE',409,$inner);
        return $this->inner->cleanup($publicId,$adminId,(string)$inner['plan_digest']);
    }

    public function rebuildSource(string $publicId): array{return $this->inner->rebuildSource($publicId);}

    private function digest($value): string
    {
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return hash('sha256',is_string($json)?$json:'{}');
    }
}
