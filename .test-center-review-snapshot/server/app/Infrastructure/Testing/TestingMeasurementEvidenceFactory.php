<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * testing 私有测量成果夹具。
 * 仅满足生产“至少一个空间且每个空间至少一张图”的前置条件；不代表上传、标注或证据真实性已验证。
 */
final class TestingMeasurementEvidenceFactory
{
    private const PNG_BASE64='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}

    /** @return array{space_id:int,space_public_id:string,media_file_id:int,created:bool} */
    public function ensure(int $instanceId,int $orderId,int $adminId): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('测量成果夹具只允许 testing 环境。','TEST_MEDIA_PRODUCTION_BLOCKED',403);
        $order=$this->db->fetch("SELECT id,is_demo,workflow_profile FROM orders WHERE id=:id",['id'=>$orderId]);
        if(!$order||(int)$order['is_demo']!==1||(string)$order['workflow_profile']!=='measurement')throw new TestCenterException('测量成果夹具只能用于普通测量测试订单。','TEST_MEASUREMENT_FIXTURE_BOUNDARY',409);
        $existing=$this->db->fetch(
            "SELECT s.id space_id,s.public_id space_public_id,tm.media_file_id
             FROM order_measurement_spaces s
             JOIN media_links ml ON ml.entity_type='order_measurement_space' AND ml.entity_id=s.id
             JOIN test_media_facts tm ON tm.media_file_id=ml.media_file_id AND tm.instance_id=:instance
             JOIN media_files mf ON mf.id=tm.media_file_id AND mf.status='active'
             WHERE s.order_id=:order_id AND s.status='active' AND tm.purpose='measurement_result'
             ORDER BY s.id,tm.id LIMIT 1",
            ['instance'=>$instanceId,'order_id'=>$orderId]
        );
        if($existing)return['space_id'=>(int)$existing['space_id'],'space_public_id'=>(string)$existing['space_public_id'],'media_file_id'=>(int)$existing['media_file_id'],'created'=>false];

        $spacePublic=\public_id();
        $spaceId=$this->db->insert("INSERT INTO order_measurement_spaces(public_id,order_id,name,sort_order,status,created_at,updated_at) VALUES(:public,:order_id,'测试空间',0,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>$spacePublic,'order_id'=>$orderId]);
        $bytes=base64_decode(self::PNG_BASE64,true);if(!is_string($bytes)||$bytes==='')throw new RuntimeException('测试图片内置资源损坏。');
        $relative='test/test-center/'.$instanceId.'/measurement-result-'.bin2hex(random_bytes(4)).'.png';$absolute=\base_path('storage/private/'.$relative);$dir=dirname($absolute);
        if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('无法创建测量测试图片目录。');
        if(file_put_contents($absolute,$bytes,LOCK_EX)===false)throw new RuntimeException('无法写入测量测试图片。');@chmod($absolute,0664);
        try{
            $mediaId=$this->db->insert("INSERT INTO media_files(public_id,storage_disk,storage_key,mime_type,extension,size_bytes,width_px,height_px,sha256,compression_quality,metadata_json,status,reference_count,created_at) VALUES(:public,'private_local',:storage,'image/png','png',:size,1,1,:sha,100,:meta,'active',1,UTC_TIMESTAMP())",['public'=>\public_id(),'storage'=>$relative,'size'=>strlen($bytes),'sha'=>hash('sha256',$bytes),'meta'=>json_encode(['test_center'=>true,'evidence_valid'=>false,'purpose'=>'measurement_result','fixture'=>'1x1_png'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            $this->db->insert("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at) VALUES(:media,'order_measurement_space',:space,'measurement',0,'admin',:admin,UTC_TIMESTAMP())",['media'=>$mediaId,'space'=>$spaceId,'admin'=>$adminId]);
            $this->db->insert("INSERT INTO test_media_facts(instance_id,media_file_id,purpose,storage_key_snapshot,created_at) VALUES(:instance,:media,'measurement_result',:storage,UTC_TIMESTAMP())",['instance'=>$instanceId,'media'=>$mediaId,'storage'=>$relative]);
            return['space_id'=>$spaceId,'space_public_id'=>$spacePublic,'media_file_id'=>$mediaId,'created'=>true];
        }catch(\Throwable $exception){@unlink($absolute);$this->db->execute('DELETE FROM order_measurement_spaces WHERE id=:id',['id'=>$spaceId]);throw$exception;}
    }
}
