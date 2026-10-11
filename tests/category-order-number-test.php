<?php

declare(strict_types=1);

function candidate_order_business_no($categoryCode, string $date = '261011'): string
{
    $code = strtoupper(preg_replace('/[^A-Z]/i', '', (string)$categoryCode));
    $code = strlen($code) === 2 ? $code : 'QT';
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $suffix = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < 6; $i++) {
        try {
            $index = random_int(0, $max);
        } catch (Throwable $exception) {
            $index = mt_rand(0, $max);
        }
        $suffix .= $alphabet[$index];
    }
    return 'BX' . $code . $date . '-' . $suffix;
}

function candidate_category_order_code($value): string
{
    $code = strtoupper(trim((string)$value));
    if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
        throw new RuntimeException('订单号类目码必须为 2 位大写英文字母');
    }
    return $code;
}

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$seen = [];
foreach (['QB', 'QZ', 'wp', 'A1', '', '墙布'] as $raw) {
    $number = candidate_order_business_no($raw);
    $assert(
        preg_match('/^BX[A-Z]{2}\d{6}-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/', $number) === 1,
        '订单号格式不正确：' . $number
    );
    $seen[$number] = true;
}
$assert(count($seen) === 6, '小样本随机码不应碰撞');
$assert(candidate_category_order_code(' qb ') === 'QB', '类目码应自动转大写并去空格');

foreach (['Q', 'Q1', 'QBB', '墙布'] as $bad) {
    $rejected = false;
    try {
        candidate_category_order_code($bad);
    } catch (RuntimeException $exception) {
        $rejected = true;
    }
    $assert($rejected, '非法类目码未被拒绝：' . $bad);
}

echo "category/order candidate OK\n";
