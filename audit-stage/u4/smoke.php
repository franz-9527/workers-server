<?php

declare(strict_types=1);

use WorkerAdmin\Application\Order\OrderDraftClosure;
use WorkerAdmin\Contracts\OrderDraftClosureRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

$root = __DIR__;
require_once $root.'/server/app/Contracts/OrderDraftClosureRepository.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Application/Order/OrderDraftClosure.php';

function ok(bool $condition, string $label): void {
    if (!$condition) { fwrite(STDERR, "[FAIL] {$label}\n"); exit(1); }
    fwrite(STDOUT, "[PASS] {$label}\n");
}

final class FakeDraftRepo implements OrderDraftClosureRepository {
    public array $calls = [];
    public array $result = ['order_id'=>'BXQZ-U4','already_closed'=>false];
    public function close(array $actor, string $orderNo): array { $this->calls[] = [$actor,$orderNo]; return $this->result; }
}

$repo = new FakeDraftRepo();
$app = new OrderDraftClosure($repo);
$result = $app->close(['user_id'=>7], ['order_id'=>' BXQZ-U4 ']);
ok(($result['message'] ?? '') === '未付款订单已关闭', 'application close message');
ok(($repo->calls[0][1] ?? '') === 'BXQZ-U4', 'application trims order id');
$repo->result = ['order_id'=>'BXQZ-U4','already_closed'=>true];
ok(($app->close(['user_id'=>7], ['order_id'=>'BXQZ-U4'])['message'] ?? '') === '该未付款订单已关闭', 'idempotent result');
$bad = false;
try { $app->close(['user_id'=>7], ['order_id'=>'']); } catch (OrderOperationException $e) { $bad = $e->apiCode() === 'ORDER_ID_REQUIRED'; }
ok($bad, 'invalid id rejected');

$source = (string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseOrderDraftClosureRepository.php');
foreach ([
  "BINARY public_no=BINARY :public_no FOR UPDATE" => 'order row lock',
  "status IN ('created','pending')" => 'active payment guard',
  'expires_at<=UTC_TIMESTAMP()' => 'only expired payment rows close locally',
  "status IN ('paid','refunded')" => 'paid/refunded guard',
  'DRAFT_CLOSE_PAYMENT_PENDING' => 'pending payment api code',
  'DRAFT_CLOSE_PAYMENT_ALREADY_PAID' => 'paid race api code',
  "lifecycle_status='cancelled'" => 'draft lifecycle close',
  "'draft_closed'" => 'dedicated audit event',
] as $needle=>$label) ok(strpos($source,$needle)!==false,$label);
foreach (['order_cancel_requests','INSERT INTO refunds','captureCancellation('] as $needle) ok(strpos($source,$needle)===false,'no refund/risk side effect '.$needle);
$module=(string)file_get_contents($root.'/server/app/Http/Api/Modules/OrderDraftClosureApiModule.php');
ok(strpos($module,"'orders.draft.close'")!==false,'dedicated API route');

echo "V2.5.0 U4 draft closure smoke OK\n";
