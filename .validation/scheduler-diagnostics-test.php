<?php
declare(strict_types=1);
require __DIR__.'/source/Database.php'; require __DIR__.'/source/RuntimeLogRepository.php';
require __DIR__.'/source/RuntimeLogMaintenance.php'; require __DIR__.'/source/FileRuntimeLogRepository.php';
require __DIR__.'/source/DatabaseSchedulerFailureRecorder.php'; require __DIR__.'/source/DatabaseSchedulerRunMaintenance.php';
use WorkerAdmin\Core\Database; use WorkerAdmin\Infrastructure\Persistence\DatabaseSchedulerFailureRecorder;
use WorkerAdmin\Infrastructure\Persistence\DatabaseSchedulerRunMaintenance; use WorkerAdmin\Infrastructure\Persistence\FileRuntimeLogRepository;
use WorkerAdmin\Infrastructure\Persistence\RuntimeLogMaintenance;
function ok($v,$m){if(!$v)throw new RuntimeException('FAIL: '.$m);}
$db=new Database(['host'=>'127.0.0.1','port'=>3306,'database'=>'scheduler_test','username'=>'root','password'=>'root']); $pdo=$db->pdo();
$pdo->exec("CREATE TABLE scheduler_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,run_uuid VARCHAR(64),job_name VARCHAR(64),status VARCHAR(24),host_name VARCHAR(128),process_id INT,scanned_count INT DEFAULT 0,success_count INT DEFAULT 0,failure_count INT DEFAULT 0,started_at DATETIME,finished_at DATETIME NULL,error_message TEXT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE scheduled_job_failures(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,job_name VARCHAR(64),business_key VARCHAR(128),business_version INT,scheduler_run_id BIGINT UNSIGNED NULL,attempt_no INT,error_class VARCHAR(191),error_message TEXT,next_retry_at DATETIME,resolved_at DATETIME NULL,created_at DATETIME,KEY idx(job_name,business_key,business_version,resolved_at)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE scheduled_job_claims(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,job_name VARCHAR(64),business_key VARCHAR(128),business_version INT,scheduler_run_id BIGINT UNSIGNED NULL,status VARCHAR(24),completed_at DATETIME NULL) ENGINE=InnoDB");
$run=$db->insert("INSERT INTO scheduler_runs(run_uuid,job_name,status,started_at,finished_at) VALUES('r','test_job','failed',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$rec=new DatabaseSchedulerFailureRecorder($db); $a=$rec->record($run,'test_job','order:1',1,'RuntimeException','a'); $b=$rec->record($run,'test_job','order:1',1,'RuntimeException','b');
ok($a===$b,'same business identity merges unresolved failures');
ok((int)$db->fetchValue("SELECT attempt_no FROM scheduled_job_failures WHERE id=:id",['id'=>$a])===2,'retry count increments');
ok((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_failures WHERE resolved_at IS NULL")===1,'only one unresolved failure');
$db->execute("UPDATE scheduled_job_failures SET resolved_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$a]); $c=$rec->record($run,'test_job','order:1',1,'RuntimeException','c');
ok($c!==$a,'resolved failure history remains immutable');
$old=$db->insert("INSERT INTO scheduler_runs(run_uuid,job_name,status,started_at,finished_at) VALUES('old','cleanup','success',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY))");
$active=$db->insert("INSERT INTO scheduler_runs(run_uuid,job_name,status,started_at,finished_at) VALUES('active','cleanup','success',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY))");
$db->execute("INSERT INTO scheduled_job_failures(job_name,business_key,business_version,scheduler_run_id,attempt_no,error_class,error_message,next_retry_at,created_at) VALUES('cleanup','order:old',1,:id,1,'E','keep',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$old]);
$db->execute("INSERT INTO scheduled_job_claims(job_name,business_key,business_version,scheduler_run_id,status,completed_at) VALUES('cleanup','active',1,:id,'claimed',NULL)",['id'=>$active]);
$r=(new DatabaseSchedulerRunMaintenance($db))->purgeOlderThan(7,'cleanup');
ok((int)$r['count']===1,'old run deleted, active claim protected');
ok($db->fetch("SELECT id FROM scheduler_runs WHERE id=:id",['id'=>$old])===null,'old summary gone');
ok($db->fetch("SELECT id FROM scheduled_job_failures WHERE business_key='order:old'")!==null,'failure fact retained');
ok($db->fetchValue("SELECT scheduler_run_id FROM scheduled_job_failures WHERE business_key='order:old'")===null,'failure pointer detached');
ok($db->fetch("SELECT id FROM scheduler_runs WHERE id=:id",['id'=>$active])!==null,'active claim run retained');
$dir=sys_get_temp_dir().'/scheduler-log-'.bin2hex(random_bytes(4)); mkdir($dir,0775,true);
try{
 file_put_contents($dir.'/app.log','stale'); touch($dir.'/app.log',time()-35*86400);
 file_put_contents($dir.'/api.log','fresh'); file_put_contents($dir.'/scheduler.log','scheduler');
 RuntimeLogMaintenance::maintain($dir,true); clearstatcache();
 ok(filesize($dir.'/app.log')===0,'stale active log truncated'); ok(file_get_contents($dir.'/api.log')==='fresh','fresh log preserved');
 (new FileRuntimeLogRepository($dir))->clearCategory('scheduler'); ok(file_exists($dir.'/api.log'),'category cleanup scoped');
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@unlink($dir.'/.maintenance.stamp');@rmdir($dir);}
echo "PASS scheduler failure consolidation, run retention, and runtime log cleanup\n";
