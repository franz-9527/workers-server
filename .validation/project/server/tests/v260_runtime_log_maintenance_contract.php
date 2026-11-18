<?php

declare(strict_types=1);

use WorkerAdmin\Infrastructure\Persistence\FileRuntimeLogRepository;
use WorkerAdmin\Infrastructure\Persistence\RuntimeLogMaintenance;

require_once dirname(__DIR__,2).'/server/app/Contracts/RuntimeLogRepository.php';
require_once dirname(__DIR__,2).'/server/app/Infrastructure/Persistence/RuntimeLogMaintenance.php';
require_once dirname(__DIR__,2).'/server/app/Infrastructure/Persistence/FileRuntimeLogRepository.php';

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

    fwrite(STDOUT,"Runtime log maintenance contract passed.\n");
} finally {
    foreach(glob($directory.'/*')?:[] as $path)if(is_file($path))@unlink($path);
    @unlink($directory.'/.maintenance.stamp');
    @rmdir($directory);
}
