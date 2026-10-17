<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{$v=file_get_contents($root.'/'.$path);if(!is_string($v))throw new RuntimeException('read failed '.$path);return$v;};
$assert=static function(bool $ok,string $label):void{if(!$ok){fwrite(STDERR,"[FAIL] {$label}\n");exit(1);}fwrite(STDOUT,"[PASS] {$label}\n");};
$has=static function(string $text,string $needle,string $label)use($assert):void{$assert(strpos($text,$needle)!==false,$label);};

$catalog=$read('app/Application/Testing/TestScenarioCatalog.php');
$form=$read('app/Infrastructure/Testing/TestingOrderFormFixture.php');
$builder=$read('app/Infrastructure/Testing/TestingOrdinaryWaitingQuoteBuilder.php');
$base=$read('app/Infrastructure/Testing/DatabaseTestCenterMeasurementRepository.php');
$balance=$read('app/Infrastructure/Testing/TestingMeasurementBalancePaymentSimulator.php');
$booking=$read('app/Infrastructure/Testing/TestingOrderBookingPaymentSimulator.php');
$evidence=$read('app/Infrastructure/Testing/TestingMeasurementEvidenceFactory.php');
$link=$read('app/Infrastructure/Testing/TestingLinkedConstructionBuilder.php');
$actions=$read('app/Infrastructure/Testing/DatabaseTestCenterMeasurementActionRunner.php');
$verify=$read('app/Infrastructure/Testing/DatabaseTestCenterMeasurementVerificationRepository.php');
$cleanup=$read('app/Infrastructure/Testing/DatabaseTestCenterMeasurementCleanupRepository.php');
$composition=$read('app/Foundation/TestCenterComposition.php');
$view=$read('views/admin/test-center-measurement-actions.php');
$demoGuard=$read('app/Application/Identity/DemoActorGuard.php');
$settlement=$read('app/Infrastructure/Persistence/DatabaseOrderPaidPaymentSettlement.php');
$measurementCompletion=$read('app/Infrastructure/Persistence/DatabaseOrderMeasurementCompletionRepository.php');
$holdExtension=$read('app/Infrastructure/Persistence/DatabaseOrderMeasurementHoldExtensionRepository.php');
$dealQuotes=$read('app/Infrastructure/Persistence/OrderDealQuoteOperations.php');

$has($catalog,'ordinary.measurement.multi_quote.waiting.zero_quotes','catalog exposes ordinary measurement start');
$has($catalog,"'family'=>'measurement'",'measurement scenario is separate family');
$has($form,"string \$workflow='construction'",'dynamic form fixture accepts workflow');
$has($form,"firstOption((int)\$field['id'],\$workflow)",'workflow answer follows scenario');
$has($builder,'order_measurement_states(order_id,service_payment_status,result_status,settlement_status','builder initializes measurement state');
$has($builder,'$formFixture->prepare($schemaId','measurement preset still uses live form fixture');
$has($builder,'OrderFormMediaRules::readiness($this->db,$orderId,$schemaId,$workflow)','media readiness uses actual workflow');
$has($base,'measurement_editing','initial verifier checks unpaid editing none state');

$has($booking,"'test-booking:'.\$instanceId.':'.\$orderId",'booking simulator idempotency is instance plus order');
$has($booking,'p.order_id=:order_id','booking replay is scoped to exact order');
$has($balance,"'measurement_balance'",'measurement balance simulator creates correct business type');
$has($balance,'DatabaseOrderPaidPaymentSettlement','measurement balance simulator reuses production settlement');
$has($balance,'TEST_INTERNAL','measurement balance remains internal provider');
$assert(strpos($balance,'wechat_transaction_id')!==false,'measurement balance validates no fake WeChat transaction');

$has($evidence,"'order_measurement_space'",'measurement evidence links through measurement space');
$has($evidence,"'evidence_valid'=>false",'measurement fixture never claims real evidence');
$has($actions,"deal()->execute('quotes.create'",'measurement quote uses formal deal service');
$has($actions,'quoteSelection()->execute','measurement selection uses formal quote selection');
$has($actions,'appointment()->save','measurement appointment uses formal appointment service');
$has($actions,"fulfillment()->execute('orders.measurement.submit'",'measurement result uses formal submit route');
$has($actions,"fulfillment()->execute('orders.measurement.rework'",'measurement rework uses formal rework route');
$has($actions,"fulfillment()->execute('fulfillment.accept'",'measurement acceptance uses formal fulfillment route');
$has($actions,'DatabaseOrderMeasurementHoldExtensionRepository','measurement hold extension uses formal repository');
$has($actions,"customer_extend_measurement_hold",'measurement action allowlist includes hold extension');
$has($actions,"'is_admin'=>false",'measurement actors never inherit admin');
$has($actions,"'business_admin'=>false",'measurement actors never inherit business admin');
$has($actions,'MEASUREMENT_WORKER_BOUND','linked construction negative probe requires formal worker-bound code');

