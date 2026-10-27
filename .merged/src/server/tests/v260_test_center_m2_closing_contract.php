<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
function m2closeFail(string $message):void{fwrite(STDERR,'[FAIL] '.$message."\n");exit(1);}
function m2closePass(string $message):void{fwrite(STDOUT,'[PASS] '.$message."\n");}
function m2closeAssert(bool $ok,string $message):void{if(!$ok)m2closeFail($message);m2closePass($message);}
function m2closeFile(string $root,string $path):string{$content=file_get_contents($root.'/'.$path);if(!is_string($content))m2closeFail('cannot read '.$path);return$content;}

$actionSafety=m2closeFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterActionSafetyRunner.php');
foreach(['GET_LOCK','RELEASE_LOCK',"build_status']??'')==='built'",'test_runs',"result='running'",'TEST_ACTION_INSTANCE_BUSY','TEST_ACTION_INSTANCE_NOT_WRITABLE',"return'worker'","return'user'","return'admin'","return'system'"] as$key)m2closeAssert(strpos($actionSafety,$key)!==false,'action safety contains '.$key);
m2closeAssert(strpos($actionSafety,'if($existing)return$this->inner->run')!==false,'existing idempotency request bypasses new lock path and delegates to persistent replay');

$cleanupSafety=m2closeFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterCleanupSafetyRepository.php');
foreach(['GET_LOCK','RELEASE_LOCK','test_runs_running','instance_cleaning','TEST_CLEANUP_INSTANCE_BUSY','TEST_CLEANUP_SAFETY_BLOCKED','inner_plan_digest'] as$key)m2closeAssert(strpos($cleanupSafety,$key)!==false,'cleanup safety contains '.$key);
m2closeAssert(strpos($cleanupSafety,"result='running'")!==false,'cleanup preview blocks running test actions');

$replay=m2closeFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterAdminRefundReplayRunner.php');
foreach(['admin_refund_approve','TEST_ACTION_REPLAY_METADATA_MISSING','TEST_ACTION_IDEMPOTENCY_CONFLICT',"before['request']",'TestingRefundBoundaryGuard','amount_cents','operation_mode'] as$key)m2closeAssert(strpos($replay,$key)!==false,'admin refund replay contains '.$key);
$existingPos=strpos($replay,'$existing=$requestKey');$fundsPos=strpos($replay,'normalizeCurrent');m2closeAssert($existingPos!==false&&$fundsPos!==false&&$existingPos<$fundsPos,'admin refund replay checks persistent run before current refund boundary');

$composition=m2closeFile($root,'server/app/Foundation/TestCenterComposition.php');
foreach(['DatabaseTestCenterActionSafetyRunner','DatabaseTestCenterAdminRefundReplayRunner','DatabaseTestCenterCleanupSafetyRepository'] as$key)m2closeAssert(strpos($composition,$key)!==false,'composition wires '.$key);
$cleanupGuardPos=strpos($composition,'$cleanupGuard=new DatabaseTestCenterCleanupGuardRepository');
$cleanupSafetyPos=strpos($composition,'$repository=new DatabaseTestCenterCleanupSafetyRepository');
m2closeAssert($cleanupGuardPos!==false&&$cleanupSafetyPos!==false&&$cleanupGuardPos<$cleanupSafetyPos,'cleanup safety is outermost repository layer');
$financePos=strpos($composition,'$financeActions=new DatabaseTestCenterDisputeFinanceActionRunner');
$replayPos=strpos($composition,'$adminReplayActions=new DatabaseTestCenterAdminRefundReplayRunner');
$actionSafetyPos=strpos($composition,'$actions=new DatabaseTestCenterActionSafetyRunner');
m2closeAssert($financePos!==false&&$replayPos!==false&&$actionSafetyPos!==false&&$financePos<$replayPos&&$replayPos<$actionSafetyPos,'action safety and admin refund replay are outside business action decorators');

m2closePass('V2.6.0 test center M2 closing safety contract completed');
