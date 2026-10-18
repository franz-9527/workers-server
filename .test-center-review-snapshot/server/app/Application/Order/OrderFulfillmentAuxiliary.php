<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Contracts\OrderFulfillmentAuxiliaryRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 到场异常、测量空间/可关联结果、维修申请的板块级用例族。 */
final class OrderFulfillmentAuxiliary
{
    private OrderFulfillmentAuxiliaryRepository $repository;
    private string $timezone;

    public function __construct(OrderFulfillmentAuxiliaryRepository $repository, string $timezone = 'Asia/Shanghai')
    {
        $this->repository = $repository;
        $this->timezone = $timezone;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $input @return array<string,mixed> */
    public function execute(string $route, array $actor, array $input): array
    {
        switch ($route) {
            case 'arrivals.report':
                return $this->reportArrival($actor, $input);
            case 'orders.measurement.space.save':
                return $this->saveMeasurementSpace($actor, $input);
            case 'orders.measurement.space.delete':
                return $this->deleteMeasurementSpace($actor, $input);
            case 'orders.measurement.links.eligible':
                return $this->eligibleMeasurementLinks($actor);
            case 'after_sales.repair.create':
                return $this->createRepair($actor, $input);
            case 'after_sales.repair.withdraw':
                return $this->withdrawRepair($actor, $input);
        }
        throw new OrderOperationException('不支持该履约附属操作', 'ORDER_AUXILIARY_ROUTE_INVALID', 404);
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $input @return array<string,mixed> */
    private function reportArrival(array $actor, array $input): array
    {
        $description = mb_substr(trim((string)($input['description'] ?? '')), 0, 1000);
        if (mb_strlen($description) < 4) {
            throw new OrderOperationException('请简要说明到场后遇到的问题', 'DESCRIPTION_REQUIRED', 422);
        }
        $lat = is_numeric($input['lat'] ?? null) ? (float)$input['lat'] : null;
        $lng = is_numeric($input['lng'] ?? null) ? (float)$input['lng'] : null;
        if (!$this->validCoordinate($lat, $lng)) {
            throw new OrderOperationException('提交到场异常必须取得实时定位，请开启微信定位权限后重试', 'ARRIVAL_LOCATION_REQUIRED', 422);
        }
        $command = [
            'order_id'=>trim((string)($input['order_id'] ?? '')),
            'description'=>$description,
            'exception_type'=>in_array((string)($input['exception_type'] ?? ''), ['cannot_contact','cannot_work','other'], true)
                ? (string)$input['exception_type'] : 'other',
            'lat'=>$lat,
            'lng'=>$lng,
        ];
        $this->repository->reportArrival($actor, $command);
        return ['message'=>'到场情况已提交，请联系客服处理'];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $input @return array<string,mixed> */
    private function saveMeasurementSpace(array $actor, array $input): array
    {
        $name = mb_substr(trim((string)($input['name'] ?? '')), 0, 120);
        if ($name === '') throw new OrderOperationException('请填写空间名称', 'MEASUREMENT_SPACE_NAME_REQUIRED', 422);
        $spaceId = trim((string)($input['space_id'] ?? ''));
        $data = $this->repository->saveMeasurementSpace($actor, [
            'order_id'=>trim((string)($input['order_id'] ?? '')),
            'name'=>$name,
            'space_id'=>$spaceId,
            'sort_order'=>max(0, min(999, (int)($input['sort_order'] ?? 0))),
        ]);
        return ['message'=>$spaceId !== '' ? '空间已更新' : '空间已添加', 'data'=>$data];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $input @return array<string,mixed> */
    private function deleteMeasurementSpace(array $actor, array $input): array
    {
        $orderNo = trim((string)($input['order_id'] ?? ''));
        $spaceId = trim((string)($input['space_id'] ?? ''));
        if ($spaceId === '') throw new OrderOperationException('测量空间不存在或已经删除', 'MEASUREMENT_SPACE_NOT_FOUND', 404);
        $this->repository->deleteMeasurementSpace($actor, $orderNo, $spaceId);
        return ['message'=>'空间已删除'];
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function eligibleMeasurementLinks(array $actor): array
    {
        $items = $this->repository->eligibleMeasurementLinks($actor);
        foreach ($items as &$item) {
            $item['hold_until'] = $this->localTime($item['hold_until'] ?? null);
        }
        unset($item);
        return ['data'=>['items'=>$items]];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $input @return array<string,mixed> */
    private function createRepair(array $actor, array $input): array
    {
        $description = mb_substr(trim((string)($input['description'] ?? '')), 0, 2000);
        if (mb_strlen($description) < 6) {
            throw new OrderOperationException('请具体说明需要维修的问题，至少 6 个字', 'REPAIR_DESCRIPTION_REQUIRED', 422);
        }
        $evidence = array_values(array_unique(array_filter(array_map('strval', (array)($input['evidence_ids'] ?? [])))));
        if (count($evidence) > 6) throw new OrderOperationException('维修证据图片最多 6 张', 'TOO_MANY_REPAIR_IMAGES', 422);
        $data = $this->repository->createRepair($actor, [
            'order_id'=>trim((string)($input['order_id'] ?? '')),
            'description'=>$description,
            'evidence_ids'=>$evidence,
            'non_human_confirmed'=>!empty($input['non_human_confirmed']),
        ]);
        return ['message'=>'维修申请已提交并通知原成交师傅，请等待师傅联系或提交维修预约','data'=>$data];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $input @return array<string,mixed> */
    private function withdrawRepair(array $actor, array $input): array
    {
        $this->repository->withdrawRepair(
            $actor,
            trim((string)($input['order_id'] ?? '')),
            trim((string)($input['after_sale_id'] ?? ''))
        );
        return ['message'=>'维修申请已撤回'];
    }

    private function validCoordinate(?float $lat, ?float $lng): bool
    {
        return $lat !== null && $lng !== null && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
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
}
