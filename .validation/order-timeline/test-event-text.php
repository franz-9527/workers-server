<?php
declare(strict_types=1);

require __DIR__ . '/DatabaseOrderDetailRepository.php';

$class = new ReflectionClass(\WorkerAdmin\Infrastructure\Persistence\DatabaseOrderDetailRepository::class);
$repository = $class->newInstanceWithoutConstructor();
$method = $class->getMethod('eventText');
$method->setAccessible(true);

$cases = [
    ['worker_quoted', [], '师傅已报价'],
    ['worker_quoted', ['message' => 'Worker quote submitted'], '师傅已报价'],
    ['worker_quoted', ['message' => '师傅已报价'], '师傅已报价'],
    ['worker_quoted', ['message' => '师傅已报价 by worker'], '师傅已报价'],
    ['unknown_event_code', ['message' => 'system event updated'], '订单进度已更新'],
    ['booking_fee_paid', ['amount_cents' => 5000], '预约费已支付 ¥50.00'],
    ['assignment_expired', ['message' => '派单超时，系统已释放。', 'amount_cents' => 10000], '派单超时，系统已释放。'],
];

foreach ($cases as [$eventType, $payload, $expected]) {
    $actual = $method->invoke($repository, $eventType, $payload);
    if ($actual !== $expected) {
        throw new RuntimeException($eventType . ': expected [' . $expected . '], got [' . $actual . ']');
    }
    if (preg_match('/[A-Za-z]/', $actual) === 1) {
        throw new RuntimeException($eventType . ': English characters remain in timeline text: ' . $actual);
    }
}

echo "Order timeline Chinese text regression passed (" . count($cases) . " cases).\n";
