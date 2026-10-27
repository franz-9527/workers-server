<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\OrderChangeRepository;
use WorkerAdmin\Contracts\OrderCompletionRepository;
use WorkerAdmin\Contracts\OrderFulfillmentContextRepository;
use WorkerAdmin\Contracts\OrderMeasurementCompletionRepository;
use WorkerAdmin\Contracts\OrderPricingRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Domain\Order\OrderOperationException;

/**
 * V2.5.0 统一履约核心入口。
 *
 * consumer / merchant / platform 共用同一路由和 orders 状态机；来源差异由 Repository
 * 按服务费/平台收入款语义处理，不在 Http 层分流。
 */
final class OrderFulfillment
{
    private OrderFulfillmentContextRepository $context;
    private OrderChangeRepository $changes;
    private OrderCompletionRepository $completion;
    private OrderMeasurementCompletionRepository $measurement;
    private OrderPricingRepository $pricing;
    private SettingsProvider $settings;

    public function __construct(
        OrderFulfillmentContextRepository $context,
        OrderChangeRepository $changes,
        OrderCompletionRepository $completion,
        OrderMeasurementCompletionRepository $measurement,
        OrderPricingRepository $pricing,
        SettingsProvider $settings
    ) {
        $this->context = $context;
        $this->changes = $changes;
        $this->completion = $completion;
        $this->measurement = $measurement;
        $this->pricing = $pricing;
        $this->settings = $settings;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array{message:string,data:array<string,mixed>} */
    public function execute(string $route, array $actor, array $body): array
    {
        if (!in_array($route, ['changes.create','changes.decide','orders.measurement.submit','orders.measurement.rework','fulfillment.pricing.update','fulfillment.complete','fulfillment.accept'], true)) {
            throw new OrderOperationException('履约操作不存在', 'ORDER_FULFILLMENT_ROUTE_NOT_FOUND', 404);
        }

        if ($route === 'changes.create') return $this->createChange($actor, $body);
        if ($route === 'changes.decide') return $this->decideChange($actor, $body);
        if ($route === 'orders.measurement.submit' || $route === 'fulfillment.complete') return $this->complete($actor, $body);
        if ($route === 'orders.measurement.rework') return $this->requestMeasurementRework($actor, $body);
        if ($route === 'fulfillment.pricing.update') return $this->updatePricing($actor, $body);
        return $this->accept($actor, $body);
    }

    private function createChange(array $actor, array $body): array
    {
        $order = $this->context->order(trim((string)($body['order_id'] ?? '')));
        $this->assertPlatformConstructionPricingConfirmed($order);
        if ((int)($actor['worker_id'] ?? 0) < 1) {
            throw new OrderOperationException('请先完成师傅入驻并登录师傅身份', 'WORKER_REQUIRED', 403);
        }
        $added = (int)($body['added_amount_cents'] ?? 0);
        $reason = mb_substr(trim((string)($body['reason'] ?? '')), 0, 1000);
        if ($added < 1 || $added > 100000000) throw new OrderOperationException('请输入正确的加价金额', 'CHANGE_AMOUNT_INVALID', 422);
        if (mb_strlen($reason) < 4) {
            $message = (string)($order['order_source_type'] ?? '') === 'platform'
                ? '请说明新增施工内容或现场情况'
                : '请说明现场难度或特殊情况';
            throw new OrderOperationException($message, 'CHANGE_REASON_REQUIRED', 422);
        }
        $data = $this->changes->create($actor, $order, $added, $reason);
        $message = (string)($data['message'] ?? '加价申请已提交，请联系用户协商并等待处理');
        unset($data['message']);
        return ['message'=>$message,'data'=>$data];
    }

    private function decideChange(array $actor, array $body): array
    {
        $changeId = trim((string)($body['change_id'] ?? ''));
        if ($changeId === '') throw new OrderOperationException('加价申请编号缺失', 'CHANGE_ID_REQUIRED', 422);
        $order = $this->context->orderByChange($changeId);
        $decision = (string)($body['decision'] ?? '');
        if (!in_array($decision, ['accepted','rejected'], true)) {
            throw new OrderOperationException('加价处理结果不正确', 'CHANGE_DECISION_INVALID', 422);
        }
        $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 500);
        $data = $this->changes->decide($actor, $order, $changeId, $decision, $note);
        $message = (string)($data['message'] ?? ($decision === 'accepted' ? '已同意加价' : '已拒绝加价'));
        unset($data['message']);
        return ['message'=>$message,'data'=>$data];
    }

    private function requestMeasurementRework(array $actor, array $body): array
    {
        $order = $this->context->order(trim((string)($body['order_id'] ?? '')));
        $reason = mb_substr(trim((string)($body['reason'] ?? '')), 0, 500);
        $data = $this->measurement->requestRework($actor, $order, $reason);
        return [
            'message'=>(string)($order['order_source_type'] ?? '') === 'platform'
                ? '已退回补测；平台单只允许本次补测，师傅可继续修改并重新提交'
                : '已退回补测，师傅可以继续修改空间和图片',
            'data'=>$data,
        ];
    }

