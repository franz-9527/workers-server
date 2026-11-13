<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\ObjectStorage;
use WorkerAdmin\Contracts\OrderSubmissionRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** Extracted cohesive order operations; retains the existing repository port. */
trait OrderSubmissionLifecycleOperations
{
    public function findOwnedOrder(array $actor, string $publicNo): ?array
    {
        $publicNo = trim($publicNo);
        if ($publicNo === '') return null;
        return $this->db->fetch(
            'SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no AND user_id=:user_id LIMIT 1',
            ['public_no' => $publicNo, 'user_id' => (int)$actor['user_id']]
        ) ?: null;
    }

    public function mediaCounts(int $orderId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT purpose,COUNT(*) total FROM media_links WHERE entity_type='order' AND entity_id=:id GROUP BY purpose",
            ['id' => $orderId]
        );
        $map = [];
        foreach ($rows as $row) $map[(string)$row['purpose']] = (int)$row['total'];
        return $map;
    }

    public function imageRule(int $schemaVersionId, string $purpose): array
    {
        return OrderFormMediaRules::rule($this->db, $schemaVersionId, $purpose);
    }

    public function markReadyForPayment(array $actor, array $order, array $agreementVersions, array $mediaCounts): void
    {
        $this->db->transaction(function (Database $db) use ($actor, $order, $agreementVersions, $mediaCounts): void {
            // 2026-10-08：先锁本单再校验账号限单；旧草稿不能绕过七天限制。
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            if (!$locked) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
            if ($locked['lifecycle_status'] !== 'active') throw new OrderOperationException('订单已结束，请重新下单', 'ORDER_STATE_CHANGED', 409);
            if ($db->fetchValue("SELECT id FROM order_events WHERE order_id=:id AND event_type='mini_ordinary_created' LIMIT 1", ['id'=>$locked['id']])
                && !$db->fetchValue("SELECT id FROM order_events WHERE order_id=:id AND event_type='mini_ordinary_ready' LIMIT 1", ['id'=>$locked['id']])) {
                throw new OrderOperationException('管理员正在整理订单资料，请等待录入完成后再确认支付', 'PROXY_DRAFT_INCOMPLETE', 409);
            }
            if (($locked['workflow_profile'] ?? 'construction') !== 'measurement') foreach (['product','wall'] as $purpose) {
                $rule = $this->imageRule((int)$locked['form_schema_version_id'], $purpose);
                $count = (int)($this->mediaCounts((int)$locked['id'])[$purpose] ?? 0);
                if ($rule['active'] && ($count < $rule['min'] || $count > $rule['max'])) throw new OrderOperationException('订单图片已变化，请核对后重试', 'ORDER_IMAGES_REQUIRED', 422);
            }
            (new DatabaseOrderCancellationRiskRepository($db))->assertCanSubmit((int)$actor['user_id'], $locked);
            $changed = $db->execute(
                "UPDATE orders SET payment_status='awaiting_payment',updated_at=UTC_TIMESTAMP(),version=version+1
                 WHERE id=:id AND user_id=:user_id AND audit_status='draft' AND payment_status IN ('unpaid','awaiting_payment')",
                ['id' => $order['id'], 'user_id' => (int)$actor['user_id']]
            );
            if ($changed !== 1) {
                throw new OrderOperationException('订单状态已变化，请刷新后查看', 'ORDER_STATE_CHANGED', 409);
            }
            $exists = $db->fetchValue(
                "SELECT id FROM order_events WHERE order_id=:id AND event_type='order_terms_accepted' LIMIT 1",
                ['id' => $order['id']]
            );
            if (!$exists) {
                $this->event($db, (int)$order['id'], 'order_terms_accepted', 'user', (int)$actor['user_id'], [
                    'agreement_versions' => $agreementVersions,
                    'accepted_at_utc' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            $this->event($db, (int)$order['id'], 'order_ready_for_prepayment', 'user', (int)$actor['user_id'], [
                'product_images' => (int)($mediaCounts['product'] ?? 0),
                'wall_images' => (int)($mediaCounts['wall'] ?? 0),
            ]);
        });
    }

    public function supplement(array $actor, array $body): void
    {
        $order = $this->findOwnedOrder($actor, (string)($body['order_id'] ?? ''));
        if (!$order) throw new OrderOperationException('订单不存在或无权操作', 'ORDER_NOT_FOUND', 404);
        if ($order['audit_status'] !== 'supplement_required' || $order['lifecycle_status'] !== 'active') {
            throw new OrderOperationException('该订单当前不需要补充资料', 'SUPPLEMENT_NOT_REQUIRED', 409);
        }
        $description = mb_substr(trim((string)($body['description'] ?? $order['description'] ?? '')), 0, 2000);
        $quantity = mb_substr(trim((string)($body['quantity_text'] ?? $order['quantity_text'] ?? '')), 0, 100);
        $height = mb_substr(trim((string)($body['wall_height_text'] ?? $order['wall_height_text'] ?? '')), 0, 100);
        $note = mb_substr(trim((string)($body['supplement_note'] ?? '')), 0, 500);
        $this->db->transaction(function (Database $db) use ($actor, $order, $description, $quantity, $height, $note): void {
            $locked = $db->fetch(
                'SELECT audit_status,lifecycle_status,audit_note,supplement_due_at FROM orders WHERE id=:id FOR UPDATE',
                ['id' => $order['id']]
            );
            if (!$locked || $locked['audit_status'] !== 'supplement_required' || $locked['lifecycle_status'] !== 'active') {
                throw new OrderOperationException('订单审核状态已变化，请刷新后重试', 'ORDER_STATE_CHANGED', 409);
            }
            if (!empty($locked['supplement_due_at']) && strtotime((string)$locked['supplement_due_at'] . ' UTC') <= time()) {
                throw new OrderOperationException('补充资料期限已到，订单将由系统取消并进入退款流程', 'SUPPLEMENT_EXPIRED', 409);
            }
            $db->execute(
                "UPDATE orders SET description=:description,quantity_text=:quantity,wall_height_text=:height,
                        audit_status='pending',publish_status='hidden',audited_at=NULL,supplement_due_at=NULL,
                        updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                [
                    'description' => $description !== '' ? $description : null,
                    'quantity' => $quantity !== '' ? $quantity : null,
                    'height' => $height !== '' ? $height : null,
                    'id' => $order['id'],
                ]
            );
            $db->execute(
                "UPDATE order_deadlines SET status='completed',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:id AND deadline_type='audit_supplement_timeout' AND status IN ('pending','blocked','claimed')",
                ['id' => $order['id']]
            );
            $this->event($db, (int)$order['id'], 'order_supplemented', 'user', (int)$actor['user_id'], [
                'message' => '用户已补充订单资料，重新提交管理员审核' . ($note !== '' ? '：' . $note : ''),
                'admin_requirement' => (string)($locked['audit_note'] ?? ''),
            ]);
        });
    }

    public function promotePendingMedia(int $orderId): void
    {
        if ($orderId < 1 || !$this->storage->enabled()) return;
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT m.id,m.public_id,m.storage_key,m.mime_type
             FROM media_files m
             JOIN media_links l ON l.media_file_id=m.id
             WHERE l.entity_type='order' AND l.entity_id=:order_id
               AND l.purpose IN ('product','wall') AND m.status='active' AND m.storage_disk='private_local'",
            ['order_id' => $orderId]
        );
        foreach ($rows as $row) {
            $path = $this->privateMediaPath((string)$row['storage_key']);
            if ($path === null) continue;
            try {
                $stored = $this->storage->store($path, (string)$row['storage_key'], 'order', (string)$row['mime_type']);
                if (($stored['disk'] ?? '') !== 'qiniu' || empty($stored['key'])) continue;
                $changed = $this->db->execute(
                    "UPDATE media_files SET storage_disk='qiniu',storage_key=:key
                     WHERE id=:id AND storage_disk='private_local' AND status='active'",
                    ['key' => (string)$stored['key'], 'id' => (int)$row['id']]
                );
                if ($changed === 1) @unlink($path);
            } catch (Throwable $exception) {
                \write_log('order-media-promotion', '[' . date('c') . '] media=' . (string)$row['public_id'] . ' ' . $exception->getMessage() . "\n");
            }
        }
    }
}