$has($measurementCompletion,"event_type='measurement_rework_requested'",'production measurement rework counts prior request events');
$has($measurementCompletion,'MEASUREMENT_REWORK_LIMIT','production ordinary measurement limits rework to once');
$has($measurementCompletion,"result_status='editing'",'production rework returns result state to editing');
$has($holdExtension,"['active','completed']",'hold extension risk logic accepts authentic completed measurement lifecycle');
$has($holdExtension,"status='approved'",'formal first small hold extension can auto approve');
$has($holdExtension,'measurement.auto_hold_extension_days','formal hold extension uses configured auto-approval window');
$has($holdExtension,'MEASUREMENT_ALREADY_LINKED','formal hold extension blocks after construction relation exists');

$assert(strpos($link,'INSERT INTO order_relations')===false,'linked construction builder never directly inserts order_relations');
$has($link,'TestingOrderBookingPaymentSimulator','linked construction relation is triggered by formal booking settlement');
$has($link,'TEST_LINK_RELATION_NOT_CREATED','builder fails if production settlement does not create relation');
$has($settlement,'formalizePendingMeasurementRelation','production booking settlement owns relation creation');
$has($settlement,'INSERT INTO order_relations','production settlement persists measurement construction relation');
$has($dealQuotes,'MEASUREMENT_WORKER_BOUND','production quote path enforces original measurement worker');
$has($demoGuard,'DEMO_CREATE_BLOCKED','production order submission demo guard remains intact');
$assert(strpos($actions,'submission()->create')===false,'test center does not bypass demo order creation guard');

foreach(['selection_pending_payment','measurement_balance_waiting','measurement_dealt','measurement_result_fixture','measurement_rework_limit','measurement_rework_editing','measurement_accepted_frozen','measurement_frozen_ledger','measurement_hold_extension_auto_approved','single_measurement_construction','bound_original_worker','same_address_hmac','child_booking_internal','other_worker_rejected']as$key)$has($verify,$key,'verifier contains '.$key);
foreach(['payment_unowned','worker_unowned','media_link_unowned','measurement_ledger_unowned','measurement_hold_extension_unowned','measurement_hold_extensions_multiple','child_foreign_quote','scheduled_job_claims']as$key)$has($cleanup,$key,'cleanup protects '.$key);
$has($cleanup,'DELETE FROM order_measurement_hold_extensions','cleanup removes owned approved hold extension');
$has($cleanup,'DELETE FROM order_relations','cleanup removes owned relation before orders');
$has($cleanup,'DELETE FROM worker_balance_ledger','cleanup removes owned frozen measurement ledger');
$has($cleanup,'DELETE FROM order_measurement_spaces','cleanup removes owned measurement spaces');

foreach(['DatabaseTestCenterMeasurementRepository','DatabaseTestCenterMeasurementVerificationRepository','DatabaseTestCenterMeasurementCleanupRepository','DatabaseTestCenterMeasurementActionRunner']as$class)$has($composition,$class,'composition wires '.$class);
foreach(['执行真实测量报价','补齐测量服务款','执行真实提交测量成果','执行真实一次补测','执行真实确认测量成果','执行真实冻结延期申请','创建关联施工并正式绑定','原测量师傅正式施工报价','验证其他师傅被拒绝']as$label)$has($view,$label,'measurement UI contains '.$label);
$has($view,'不触发微信','UI labels payment isolation');
$has($view,'不代表七牛上传、标注工具或证据真实性已验证','UI labels evidence limitation');
$has($measurementCompletion,"settlement_status='frozen'",'production measurement acceptance retains frozen settlement rule');
$has($measurementCompletion,'measurement_income_frozen','production measurement acceptance creates frozen worker ledger');
$has($measurementCompletion,"'order_measurement'",'production measurement ledger uses order_measurement business type');

fwrite(STDOUT,"V2.6.0 ordinary measurement + linked construction contract: OK\n");
