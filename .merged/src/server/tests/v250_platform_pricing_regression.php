<?php

declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderPricingRepository;

$root = dirname(__DIR__, 2);
require_once __DIR__.'/support/order_runtime.php';
require_once $root . '/server/app/Core/Database.php';
require_once $root . '/server/app/Contracts/OrderPricingRepository.php';
require_once $root . '/server/app/Domain/Order/OrderOperationException.php';
require_once $root . '/server/app/Domain/Order/PlatformOrderSettlementPolicy.php';
require_once $root . '/server/app/Infrastructure/Persistence/DatabaseOrderPricingRepository.php';

function pricingAssert($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, '[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

final class PricingFakeDatabase extends Database
{
    public array $order;
    public array $terms;
    public array $assignment;
    public array $lines;
    public array $events = [];
    public array $notifications = [];

    public function __construct(string $mode = 'worker_collect')
    {
        $this->order = [
            'id'=>42,'public_no'=>'QT-PLATFORM-42','order_source_type'=>'platform',
            'lifecycle_status'=>'active','fulfillment_status'=>'in_progress',
            'booking_fee_cents'=>0,'assigned_worker_id'=>7,'current_assignment_id'=>71,
            'final_amount_cents'=>80480,'version'=>1,
        ];
        $this->terms = [
            'customer_flow_mode'=>'platform_managed','booking_paid_cents'=>5000,'customer_pricing_mode'=>'itemized','customer_contract_total_cents'=>80480,
            'worker_settlement_mode'=>$mode,'worker_labor_cents'=>70000,
            'settlement_status'=>'pending',
        ];
        $this->assignment = ['id'=>71,'status'=>'accepted','worker_id'=>7];
        $this->lines = [
            ['id'=>1,'order_id'=>42,'item_source'=>'base','status'=>'active','description'=>'贴墙布','note'=>'按实际面积','quantity'=>'50.400','unit'=>'平','unit_price_cents'=>1200,'amount_cents'=>60480,'sort_order'=>1],
            ['id'=>2,'order_id'=>42,'item_source'=>'base','status'=>'active','description'=>'撕墙纸','note'=>'已约定拆旧','quantity'=>'40.000','unit'=>'平','unit_price_cents'=>500,'amount_cents'=>20000,'sort_order'=>2],
        ];
    }

    public function transaction(callable $callback)
    {
        $snapshot = [$this->order,$this->terms,$this->assignment,$this->lines,$this->events,$this->notifications];
        try { return $callback($this); }
        catch (Throwable $error) {
            [$this->order,$this->terms,$this->assignment,$this->lines,$this->events,$this->notifications] = $snapshot;
            throw $error;
        }
    }

    public function fetch($sql, array $params = [])
    {
        if (strpos($sql, 'JOIN users') !== false) return array_merge($this->order, ['user_id'=>99,'is_demo'=>1,'user_mobile'=>'']);
        if (strpos($sql, 'FROM orders o') !== false && strpos($sql, 'order_platform_terms') !== false) return array_merge($this->order, $this->terms);
        if (strpos($sql, 'FROM assignments') !== false) {
            return ($params['worker_id'] ?? 0) === $this->assignment['worker_id']
                && ($params['assignment_id'] ?? 0) === $this->assignment['id'] ? $this->assignment : null;
        }
        throw new RuntimeException('Unexpected fake SELECT: '.$sql);
    }

    public function fetchAll($sql, array $params = [])
    {
        if (strpos($sql, 'FROM payment_orders') !== false) return [];
        if (strpos($sql, 'FROM order_pricing_items') === false) throw new RuntimeException('Unexpected fake SELECT ALL: '.$sql);
        $baseOnly = strpos($sql, "item_source='base'") !== false;
        return array_values(array_filter($this->lines, static function (array $row) use ($baseOnly): bool {
            return $row['status'] === 'active' && (!$baseOnly || $row['item_source'] === 'base');
        }));
    }

    public function fetchValue($sql, array $params = [])
    {
        if (strpos($sql, 'FROM notifications') !== false) return false;
        if (strpos($sql, 'SUM(amount_cents)') === false) throw new RuntimeException('Unexpected fake SELECT VALUE: '.$sql);
        return array_sum(array_column(array_filter($this->lines, static fn($row) => $row['status'] === 'active'), 'amount_cents'));
    }

    public function execute($sql, array $params = [])
    {
        if (strpos($sql, 'UPDATE order_pricing_items') !== false) {
            foreach ($this->lines as &$row) {
                if ($row['id'] !== $params['id']) continue;
                $row['quantity'] = $params['quantity'];
                $row['amount_cents'] = $params['amount'];
                unset($row);
                return 1;
            }
            unset($row);
            return 0;
        }
        if (strpos($sql, 'UPDATE order_platform_terms') !== false) { $this->terms['customer_contract_total_cents'] = $params['total']; return 1; }
        if (strpos($sql, 'UPDATE orders') !== false) { $this->order['final_amount_cents'] = $params['total']; $this->order['version']++; return 1; }
        throw new RuntimeException('Unexpected fake UPDATE: '.$sql);
    }

    public function insert($sql, array $params = [])
    {
        if (strpos($sql, 'INSERT INTO notifications') !== false) { $this->notifications[] = $params; return count($this->notifications); }
        if (strpos($sql, 'INSERT INTO order_events') === false) throw new RuntimeException('Unexpected fake INSERT: '.$sql);
        $this->events[] = ['event_type'=>'platform_pricing_quantity_adjusted','payload'=>json_decode($params['payload'],true)];
        return count($this->events);
    }
}

$actor = ['worker_id'=>7,'user_id'=>77];
$db = new PricingFakeDatabase();
$repo = new DatabaseOrderPricingRepository($db);
$result = $repo->updateActualQuantities($actor, $db->order, [['sort_order'=>1,'quantity_milli'=>53000]]);
pricingAssert($result['old_customer_total_cents'], 80480, '原手工开单合计 804.80 元');
pricingAssert($result['customer_total_cents'], 83600, '面积 53×12 + 40×5 重算为 836 元');
pricingAssert($db->lines[0]['amount_cents'], 63600, '单项金额按原单价重新计算');
pricingAssert($db->order['final_amount_cents'], 83600, '统一 orders 同步客户最终金额');
pricingAssert($db->terms['customer_contract_total_cents'], 83600, '平台计价条件同步客户最终金额');
pricingAssert($db->assignment['id'], 71, '实际用量变化不重新指派师傅');
pricingAssert($db->terms['worker_labor_cents'], 70000, '实际用量变化不修改师傅工费');
pricingAssert(count($db->events), 1, '实际用量变化只写独立审计事件');
pricingAssert($db->events[0]['event_type'], 'platform_pricing_quantity_adjusted', '审计事件类型正确');
pricingAssert(count($db->notifications), 1, '实际用量重算同步生成一条客户站内提醒');

$rejectSame = false;
try { $repo->updateActualQuantities($actor, $db->order, [['sort_order'=>1,'quantity_milli'=>53000]]); }
catch (OrderOperationException $e) { $rejectSame = $e->apiCode() === 'PLATFORM_QUANTITY_UNCHANGED'; }
pricingAssert($rejectSame, true, '重复数量不发生无意义更新');
pricingAssert(count($db->notifications), 1, '重复数量不重复投递提醒');

$forbidden = false;
try { $repo->updateActualQuantities(['worker_id'=>8], $db->order, [['sort_order'=>1,'quantity_milli'=>54000]]); }
catch (OrderOperationException $e) { $forbidden = $e->apiCode() === 'FORBIDDEN'; }
pricingAssert($forbidden, true, '其他师傅无权变更计价');

$negative = new PricingFakeDatabase();
$negativeRepo = new DatabaseOrderPricingRepository($negative);
$negativeRejected = false;
try { $negativeRepo->updateActualQuantities($actor, $negative->order, [['sort_order'=>1,'quantity_milli'=>40000]]); }
catch (OrderOperationException $e) { $negativeRejected = $e->apiCode() === 'PLATFORM_SETTLEMENT_NEGATIVE'; }
pricingAssert($negativeRejected, true, '师傅代收减少数量不能形成平台负差额');
pricingAssert($negative->lines[0]['amount_cents'], 60480, '负差额拒绝后恢复原项目金额');
pricingAssert($negative->order['final_amount_cents'], 80480, '负差额拒绝后订单金额未变化');
pricingAssert(count($negative->events), 0, '负差额拒绝不产生审计成功事件');

$balance = new PricingFakeDatabase('platform_balance');
$balanceRepo = new DatabaseOrderPricingRepository($balance);
$balanceResult = $balanceRepo->updateActualQuantities($actor, $balance->order, [['sort_order'=>1,'quantity_milli'=>40000]]);
pricingAssert($balanceResult['customer_total_cents'], 68000, '平台余额工费不与客户数量变化强制一一对应');
pricingAssert($balance->terms['worker_labor_cents'], 70000, '平台余额工费由独立流程核准');

$withExtra = new PricingFakeDatabase();
$withExtra->lines[] = ['id'=>3,'order_id'=>42,'item_source'=>'change','status'=>'active','description'=>'订单外施工项','note'=>null,'quantity'=>'1.000','unit'=>'项','unit_price_cents'=>20000,'amount_cents'=>20000,'sort_order'=>3];
$withExtra->order['final_amount_cents'] = 100480;
$withExtra->terms['customer_contract_total_cents'] = 100480;
$extraResult = (new DatabaseOrderPricingRepository($withExtra))->updateActualQuantities($actor, $withExtra->order, [['sort_order'=>1,'quantity_milli'=>53000]]);
pricingAssert($extraResult['customer_total_cents'], 103600, '实际用量重算保留已通过的订单外加项');
pricingAssert($withExtra->lines[2]['amount_cents'], 20000, '实际用量不修改订单外加项');

echo "\nV2.5.0 platform actual quantity regression: OK\n";
