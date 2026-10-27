<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use Throwable;
use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\MessageOutboxRepository;
use WorkerAdmin\Domain\Messaging\MessageOutboxPolicy;

final class DatabaseMessageOutboxRepository implements MessageOutboxRepository
{
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }
    public function schema(): array
    {
        try {
            $exists = (int) $this->db->fetchValue("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='message_outbox'");
            if ($exists < 1) {
                return [
                    'ready' => false,
                    'columns' => [],
                    'message' => '消息发送记录表尚未建立，请先在系统升级页完成当前版本数据库升级。',
                ];
            }

            $columnRows = $this->db->fetchAll('SHOW COLUMNS FROM message_outbox');
            $columns = [];
            foreach ($columnRows as $columnRow) {
                $field = (string) ($columnRow['Field'] ?? $columnRow['field'] ?? '');
                if ($field !== '') $columns[$field] = true;
            }
            $required = ['id', 'topic', 'business_key', 'status'];
            $missing = array_values(array_filter($required, static function (string $field) use ($columns): bool {
                return !isset($columns[$field]);
            }));
            if ($missing) {
                \write_log('admin-message-outbox', '[' . date('c') . '] schema missing columns: ' . implode(',', $missing) . "\n");
                return [
                    'ready' => false,
                    'columns' => $columns,
                    'message' => '消息发送记录表结构与当前版本不一致，请先完成数据库升级后再查看。',
                ];
            }
            return ['ready' => true, 'columns' => $columns, 'message' => ''];
        } catch (Throwable $exception) {
            \write_log('admin-message-outbox', '[' . date('c') . '] schema read failed: ' . mb_substr($exception->getMessage(), 0, 500) . "\n");
            return [
                'ready' => false,
                'columns' => [],
                'message' => '消息发送记录暂时无法读取，请检查数据库升级状态后重试。',
            ];
        }
    }

    public function dashboard(string $status, string $q, int $page, string $tab = 'records'): array
    {
        $result = ['rows'=>[], 'total'=>0, 'page'=>1, 'pages'=>1, 'per_page'=>20];
        $suppressionResult = ['rows'=>[], 'total'=>0, 'page'=>1, 'pages'=>1, 'per_page'=>20];
        $failedCount = 0;
        $schema = $this->schema();
        $schemaWarning = (string) ($schema['message'] ?? '');

        if (!empty($schema['ready'])) {
            $columns = (array) ($schema['columns'] ?? []);
            $has = static function (string $field) use ($columns): bool { return isset($columns[$field]); };
            try {
                $where = ['1=1'];
                $params = [];
                if ($status !== '') {
                    $where[] = 'm.status=:status';
                    $params['status'] = $status;
                }
                if ($q !== '') {
                    $like = '%' . $q . '%';
                    $searchParts = ['CAST(m.business_key AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci LIKE :q_key'];
                    $params['q_key'] = $like;
                    if ($has('last_error')) {
                        $searchParts[] = 'CAST(m.last_error AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci LIKE :q_error';
                        $params['q_error'] = $like;
                    }
                    if ($has('payload_json')) {
                        $searchParts[] = 'CAST(m.payload_json AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci LIKE :q_payload';
                        $params['q_payload'] = $like;
                    }
                    $where[] = '(' . implode(' OR ', $searchParts) . ')';
                }

                $paidSmsReady = $this->db->tableExists('order_worker_sms_invites') && $this->db->tableExists('order_worker_sms_batches');
                $select = [
                    'm.id', 'm.topic', 'm.business_key', 'm.status',
                    $has('payload_json') ? 'm.payload_json AS _payload_json' : 'NULL AS _payload_json',
                    $has('attempt_count') ? 'm.attempt_count' : '0 AS attempt_count',
                    $has('next_attempt_at') ? 'm.next_attempt_at' : 'NULL AS next_attempt_at',
                    $has('last_error') ? 'm.last_error' : 'NULL AS last_error',
                    $has('created_at') ? 'm.created_at' : 'NULL AS created_at',
                    $has('processed_at') ? 'm.processed_at' : ($has('updated_at') ? 'm.updated_at AS processed_at' : 'NULL AS processed_at'),
                ];
                $join = '';
                if ($paidSmsReady) {
                    $select[] = 'psi.delivery_status AS paid_delivery_status';
                    $select[] = 'psi.billing_status AS paid_billing_status';
                    $select[] = 'psi.worker_name_snapshot AS paid_worker_name';
                    $select[] = 'psi.fee_cents_snapshot AS paid_fee_cents';
                    $select[] = 'psb.public_no AS paid_batch_no';
                    $join = ' LEFT JOIN order_worker_sms_invites psi ON psi.outbox_id=m.id LEFT JOIN order_worker_sms_batches psb ON psb.id=psi.batch_id';
                } else {
                    $select[] = 'NULL AS paid_delivery_status';
                    $select[] = 'NULL AS paid_billing_status';
                    $select[] = 'NULL AS paid_worker_name';
                    $select[] = 'NULL AS paid_fee_cents';
                    $select[] = 'NULL AS paid_batch_no';
                }
                $result = $this->paginate(
                    'SELECT ' . implode(',', $select) . ' FROM message_outbox m' . $join . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY m.id DESC',
                    'SELECT COUNT(*) FROM message_outbox m WHERE ' . implode(' AND ', $where),
                    $params, $tab === 'records' ? $page : 1
                );

                foreach ($result['rows'] as &$messageRow) {
                    $safeError = (string) ($messageRow['last_error'] ?? '');
                    $safeError = preg_replace('/(?<!\\d)1[3-9]\\d{9}(?!\\d)/', '1**********', $safeError);
                    $safeError = preg_replace('#https?://qyapi\\.weixin\\.qq\\.com/cgi-bin/webhook/send\\?key=[A-Za-z0-9_-]+#i', '企业微信 Webhook 地址已隐藏', $safeError);
                    $messageRow['last_error_display'] = mb_substr((string) $safeError, 0, 1000);
                }
                unset($messageRow);

                if ($has('attempt_count')) {
                    $failedCount = (int) $this->db->fetchValue("SELECT COUNT(*) FROM message_outbox WHERE topic IN ('aliyun_sms','wecom_webhook','wechat_subscribe') AND status='failed' AND attempt_count>=5");
                }
            } catch (Throwable $exception) {
                \write_log('admin-message-outbox', '[' . date('c') . '] list read failed: ' . mb_substr($exception->getMessage(), 0, 500) . "\n");
                $result = ['rows'=>[], 'total'=>0, 'page'=>1, 'pages'=>1, 'per_page'=>20];
                $failedCount = 0;
                $schemaWarning = '消息发送记录读取失败。后台其他功能不受影响，请检查数据库升级状态或服务器日志后重试。';
            }
        }

        if($this->db->tableExists('sms_recipient_suppressions')){
            try{
                $suppressionResult=$this->paginate(
                    "SELECT id,mobile_masked,reason,note,status,created_at,updated_at FROM sms_recipient_suppressions WHERE status='active' ORDER BY id DESC",
                    "SELECT COUNT(*) FROM sms_recipient_suppressions WHERE status='active'",
                    [], $tab === 'suppressions' ? $page : 1
                );
            }catch(Throwable $exception){
                \write_log('admin-sms-suppression','['.date('c').'] list failed: '.mb_substr($exception->getMessage(),0,500)."\n");
            }
        }

        return [
            'result'=>$result,
            'suppressionResult'=>$suppressionResult,
            'status'=>$status,
            'q'=>$q,
            'failedCount'=>$failedCount,
            'schemaWarning'=>$schemaWarning,
        ];

    }

    public function retry(int $id): array
    {
        $schema = $this->schema();
        $columns = (array) ($schema['columns'] ?? []);
        if (empty($schema['ready']) || !isset($columns['attempt_count'])) {
            throw new RuntimeException((string)(($schema['message'] ?? '') ?: '消息发送记录表缺少重试字段，请先完成数据库升级。'));
        }

        $message = $this->db->transaction(function (Database $db) use ($id, $columns) {
            $row = $db->fetch('SELECT id,topic,status,attempt_count FROM message_outbox WHERE id=:id FOR UPDATE', ['id' => $id]);
            MessageOutboxPolicy::requireDeadLetter($row, 'retry');
            $sets = ["status='pending'", 'attempt_count=0'];
            if (isset($columns['next_attempt_at'])) $sets[] = 'next_attempt_at=UTC_TIMESTAMP()';
            if (isset($columns['last_error'])) $sets[] = 'last_error=NULL';
            if (isset($columns['processed_at'])) $sets[] = 'processed_at=NULL';
            $changed = $db->execute('UPDATE message_outbox SET ' . implode(',', $sets) . " WHERE id=:id AND status='failed' AND attempt_count>=5", ['id' => $id]);
            if ($changed !== 1) throw new RuntimeException('消息状态已变化，没有重复加入重试队列。');
            return $row;
        });
        return $message;
    }

    public function close(int $id): array
    {
        $schema = $this->schema();
        $columns = (array) ($schema['columns'] ?? []);
        if (empty($schema['ready']) || !isset($columns['attempt_count'])) {
            throw new RuntimeException((string)(($schema['message'] ?? '') ?: '消息发送记录表缺少结案所需字段，请先完成数据库升级。'));
        }

        $message = $this->db->transaction(function (Database $db) use ($id, $columns) {
            $row = $db->fetch('SELECT id,topic,status,attempt_count FROM message_outbox WHERE id=:id FOR UPDATE', ['id' => $id]);
            MessageOutboxPolicy::requireDeadLetter($row, 'close');
            $sets = ["status='closed'"];
            if (isset($columns['next_attempt_at'])) $sets[] = 'next_attempt_at=NULL';
            $changed = $db->execute('UPDATE message_outbox SET ' . implode(',', $sets) . " WHERE id=:id AND status='failed' AND attempt_count>=5", ['id' => $id]);
            if ($changed !== 1) throw new RuntimeException('消息状态已变化，没有执行结案。');
            return $row;
        });
        return $message;
    }

    public function addSuppression(string $hmac, string $masked, string $reason, string $note, int $adminId): void
    {
        if(!$this->db->tableExists('sms_recipient_suppressions')){
            throw new RuntimeException('短信抑制名单表尚未建立，请先完成数据库升级。');
        }
        $this->db->execute(
            "INSERT INTO sms_recipient_suppressions
             (mobile_hmac,mobile_masked,reason,note,status,created_by_admin_id,created_at,updated_at)
             VALUES(:hmac,:masked,:reason,:note,'active',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE mobile_masked=VALUES(mobile_masked),reason=VALUES(reason),note=VALUES(note),
                                     status='active',created_by_admin_id=VALUES(created_by_admin_id),updated_at=UTC_TIMESTAMP()",
            ['hmac'=>$hmac,'masked'=>$masked,'reason'=>$reason,'note'=>$note!==''?$note:null,'admin'=>$adminId]
        );
    }

    public function removeSuppression(int $id): array
    {
        $row=$this->db->fetch('SELECT id,mobile_masked,status FROM sms_recipient_suppressions WHERE id=:id LIMIT 1',['id'=>$id]);
        if(!$row)throw new RuntimeException('抑制名单记录不存在。');
        $this->db->execute(
            "UPDATE sms_recipient_suppressions SET status='inactive',updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='active'",
            ['id'=>$id]
        );
        return $row;
    }
    private function paginate($sql, $countSql, array $params, int $page)
    {
        $page=max(1,$page); $perPage=20; $total=(int)$this->db->fetchValue($countSql,$params); $pages=max(1,(int)ceil($total/$perPage)); $page=min($page,$pages); $offset=($page-1)*$perPage;
        $rows=$this->db->fetchAll($sql.' LIMIT '.$perPage.' OFFSET '.$offset,$params);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$perPage];
    }
}
