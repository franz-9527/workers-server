<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
function sgText(string $path): string{$value=file_get_contents($path);if(!is_string($value)){fwrite(STDERR,"[FAIL] cannot read {$path}\n");exit(1);}return$value;}
function sgHas(string $text,string $needle,string $label): void{if(strpos($text,$needle)===false){fwrite(STDERR,"[FAIL] {$label}\n");exit(1);}fwrite(STDOUT,"[PASS] {$label}\n");}

$catalog=sgText($root.'/server/app/Application/Testing/TestScenarioCatalog.php');
$builder=sgText($root.'/server/app/Infrastructure/Testing/TestingOrdinaryWaitingQuoteBuilder.php');
$m1=sgText($root.'/server/app/Infrastructure/Testing/DatabaseTestCenterM1Repository.php');
$runner=sgText($root.'/server/app/Infrastructure/Testing/DatabaseTestCenterSingleGrabActionRunner.php');
$verifier=sgText($root.'/server/app/Infrastructure/Testing/DatabaseTestCenterSingleGrabVerificationRepository.php');
$dealApp=sgText($root.'/server/app/Application/Order/OrderDeal.php');
$dealRepo=sgText($root.'/server/app/Infrastructure/Persistence/OrderDealAssignmentOperations.php');
$composition=sgText($root.'/server/app/Foundation/TestCenterComposition.php');
$view=sgText($root.'/server/views/admin/test-center-single-grab-actions.php');

sgHas($catalog,"ordinary.construction.single_grab.waiting.open",'catalog exposes single-grab waiting scenario');
sgHas($catalog,"'expected_fee_type'=>'total'",'single-grab scenario is fixed-total');
sgHas($builder,"\$dispatchMode==='single_grab'",'ordinary builder adapts single-grab mode');
sgHas($builder,"expected_fee_type='total'",'ordinary builder persists fixed expected total');
sgHas($m1,"isSingleGrab(\$scenarioKey)",'M1 ownership/cleanup lifecycle includes single-grab scenario');
sgHas($runner,"'orders.grab'",'single-grab runner calls formal orders.grab');
sgHas($runner,"worker_grab_competitor",'single-grab runner has explicit losing-worker probe');
sgHas($runner,"ORDER_ALREADY_GRABBED",'competitor probe requires formal already-grabbed code');
sgHas($runner,"'is_admin'=>false",'test worker actor never inherits admin privileges');
sgHas($verifier,"'single_assignment'",'verifier asserts exactly one assignment');
sgHas($verifier,"'single_selected_quote'",'verifier asserts exactly one selected quote');
sgHas($verifier,"'competitor_rejected'",'verifier persists losing-worker rejection assertion');
sgHas($dealRepo,"SELECT * FROM orders WHERE id=:id FOR UPDATE",'production ordinary grab locks order row before final eligibility check');
sgHas($dealApp,"ORDER_ALREADY_GRABBED",'application maps production grab race to stable business error code');
sgHas($dealApp,"该订单已被其他师傅抢走或状态已变化",'race mapping is limited to known ordinary repository message');
sgHas($composition,'DatabaseTestCenterSingleGrabActionRunner','composition wires single-grab action runner');
sgHas($composition,'DatabaseTestCenterSingleGrabVerificationRepository','composition wires single-grab verifier');
sgHas($view,'执行真实抢单','admin test center exposes real grab control');
sgHas($view,'验证竞争失败','admin test center exposes losing-worker probe');

echo "V2.6.0 single-grab test center contract: OK\n";
