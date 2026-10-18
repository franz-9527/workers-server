<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Contracts\OrderCenterRepository;
use WorkerAdmin\Contracts\OrderMediaUrlSigner;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** V2.5.0 用户/师傅统一订单中心用例。 */
final class OrderCenterQuery
{
    private OrderCenterRepository $repository;
    private PrivateValueCipher $cipher;
    private OrderMediaUrlSigner $mediaSigner;
    private string $timezone;

    public function __construct(
        OrderCenterRepository $repository,
        PrivateValueCipher $cipher,
        OrderMediaUrlSigner $mediaSigner,
        string $timezone = 'Asia/Shanghai'
    ) {
        $this->repository = $repository;
        $this->cipher = $cipher;
        $this->mediaSigner = $mediaSigner;
        $this->timezone = $timezone !== '' ? $timezone : 'Asia/Shanghai';
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function query(array $actor, string $kind, string $status, int $page, string $keyword = ''): array
    {
        if (!in_array($kind, ['mine', 'worker'], true)) {
            throw new OrderOperationException('订单中心类型无效', 'ORDER_CENTER_KIND_INVALID', 422);
        }
        if (!in_array($status, ['all', 'todo', 'supplement', 'quoting', 'progress', 'completed', 'refunding'], true)) {
            throw new OrderOperationException('订单筛选状态无效', 'ORDER_CENTER_STATUS_INVALID', 422);
        }
        if ($kind === 'worker' && empty($actor['worker_id'])) {
            return ['items' => [], 'page' => max(1, $page), 'status' => $status, 'keyword' => '', 'has_more' => false, 'supplement_count' => 0, 'todo_count' => 0];
        }

        // UX-M3：关键词搜索只开放给订单所有者；师傅侧继续沿用既有关系过滤，不在本轮扩大查询范围。
        $keyword = $kind === 'mine' ? $this->normalizeKeyword($keyword) : '';
        $result = $this->repository->page($actor, $kind, $status, max(1, $page), 20, $keyword);
        $items = [];
        foreach ($result['rows'] as $order) {
            $isOwner = $kind === 'mine' && (int)$order['user_id'] === (int)$actor['user_id'];
            $items[] = $this->card($order, $actor, $kind, $isOwner);
        }

        return [
            'items' => $items,
            'page' => max(1, $page),
            'status' => $status,
            'keyword' => $keyword,
            'has_more' => (bool)$result['has_more'],
            'supplement_count' => $this->repository->supplementCount($actor, $kind),
            'todo_count' => $this->repository->todoCount($actor, $kind),
        ];
    }

    private function normalizeKeyword(string $keyword): string
    {
        $value = trim((string)(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $keyword) ?? $keyword));
        if ($value === '') return '';
        return function_exists('mb_substr') ? (string)mb_substr($value, 0, 80, 'UTF-8') : substr($value, 0, 80);
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $actor @return array<string,mixed> */
    private function card(array $order, array $actor, string $kind, bool $isOwner): array
    {
        $workflow = (string)($order['workflow_profile'] ?? 'construction');
        $address = $isOwner
            ? ($this->cipher->decrypt((string)($order['address_ciphertext'] ?? ''), 'order-address') ?: (string)($order['address_summary'] ?? ''))
            : $this->maskAddress((string)($order['address_summary'] ?? ''));
        $activeRepair = (string)($order['active_repair_status'] ?? '');
        $inviteRequiresQuote = !empty($order['invite_requires_quote']);
        $pricingConfirmationPending = !empty($order['platform_pricing_confirmation_pending']);
        $pricingConfirmed = !empty($order['platform_pricing_confirmed']);
        $serviceAppointmentExists = !empty($order['service_appointment_exists']);
        $constructionAppointmentTodo = (string)($order['order_source_type'] ?? '') === 'platform'
            && $workflow === 'construction'
            && $pricingConfirmed
            && !$serviceAppointmentExists
            && (string)($order['assignment_status'] ?? '') === 'accepted'
            && (string)($order['fulfillment_status'] ?? '') === 'not_started'
            && (string)($order['lifecycle_status'] ?? '') === 'active';
        $canCloseDraft = $kind === 'mine'
            && (string)($order['order_source_type'] ?? 'consumer') !== 'platform'
            && (string)($order['audit_status'] ?? '') === 'draft'
            && in_array((string)($order['payment_status'] ?? ''), ['unpaid', 'awaiting_payment'], true)
            && (string)($order['lifecycle_status'] ?? '') === 'active'
            && empty($order['selected_quote_id']) && empty($order['assigned_worker_id'])
            && in_array((string)($order['fulfillment_status'] ?? 'not_started'), ['', 'not_started'], true);
        $card = [
            'id' => (string)$order['public_no'],
            'title' => (string)($order['title'] ?? ''),
            'description' => (string)($order['description'] ?? ''),
            'workflow_profile' => $workflow,
            'business_type' => $workflow === 'measurement' ? 'measurement' : 'construction',
            'order_source_type' => (string)($order['order_source_type'] ?? 'consumer'),
            'dispatch_mode' => (string)($order['dispatch_mode'] ?? 'multi_quote'),
            'expected_fee_type' => (string)($order['expected_fee_type'] ?? ''),
            'expected_fee_cents' => $order['expected_fee_cents'] === null ? null : (int)$order['expected_fee_cents'],
            'expected_fee_text' => (string)($order['expected_fee_text'] ?? ''),
            'category_name' => $workflow === 'measurement' ? '上门量尺' : (string)((($order['category_names_snapshot'] ?? '') !== '') ? $order['category_names_snapshot'] : ($order['category_name_snapshot'] ?? '')),
            'district' => (string)($order['district'] ?? ''),
            'address' => $address,
            'display_address' => $isOwner ? $address : trim((string)($order['district'] ?? '') . ' ' . $address),
            'is_owner' => $isOwner,
            'is_invited' => !empty($order['is_invited']),
            'invite_requires_quote' => $inviteRequiresQuote,
            'can_close_draft' => $canCloseDraft,
            'quantity_text' => (string)($order['quantity_text'] ?? ''),
            'expected_visit_at' => $this->expectedVisitLabel($order['expected_visit_at'] ?? null) ?: '接单后与业主预约',
            'created_at' => $this->localTime($order['created_at'] ?? null),
            'published_at' => $this->localTime($order['published_at'] ?? null),
            'updated_at' => $this->localTime($order['updated_at'] ?? null),
            'sort_at' => (string)($order['updated_at'] ?? $order['created_at'] ?? ''),
            'quote_count' => (int)($order['quote_count'] ?? 0),
            'own_quote_cents' => $order['own_quote_cents'] === null ? null : (int)$order['own_quote_cents'],
            'fulfillment_status' => (string)($order['fulfillment_status'] ?? 'not_started'),
            'assignment_status' => (string)($order['assignment_status'] ?? 'unassigned'),
            'lifecycle_status' => (string)($order['lifecycle_status'] ?? 'active'),
            'audit_status' => (string)($order['audit_status'] ?? ''),
            'publish_status' => (string)($order['publish_status'] ?? ''),
            'quote_status' => (string)($order['quote_status'] ?? ''),
            'payment_status' => (string)($order['payment_status'] ?? ''),
            'refund_status' => (string)($order['refund_status'] ?? 'none'),
            'selected_quote_id' => empty($order['selected_quote_id']) ? null : (int)$order['selected_quote_id'],
            'center_kind' => $kind,
            'detail_page' => $kind === 'mine' ? 'user' : 'worker',
            'active_repair_status' => $activeRepair,
            'repair_attention' => $activeRepair !== '',
            'platform_pricing_reviewed' => !empty($order['platform_pricing_reviewed']),
            'platform_pricing_confirmation_pending' => $pricingConfirmationPending,
            'platform_pricing_confirmed' => $pricingConfirmed,
            'service_appointment_exists' => $serviceAppointmentExists,
            'construction_appointment_todo' => $constructionAppointmentTodo,
            'cover_url' => !empty($order['cover_media_id'])
                ? $this->mediaSigner->sign((string)$order['cover_media_id'], $actor, (int)$order['id'])
                : '',
        ];

        if ($activeRepair !== '') {
            $card['status_text'] = $workflow === 'measurement' ? '测量售后中' : '维修中';
            $card['phase_text'] = $card['status_text'];
            $card['repair_status_text'] = $workflow === 'measurement' ? '测量售后处理中' : '维修处理中';
        } elseif ($pricingConfirmationPending) {
            $card['status_text'] = $kind === 'worker' ? '等待客户确认价格' : '待确认施工价格';
            $card['phase_text'] = $card['status_text'];
        } elseif ($constructionAppointmentTodo) {
            $card['status_text'] = '待预约施工';
            $card['phase_text'] = '施工准备';
        }
        if ($kind === 'mine') {
            if (($order['audit_status'] ?? '') === 'supplement_required') $card['todo_action'] = '补充订单资料';
            elseif (($order['audit_status'] ?? '') === 'draft' && in_array((string)($order['payment_status'] ?? ''), ['unpaid','awaiting_payment'], true)) $card['todo_action'] = '支付预约费';
            elseif ($pricingConfirmationPending) $card['todo_action'] = '确认施工价格';
            elseif (!empty($order['pending_change_id'])) $card['todo_action'] = '处理费用调整';
            elseif (($order['pending_run_empty_status'] ?? '') === 'pending_confirmation') $card['todo_action'] = '确认跑空费';
            elseif (($order['pending_run_empty_status'] ?? '') === 'awaiting_payment') $card['todo_action'] = '支付跑空费';
            elseif (($order['fulfillment_status'] ?? '') === 'submitted') $card['todo_action'] = '确认完工';
            else $card['todo_action'] = '选择报价';
        } else {
            if ($inviteRequiresQuote) {
                $card['todo_action'] = '提交工费报价';
                $card['status_text'] = '受邀待报价';
            } elseif (($order['assignment_status'] ?? '') === 'offered') {
                $card['todo_action'] = '确认接单';
            } elseif ($constructionAppointmentTodo) {
                $card['todo_action'] = '预约施工';
            } elseif ((int)($order['pending_repair_count'] ?? 0) > 0) {
                $card['todo_action'] = $workflow === 'measurement' ? '处理测量售后' : '处理待维修';
            }
        }
        return $card;
    }

    private function maskAddress(string $address): string
    {
        $value = trim($address);
        if ($value === '') return '';
        $masked = preg_replace('/\d+[A-Za-z]?\s*(?=幢|栋|号楼)/u', '***', $value);
        $masked = preg_replace('/\d+\s*(?=单元|室|号|楼层|层)/u', '*', (string)$masked);
        $masked = preg_replace_callback('/(小区|村|社区|公寓|花园|苑|广场|大厦|园区)([^\r\n]*)/u', static function (array $matches): string {
            return $matches[1] . (preg_replace('/\d+/u', '*', $matches[2]) ?: $matches[2]);
        }, (string)$masked);
        return $masked ?: $value;
    }

    private function localTime($value): ?string
    {
        if (!$value) return null;
        try {
            return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d H:i');
        } catch (Throwable $exception) {
            return (string)$value;
        }
    }

    private function expectedVisitLabel($value): ?string
    {
        if (!$value) return null;
        try {
            $date = (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($this->timezone));
            $hour = (int)$date->format('H');
            $period = $hour < 12 ? '上午' : ($hour < 18 ? '下午' : '晚上');
            return $date->format('Y-m-d') . ' ' . $period;
        } catch (Throwable $exception) {
            return (string)$value;
        }
    }
}
