<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\MediaRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Media\MediaOperationException;
use WorkerAdmin\Services\OrderNotificationService;

/** Media 板块统一持久化：上传关联、删除、身份材料、测量媒体与动态访问授权。 */
final class DatabaseMediaRepository implements MediaRepository
{
    private Database $db;
    private SettingsProvider $settings;
    private PrivateValueCipher $cipher;

    public function __construct(Database $db, SettingsProvider $settings, ?PrivateValueCipher $cipher = null)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->cipher = $cipher ?? new GlobalPrivateValueCipher();
    }

    public function newPublicId(): string { return \public_id(); }

    public function prepareOrderUpload(array $actor, string $orderPublicNo, string $purpose): array
    {
        $order = $this->order($orderPublicNo);
        $isAdmin = !empty($actor['is_admin']);
        $isOwner = (int)$order['user_id'] === (int)($actor['user_id'] ?? 0);
        if ($isAdmin && !$isOwner && in_array($purpose, ['product','wall'], true)) $this->assertProxyEditable($order);
        $imageRule = in_array($purpose, ['product','wall'], true)
            ? $this->orderImageRule((int)($order['form_schema_version_id'] ?? 0), $purpose) : null;
        if (in_array($purpose, ['product','wall'], true)) {
            if (!$isAdmin && !$isOwner) $this->fail('只能给自己的草稿或待补资料订单上传图片','FORBIDDEN',403);
            if (!$isAdmin && (string)$order['lifecycle_status'] === 'archived') $this->fail('这份未付款草稿已超过保留时间；本机资料仍在，请继续提交以重新创建订单。','ORDER_DRAFT_EXPIRED',409);
            if (!$isAdmin && !in_array((string)$order['audit_status'], ['draft','supplement_required'], true)) $this->fail('订单已提交，不能再上传下单图片','ORDER_IMAGES_LOCKED',409);
            if (!$imageRule || empty($imageRule['active'])) $this->fail('当前订单表单未启用该类图片字段，请刷新后重试','IMAGE_FIELD_DISABLED',409);
            $creatorType = $isAdmin ? 'mini_admin' : 'user';
            $creatorId = (int)$actor['user_id'];
        } elseif (in_array($purpose, ['dispute_evidence','repair_evidence'], true)) {
            if (!$isOwner) $this->fail('只能为自己的订单上传证据图片','FORBIDDEN',403);
            if ($purpose === 'dispute_evidence') {
                if ((string)$order['fulfillment_status'] !== 'submitted' || (string)$order['lifecycle_status'] !== 'active'
                    || !in_array((string)($order['dispute_status'] ?? 'none'), ['none','resolved'], true)) {
                    $this->fail('只有完工待验收且尚未进入争议的订单可以上传争议证据','DISPUTE_EVIDENCE_NOT_ALLOWED',409);
                }
            } else {
                $measurement = (string)($order['workflow_profile'] ?? 'construction') === 'measurement';
                $warrantyInvalid = !$measurement && (empty($order['warranty_expires_at']) || (int)($order['warranty_months'] ?? 0) < 1 || !$this->isFutureUtc($order['warranty_expires_at']));
                if ((string)$order['fulfillment_status'] !== 'accepted' || (string)$order['lifecycle_status'] !== 'completed' || $warrantyInvalid) {
                    $this->fail($measurement ? '测量成果确认后才能上传测量售后证据' : '只有保障期内的已验收订单可以上传维修证据','REPAIR_EVIDENCE_NOT_ALLOWED',409);
                }
            }
            $creatorType = 'user';
            $creatorId = (int)$actor['user_id'];
        } elseif ($purpose === 'collection_receipt') {
            $workerId = $this->requireWorker($actor);
            $assignment = $this->db->fetch("SELECT id FROM assignments WHERE order_id=:order_id AND worker_id=:worker_id AND status IN ('accepted','completed') ORDER BY id DESC LIMIT 1", ['order_id'=>$order['id'],'worker_id'=>$workerId]);
            if (!$assignment || !in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true)) $this->fail('提交完工后才能上传收款凭证','RECEIPT_NOT_ALLOWED',409);
            $creatorType = 'worker'; $creatorId = $workerId;
        } else {
            $workerId = $this->assertWorkAssignment($actor, $order);
            $creatorType = 'worker'; $creatorId = $workerId;
        }
        return [
            'order'=>$order,'purpose'=>$purpose,'image_rule'=>$imageRule,'is_admin'=>$isAdmin,
            'creator_type'=>$creatorType,'creator_id'=>$creatorId,'is_proxy_operator'=>$isAdmin && !$isOwner,
            'defer_qiniu'=>in_array($purpose,['product','wall'],true)
                && in_array((string)$order['audit_status'],['draft','supplement_required'],true)
                && (string)$order['lifecycle_status']==='active',
            'qiniu_type'=>in_array($purpose,['completion','arrival_evidence','collection_receipt','dispute_evidence','repair_evidence'],true)?$purpose:'order',
        ];
    }

    public function storeOrderMedia(array $context, array $optimized, array $metadata, string $publicId): void
    {
        $order = $context['order']; $purpose = (string)$context['purpose'];
        $locked = $this->db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>$order['id']]);
        if (!$locked) throw new RuntimeException('订单不存在或已删除');
        if (!empty($context['is_proxy_operator']) && in_array($purpose,['product','wall'],true)) $this->assertProxyEditable($locked);
        if (in_array($purpose,['product','wall'],true) && empty($context['is_admin']) && !in_array((string)$locked['audit_status'],['draft','supplement_required'],true)) {
            throw new RuntimeException('订单已提交，不能再上传下单图片');
        }
        $count = (int)$this->db->fetchValue("SELECT COUNT(*) FROM media_links WHERE entity_type='order' AND entity_id=:id AND purpose=:purpose",['id'=>$order['id'],'purpose'=>$purpose]);
        $fixed = ['before_optional'=>3,'completion'=>6,'arrival_evidence'=>6,'collection_receipt'=>3,'dispute_evidence'=>6,'repair_evidence'=>6];
        $maximum = $context['image_rule'] ? (int)$context['image_rule']['max'] : (int)($fixed[$purpose] ?? 1);
        if ($count >= $maximum) throw new RuntimeException('该类图片最多上传 '.$maximum.' 张');
        $meta = ['compression_mode'=>'lossless-first','compression_method'=>(string)($optimized['method']??'original')];
        if ($metadata) $meta = array_merge($meta,$metadata);
        $mediaId = $this->insertMedia($publicId,$optimized,$meta);
        $this->db->insert("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at) VALUES(:media_id,'order',:entity_id,:purpose,:sort,:creator_type,:creator_id,UTC_TIMESTAMP())",[
            'media_id'=>$mediaId,'entity_id'=>$order['id'],'purpose'=>$purpose,'sort'=>$count,
            'creator_type'=>$context['creator_type'],'creator_id'=>$context['creator_id'],
        ]);
    }

    public function deleteOrderMedia(array $actor, string $orderPublicNo, string $mediaPublicId): string
    {
        $order = $this->order($orderPublicNo);
        if (!empty($actor['is_admin']) && (int)$order['user_id'] !== (int)($actor['user_id']??0)) {
            $locked = $this->db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            if (!$locked) return '';
            $row = $this->db->fetch("SELECT m.id,l.id link_id FROM media_links l JOIN media_files m ON m.id=l.media_file_id AND m.status='active' WHERE l.entity_type='order' AND l.entity_id=:id AND l.purpose IN ('product','wall') AND m.public_id=:media LIMIT 1 FOR UPDATE", ['id'=>$order['id'],'media'=>$mediaPublicId]);
            if ($row) {
                $this->assertProxyEditable($locked);
                if ($locked['lifecycle_status'] !== 'active' || !in_array($locked['audit_status'], ['draft','supplement_required'], true)) return '';
                $this->unlinkMedia((int)$row['link_id'], (int)$row['id']);
                return 'order_image';
            }
        }
        if ((int)$order['user_id'] === (int)($actor['user_id']??0)) {
            $locked = $this->db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>$order['id']]);
            if (!$locked) return '';
            if (in_array((string)$locked['audit_status'],['draft','supplement_required'],true)) {
                $row = $this->db->fetch("SELECT m.id,l.id link_id FROM media_links l JOIN media_files m ON m.id=l.media_file_id AND m.status='active' WHERE l.entity_type='order' AND l.entity_id=:order_id AND l.purpose IN ('product','wall') AND m.public_id=:public_id LIMIT 1 FOR UPDATE",['order_id'=>$order['id'],'public_id'=>$mediaPublicId]);
                if ($row) { $this->unlinkMedia((int)$row['link_id'],(int)$row['id']); return 'order_image'; }
            }
            $row = $this->db->fetch("SELECT m.id,l.id link_id,l.purpose FROM media_links l JOIN media_files m ON m.id=l.media_file_id AND m.status='active' WHERE l.entity_type='order' AND l.entity_id=:order_id AND l.purpose IN ('dispute_evidence','repair_evidence') AND m.public_id=:public_id LIMIT 1 FOR UPDATE",['order_id'=>$order['id'],'public_id'=>$mediaPublicId]);
            if (!$row) return '';
            if ($row['purpose']==='dispute_evidence' && $this->db->fetchValue('SELECT id FROM dispute_evidence WHERE media_file_id=:id LIMIT 1',['id'=>$row['id']])) throw new RuntimeException('争议已提交，证据图片不能再删除');
            if ($row['purpose']==='repair_evidence') {
                foreach ($this->db->fetchAll('SELECT evidence_media_ids_json FROM after_sales WHERE order_id=:id',['id'=>$order['id']]) as $afterSale) {
                    $ids=json_decode((string)($afterSale['evidence_media_ids_json']??''),true);
                    if (is_array($ids)&&in_array((int)$row['id'],array_map('intval',$ids),true)) throw new RuntimeException('维修申请已提交，证据图片不能再删除');
                }
            }
            $this->unlinkMedia((int)$row['link_id'],(int)$row['id']);
            return (string)$row['purpose'];
        }
        $workerId = $this->requireWorker($actor);
        $locked = $this->db->fetch('SELECT fulfillment_status FROM orders WHERE id=:id FOR UPDATE',['id'=>$order['id']]);
        $assignment=$this->db->fetch("SELECT id FROM assignments WHERE order_id=:order_id AND worker_id=:worker_id AND status IN ('accepted','completed') ORDER BY id DESC LIMIT 1 FOR UPDATE",['order_id'=>$order['id'],'worker_id'=>$workerId]);
        if (!$assignment || !$locked || !in_array((string)$locked['fulfillment_status'],['submitted','accepted'],true)) throw new RuntimeException('当前订单不能修改收款凭证');
        if ($this->db->fetchValue("SELECT id FROM offline_collection_adjustment_requests WHERE order_id=:id AND status IN ('auto_approved','approved') LIMIT 1",['id'=>$order['id']])) throw new RuntimeException('收款凭证已形成实际尾款记录，不能删除；驳回后可重新上传');
        if ((int)$this->db->fetchValue('SELECT COALESCE(amount_paid_cents,0) FROM commission_receivables WHERE order_id=:id LIMIT 1',['id'=>$order['id']])>0) throw new RuntimeException('平台服务费已有支付记录，收款凭证不能再删除');
        $row=$this->db->fetch("SELECT m.id,l.id link_id FROM media_links l JOIN media_files m ON m.id=l.media_file_id AND m.status='active' WHERE l.entity_type='order' AND l.entity_id=:order_id AND l.purpose='collection_receipt' AND m.public_id=:public_id LIMIT 1 FOR UPDATE",['order_id'=>$order['id'],'public_id'=>$mediaPublicId]);
        if(!$row)throw new RuntimeException('收款凭证不存在或已经删除');
        $this->unlinkMedia((int)$row['link_id'],(int)$row['id']);
        return 'collection_receipt';
    }

    private function assertProxyEditable(array $order): void
    {
        if (!$this->db->fetchValue("SELECT id FROM order_events WHERE order_id=:id AND event_type='mini_ordinary_created' LIMIT 1", ['id'=>$order['id']])) return;
        if ($order['lifecycle_status'] === 'archived') $this->fail('代录草稿已过期，请重新提交', 'ORDER_DRAFT_EXPIRED', 409);
        if ($order['lifecycle_status'] !== 'active' || $order['audit_status'] !== 'draft' || $order['payment_status'] !== 'unpaid'
            || $this->db->fetchValue("SELECT id FROM order_events WHERE order_id=:id AND event_type='mini_ordinary_ready' LIMIT 1", ['id'=>$order['id']])) {
            $this->fail('代录资料已交付客户或状态已变化，请由客户核对后继续', 'PROXY_DRAFT_LOCKED', 409);
        }
    }

    public function prepareMeasurementUpload(array $actor, string $orderPublicNo, string $spacePublicId, string $purpose): array
    {
        $order=$this->order($orderPublicNo);
        if((string)($order['workflow_profile']??'construction')!=='measurement')$this->fail('当前订单不是测量订单','NOT_MEASUREMENT_ORDER',409);
        $workerId=$this->assertWorkAssignment($actor,$order);
        $space=$this->db->fetch("SELECT * FROM order_measurement_spaces WHERE public_id=:public_id AND order_id=:order_id AND status='active' LIMIT 1",['public_id'=>$spacePublicId,'order_id'=>$order['id']]);
        if(!$space)$this->fail('请先选择测量空间','MEASUREMENT_SPACE_REQUIRED',422);
        $state=$this->db->fetch('SELECT result_status,service_payment_status FROM order_measurement_states WHERE order_id=:id LIMIT 1',['id'=>$order['id']]);
        if(!$state||$state['service_payment_status']!=='paid'||$state['result_status']!=='editing')$this->fail('测量成果当前不能修改','MEASUREMENT_RESULT_LOCKED',409);
        return ['order'=>$order,'space'=>$space,'purpose'=>$purpose,'worker_id'=>$workerId,'qiniu_type'=>'order','defer_qiniu'=>false];
    }

    public function storeMeasurementMedia(array $context, array $optimized, string $publicId): void
    {
        $order=$context['order'];$space=$context['space'];$purpose=(string)$context['purpose'];
        $state=$this->db->fetch('SELECT result_status,service_payment_status FROM order_measurement_states WHERE order_id=:id FOR UPDATE',['id'=>$order['id']]);
        if(!$state||((string)($order['order_source_type']??'')!=='platform'&&$state['service_payment_status']!=='paid')||$state['result_status']!=='editing')throw new RuntimeException('测量成果已提交或锁定，不能继续上传');
        if(!$this->db->fetchValue("SELECT id FROM order_measurement_spaces WHERE id=:id AND order_id=:order_id AND status='active' FOR UPDATE",['id'=>$space['id'],'order_id'=>$order['id']]))throw new RuntimeException('测量空间不存在或已删除');
        $count=(int)$this->db->fetchValue("SELECT COUNT(*) FROM media_links WHERE entity_type='order_measurement_space' AND entity_id=:id",['id'=>$space['id']]);
        if($count>=20)throw new RuntimeException('单个空间最多上传 20 张测量图片');
        $metadata=['compression_mode'=>'lossless-first','compression_method'=>(string)($optimized['method']??'original'),'purpose'=>$purpose,'measurement_image_only'=>true];
        if (!empty($context['metadata'])) $metadata += $context['metadata'];
        $mediaId=$this->insertMedia($publicId,$optimized,$metadata);
        $this->db->insert("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at) VALUES(:media_id,'order_measurement_space',:entity_id,:purpose,:sort,:creator_type,:creator_id,UTC_TIMESTAMP())",['media_id'=>$mediaId,'entity_id'=>$space['id'],'purpose'=>$purpose,'sort'=>$count,'creator_type'=>$context['creator_type']??'worker','creator_id'=>$context['creator_id']??$context['worker_id']]);
    }

    /** 调用方已锁定当前订单/指派与可编辑阶段；只整理本次指派下管理员上传的资料。 */
    public function deleteAssistedMedia(array $order,array $command,string $assignmentPublicId): void
    {
        $row=$this->db->fetch("SELECT m.id,m.metadata_json,l.id link_id,l.purpose,l.entity_type,l.entity_id
            FROM media_links l JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
            LEFT JOIN order_measurement_spaces s ON l.entity_type='order_measurement_space' AND s.id=l.entity_id
            WHERE BINARY m.public_id=BINARY :public_id AND l.created_by_type='mini_admin' AND l.purpose=:purpose
                AND ((l.entity_type='order' AND l.entity_id=:order_id)
                OR (l.entity_type='order_measurement_space' AND s.order_id=:order_id AND s.status='active' AND BINARY s.public_id=BINARY :space))
            LIMIT 1 FOR UPDATE",['public_id'=>$command['media_id'],'purpose'=>$command['purpose'],'order_id'=>$order['id'],'space'=>$command['space_id']??'']);
        $metadata=$row ? json_decode((string)$row['metadata_json'],true) : null;
        if (!$row || !is_array($metadata) || ($metadata['assistance_assignment_id']??'')!==$assignmentPublicId
            || (($command['purpose']??'')==='repair_result' && ($metadata['after_sale_id']??'')!==($command['after_sale_id']??''))) {
            throw new MediaOperationException('只能整理当前指派下管理员上传且未锁定的资料','ASSISTANCE_MEDIA_LOCKED',409);
        }
        $this->unlinkMedia((int)$row['link_id'],(int)$row['id']);
    }

    public function deleteMeasurementMedia(array $actor, string $orderPublicNo, string $spacePublicId, string $mediaPublicId): void
    {
        $order=$this->order($orderPublicNo);
        if((string)($order['workflow_profile']??'construction')!=='measurement')$this->fail('当前订单不是测量订单','NOT_MEASUREMENT_ORDER',409);
        $this->assertWorkAssignment($actor,$order);
        $space=$this->db->fetch("SELECT * FROM order_measurement_spaces WHERE public_id=:public_id AND order_id=:order_id AND status='active' LIMIT 1",['public_id'=>$spacePublicId,'order_id'=>$order['id']]);
        if(!$space)$this->fail('测量空间不存在','MEASUREMENT_SPACE_NOT_FOUND',404);
        $state=$this->db->fetch('SELECT result_status FROM order_measurement_states WHERE order_id=:id FOR UPDATE',['id'=>$order['id']]);
        if(!$state||$state['result_status']!=='editing')throw new RuntimeException('测量成果已提交或锁定，不能删除图片');
        $row=$this->db->fetch("SELECT m.id,l.id link_id FROM media_links l JOIN media_files m ON m.id=l.media_file_id AND m.status='active' WHERE l.entity_type='order_measurement_space' AND l.entity_id=:space_id AND m.public_id=:public_id LIMIT 1 FOR UPDATE",['space_id'=>$space['id'],'public_id'=>$mediaPublicId]);
        if(!$row)throw new RuntimeException('测量图片不存在或已经删除');
        $this->unlinkMedia((int)$row['link_id'],(int)$row['id']);
    }

    public function prepareReviewUpload(array $actor, string $reviewPublicId): array
    {
        $review=$this->db->fetch('SELECT id,user_id,order_id FROM reviews WHERE public_id=:id LIMIT 1',['id'=>$reviewPublicId]);
        if(!$review||(int)$review['user_id']!==(int)($actor['user_id']??0))$this->fail('只能给自己的评价上传图片','FORBIDDEN',403);
        if((int)$this->db->fetchValue('SELECT COUNT(*) FROM review_media WHERE review_id=:id',['id'=>$review['id']])>=6)$this->fail('评价最多上传 6 张图片','TOO_MANY_REVIEW_IMAGES',422);
        return ['review'=>$review,'creator_id'=>(int)$actor['user_id'],'qiniu_type'=>'review','defer_qiniu'=>false];
    }

    public function storeReviewMedia(array $context, array $optimized, string $publicId): void
    {
        $review=$context['review'];
        if((int)$this->db->fetchValue('SELECT COUNT(*) FROM review_media WHERE review_id=:id',['id'=>$review['id']])>=6)throw new RuntimeException('评价最多上传 6 张图片');
        $mediaId=$this->insertMedia($publicId,$optimized,['compression_mode'=>'lossless-first','compression_method'=>(string)($optimized['method']??'original')]);
        $this->db->insert("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at) VALUES(:media_id,'review',:entity_id,'review_image',0,'user',:creator_id,UTC_TIMESTAMP())",['media_id'=>$mediaId,'entity_id'=>$review['id'],'creator_id'=>$context['creator_id']]);
        $sort=(int)$this->db->fetchValue('SELECT COALESCE(MAX(sort_order),0)+10 FROM review_media WHERE review_id=:id',['id'=>$review['id']]);
        $this->db->insert('INSERT INTO review_media(review_id,media_file_id,sort_order,created_at) VALUES(:review_id,:media_id,:sort,UTC_TIMESTAMP())',['review_id'=>$review['id'],'media_id'=>$mediaId,'sort'=>$sort]);
    }

    public function prepareRealNameUpload(array $actor, string $purpose, bool $watermarkApplied): array
    {
        $workerId=$this->requireWorker($actor);
        if(!in_array($purpose,['id_front','id_back'],true))$this->fail('身份证图片类型不正确','INVALID_PURPOSE',422);
        if((string)$this->settings->get('order.id_watermark_text','仅限办理壁选业务使用')!==''&&!$watermarkApplied)$this->fail('身份证图片尚未合成用途水印，请使用最新版小程序重新上传','ID_WATERMARK_REQUIRED',422);
        $verification=$this->db->fetch('SELECT id,status FROM worker_real_name_verifications WHERE worker_id=:worker_id LIMIT 1 FOR UPDATE',['worker_id'=>$workerId]);
        if(!$verification){$id=$this->db->insert("INSERT INTO worker_real_name_verifications(worker_id,status,created_at,updated_at) VALUES(:worker_id,'draft',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['worker_id'=>$workerId]);$verification=['id'=>$id,'status'=>'draft'];}
        if($verification['status']==='pending')$this->fail('实名认证正在审核，暂不能修改照片','REALNAME_PENDING',409);
        if($verification['status']==='approved')$purpose='draft_'.$purpose;
        // 替换照片在保存成功的事务内进行，优化/上传失败不删除已有草稿。
        return ['entity_id'=>(int)$verification['id'],'purpose'=>$purpose,'verification_status'=>$verification['status'],'creator_id'=>$workerId,'qiniu_type'=>'worker_identity','defer_qiniu'=>false];
    }

    public function storeRealNameMedia(array $context, array $optimized, string $publicId): void
    {
        $verification=$this->db->fetch('SELECT id,status,ocr_payload_ciphertext FROM worker_real_name_verifications WHERE id=:id FOR UPDATE',['id'=>$context['entity_id']]);
        if(!$verification||$verification['status']==='pending'||$verification['status']!==$context['verification_status'])$this->fail('认证状态已变化，请刷新后重新上传','REALNAME_CHANGED',409);
        if($verification['status']==='approved'){
            $payload=json_decode($this->cipher->decrypt((string)$verification['ocr_payload_ciphertext'],'worker-realname-ocr'),true);
            $slot=$context['purpose']==='draft_id_front'?'front':'back';
            $entry=$payload['reauth_draft']['pending_'.$slot]??null;
            if(!is_array($entry)||empty($context['ocr_client_msg_id'])||($entry['client_msg_id']??'')!==$context['ocr_client_msg_id'])$this->fail('身份证识别结果已变化，请重新识别并上传','REALNAME_OCR_CHANGED',409);
            $payload['reauth_draft'][$slot]=$entry;
            unset($payload['reauth_draft']['pending_'.$slot]);
            if($slot==='front')unset($payload['reauth_draft']['draft_fields']);
            $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if(!is_string($json)||strlen($json)>2000000)$this->fail('认证草稿保存失败','REALNAME_DRAFT_FAILED',500);
            $encrypted=$this->cipher->encrypt($json,'worker-realname-ocr');
            if(!is_string($encrypted)||$encrypted==='')$this->fail('认证草稿保存失败','REALNAME_DRAFT_FAILED',500);
            $this->db->execute('UPDATE worker_real_name_verifications SET ocr_payload_ciphertext=:payload,updated_at=UTC_TIMESTAMP() WHERE id=:id',['payload'=>$encrypted,'id'=>$context['entity_id']]);
        }
        $existing=$this->db->fetchAll("SELECT id,media_file_id FROM media_links WHERE entity_type='worker_verification' AND entity_id=:id AND purpose=:purpose",['id'=>$context['entity_id'],'purpose'=>$context['purpose']]);
        $mediaId=$this->insertMedia($publicId,$optimized,['compression_mode'=>'lossless-first','compression_method'=>(string)($optimized['method']??'original')]);
        foreach($existing as $link)$this->unlinkMedia((int)$link['id'],(int)$link['media_file_id']);
        $this->db->insert("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at) VALUES(:media_id,'worker_verification',:entity_id,:purpose,0,'worker',:creator_id,UTC_TIMESTAMP())",['media_id'=>$mediaId,'entity_id'=>$context['entity_id'],'purpose'=>$context['purpose'],'creator_id'=>$context['creator_id']]);
    }

    public function prepareCollectionCodeUpload(array $actor): array
    {
        $workerId=$this->requireWorker($actor);
        $current=$this->db->fetch('SELECT id,media_file_id,status FROM worker_collection_code_items WHERE worker_id=:worker_id ORDER BY updated_at DESC,id DESC LIMIT 1',['worker_id'=>$workerId]);
        if(!$current){$id=$this->db->insert("INSERT INTO worker_collection_code_items(public_id,worker_id,code_type,status,created_at,updated_at) VALUES(:public_id,:worker_id,'wechat','draft',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>\public_id(),'worker_id'=>$workerId]);$current=['id'=>$id,'media_file_id'=>null,'status'=>'draft'];}
        return ['entity_id'=>(int)$current['id'],'old_media_id'=>$current['media_file_id']? (int)$current['media_file_id']:0,'worker_id'=>$workerId,'qiniu_type'=>'worker_collection_code','defer_qiniu'=>false];
    }

    public function storeCollectionCodeMedia(array $context, array $optimized, string $publicId, array $actor): void
    {
        $mediaId=$this->insertMedia($publicId,$optimized,['compression_mode'=>'lossless-first','compression_method'=>(string)($optimized['method']??'original')]);
        $this->db->insert("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at) VALUES(:media_id,'worker_collection_code',:entity_id,'merchant_order_payment',0,'worker',:creator_id,UTC_TIMESTAMP())",['media_id'=>$mediaId,'entity_id'=>$context['entity_id'],'creator_id'=>$context['worker_id']]);
        if((int)$context['old_media_id']>0){$this->db->execute("DELETE FROM media_links WHERE entity_type='worker_collection_code' AND media_file_id=:id",['id'=>$context['old_media_id']]);$this->decrementMedia((int)$context['old_media_id']);}
        $this->db->execute("UPDATE worker_collection_code_items SET media_file_id=:media_id,status='auto_approved',review_reason='系统自动预审通过，管理员可抽查驳回',submitted_at=UTC_TIMESTAMP(),reviewed_at=UTC_TIMESTAMP(),reviewed_by=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id",['media_id'=>$mediaId,'id'=>$context['entity_id']]);
        OrderNotificationService::queueAdminTask($this->db,'worker-collection-code-auto:'.$context['entity_id'].':'.$mediaId,'worker_collection_code_auto_approved','师傅收款码已自动预审，请抽查',['师傅编号'=>(string)$context['worker_id'],'师傅姓名'=>(string)($actor['worker_name']??'')]);
    }

    public function prepareMerchantLicenseUpload(array $actor): array
    {
        $userId=(int)($actor['user_id']??0);
        $profile=$this->db->fetch('SELECT id,license_media_file_id,verification_status FROM merchant_profiles WHERE user_id=:user_id LIMIT 1',['user_id'=>$userId]);
        if(!$profile){$id=$this->db->insert("INSERT INTO merchant_profiles(public_id,user_id,verification_status,recognition_level,created_at,updated_at) VALUES(:public_id,:user_id,'draft','consumer',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>\public_id(),'user_id'=>$userId]);$profile=['id'=>$id,'license_media_file_id'=>null,'verification_status'=>'draft'];}
        if($profile['verification_status']==='approved')$this->fail('商家认证已通过，如需变更请先联系平台','MERCHANT_ALREADY_APPROVED',409);
        return ['entity_id'=>(int)$profile['id'],'old_media_id'=>$profile['license_media_file_id']?(int)$profile['license_media_file_id']:0,'creator_id'=>$userId,'qiniu_type'=>'merchant_license','defer_qiniu'=>false];
    }

    public function storeMerchantLicenseMedia(array $context, array $optimized, string $publicId): void
    {
        $mediaId=$this->insertMedia($publicId,$optimized,['compression_mode'=>'lossless-first','compression_method'=>(string)($optimized['method']??'original')]);
        $this->db->insert("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at) VALUES(:media_id,'merchant_profile',:entity_id,'business_license',0,'user',:creator_id,UTC_TIMESTAMP())",['media_id'=>$mediaId,'entity_id'=>$context['entity_id'],'creator_id'=>$context['creator_id']]);
        if((int)$context['old_media_id']>0){$this->db->execute("DELETE FROM media_links WHERE entity_type='merchant_profile' AND entity_id=:entity_id AND purpose='business_license' AND media_file_id=:media_id",['entity_id'=>$context['entity_id'],'media_id'=>$context['old_media_id']]);$this->decrementMedia((int)$context['old_media_id']);}
        $this->db->execute("UPDATE merchant_profiles SET license_media_file_id=:media_id,verification_status='draft',review_reason=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id",['media_id'=>$mediaId,'id'=>$context['entity_id']]);
    }

    public function markStoredOnQiniu(string $publicId, string $storageKey): bool
    {
        return $this->db->execute("UPDATE media_files SET storage_disk='qiniu',storage_key=:key WHERE public_id=:id AND storage_disk='private_local' AND status='active'",['key'=>$storageKey,'id'=>$publicId])===1;
    }

    public function findPrivateMedia(string $publicId): ?array
    {
        return $this->db->fetch("SELECT * FROM media_files WHERE public_id=:id AND status='active' LIMIT 1",['id'=>$publicId]) ?: null;
    }

    public function findPublicWorkerMedia(string $publicId): ?array
    {
        return $this->db->fetch("SELECT m.* FROM media_files m JOIN media_links l ON l.media_file_id=m.id AND l.entity_type='worker_profile' AND l.purpose='profile' WHERE m.public_id=:id AND m.status='active' LIMIT 1",['id'=>$publicId]) ?: null;
    }

    public function canAccessPrivateMedia(array $media, array $token): bool
    {
        if(!$this->tokenActorValid($token))return false;
        $mediaId=(int)($media['id']??0);$entityType=(string)($token['entity_type']??'');$entityId=(int)($token['entity_id']??0);$orderId=(int)($token['order_id']??0);
        if($mediaId<1||$entityId<1||$entityType==='')return false;
        if($entityType==='order'){
            if($orderId<1||$entityId!==$orderId)return false;
            $order=$this->db->fetch('SELECT * FROM orders WHERE id=:id LIMIT 1',['id'=>$orderId]);if(!$order)return false;
            foreach($this->db->fetchAll("SELECT purpose FROM media_links WHERE media_file_id=:media_id AND entity_type='order' AND entity_id=:order_id",['media_id'=>$mediaId,'order_id'=>$orderId]) as $link){if($this->canAccessOrderMedia($order,$token,(string)$link['purpose']))return true;}return false;
        }
        if($entityType==='review'){
            if($orderId<1)return false;
            if(!$this->db->fetchValue('SELECT id FROM reviews WHERE id=:id AND order_id=:order_id LIMIT 1',['id'=>$entityId,'order_id'=>$orderId])||!$this->db->fetchValue('SELECT id FROM review_media WHERE review_id=:review_id AND media_file_id=:media_id LIMIT 1',['review_id'=>$entityId,'media_id'=>$mediaId]))return false;
            $order=$this->db->fetch('SELECT * FROM orders WHERE id=:id LIMIT 1',['id'=>$orderId]);return $order?$this->canAccessOrderMedia($order,$token,'review',false,false):false;
        }
        if($entityType==='order_measurement_space'){
            $row=$this->db->fetch("SELECT s.id,o.id order_id,o.user_id,o.assigned_worker_id,o.workflow_profile,o.order_source_type FROM order_measurement_spaces s JOIN orders o ON o.id=s.order_id WHERE s.id=:id AND s.status='active' LIMIT 1",['id'=>$entityId]);
            $archive=$row && (string)$row['order_source_type']==='platform' && (string)$row['workflow_profile']==='construction'
                && $this->db->fetchValue("SELECT order_id FROM order_measurement_states WHERE order_id=:id AND result_status='accepted' LIMIT 1",['id'=>$row['order_id']]);
            if(!$row||((string)$row['workflow_profile']!=='measurement'&&!$archive)||!$this->db->fetchValue("SELECT id FROM media_links WHERE media_file_id=:media_id AND entity_type='order_measurement_space' AND entity_id=:entity_id LIMIT 1",['media_id'=>$mediaId,'entity_id'=>$entityId]))return false;
            if(($token['actor_type']??'')==='admin'||(int)($token['user_id']??0)===(int)$row['user_id'])return true;
            return ($token['actor_type']??'')==='worker'&&(int)($token['actor_id']??0)===(int)$row['assigned_worker_id'];
        }
        if(!$this->db->fetchValue('SELECT id FROM media_links WHERE media_file_id=:media_id AND entity_type=:entity_type AND entity_id=:entity_id LIMIT 1',['media_id'=>$mediaId,'entity_type'=>$entityType,'entity_id'=>$entityId]))return false;
        if($entityType==='merchant_profile')return ($token['actor_type']??'')==='admin'||(bool)$this->db->fetchValue('SELECT id FROM merchant_profiles WHERE id=:id AND user_id=:user_id LIMIT 1',['id'=>$entityId,'user_id'=>(int)$token['user_id']]);
        if($entityType==='worker_verification')return ($token['actor_type']??'')==='admin'||(bool)$this->db->fetchValue('SELECT v.id FROM worker_real_name_verifications v JOIN worker_profiles w ON w.id=v.worker_id WHERE v.id=:id AND w.user_id=:user_id LIMIT 1',['id'=>$entityId,'user_id'=>(int)$token['user_id']]);
        if($entityType==='worker_collection_code'){
            $workerId=(int)$this->db->fetchValue('SELECT worker_id FROM worker_collection_code_items WHERE id=:id LIMIT 1',['id'=>$entityId]);if(!$workerId)$workerId=(int)$this->db->fetchValue('SELECT worker_id FROM worker_collection_codes WHERE id=:id LIMIT 1',['id'=>$entityId]);
            if(($token['actor_type']??'')==='admin'||(($token['actor_type']??'')==='worker'&&(int)($token['actor_id']??0)===$workerId))return true;
            if($orderId>0){$order=$this->db->fetch('SELECT user_id,assigned_worker_id FROM orders WHERE id=:id LIMIT 1',['id'=>$orderId]);return $order&&(int)$token['user_id']===(int)$order['user_id']&&(int)$order['assigned_worker_id']===$workerId;}
        }
        return false;
    }

    private function order(string $publicNo): array
    {
        $order=$this->db->fetch('SELECT * FROM orders WHERE public_no=:id LIMIT 1',['id'=>trim($publicNo)]);
        if(!$order)$this->fail('订单不存在','ORDER_NOT_FOUND',404);
        return $order;
    }

    private function requireWorker(array $actor): int
    {
        $workerId=(int)($actor['worker_id']??0);if($workerId<1)$this->fail('你还不是师傅用户','WORKER_REQUIRED',403);return $workerId;
    }

    private function assertWorkAssignment(array $actor,array $order): int
    {
        $workerId=$this->requireWorker($actor);
        $assignment=$this->db->fetch("SELECT id FROM assignments WHERE order_id=:order_id AND worker_id=:worker_id AND status='accepted' ORDER BY id DESC LIMIT 1",['order_id'=>$order['id'],'worker_id'=>$workerId]);
        if(!$assignment||(string)$order['lifecycle_status']!=='active')$this->fail('请先接单后再操作','ASSIGNMENT_REQUIRED',403);
        if(in_array((string)($order['refund_status']??'none'),['pending_review','approved','processing','refunding'],true))$this->fail('订单正在审核退款，暂时不能继续施工操作','ORDER_REFUNDING',409);
        return $workerId;
    }

    private function orderImageRule(int $schemaVersionId,string $purpose): array
    {
        $rules=['product'=>'material_images','wall'=>'wall_images'];if(!isset($rules[$purpose])||$schemaVersionId<1)return ['active'=>false,'min'=>0,'max'=>1,'label'=>'图片'];
        $field=$this->db->fetch("SELECT f.label,f.component_type,f.is_required,f.validation_json FROM form_fields f JOIN form_steps s ON s.id=f.step_id WHERE s.schema_version_id=:version AND s.status='active' AND f.status='active' AND f.field_key=:field LIMIT 1",['version'=>$schemaVersionId,'field'=>$rules[$purpose]]);
        if(!$field||!in_array($field['component_type'],['image_upload','image'],true))return ['active'=>false,'min'=>0,'max'=>1,'label'=>$rules[$purpose]];
        $validation=json_decode((string)($field['validation_json']??''),true);if(!is_array($validation))$validation=[];$max=max(1,min(20,(int)($validation['max_items']??20)));$configured=max(0,(int)($validation['min_items']??0));
        return ['active'=>true,'min'=>max((int)$field['is_required']===1?1:0,min($max,$configured)),'max'=>$max,'label'=>(string)$field['label']];
    }

    private function insertMedia(string $publicId,array $optimized,array $metadata): int
    {
        return $this->db->insert("INSERT INTO media_files(public_id,storage_disk,storage_key,mime_type,extension,size_bytes,width_px,height_px,sha256,compression_quality,metadata_json,status,reference_count,created_at) VALUES(:public_id,'private_local',:key,:mime,:extension,:size,:width,:height,:sha,:quality,:metadata,'active',1,UTC_TIMESTAMP())",[
            'public_id'=>$publicId,'key'=>$optimized['storage_key'],'mime'=>$optimized['mime_type'],'extension'=>$optimized['extension'],'size'=>$optimized['size_bytes'],'width'=>$optimized['width_px'],'height'=>$optimized['height_px'],'sha'=>$optimized['sha256'],'quality'=>$optimized['compression_quality'],'metadata'=>json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function unlinkMedia(int $linkId,int $mediaId): void { $this->db->execute('DELETE FROM media_links WHERE id=:id',['id'=>$linkId]);$this->decrementMedia($mediaId); }
    private function decrementMedia(int $mediaId): void { $this->db->execute("UPDATE media_files SET status=IF(reference_count<=1,'pending_delete',status),pending_delete_at=IF(reference_count<=1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),pending_delete_at),reference_count=IF(reference_count>0,reference_count-1,0) WHERE id=:id",['id'=>$mediaId]); }
    private function isFutureUtc($value): bool { return $value && strtotime((string)$value.' UTC')>time(); }

    private function tokenActorValid(array $token): bool
    {
        $userId=(int)($token['user_id']??0);$actorId=(int)($token['actor_id']??0);$type=(string)($token['actor_type']??'');
        if($userId<1||$actorId<1||!in_array($type,['user','worker','admin'],true)||!$this->db->fetchValue("SELECT id FROM users WHERE id=:id AND status='active' LIMIT 1",['id'=>$userId]))return false;
        if($type==='user')return $actorId===$userId;if($type==='admin')return $actorId===$userId&&$this->isAdminUserId($userId);
        return (bool)$this->db->fetchValue('SELECT id FROM worker_profiles WHERE id=:worker_id AND user_id=:user_id LIMIT 1',['worker_id'=>$actorId,'user_id'=>$userId]);
    }

    private function isAdminUserId(int $userId): bool
    {
        $configured=$this->settings->get('wechat.admin_openids',[]);if(is_string($configured)){$decoded=json_decode($configured,true);$configured=is_array($decoded)?$decoded:preg_split('/[\s,，]+/u',$configured,-1,PREG_SPLIT_NO_EMPTY);}if(!is_array($configured))return false;
        $configured=array_values(array_filter(array_map(static fn($v):string=>trim((string)$v),$configured),static fn($v):bool=>$v!==''));
        foreach($this->db->fetchAll("SELECT openid FROM wechat_identities WHERE user_id=:id AND status='active'",['id'=>$userId]) as $identity)if(in_array(trim((string)$identity['openid']),$configured,true))return true;return false;
    }

    private function canAccessOrderMedia(array $order,array $token,string $purpose,bool $allowShare=true,bool $requireOrderEntity=true): bool
    {
        if((int)($token['order_id']??0)!==(int)$order['id'])return false;if($requireOrderEntity&&(int)($token['entity_id']??0)!==(int)$order['id'])return false;if(($token['actor_type']??'')==='admin')return true;
        $public = (string)($order['audit_status']??'')==='approved'&&(string)($order['publish_status']??'')==='published'&&(string)($order['payment_status']??'')==='paid'&&(string)($order['lifecycle_status']??'')==='active'&&in_array((string)($order['quote_status']??''),['waiting','quoting','collecting'],true)&&in_array((string)($order['refund_status']??'none'),['','none'],true)&&empty($order['selected_quote_id'])&&empty($order['assigned_worker_id'])&&empty($order['current_assignment_id'])&&(empty($order['quote_deadline_at'])||$this->isFutureUtc($order['quote_deadline_at']))&&!$this->db->fetchValue("SELECT 1 FROM order_relations WHERE child_order_id=:id AND relation_type='measurement_construction' AND status='active' LIMIT 1",['id'=>$order['id']]);
        if($allowShare&&in_array($purpose,['product','wall'],true)&&($token['share_hash']??'')!==''&&preg_match('/^[a-f0-9]{64}$/',(string)$token['share_hash'])===1&&$this->db->fetchValue("SELECT id FROM share_contexts WHERE order_id=:id AND context_type='ordinary_order' AND token_hash=:hash AND status='active' AND expires_at>UTC_TIMESTAMP() LIMIT 1",['id'=>$order['id'],'hash'=>$token['share_hash']])&&$public)return true;
        if((int)($token['user_id']??0)===(int)$order['user_id'])return true;if($allowShare&&in_array($purpose,['product','wall'],true)&&$public)return true;if(($token['actor_type']??'')!=='worker')return false;
        $workerId=(int)($token['actor_id']??0);if($workerId<1||(int)($order['assigned_worker_id']??0)!==$workerId)return false;$assignment=$this->db->fetch("SELECT status FROM assignments WHERE order_id=:order_id AND worker_id=:worker_id ORDER BY FIELD(status,'accepted','completed','offered'),id DESC LIMIT 1",['order_id'=>$order['id'],'worker_id'=>$workerId]);if(!$assignment)return false;
        return in_array((string)$assignment['status'],['accepted','completed'],true)||($allowShare&&in_array($purpose,['product','wall'],true)&&$assignment['status']==='offered');
    }

    private function fail(string $message,string $code,int $status): void { throw new MediaOperationException($message,$code,$status); }
}