    private function updatePricing(array $actor, array $body): array
    {
        $order = $this->context->order(trim((string)($body['order_id'] ?? '')));
        if ((string)($order['order_source_type'] ?? '') !== 'platform') {
            throw new OrderOperationException('只有平台承接单支持按实际用量重算', 'PLATFORM_ORDER_REQUIRED', 409);
        }
        $this->assertPlatformConstructionPricingConfirmed($order);
        if ((int)($actor['worker_id'] ?? 0) < 1) {
            throw new OrderOperationException('请先完成师傅入驻并登录师傅身份', 'WORKER_REQUIRED', 403);
        }
        $rawItems = is_array($body['items'] ?? null) ? $body['items'] : [];
        if (!$rawItems || count($rawItems) > 50) {
            throw new OrderOperationException('请提交 1 至 50 个实际用量项目', 'PLATFORM_PRICING_ITEMS_INVALID', 422);
        }

        $items = [];
        $seen = [];
        foreach ($rawItems as $raw) {
            if (!is_array($raw)) throw new OrderOperationException('实际用量项目格式不正确', 'PLATFORM_PRICING_ITEM_INVALID', 422);
            $sortOrder = (int)($raw['sort_order'] ?? 0);
            $quantityMilli = $this->quantityToMilli($raw['quantity'] ?? null);
            if ($sortOrder < 1 || isset($seen[$sortOrder]) || $quantityMilli < 1) {
                throw new OrderOperationException('实际用量项目编号或数量不正确；数量最多保留 3 位小数', 'PLATFORM_PRICING_ITEM_INVALID', 422);
            }
            $seen[$sortOrder] = true;
            $items[] = ['sort_order'=>$sortOrder,'quantity_milli'=>$quantityMilli];
        }

        $data = $this->pricing->updateActualQuantities($actor, $order, $items);
        return [
            'message'=>'实际用量已更新，已按原约定单价重新计算客户金额；本次不属于加价',
            'data'=>$data,
        ];
    }

    private function complete(array $actor, array $body): array
    {
        $order = $this->context->order(trim((string)($body['order_id'] ?? '')));
        if ((int)($actor['worker_id'] ?? 0) < 1) {
            throw new OrderOperationException('请先完成师傅入驻并登录师傅身份', 'WORKER_REQUIRED', 403);
        }
        if ((string)($order['workflow_profile'] ?? 'construction') === 'measurement') {
            $hours = max(1, min(720, (int)$this->settings->get('measurement.auto_accept_hours', 72)));
            $data = $this->measurement->complete($actor, $order, $hours);
            $message = !empty($data['awaiting_pricing_review'])
                ? '平台单测量成果已提交并确认，等待平台核价后进入施工阶段'
                : (!empty($data['advanced_to_construction'])
                    ? '测量成果已提交，同一平台订单已进入施工阶段'
                    : '测量成果已提交，等待用户确认');
            return ['message'=>$message,'data'=>$data];
        }

        $this->assertPlatformConstructionPricingConfirmed($order);
        $description = mb_substr(trim((string)($body['description'] ?? '')), 0, 1000);
        $warrantyMonths = max(0, (int)($body['warranty_months'] ?? 0));
        if ($warrantyMonths > 120) {
            throw new OrderOperationException('维修保障期限最多 120 个月，也可以不填写', 'WARRANTY_MONTHS_INVALID', 422);
        }
        $warrantyNote = mb_substr(trim((string)($body['warranty_note'] ?? '')), 0, 500);
        $hours = max(1, min(720, (int)$this->settings->get('order.auto_accept_hours', 72)));
        $data = $this->completion->complete($actor, $order, $description, $warrantyMonths, $warrantyNote, $hours);
        $message = (string)($data['message'] ?? '完工已提交，等待用户验收');
        unset($data['message']);
        return ['message'=>$message,'data'=>$data];
    }

    private function accept(array $actor, array $body): array
    {
        $order = $this->context->order(trim((string)($body['order_id'] ?? '')));
        if ((int)($order['user_id'] ?? 0) !== (int)($actor['user_id'] ?? 0)) {
            throw new OrderOperationException('只能验收自己的订单', 'FORBIDDEN', 403);
        }
        $reviewDays = max(1, min(30, (int)$this->settings->get('order.auto_review_days', 5)));
        if ((string)($order['workflow_profile'] ?? 'construction') === 'measurement') {
            $freezeDays = max(1, min(36500, (int)$this->settings->get('measurement.default_freeze_days', 15)));
            $maxFreezeDays = max($freezeDays, min(36500, (int)$this->settings->get('measurement.max_freeze_days', 30)));
            $data = $this->measurement->accept($actor, $order, $reviewDays, $freezeDays, $maxFreezeDays);
            return [
                'message'=>!empty($data['platform_measurement'])
                    ? '测量成果已确认，等待平台核价后进入施工阶段'
                    : '测量成果已确认，测量费已进入冻结期',
                'data'=>$data,
            ];
        }
        $data = $this->completion->accept($actor, $order, $reviewDays);
        $message = (string)($data['message'] ?? '验收完成，感谢使用');
        unset($data['message']);
        return ['message'=>$message,'data'=>$data];
    }

    /** @param array<string,mixed> $order */
    private function assertPlatformConstructionPricingConfirmed(array $order): void
    {
        if ((string)($order['order_source_type'] ?? '') !== 'platform'
            || (string)($order['workflow_profile'] ?? '') !== 'construction'
            || empty($order['platform_pricing_reviewed'])) {
            return;
        }
        if (!empty($order['platform_pricing_confirmation_pending']) || empty($order['platform_pricing_confirmed'])) {
            throw new OrderOperationException(
                '客户尚未确认核价后的施工价格，暂不能预约后的施工操作',
                'PLATFORM_PRICING_CONFIRM_REQUIRED',
                409
            );
        }
    }

    /** @param mixed $value */
    private function quantityToMilli($value): int
    {
        if (!is_scalar($value)) return 0;
        $text = trim((string)$value);
        if (!preg_match('/^(\d{1,9})(?:\.(\d{1,3}))?$/D', $text, $parts)) return 0;
        $milli = ((int)$parts[1]) * 1000 + (int)str_pad($parts[2] ?? '', 3, '0');
        return $milli > 999999999999 ? 0 : $milli;
    }
}
