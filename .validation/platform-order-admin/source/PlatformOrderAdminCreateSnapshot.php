<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

final class OrderOperationException extends \RuntimeException
{
    public string $businessCode;
    public int $httpStatus;
    public function __construct(string $message,string $businessCode,int $httpStatus,array $context=[])
    {
        parent::__construct($message);
        $this->businessCode=$businessCode;
        $this->httpStatus=$httpStatus;
    }
}

final class PlatformOrderAdminCreateSnapshot
{
    public function create(array $input, int $adminId, array $operator = []): int
    {
        $userId = max(0, (int)($input['user_id'] ?? 0));
        $rawCategoryIds = is_array($input['category_ids'] ?? null) ? $input['category_ids'] : [$input['category_id'] ?? 0];
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $rawCategoryIds), static fn(int $id): bool => $id > 0)));
        if (count($categoryIds) > 6) throw new OrderOperationException('一个订单最多选择 6 个类目。', 'TOO_MANY_CATEGORIES', 422);
        if (!$categoryIds) throw new OrderOperationException('请选择至少一个有效服务类目。', 'PLATFORM_CATEGORY_REQUIRED', 422);
        $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
        $answers['category_ids'] = $categoryIds;
        $answers['category_mode'] = 'listed';

        $fulfillmentMode = (string)($input['fulfillment_mode'] ?? 'direct_install');
        if (!in_array($fulfillmentMode, ['direct_install','measure_then_install'], true)) {
            throw new OrderOperationException('履约方式无效。', 'PLATFORM_FULFILLMENT_MODE_INVALID', 422);
        }

        $title = mb_substr(trim((string)($input['title'] ?? '')), 0, 160);
        $description = mb_substr(trim((string)($input['description'] ?? '')), 0, 2000);
        $answers['requirement_note'] = $description;
        $province = mb_substr(trim((string)($input['province'] ?? '')), 0, 64);
        $city = mb_substr(trim((string)($input['city'] ?? '')), 0, 64);
        $district = mb_substr(trim((string)($input['district'] ?? '')), 0, 64);
        $address = mb_substr(trim((string)($input['address'] ?? '')), 0, 255);
        $contactName = mb_substr(trim((string)($input['contact_name'] ?? '')), 0, 80);
        $contactMobile = preg_replace('/\s+/u', '', trim((string)($input['contact_mobile'] ?? ''))) ?: '';
        if ($title === '' || $city === '' || $district === '' || $address === '') {
            throw new OrderOperationException('请完整填写订单标题、城市、区域和施工地址。', 'PLATFORM_ORDER_FIELDS_REQUIRED', 422);
        }
        if ($contactName === '' || preg_match('/^1\d{10}(?:(?:-|转)\d{1,10})?$/u', $contactMobile) !== 1) {
            throw new OrderOperationException('请填写有效联系人和手机号；虚拟号可在 11 位手机号后填写转拨号。', 'PLATFORM_CONTACT_INVALID', 422);
        }

        [$lat,$lng] = $this->coordinatePair($input['map_lat'] ?? null, $input['map_lng'] ?? null);
        $mapPointName = mb_substr(trim((string)($input['map_point_name'] ?? '')), 0, 160);
        $mapPointAddress = mb_substr(trim((string)($input['map_point_address'] ?? '')), 0, 255);

        $pricingMode = (string)($input['customer_pricing_mode'] ?? 'fixed_total');
        if (!in_array($pricingMode, ['fixed_total', 'itemized'], true)) {
            throw new OrderOperationException('客户计价方式无效。', 'PLATFORM_PRICING_MODE_INVALID', 422);
        }
        $items = $pricingMode === 'itemized' ? $this->pricingItems($input) : [];
        $customerTotal = $pricingMode === 'itemized'
            ? array_sum(array_column($items, 'amount_cents'))
            : $this->moneyToCents((string)($input['customer_total'] ?? ''));
        if ($customerTotal <= 0 || $customerTotal > 100000000) {
            throw new OrderOperationException('客户成交金额必须大于 0 且不能超过 100 万元。', 'PLATFORM_CUSTOMER_TOTAL_INVALID', 422);
        }

        $prepaymentRaw = trim(str_replace([',','￥','¥','元'], '', (string)($input['external_prepayment'] ?? '0')));
        if (!preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/D', $prepaymentRaw)) {
            throw new OrderOperationException('外部已收预付款金额格式不正确。', 'PLATFORM_BOOKING_AMOUNT_INVALID', 422);
        }
        $externalPrepayment = $this->moneyToCents($prepaymentRaw);
        if ($externalPrepayment < 0 || $externalPrepayment > $customerTotal) {
            throw new OrderOperationException('外部已收预付款不能超过客户成交金额。', 'PLATFORM_TOTAL_BELOW_BOOKING', 422);
        }

        $dispatchMode = (string)($input['dispatch_mode'] ?? 'multi_quote');
        if (!in_array($dispatchMode, ['multi_quote', 'single_grab'], true)) {
            throw new OrderOperationException('师傅成交方式无效。', 'PLATFORM_DISPATCH_MODE_INVALID', 422);
        }
        $settlementMode = (string)($input['worker_settlement_mode'] ?? PlatformOrderSettlementPolicy::MODE_WORKER_COLLECT);
        if (!in_array($settlementMode, [PlatformOrderSettlementPolicy::MODE_WORKER_COLLECT, PlatformOrderSettlementPolicy::MODE_PLATFORM_BALANCE], true)) {
            throw new OrderOperationException('工费结算方式无效。', 'PLATFORM_SETTLEMENT_MODE_INVALID', 422);
        }
        $workerLabor = $dispatchMode === 'single_grab' ? $this->moneyToCents((string)($input['worker_labor'] ?? '')) : 0;
        if ($dispatchMode === 'single_grab' && ($workerLabor <= 0 || $workerLabor > 100000000)) {
            throw new OrderOperationException('一口价抢单必须填写师傅工费。', 'PLATFORM_LABOR_REQUIRED', 422);
        }
        if ($settlementMode === PlatformOrderSettlementPolicy::MODE_WORKER_COLLECT && $workerLabor > 0) {
            $this->settlementPolicy->settlement($customerTotal, $workerLabor, $externalPrepayment, $settlementMode);
        }

        $defaultRate = max(0, min(10000, (int)$this->settings->get('platform_order.extra_income_rate_bp', 2000)));
        $extraIncomeMode = (string)($input['extra_income_mode'] ?? 'rate');
        if (!in_array($extraIncomeMode, ['rate','fixed'], true)) {
            throw new OrderOperationException('加项平台收入方式无效。', 'PLATFORM_EXTRA_INCOME_MODE_INVALID', 422);
        }
        if ($extraIncomeMode === 'fixed') {
            $fixedIncome = $this->moneyToCents((string)($input['extra_income_fixed'] ?? ''));
            if ($fixedIncome < 0 || $fixedIncome > 100000000) {
                throw new OrderOperationException('固定平台收入金额不能超过 100 万元。', 'PLATFORM_EXTRA_FIXED_INVALID', 422);
            }
            $extraRateBp = PlatformOrderSettlementPolicy::EXTRA_INCOME_FIXED_OFFSET + $fixedIncome;
        } else {
            $extraRateBp = array_key_exists('extra_income_rate_percent', $input)
                ? $this->percentToBasisPoints((string)$input['extra_income_rate_percent'])
                : $defaultRate;
        }
        $freezeDays = max(0, min(365, (int)$this->settings->get('platform_order.worker_balance_freeze_days', 0)));

        return $this->repository->create([
            'operator_channel'=>($operator['channel'] ?? '') === 'mini_admin' ? 'mini_admin' : 'web_admin',
            'operator_user_id'=>($operator['channel'] ?? '') === 'mini_admin' ? max(0, (int)($operator['user_id'] ?? 0)) : 0,
            'user_id'=>$userId,
            'customer_flow_mode'=>'platform_managed',
            'fulfillment_mode'=>$fulfillmentMode,
            'category_id'=>$categoryIds[0],
            'category_ids'=>$categoryIds,
            'answers'=>$answers,
            'title'=>$title,
            'description'=>$description,
            'province'=>$province,
            'city'=>$city,
            'district'=>$district,
            'address'=>$address,
            'lat'=>$lat,
            'lng'=>$lng,
            'map_point_name'=>$mapPointName,
            'map_point_address'=>$mapPointAddress,
            'contact_name'=>$contactName,
            'contact_mobile'=>$contactMobile,
            'customer_pricing_mode'=>$pricingMode,
            'customer_total_cents'=>$customerTotal,
            'pricing_items'=>$items,
            'external_prepayment_cents'=>$externalPrepayment,
            'dispatch_mode'=>$dispatchMode,
            'worker_settlement_mode'=>$settlementMode,
            'worker_labor_cents'=>$workerLabor,
            'extra_income_rate_bp'=>$extraRateBp,
            'worker_balance_freeze_days'=>$freezeDays,
        ], $adminId);
    }

}
