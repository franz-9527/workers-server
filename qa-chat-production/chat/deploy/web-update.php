<?php
declare(strict_types=1);

use WorkerAdmin\Application\Chat\ChatGoUpdate;

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '/www/wwwroot/worker') exit(2);
define('WORKER_BASE_PATH', $argv[1]);
require WORKER_BASE_PATH.'/app/Support/functions.php';
require WORKER_BASE_PATH.'/app/Application/Chat/ChatGoUpdate.php';

$inbox = ChatGoUpdate::directory();
$state = '/var/lib/bixuan-chat-prod';
$mode = $argv[2] ?? '';
$revision = $argv[3] ?? '';
$pending = $inbox.'/pending.chatgo';

function reportUpdate(string $inbox, string $state, string $revision): void
{
    $tmp = $inbox.'/status.tmp.'.bin2hex(random_bytes(8));
    $data = json_encode(['state'=>$state,'revision'=>$revision], JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $data) !== strlen($data) || !rename($tmp, $inbox.'/status.json')) {
        @unlink($tmp);
        throw new RuntimeException('CHAT_UPDATE_FAILED');
    }
}

umask(0077);
try {
    if ($mode === 'verify') {
        if (!preg_match('/^[a-f0-9]{40}$/D',$revision)) exit(2);
        $directory = $state.'/go-web/'.$revision;
        $meta = ChatGoUpdate::inspect($directory.'/release.chatgo');
        if ($meta['revision'] !== $revision || !hash_equals($meta['sha256'], (string) @hash_file('sha256',$directory.'/chatd'))) exit(2);
        exit;
    }
    if ($mode === 'finish') {
        if (!in_array($argv[4] ?? '', ['applied','rolled_back','CHAT_UPDATE_FAILED'], true)) exit(2);
        reportUpdate($inbox, $argv[4], $revision);
        if (($argv[4] ?? '') !== 'applied' && preg_match('/^[a-f0-9]{40}$/D',$revision)) {
            @unlink($state.'/go-web/'.$revision.'/chatd');
            @unlink($state.'/go-web/'.$revision.'/release.chatgo');
            @rmdir($state.'/go-web/'.$revision);
        }
        unlink($pending);
        exit;
    }
    if ($mode !== 'stage' || !is_file($pending)) exit(2);
    $meta = ChatGoUpdate::inspect($pending, true);
    $revision = $meta['revision'];
    $previous = trim((string) @file_get_contents($state.'/go-web.active',false,null,128));
    $base = readlink(WORKER_BASE_PATH.'/chat/bin/current');
    if ($previous === $revision || ($previous === '' && $base === 'releases/'.$revision)) {
        echo $revision;
        exit;
    }
    $root = $state.'/go-web';
    if (is_link($root) || (!is_dir($root) && !mkdir($root,0700))) throw new RuntimeException('CHAT_UPDATE_FAILED');
    $directory = $root.'/'.$revision;
    if (is_link($directory) || (!is_dir($directory) && !mkdir($directory,0700))) throw new RuntimeException('CHAT_UPDATE_FAILED');
    $tmp = $directory.'/chatd.'.bin2hex(random_bytes(8));
    try {
        if (file_put_contents($tmp,$meta['binary']) !== $meta['bytes'] || !chmod($tmp,0700) || !rename($tmp,$directory.'/chatd')) {
            throw new RuntimeException('CHAT_UPDATE_FAILED');
        }
    } finally {
        if (is_file($tmp)) unlink($tmp);
    }
    $signed = $directory.'/release.chatgo';
    $signedTmp = $signed.'.'.bin2hex(random_bytes(8));
    try {
        if (!copy($pending,$signedTmp) || !rename($signedTmp,$signed)) throw new RuntimeException('CHAT_UPDATE_FAILED');
    } finally {
        if (is_file($signedTmp)) unlink($signedTmp);
    }
    reportUpdate($inbox,'checking',$revision);
    echo $revision;
} catch (Throwable $error) {
    $code = $error->getMessage();
    if (!preg_match('/^CHAT_UPDATE_[A-Z_]+$/D',$code)) $code = 'CHAT_UPDATE_FAILED';
    if ($mode === 'stage') {
        reportUpdate($inbox,$code,$revision);
        @unlink($pending);
    }
    exit(2);
}
