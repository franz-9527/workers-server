<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Application\Identity\DemoActorGuard;
use WorkerAdmin\Contracts\OrderAgreementProvider;
use WorkerAdmin\Contracts\OrderSubmissionRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** V2.5.0 订单提交应用用例：表单、草稿、最终提交与补资料。 */
final class OrderSubmission
{
    private OrderSubmissionRepository $repository;
    private OrderAgreementProvider $agreements;
    private SettingsProvider $settings;
    private ?\WorkerAdmin\Contracts\OrderProductReuseRepository $products;

    public function __construct(
        OrderSubmissionRepository $repository,
        OrderAgreementProvider $agreements,
        SettingsProvider $settings,
        ?\WorkerAdmin\Contracts\OrderProductReuseRepository $products = null
    ) {
        $this->repository = $repository;
        $this->agreements = $agreements;
        $this->settings = $settings;
        $this->products = $products;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function form(array $actor): array
    {
        $context = $this->repository->formContext($actor);
        $context['agreements'] = $this->agreements->orderSubmitAgreements();
        return $context;
    }

    public function productHistory(array $actor, array $query): array
    {
        return $this->products ? $this->products->history($actor, $query) : ['items'=>[], 'has_more'=>false];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array<string,mixed> */
    public function create(array $actor, array $body): array
    {
        DemoActorGuard::requireProductionOrder($actor);
        if ($this->settings->bool('development.pause_orders', false)) {
            throw new OrderOperationException('系统维护中，暂时停止下单；已有订单仍可正常处理', 'ORDERS_PAUSED', 503);
        }
        $created = $this->repository->createDraft($actor, $body);
        return [
            'message' => !empty($created['restored']) ? '已恢复原订单草稿' : '草稿已保存，请继续上传图片',
            'data' => $created,
        ];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array<string,mixed> */
    public function finalize(array $actor, array $body): array
    {
        DemoActorGuard::requireProductionOrder($actor);
        $order = $this->repository->findOwnedOrder($actor, (string)($body['order_id'] ?? ''));
        if (!$order) {
            throw new OrderOperationException('订单不存在或无权操作', 'ORDER_NOT_FOUND', 404);
        }
        if ($order['audit_status'] === 'pending' || $order['payment_status'] === 'paid') {
            return [
                'message' => $order['audit_status'] === 'approved' ? '预约费已支付，订单已进入报价阶段' : '预约费已支付，订单已提交管理员审核',
                'data' => ['id' => (string)$order['public_no'], 'payment_required' => false],
            ];
        }
        if ($order['audit_status'] !== 'draft') {
            throw new OrderOperationException('该订单已经提交过', 'ORDER_ALREADY_SUBMITTED', 409);
        }

        $consent = is_array($body['agreements'] ?? null) ? $body['agreements'] : [];
        if (empty($consent['accepted'])) {
            throw new OrderOperationException('请先阅读并确认平台规则、隐私说明和预约费规则', 'ORDER_AGREEMENT_REQUIRED', 422);
        }
        // 尚未正式运营：直接以 version 作为实际发布版本，不再保留 published_versions / 技术兼容版本双轨。
        $rawVersions = is_array($consent['versions'] ?? null) ? $consent['versions'] : [];
        $versions = [];
        foreach ($rawVersions as $key => $version) {
            if (is_string($key) && is_scalar($version)) {
                $key = trim($key);
                $version = trim((string)$version);
                if ($key !== '' && $version !== '') $versions[$key] = $version;
            }
        }
        if (!$versions) {
            throw new OrderOperationException('缺少协议发布版本，请重新打开下单页', 'ORDER_AGREEMENT_VERSION_REQUIRED', 409);
        }
        $check = $this->agreements->validatePublishedVersions($versions);
        if (empty($check['ok'])) {
            throw new OrderOperationException(
                '协议或平台规则已更新，请重新打开下单页阅读最新版本后再提交',
                'AGREEMENT_VERSION_CHANGED',
                409,
                ['missing' => $check['missing'] ?? [], 'outdated' => $check['outdated'] ?? []]
            );
        }

        $counts = $this->repository->mediaCounts((int)$order['id']);
        if ((string)($order['workflow_profile'] ?? 'construction') !== 'measurement') {
            foreach (['product', 'wall'] as $purpose) {
                $rule = $this->repository->imageRule((int)($order['form_schema_version_id'] ?? 0), $purpose);
                $count = (int)($counts[$purpose] ?? 0);
                if ($rule['active'] && $count < $rule['min']) {
                    throw new OrderOperationException('“' . $rule['label'] . '”至少需要上传 ' . $rule['min'] . ' 张', 'ORDER_IMAGES_REQUIRED', 422, ['purpose' => $purpose]);
                }
                if ($rule['active'] && $count > $rule['max']) {
                    throw new OrderOperationException('“' . $rule['label'] . '”最多允许上传 ' . $rule['max'] . ' 张，请删除多余图片后重试', 'ORDER_IMAGES_TOO_MANY', 422, ['purpose' => $purpose]);
                }
            }
        }

        // 七牛迁移由 scheduler 的 media_promote 异步完成；这里不再等待对象存储网络请求。
        $this->repository->markReadyForPayment($actor, $order, $versions, $counts);
        return [
            'message' => '资料已保存，请支付预约费后提交审核',
            'data' => [
                'id' => (string)$order['public_no'],
                'payment_required' => true,
                'amount_cents' => (int)$order['booking_fee_cents'],
            ],
        ];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array<string,mixed> */
    public function supplement(array $actor, array $body): array
    {
        $this->repository->supplement($actor, $body);
        return ['message' => '资料已补充，订单已重新提交审核', 'data' => []];
    }
}
