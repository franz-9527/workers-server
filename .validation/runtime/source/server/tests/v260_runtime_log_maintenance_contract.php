<?php

declare(strict_types=1);

use WorkerAdmin\Infrastructure\Persistence\FileRuntimeLogRepository;
use WorkerAdmin\Infrastructure\Persistence\RuntimeLogMaintenance;

require_once dirname(__DIR__,2).'/server/app/Contracts/RuntimeLogRepository.php';
require_once dirname(__DIR__,2).'/server/app/Infrastructure/Persistence/RuntimeLogMaintenance.php';
require_once dirname(__DIR__,2).'/server/app/Infrastructure/Persistence/FileRuntimeLogRepository.php';

require_once dirname(__DIR__,2).'/server/app/Support/functions.php';
require_once dirname(__DIR__,2).'/server/app/Support/RuntimeExceptionReporter.php';

function runtimeLogAssert(bool $condition,string $label): void
{
    if(!$condition)throw new RuntimeException('[FAIL] '.$label);
    fwrite(STDOUT,'[PASS] '.$label."\n");
}

$directory=sys_get_temp_dir().'/workers-runtime-log-'.bin2hex(random_bytes(6));
if(!mkdir($directory,0775,true) && !is_dir($directory))throw new RuntimeException('cannot create runtime log fixture');
try {
    file_put_contents($directory.'/app.log','stale active application log');
    touch($directory.'/app.log',time()-35*86400);
    file_put_contents($directory.'/api.log','fresh api log');
    file_put_contents($directory.'/app-20200101-010101.log','expired archive');
    touch($directory.'/app-20200101-010101.log',time()-35*86400);

    RuntimeLogMaintenance::maintain($directory,true);
    clearstatcache(true,$directory.'/app.log');
    runtimeLogAssert(filesize($directory.'/app.log')===0,'stale active log is truncated after the locked mtime check');
    runtimeLogAssert(!file_exists($directory.'/app-20200101-010101.log'),'expired archive is removed');
    runtimeLogAssert(file_get_contents($directory.'/api.log')==='fresh api log','fresh active log is preserved');

    file_put_contents($directory.'/scheduler.log','active scheduler log');
    file_put_contents($directory.'/scheduler-20260101-010101.log','scheduler archive');
    $repository=new FileRuntimeLogRepository($directory);
    $cleared=$repository->clearCategory('scheduler');
    clearstatcache(true,$directory.'/scheduler.log');
    runtimeLogAssert((int)$cleared['count']===2,'category clear handles active and archived logs');
    runtimeLogAssert(filesize($directory.'/scheduler.log')===0,'category clear truncates active log');
    runtimeLogAssert(!file_exists($directory.'/scheduler-20260101-010101.log'),'category clear removes archive');
    runtimeLogAssert(file_get_contents($directory.'/api.log')==='fresh api log','category clear does not touch other categories');

    file_put_contents($directory.'/app.log','stale again');
    touch($directory.'/app.log',time()-35*86400);
    $purged=$repository->purgeOlderThan(30,'app');
    clearstatcache(true,$directory.'/app.log');
    runtimeLogAssert((int)$purged['count']===1,'age cleanup is scoped to selected category');
    runtimeLogAssert(filesize($directory.'/app.log')===0,'old selected active log is truncated');
    runtimeLogAssert(file_get_contents($directory.'/api.log')==='fresh api log','age cleanup preserves fresh logs in other categories');

    file_put_contents($directory.'/message.log','writer still appending');
    touch($directory.'/message.log',time()-35*86400);
    $writer=fopen($directory.'/message.log','c+');
    if(!$writer || !flock($writer,LOCK_EX))throw new RuntimeException('cannot lock writer fixture');
    try {
        RuntimeLogMaintenance::maintain($directory,true);
        runtimeLogAssert(file_get_contents($directory.'/message.log')==='writer still appending','maintenance skips a file locked by an active writer');
    } finally {
        flock($writer,LOCK_UN);
        fclose($writer);
    }
    RuntimeLogMaintenance::maintain($directory,true);
    clearstatcache(true,$directory.'/message.log');
    runtimeLogAssert(filesize($directory.'/message.log')===0,'stale log is cleaned after writer releases its lock');


    // Automatic archive pruning must skip a file currently locked by a writer.
    $lockedOldArchive=$directory.'/app-20200101-010102.log';
    file_put_contents($lockedOldArchive,'writer still owns this archive');
    touch($lockedOldArchive,time()-35*86400);
    $archiveWriter=fopen($lockedOldArchive,'rb');
    if(!$archiveWriter || !flock($archiveWriter,LOCK_EX))throw new RuntimeException('cannot lock archive fixture');
    try {
        RuntimeLogMaintenance::maintain($directory,true);
        runtimeLogAssert(file_exists($lockedOldArchive),'automatic retention skips an archive locked by a writer');
    } finally {
        flock($archiveWriter,LOCK_UN);
        fclose($archiveWriter);
    }
    RuntimeLogMaintenance::maintain($directory,true);
    runtimeLogAssert(!file_exists($lockedOldArchive),'automatic retention removes the old archive after its writer releases the lock');

    // Manual category clearing also skips an archive held by a writer.
    $lockedCategoryArchive=$directory.'/scheduler-20260101-010102.log';
    file_put_contents($lockedCategoryArchive,'active archived scheduler writer');
    $categoryWriter=fopen($lockedCategoryArchive,'rb');
    if(!$categoryWriter || !flock($categoryWriter,LOCK_EX))throw new RuntimeException('cannot lock category archive fixture');
    try {
        $lockedClear=$repository->clearCategory('scheduler');
        runtimeLogAssert(file_exists($lockedCategoryArchive),'manual category clear preserves a locked archive');
        runtimeLogAssert((int)$lockedClear['skipped_count']===1,'manual category clear reports a locked archive as skipped');
    } finally {
        flock($categoryWriter,LOCK_UN);
        fclose($categoryWriter);
    }
    $afterUnlockClear=$repository->clearCategory('scheduler');
    runtimeLogAssert(!file_exists($lockedCategoryArchive),'manual category clear removes the archive after the writer releases the lock');
    runtimeLogAssert((int)$afterUnlockClear['skipped_count']===0,'manual category clear reports no skipped files after the lock is released');

    // Age-based archive purge reports files retained because a writer still holds the lock.
    $lockedPurgeArchive=$directory.'/app-20200101-010103.log';
    file_put_contents($lockedPurgeArchive,'old archive currently locked');
    touch($lockedPurgeArchive,time()-35*86400);
    $purgeWriter=fopen($lockedPurgeArchive,'rb');
    if(!$purgeWriter || !flock($purgeWriter,LOCK_EX))throw new RuntimeException('cannot lock purge archive fixture');
    try {
        $lockedPurge=$repository->purgeOlderThan(30,'app');
        runtimeLogAssert(file_exists($lockedPurgeArchive),'age-based purge preserves a locked archive');
        runtimeLogAssert((int)$lockedPurge['skipped_count']===1,'age-based purge reports the locked archive as skipped');
    } finally {
        flock($purgeWriter,LOCK_UN);
        fclose($purgeWriter);
    }
    $afterUnlockPurge=$repository->purgeOlderThan(30,'app');
    runtimeLogAssert(!file_exists($lockedPurgeArchive),'age-based purge removes the archive after the writer releases the lock');
    runtimeLogAssert((int)$afterUnlockPurge['count']===1 && (int)$afterUnlockPurge['skipped_count']===0,'age-based purge reports completed cleanup without skipped files');

    // The admin preview masks common credentials and personally identifying fields.
    $sensitiveLog=$directory.'/app.log';
    file_put_contents($sensitiveLog,
        'password=plain-password Authorization: Bearer bearer-secret '
        .'client_secret="client-secret" session_key=session-secret '
        .'refresh_token=refresh-secret mobile=13800138000 '
        .'id_number=110101199001011234 email=user@example.test' . "\n"
    );
    $preview=$repository->dashboard('app.log','',1);
    $previewText=implode("\n",$preview['lines'] ?? []);
    foreach([
        'plain-password','bearer-secret','client-secret','session-secret',
        'refresh-secret','13800138000','110101199001011234','user@example.test',
    ] as $secret) {
        runtimeLogAssert(strpos($previewText,$secret)===false,'log preview redacts sensitive value: '.$secret);
    }
    runtimeLogAssert(strpos($previewText,'password=')!==false && strpos($previewText,'***')!==false,'redaction retains field context for diagnostics');

    $hiddenSearch=$repository->dashboard('app.log','plain-password',1);
    runtimeLogAssert((int)$hiddenSearch['result']['total']===0,'search cannot reveal redacted credential presence');

    $error=new PDOException("SQLSTATE[HY000]: General error: 1267 Illegal mix of collations; fixture-secret");
    $error->errorInfo=['HY000',1267,'fixture-secret'];
    $report=\WorkerAdmin\Support\RuntimeExceptionReporter::record($error,'fixture-request','users');
    $encoded=\WorkerAdmin\Support\RuntimeExceptionReporter::encode($report);
    runtimeLogAssert($report['category']==='sql_collation'&&$report['code']==='SQLSTATE_HY000_1267','structured SQL collation classification');
    runtimeLogAssert(strpos($encoded,'fixture-secret')===false&&strpos($encoded,'fixture-request')!==false,'raw exception values are not retained');
    $another=\WorkerAdmin\Support\RuntimeExceptionReporter::record($error,'another-request','users');
    runtimeLogAssert($another['fingerprint']===$report['fingerprint'],'fingerprint groups independent requests without losing IDs');
    $generic=\WorkerAdmin\Support\RuntimeExceptionReporter::record(new RuntimeException('password=private-fixture'),'fixture','users');
    runtimeLogAssert(strpos(\WorkerAdmin\Support\RuntimeExceptionReporter::encode($generic),'private-fixture')===false,'unexpected exception text is never logged');
    runtimeLogAssert(strpos(\WorkerAdmin\Support\RuntimeExceptionReporter::publicMessage($generic),'private-fixture')===false,'unexpected exception text is never public');
    $disabled=\WorkerAdmin\Support\RuntimeExceptionReporter::classify(new Error('Call to undefined function WorkerAdmin\\Application\\Chat\\readlink()'));
    runtimeLogAssert($disabled['category']==='php_function'&&$disabled['symbol']==='WorkerAdmin\\Application\\Chat\\readlink','disabled function classification retains safe symbol');
    runtimeLogAssert(\WorkerAdmin\Support\RuntimeExceptionReporter::classify(new ParseError("unexpected private"))['category']==='php_parse','parse error classification');

    fwrite(STDOUT,"Runtime log maintenance contract passed.\n");
} finally {
    foreach(glob($directory.'/*')?:[] as $path)if(is_file($path))@unlink($path);
    @unlink($directory.'/.maintenance.stamp');
    @rmdir($directory);
}
