<?php
declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Chat\ChatRecoveryCheckpoint;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$site = '/www/wwwroot/worker';
$control = '/www/backup-control/bixuan-chat/production';
$marker = $site.'/config/chat-production-recovery.json';
umask(0077);


function recoveryWrite(string $path, string $data, int $mode = 0600): void
{
    if (is_link($path)) throw new RuntimeException('CHAT_RECOVERY_UNSAFE_PATH');
    $temporary = $path.'.'.bin2hex(random_bytes(8));
    $file = fopen($temporary, 'x');
    if ($file === false) throw new RuntimeException('CHAT_RECOVERY_WRITE');
    try {
        if (!chmod($temporary, $mode) || fwrite($file, $data) !== strlen($data) || !fflush($file) || !fsync($file)) {
            throw new RuntimeException('CHAT_RECOVERY_WRITE');
        }
    } finally {
        fclose($file);
    }
    if (!rename($temporary, $path)) throw new RuntimeException('CHAT_RECOVERY_WRITE');
}

function recoveryJson(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

$stage = 'SITE';
$probe = ($argv[2] ?? '') === '--check';
try {
    require $site.'/bootstrap.php';
    if ($probe && isset($argv[3])) require $argv[3];
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !is_installed()
        || upgrade_required() || app_config('app.env') !== 'production'
        || (!$probe && (realpath($control) !== $control || (fileperms($control) & 0027) !== 0))) {
        throw new RuntimeException('CHAT_RECOVERY_PRODUCTION_REQUIRED');
    }
    $installed = require $site.'/config/installed.php';
    foreach (['recovery_directory', 'recovery_gate_file', 'recovery_epoch'] as $field) {
        if (!$probe && !empty($installed['chat'][$field])) throw new RuntimeException('CHAT_RECOVERY_ALREADY_CONFIGURED');
    }
    if (!$probe && is_link($marker)) throw new RuntimeException('CHAT_RECOVERY_UNSAFE_PATH');
    if (!$probe && is_file($marker)) {
        if (\WorkerAdmin\Infrastructure\Chat\ChatRecoveryGate::ready((array) app_config('chat', []), 'production')) {
            echo "CHAT_RECOVERY_BASELINE_READY\n";
            exit;
        }
        throw new RuntimeException('CHAT_RECOVERY_EXISTING_BASELINE_BLOCKED');
    }
    $stage = 'MYSQL_CONNECTION';
    $user = (string) getenv('CHAT_RECOVERY_DB_USER');
    $password = getenv('CHAT_RECOVERY_DB_PASSWORD');
    if ($user === '' || $password === false) throw new RuntimeException('CHAT_RECOVERY_DB_CREDENTIALS');
    $connection = (array) app_config('database', []);
    if (!in_array(strtolower((string) ($connection['host'] ?? '')), ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('CHAT_RECOVERY_LOCAL_MYSQL_REQUIRED');
    }
    $connection['username'] = $user;
    $connection['password'] = $password;
    try {
        $pdo = (new Database($connection))->pdo();
    } catch (RuntimeException $error) {
        $cause = $error->getPrevious();
        if (!$cause instanceof PDOException || !in_array((int) ($cause->errorInfo[1] ?? 0), [1044,1045,1698,2002,2003], true)) {
            throw $error;
        }
        // BaoTa often authenticates MySQL root through the local socket only.
        $socket = (string) db()->pdo()->query('SELECT @@GLOBAL.socket')->fetchColumn();
        if (!str_starts_with($socket, '/') || str_contains($socket, ';') || !file_exists($socket)) throw $error;
        $database = (string) ($connection['database'] ?? '');
        if ($database === '' || str_contains($database, ';')) throw $error;
        $pdo = new PDO('mysql:unix_socket='.$socket.';dbname='.$database.';charset=utf8mb4', $user, $password, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_STRINGIFY_FETCHES=>false,
            PDO::ATTR_TIMEOUT=>5,
        ]);
        $pdo->exec("SET time_zone='+00:00'");
        $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }
    $stage = 'BINLOG_COORDINATE';
    $status = $pdo->query('SHOW MASTER STATUS')->fetch(PDO::FETCH_ASSOC);
    if (!$status || !isset($status['File'], $status['Position'])) throw new RuntimeException('CHAT_RECOVERY_BINLOG_REQUIRED');
    $source = (string) $pdo->query('SELECT @@GLOBAL.log_bin_basename')->fetchColumn();
    $binary = $argv[1] ?? '';
    if (!str_starts_with($source, '/') || !str_starts_with($binary, '/') || !is_executable($binary) || !function_exists('proc_open')) {
        throw new RuntimeException('CHAT_RECOVERY_BINLOG_REQUIRED');
    }

    if ($probe) {
        $stage = 'SCHEMA';
        foreach (ChatRecoveryCheckpoint::queries() as $name => $query) {
            $checkName = $name;
            $pdo->query($query.' LIMIT 0')->closeCursor();
        }
        unset($checkName);
        $stage = 'HISTORY';
        // This mode only reads the live database and does not need the control directory.
        $history = ChatRecoveryCheckpoint::firstDeploymentBlockers(ChatRecoveryCheckpoint::databaseFacts($pdo));
        if ($history) throw new RuntimeException('CHAT_RECOVERY_HISTORY_EXISTS');
        echo "CHAT_RECOVERY_PREFLIGHT_READY\n";
        exit;
    }

    $stage = 'CONTROL_FILES';
    $identity = $control.'/initial-identity.json';
    foreach ([$identity, $control.'/gate.json', $control.'/checkpoint.json', $control.'/latest.json', $control.'/continuity.json'] as $file) {
        if (is_link($file)) throw new RuntimeException('CHAT_RECOVERY_UNSAFE_PATH');
    }
    if (is_file($identity)) {
        $initial = json_decode(file_get_contents($identity), true, 8, JSON_THROW_ON_ERROR);
        if (($initial['schema'] ?? null) !== 1 || !preg_match('/^[a-f0-9]{64}$/D', $initial['epoch'] ?? '')) {
            throw new RuntimeException('CHAT_RECOVERY_CONFIG');
        }
    } else {
        foreach (['gate.json', 'checkpoint.json', 'latest.json', 'continuity.json'] as $name) {
            if (file_exists($control.'/'.$name)) throw new RuntimeException('CHAT_RECOVERY_ALREADY_CONFIGURED');
        }
        $initial = ['schema'=>1, 'epoch'=>bin2hex(random_bytes(32)), 'key'=>base64_encode(random_bytes(32))];
        recoveryWrite($identity, recoveryJson($initial));
    }
    $epoch = $initial['epoch'];
    $key = base64_decode($initial['key'] ?? '', true);
    if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('CHAT_RECOVERY_CONFIG');
    $checkpoint = new ChatRecoveryCheckpoint($pdo, 'production', $key, $control, $epoch);
    $existingGate = json_decode((string) @file_get_contents($control.'/gate.json'), true);
    if (($existingGate['state'] ?? '') === 'ready') {
        $config = ['recovery_gate_file'=>$control.'/gate.json','recovery_epoch'=>$epoch];
        if (!\WorkerAdmin\Infrastructure\Chat\ChatRecoveryGate::ready($config, 'production')) {
            throw new RuntimeException('CHAT_RECOVERY_ALREADY_CONFIGURED');
        }
        if (!chgrp($control.'/gate.json', 'www')) throw new RuntimeException('CHAT_RECOVERY_GATE_PERMISSIONS');
        recoveryWrite($marker, $epoch."\n", 0644);
        echo "CHAT_RECOVERY_BASELINE_READY\n";
        exit;
    }
    $stage = 'CAPTURE';
    $checkpoint->block();
    $captured = $checkpoint->capture(true, $history);
    $stage = 'BINLOG_ARCHIVE';
    $watermark = $captured['watermark'];
    $name = $watermark['file'];
    $position = (int) $watermark['position'];
    if (!preg_match('/^[A-Za-z0-9_-]+\.[0-9]{6}$/D', $name) || $position < 4
        || !str_starts_with($name, basename($source).'.')) {
        throw new RuntimeException('CHAT_RECOVERY_BINLOG_REQUIRED');
    }
    $archiveDirectory = $control.'/archive';
    if (is_link($archiveDirectory) || (!is_dir($archiveDirectory) && !mkdir($archiveDirectory, 0700))) {
        throw new RuntimeException('CHAT_RECOVERY_BINLOG_ARCHIVE');
    }
    $archive = $archiveDirectory.'/'.$name;
    if (is_link($archive)) throw new RuntimeException('CHAT_RECOVERY_UNSAFE_PATH');
    $temporary = $archive.'.'.bin2hex(random_bytes(8));
    $input = fopen(dirname($source).'/'.$name, 'rb');
    $output = fopen($temporary, 'x');
    if ($input === false || $output === false) throw new RuntimeException('CHAT_RECOVERY_BINLOG_ARCHIVE_OPEN');
    try {
        if (stream_copy_to_stream($input, $output, $position) !== $position || !fflush($output) || !fsync($output)) {
            throw new RuntimeException('CHAT_RECOVERY_BINLOG_ARCHIVE_COPY');
        }
    } finally {
        fclose($input);
        fclose($output);
    }
    $proc = proc_open([$binary,'--no-defaults','--verify-binlog-checksum','--stop-position='.$position,$temporary],
        [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
    $archiveStatus = is_resource($proc) ? proc_close($proc) : null;
    if ($archiveStatus !== 0 || !rename($temporary, $archive)) {
        @unlink($temporary);
        throw new RuntimeException($archiveStatus !== 0 ? 'CHAT_RECOVERY_BINLOG_ARCHIVE_VERIFY' : 'CHAT_RECOVERY_BINLOG_ARCHIVE_PUBLISH');
    }
    $from = $watermark;
    $from['position'] = '4';
    $digest = hash_file('sha256', $archive);
    if (!is_string($digest)) throw new RuntimeException('CHAT_RECOVERY_BINLOG_ARCHIVE');
    $proof = ['schema'=>1,'environment'=>'production','epoch'=>$epoch,'watermark'=>$watermark,'complete'=>true,
        'from'=>$from,'archive_sha256'=>$digest];
    recoveryWrite($control.'/continuity.json', recoveryJson([
        'payload'=>$proof, 'mac'=>hash_hmac('sha256', recoveryJson($proof), $key),
    ]));
    $stage = 'VERIFY';
    $result = $checkpoint->verify(true, true);
    if (($result['status'] ?? '') !== 'ready') throw new RuntimeException('CHAT_RECOVERY_SOURCE_NOT_QUIESCENT');
    $stage = 'PUBLISH_GATE';
    if (!chgrp($control.'/gate.json', 'www')) throw new RuntimeException('CHAT_RECOVERY_GATE_PERMISSIONS');
    recoveryWrite($marker, $epoch."\n", 0644);
    echo "CHAT_RECOVERY_BASELINE_READY\n";
} catch (Throwable $e) {
    $code = $e->getMessage();
    $mysqlCode = 0;
    for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
        if ($cause instanceof PDOException && isset($cause->errorInfo[1])) {
            $mysqlCode = (int) $cause->errorInfo[1];
            break;
        }
    }
    if (!in_array($code,['CHAT_RECOVERY_SNAPSHOT_PERMISSION','CHAT_RECOVERY_SNAPSHOT_LOCK_FAILED'],true)) $code = match ($mysqlCode) {
        1045,1698 => 'CHAT_RECOVERY_MYSQL_AUTH_FAILED',
        1044,1142 => 'CHAT_RECOVERY_MYSQL_DATABASE_PERMISSION',
        1049 => 'CHAT_RECOVERY_MYSQL_DATABASE_NOT_FOUND',
        1054,1146 => 'CHAT_RECOVERY_MYSQL_SCHEMA_MISMATCH',
        1227 => 'CHAT_RECOVERY_BINLOG_PERMISSION',
        2002,2003 => 'CHAT_RECOVERY_MYSQL_UNREACHABLE',
        default => preg_match('/^CHAT_RECOVERY_[A-Z0-9_]+$/D', $code) === 1 ? $code : 'CHAT_RECOVERY_INIT_FAILED',
    };
    $diagnostic = ['step'=>$stage, 'mysql_code'=>$mysqlCode, 'exception'=>get_class($e)];
    if (isset($archiveStatus) && $archiveStatus !== 0) $diagnostic['mysqlbinlog_exit'] = $archiveStatus;
    if (isset($checkName)) $diagnostic['check'] = $checkName;
    if ($code === 'CHAT_RECOVERY_HISTORY_EXISTS' && !empty($history)) $diagnostic['history_rows'] = $history;
    fwrite(STDERR, $code.' '.recoveryJson($diagnostic)."\n");
    exit(2);
}
