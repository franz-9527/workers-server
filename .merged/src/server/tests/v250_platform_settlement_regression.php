<?php

declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Infrastructure\Persistence\PlatformOrderSettlementWriter;

$root=dirname(__DIR__,2);
require_once $root.'/server/app/Core/Database.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Domain/Order/PlatformOrderSettlementPolicy.php';
require_once $root.'/server/app/Infrastructure/Persistence/PlatformOrderBookingPaymentVerifier.php';
require_once $root.'/server/app/Infrastructure/Persistence/PlatformOrderSettlementWriter.php';
function settlementExpect($actual,$expected,string $label):void{if($actual!==$expected){fwrite(STDERR,'FAIL '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");exit(1);}echo 'PASS '.$label."\n";}

final class SettlementWriterFakeDb extends Database
{
    public int $unexpectedWechatPaid=0; public int $receiptCount=1; public bool $ledgerExists=false; public array $writes=[]; public array $ledger=[]; public int $termsUpdated=0; public string $termsStatus='pending';
    public function __construct(){}
    public function fetchValue($sql,array $params=[]){if(strpos($sql,"ml.purpose='collection_receipt'")!==false)return $this->receiptCount;throw new RuntimeException('Unexpected fetchValue: '.$sql);}
    public function fetchAll($sql,array $params=[]){if(strpos($sql,'FROM payment_orders')!==false)return $this->unexpectedWechatPaid>0?[['id'=>91,'amount_cents'=>$this->unexpectedWechatPaid]]:[];throw new RuntimeException('Unexpected fetchAll: '.$sql);}
    public function fetch($sql,array $params=[]){if(strpos($sql,'FROM worker_balance_ledger')!==false)return $this->ledgerExists?['id'=>12]:null;throw new RuntimeException('Unexpected fetch: '.$sql);}
    public function execute($sql,array $params=[]){$this->writes[]=['sql'=>$sql,'params'=>$params];if(strpos($sql,'UPDATE order_platform_terms')!==false){$this->termsUpdated++;if($this->termsStatus!=='pending')return 0;$this->termsStatus=$params['status'];return 1;}if(strpos($sql,'UPDATE assignments')!==false)return 1;if(strpos($sql,'INSERT INTO worker_balance_ledger')!==false){$this->ledger[]=$params;return 1;}throw new RuntimeException('Unexpected execute: '.$sql);}
}
function settleFixture(string $mode='worker_collect',int $total=100000,int $labor=70000,int $externalPrepayment=5000):array{return [
    ['id'=>42,'order_source_type'=>'platform','assigned_worker_id'=>7,'booking_fee_cents'=>0],
    ['order_id'=>42,'customer_flow_mode'=>'platform_managed','customer_contract_total_cents'=>$total,'booking_paid_cents'=>$externalPrepayment,'worker_labor_cents'=>$labor,'worker_settlement_mode'=>$mode,'settlement_status'=>'pending','worker_balance_freeze_days_snapshot'=>0],
    ['id'=>71,'worker_id'=>7,'status'=>'accepted'],
];}
$writer=new PlatformOrderSettlementWriter();
$db=new SettlementWriterFakeDb();[$order,$terms,$assignment]=settleFixture();$result=$writer->apply($db,$order,$terms,$assignment);
settlementExpect($result['booking_paid_cents'],5000,'使用平台条款外部预付款 50 元');
settlementExpect($result['customer_tail_cents'],95000,'客户现场剩余正确');
settlementExpect($result['platform_receivable_cents'],25000,'师傅代收平台收入款=1000-700-50');
settlementExpect($result['worker_balance_credit_cents'],0,'师傅代收不重复进余额');
settlementExpect($db->termsStatus,'awaiting_platform_income','验收后仍等待平台真实收入款');
settlementExpect(count($db->ledger),0,'师傅代收无余额台账');

$db=new SettlementWriterFakeDb();[$order,$terms,$assignment]=settleFixture('worker_collect',100000,70000,0);$result=$writer->apply($db,$order,$terms,$assignment);
settlementExpect($result['booking_paid_cents'],0,'托管允许外部未预收');settlementExpect($result['customer_tail_cents'],100000,'未预收时客户现场应收全额');settlementExpect($result['platform_receivable_cents'],30000,'未预收时平台收入款=1000-700');

$db=new SettlementWriterFakeDb();[$order,$terms,$assignment]=settleFixture('platform_balance');$result=$writer->apply($db,$order,$terms,$assignment);
settlementExpect($result['platform_receivable_cents'],95000,'平台余额模式平台应收为现场剩余');settlementExpect($result['worker_balance_credit_cents'],70000,'平台余额模式工费全额入师傅余额');settlementExpect($db->ledger[0]['available'],70000,'默认冻结0天直接入可提现余额');settlementExpect($db->ledger[0]['frozen'],0,'默认不进冻结余额');settlementExpect($db->ledger[0]['key'],'platform-order:42:worker-income','工费使用稳定幂等键');

$db=new SettlementWriterFakeDb();[$order,$terms,$assignment]=settleFixture('platform_balance');$terms['worker_balance_freeze_days_snapshot']=10;$writer->apply($db,$order,$terms,$assignment);settlementExpect($db->ledger[0]['available'],0,'冻结期内不进可提现余额');settlementExpect($db->ledger[0]['frozen'],70000,'冻结期内进入冻结余额');

$db=new SettlementWriterFakeDb();[$order,$terms,$assignment]=settleFixture('worker_collect',75000,70000,5000);$result=$writer->apply($db,$order,$terms,$assignment);settlementExpect($result['platform_receivable_cents'],0,'平台应收0可以直接结清');settlementExpect($db->termsStatus,'settled','零应收自动结清');
$db=new SettlementWriterFakeDb();[$order,$terms,$assignment]=settleFixture('worker_collect',74000,70000,5000);try{$writer->apply($db,$order,$terms,$assignment);settlementExpect('accepted','rejected','负差额必须拒绝');}catch(OrderOperationException $e){settlementExpect($e->apiCode(),'PLATFORM_SETTLEMENT_NEGATIVE','负差额拒绝');}settlementExpect($db->termsUpdated,0,'负差额不写结算快照');

$db=new SettlementWriterFakeDb();$db->receiptCount=0;[$order,$terms,$assignment]=settleFixture();try{$writer->apply($db,$order,$terms,$assignment);settlementExpect('accepted','rejected','无凭证拒绝');}catch(OrderOperationException $e){settlementExpect($e->apiCode(),'COLLECTION_RECEIPT_REQUIRED','缺少现场收款凭证不能验收');}settlementExpect($db->termsUpdated,0,'无凭证不写资金数据');
$db=new SettlementWriterFakeDb();[$order,$terms,$assignment]=settleFixture('platform_balance');$db->ledgerExists=true;try{$writer->apply($db,$order,$terms,$assignment);settlementExpect('accepted','rejected','重复工费台账拒绝');}catch(OrderOperationException $e){settlementExpect($e->apiCode(),'PLATFORM_WORKER_CREDIT_ALREADY_POSTED','重复工费台账保护');}
$db=new SettlementWriterFakeDb();[$order,$terms,$assignment]=settleFixture();$terms['settlement_status']='settled';try{$writer->apply($db,$order,$terms,$assignment);settlementExpect('accepted','rejected','重复验收拒绝');}catch(OrderOperationException $e){settlementExpect($e->apiCode(),'PLATFORM_SETTLEMENT_STATE_CHANGED','已结算状态拒绝重复写');}

$db=new SettlementWriterFakeDb();$db->unexpectedWechatPaid=5000;[$order,$terms,$assignment]=settleFixture();try{$writer->apply($db,$order,$terms,$assignment);settlementExpect('accepted','rejected','平台托管意外微信预约费必须拒绝');}catch(OrderOperationException $e){settlementExpect($e->apiCode(),'PLATFORM_BOOKING_UNEXPECTED_PAYMENT','平台托管微信预约费进入资金异常');}settlementExpect($db->termsUpdated,0,'资金串线不生成结算快照');settlementExpect(count($db->ledger),0,'资金串线不写师傅工费');
$db=new SettlementWriterFakeDb();[$order,$terms,$assignment]=settleFixture();$terms['customer_flow_mode']='miniapp_booking';try{$writer->apply($db,$order,$terms,$assignment);settlementExpect('accepted','rejected','旧 miniapp_booking 拒绝');}catch(OrderOperationException $e){settlementExpect($e->apiCode(),'PLATFORM_SETTLEMENT_STATE_CHANGED','旧平台客户流不能进入新结算');}

$schedulerSource=(string)file_get_contents($root.'/server/app/Services/SchedulerService.php');
settlementExpect(strpos($schedulerSource,"COALESCE(o.order_source_type,'consumer')<>'platform' AND (NOT EXISTS")!==false,true,'普通佣金补偿扫描排除平台单');
settlementExpect(strpos($schedulerSource,"COALESCE(order_source_type,'consumer')<>'platform' FOR UPDATE")!==false,true,'普通佣金事务复核排除平台单');
$automaticAcceptanceSource=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/OrderAutomaticAcceptanceWriter.php');
settlementExpect(strpos($automaticAcceptanceSource,"(new PlatformOrderSettlementWriter())->apply(\$db, \$order, \$platformTerms, \$platformAssignment)")!==false,true,'平台自动验收继续调用唯一资金写入器');
echo "Platform managed settlement writer regressions: OK\n";
