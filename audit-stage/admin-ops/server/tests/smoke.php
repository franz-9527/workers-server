<?php

declare(strict_types=1);

use WorkerAdmin\Domain\Admin\WorkbenchTaskProjection;

$root=dirname(__DIR__);
require_once $root.'/app/Domain/Admin/WorkbenchTaskProjection.php';
function ok(bool $value,string $label):void{if(!$value){fwrite(STDERR,"[FAIL] {$label}\n");exit(1);}fwrite(STDOUT,"[PASS] {$label}\n");}
$repo=file_get_contents($root.'/app/Infrastructure/Persistence/DatabaseAdminWorkbenchOperationsRepository.php')?:'';
$publish=file_get_contents($root.'/app/Infrastructure/Scheduling/PlatformOrderAutoPublishTask.php')?:'';
$follow=file_get_contents($root.'/app/Infrastructure/Persistence/AdminFollowupWorkbenchTaskSource.php')?:'';
$app=file_get_contents($root.'/app/Application/Admin/AdminWorkbenchOperations.php')?:'';
ok(strpos($repo,"assignment_status='offered'")!==false,'admin reassignment leaves new assignment offered');
ok(strpos($repo,"'requires_worker_acceptance'=>true")!==false,'new worker must personally accept');
ok(strpos($repo,"UPDATE order_role_grants SET status='revoked'")!==false,'old worker access is revoked');
ok(strpos($repo,"UPDATE order_worker_contact_reveal_requests SET status='cancelled'")!==false,'old phone reveal request is cancelled');
ok(strpos($repo,"UPDATE invite_tokens SET status='revoked'")!==false,'old invite tokens are revoked');
ok(strpos($repo,"'平台主动介入：'.\$reason")!==false,'proactive repair keeps admin identity');
ok(strpos($repo,'INSERT INTO order_revisions')!==false&&strpos($follow,'FROM order_revisions r')!==false,'internal followups live in private revisions');
ok(strpos($follow,'FROM order_events e')===false,'internal followups are not public order events');
ok(strpos($publish,'public function retryOrder(int $orderId)')!==false&&strpos($publish,'$this->publishOne($orderId)')!==false,'safe retry reuses original publish validation');
ok(strpos($publish,"status'=>'not_ready'")!==false,'safe retry can refuse when current conditions are not ready');
ok(WorkbenchTaskProjection::taskKind(['task_type'=>'admin_followup_due'])==='operation','due followup is normal operation task');
ok(WorkbenchTaskProjection::matchesScope(['task_type'=>'admin_followup_due'],'followup'),'followup scope works');
ok(strpos($app,'ADMIN_REASSIGN_REASON_REQUIRED')!==false&&strpos($app,'ADMIN_REPAIR_INTERVENTION_REASON_REQUIRED')!==false,'admin high-impact operations require reason');
fwrite(STDOUT,"V2.5.0 isolated admin operations smoke OK\n");
