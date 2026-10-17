<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Persistence\OrderFormMediaRules;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 为当前表单图片规则生成 testing 私有占位图；不触发七牛上传。 */
final class TestingMediaFixtureFactory
{
    private Database $db;
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function __construct(Database $db) { $this->db = $db; }

    /** @return array<string,int> */
    public function attachRequired(int $instanceId, int $orderId, int $schemaVersionId, int $adminId): array
    {
        if (!TestingEnvironmentPolicy::active()) throw new TestCenterException('测试图片夹具仅允许 testing 环境。','TEST_MEDIA_PRODUCTION_BLOCKED',403);
        $counts = [];
        foreach (['product','wall'] as $purpose) {
            $rule = OrderFormMediaRules::rule($this->db,$schemaVersionId,$purpose);
            $required = $rule['active'] ? max(0,(int)$rule['min']) : 0;
            $counts[$purpose] = 0;
            for ($i=0; $i<$required; $i++) {
                $this->attachOne($instanceId,$orderId,$purpose,$i,$adminId);
                $counts[$purpose]++;
            }
        }
        return $counts;
    }

    private function attachOne(int $instanceId,int $orderId,string $purpose,int $sort,int $adminId): void
    {
        $bytes = base64_decode(self::PNG_BASE64,true);
        if (!is_string($bytes) || $bytes === '') throw new RuntimeException('测试图片内置资源损坏。');
        $relative = 'test/test-center/' . $instanceId . '/' . $purpose . '-' . $sort . '-' . bin2hex(random_bytes(4)) . '.png';
        $absolute = \base_path('storage/private/' . $relative);
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException('无法创建测试图片目录。');
        if (file_put_contents($absolute,$bytes,LOCK_EX) === false) throw new RuntimeException('无法写入测试图片。');
        @chmod($absolute,0664);
        try {
            $mediaId = $this->db->insert(
                "INSERT INTO media_files(public_id,storage_disk,storage_key,mime_type,extension,size_bytes,width_px,height_px,sha256,compression_quality,metadata_json,status,reference_count,created_at)
                 VALUES(:public_id,'private_local',:storage_key,'image/png','png',:size,1,1,:sha,100,:metadata,'active',1,UTC_TIMESTAMP())",
                [
                    'public_id'=>\public_id(),'storage_key'=>$relative,'size'=>strlen($bytes),'sha'=>hash('sha256',$bytes),
                    'metadata'=>json_encode(['test_center'=>true,'evidence_valid'=>false,'purpose'=>$purpose,'fixture'=>'1x1_png'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ]
            );
            $this->db->insert(
                "INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at)
                 VALUES(:media,'order',:order_id,:purpose,:sort,'admin',:admin,UTC_TIMESTAMP())",
                ['media'=>$mediaId,'order_id'=>$orderId,'purpose'=>$purpose,'sort'=>$sort,'admin'=>$adminId]
            );
            $this->db->insert(
                "INSERT INTO test_media_facts(instance_id,media_file_id,purpose,storage_key_snapshot,created_at)
                 VALUES(:instance,:media,:purpose,:key,UTC_TIMESTAMP())",
                ['instance'=>$instanceId,'media'=>$mediaId,'purpose'=>$purpose,'key'=>$relative]
            );
        } catch (\Throwable $exception) {
            @unlink($absolute);
            throw $exception;
        }
    }
}
