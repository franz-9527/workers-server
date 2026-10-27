<?php

declare(strict_types=1);

// Run with PHP extensions mbstring and pdo_sqlite. SQLite only adapts MySQL syntax;
// production repositories, use cases, transactions and media switches execute unchanged.
spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'WorkerAdmin\\') !== 0) return;
    $path = dirname(__DIR__) . '/server/app/' . str_replace('\\', '/', substr($class, 12)) . '.php';
    if (is_file($path)) require_once $path;
});

use WorkerAdmin\Application\Identity\ApiActorResolver;
use WorkerAdmin\Application\Identity\OcrResultRecorder;
use WorkerAdmin\Application\Identity\RealNameSubmission;
use WorkerAdmin\Contracts\ApiActorRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\RealNameSubmissionNotifier;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\TransactionManager;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMediaRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOcrResultRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseRealNameReauthenticationRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseRealNameSubmissionRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseWorkerMoneyRepository;

function app_config(string $key, $default = null) { return $key === 'app.key' ? 'test-key' : $default; }
function app_url(string $path): string { return 'https://test.invalid/' . $path; }
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function fails(callable $callback, int $code): void {
    try { $callback(); } catch (RuntimeException $error) { check($error->getCode() === $code, $error->getMessage()); return; }
    throw new RuntimeException('Expected rejection ' . $code);
}

final class DraftDatabase extends Database
{
    private PDO $connection;
    public bool $failPending = false;
    public function __construct() {
        parent::__construct([]);
        $this->connection = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->connection->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => gmdate('Y-m-d H:i:s'));
        $this->connection->sqliteCreateFunction('IF', static fn($condition, $yes, $no) => $condition ? $yes : $no);
    }
    public function pdo() { return $this->connection; }
    private function adapt($sql): string {
        return str_replace([' FOR UPDATE', 'INSERT IGNORE', 'DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY)'], ['', 'INSERT OR IGNORE', "datetime('now','+1 day')"], (string)$sql);
    }
    public function fetch($sql, array $params = []) { return parent::fetch($this->adapt($sql), $params); }
    public function fetchAll($sql, array $params = []) { return parent::fetchAll($this->adapt($sql), $params); }
    public function fetchValue($sql, array $params = []) { return parent::fetchValue($this->adapt($sql), $params); }
    public function execute($sql, array $params = []) {
        if ($this->failPending && strpos((string)$sql, "status='pending',review_reason") !== false) throw new RuntimeException('Injected write failure', 500);
        return parent::execute($this->adapt($sql), $params);
    }
}
final class DraftCipher implements PrivateValueCipher
{
    public function encrypt(string $plainText, string $context): ?string { return $context . ':' . base64_encode($plainText); }
    public function decrypt(string $cipherText, string $context): string {
        if (strpos($cipherText, $context . ':') !== 0) throw new RuntimeException('Bad ciphertext');
        return (string)base64_decode(substr($cipherText, strlen($context) + 1), true);
    }
    public function hmac(string $plainText, string $context): string { return hash('sha256', $context . $plainText); }
}
final class DraftSettings implements SettingsProvider
{
    public int $limit = 1;
    public function get(string $key, $default = null) { return $key === 'wechat.miniapp_appid' ? 'test-app' : $default; }
    public function int(string $key, int $default = 0): int { return $key === 'worker.realname_self_reauth_limit' ? $this->limit : $default; }
    public function bool(string $key, bool $default = false): bool { return $default; }
}
final class DraftActors implements ApiActorRepository
{
    public function findByTokenHash(string $tokenHash, string $miniappAppId): ?array { return $tokenHash === hash('sha256', str_repeat('a', 64)) ? ['user_id'=>8,'worker_id'=>12,'session_id'=>1] : null; }
    public function touchSession(int $sessionId): void {}
    public function findDemoActor(int $userId): ?array { return null; }
    public function findTestActor(int $userId, int $instanceId): ?array { return null; }
    public function clearDemoActor(int $sessionId): void {}
    public function clearTestActor(int $sessionId): void {}
}
final class DraftTransaction implements TransactionManager
{
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }
    public function run(callable $callback) { return $this->db->transaction($callback); }
}
final class DraftNotifier implements RealNameSubmissionNotifier
{
    public int $sent = 0;
    public function notifySubmitted(int $verificationId, int $workerId, string $realName): void { $this->sent++; }
}

$db = new DraftDatabase(); $cipher = new DraftCipher(); $settings = new DraftSettings();
$db->pdo()->exec(<<<'SQL'
CREATE TABLE worker_real_name_verifications (
 id INTEGER PRIMARY KEY, worker_id INTEGER UNIQUE, status TEXT, real_name_masked TEXT DEFAULT '', real_name_ciphertext TEXT,
 id_number_masked TEXT DEFAULT '', id_number_ciphertext TEXT, id_number_hmac TEXT, ocr_payload_ciphertext TEXT,
 ocr_provider TEXT DEFAULT '', ocr_updated_at TEXT, review_reason TEXT, reviewed_by INTEGER, submitted_at TEXT, reviewed_at TEXT, created_at TEXT, updated_at TEXT
);
CREATE TABLE media_files (id INTEGER PRIMARY KEY, public_id TEXT, storage_disk TEXT, storage_key TEXT, mime_type TEXT, extension TEXT, size_bytes INTEGER, width_px INTEGER, height_px INTEGER, sha256 TEXT, compression_quality INTEGER, metadata_json TEXT, status TEXT, reference_count INTEGER, created_at TEXT, pending_delete_at TEXT);
CREATE TABLE media_links (id INTEGER PRIMARY KEY, media_file_id INTEGER, entity_type TEXT, entity_id INTEGER, purpose TEXT, sort_order INTEGER, created_by_type TEXT, created_by_id INTEGER, created_at TEXT);
CREATE TABLE users (id INTEGER PRIMARY KEY, status TEXT);
CREATE TABLE worker_profiles (id INTEGER PRIMARY KEY, user_id INTEGER);
CREATE TABLE login_sessions (id INTEGER PRIMARY KEY, user_id INTEGER, token_hash TEXT, status TEXT, expires_at TEXT, last_seen_at TEXT);
CREATE TABLE ocr_usage_logs (id INTEGER PRIMARY KEY, client_msg_id TEXT UNIQUE, user_id INTEGER, worker_id INTEGER, scene TEXT, ocr_type INTEGER, status TEXT, quota_day TEXT, result_digest TEXT, created_at TEXT);
CREATE TABLE worker_balance_ledger (worker_id INTEGER, available_delta_cents INTEGER, frozen_delta_cents INTEGER, status TEXT);
CREATE TABLE worker_payout_accounts (id INTEGER, worker_id INTEGER, account_type TEXT, holder_name_masked TEXT, account_masked TEXT, status TEXT, is_default INTEGER);
CREATE TABLE worker_collection_code_items (id INTEGER, worker_id INTEGER, media_file_id INTEGER, status TEXT, review_reason TEXT, submitted_at TEXT, reviewed_at TEXT, updated_at TEXT);
INSERT INTO users VALUES (8,'active');
INSERT INTO worker_profiles VALUES (12,8);
SQL);
$token = str_repeat('a', 64);
$db->insert("INSERT INTO login_sessions VALUES (1,8,:hash,'active','2099-01-01',NULL)", ['hash'=>hash('sha256', $token)]);
$originalOcr = ['front'=>['normalized'=>['name'=>'原姓名','id'=>'110101199001010011'], 'raw'=>['original'=>true]], 'back'=>['normalized'=>['valid_date'=>'2020-2040']]];
$db->insert("INSERT INTO worker_real_name_verifications (id,worker_id,status,real_name_masked,real_name_ciphertext,id_number_masked,id_number_ciphertext,ocr_payload_ciphertext,ocr_provider,ocr_updated_at,submitted_at,reviewed_at,reviewed_by) VALUES (21,12,'approved','原**',:name,'110***********0011',:id,:ocr,'original-provider','2026-01-01','2026-01-01','2026-01-02',7)", ['name'=>$cipher->encrypt('原姓名','worker-real-name'),'id'=>$cipher->encrypt('110101199001010011','worker-id-number'),'ocr'=>$cipher->encrypt(json_encode($originalOcr),'worker-realname-ocr')]);
foreach (['id_front','id_back'] as $i=>$purpose) {
    $media = $db->insert("INSERT INTO media_files(public_id,status,reference_count) VALUES(:public_id,'active',1)", ['public_id'=>'original-'.$purpose]);
    $db->insert("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose) VALUES(:media,'worker_verification',21,:purpose)", ['media'=>$media,'purpose'=>$purpose]);
}
$reauth = new DatabaseRealNameReauthenticationRepository($db, $cipher);
$repo = new DatabaseRealNameSubmissionRepository($db, $cipher, new ApiActorResolver(new DraftActors(), $settings), $settings);
$notifier = new DraftNotifier();
$submit = new RealNameSubmission($repo, $notifier, new DraftTransaction($db));
$ocr = new OcrResultRecorder(new DatabaseOcrResultRepository($db), $settings, new DraftTransaction($db), $cipher);
$mediaRepo = new DatabaseMediaRepository($db, $settings, $cipher);
$money = new DatabaseWorkerMoneyRepository($db, $cipher, $settings);
$actor = ['user_id'=>8,'worker_id'=>12];
$row = static fn(): array => $db->fetch('SELECT * FROM worker_real_name_verifications WHERE id=21');
$payload = static fn(): array => json_decode($cipher->decrypt((string)$row()['ocr_payload_ciphertext'], 'worker-realname-ocr'), true);
$purposes = static fn(): array => array_column($db->fetchAll('SELECT purpose FROM media_links ORDER BY id'), 'purpose');
$summary = static fn(): array => $money->financeSummary($actor, 'identity', 1, 10)['real_name'];
$before = $row(); $originalPurposes = $purposes();
$reauth->restartApproved(12, 1); $reauth->restartApproved(12, 1);
check($row() === $before && $purposes() === $originalPurposes, 'Entering/re-entering reauthentication changed current verification');
check($reauth->policy(12)['self_reauth_used'] === 0 && !isset($summary()['draft']), 'Empty editing counted as a draft or history');

$newId = '110101199202020022';
$saveOcr = static function (string $side, string $msg) use ($ocr, $token, $newId): void {
    $ocr->record($token, ['scene'=>'realname_'.$side,'ocr_type'=>1,'client_msg_id'=>$msg,'normalized'=>$side === 'front' ? ['name'=>'新姓名','id'=>$newId] : ['valid_date'=>'2025-2045'],'raw'=>['new'=>$side]]);
};
$upload = static function (string $purpose, string $id) use ($mediaRepo, $db, $actor): void {
    $context = $db->transaction(fn(): array => $mediaRepo->prepareRealNameUpload($actor, $purpose, true));
    $context['ocr_client_msg_id'] = $id;
    $image = ['storage_key'=>$id.'.png','mime_type'=>'image/png','extension'=>'png','size_bytes'=>12,'width_px'=>100,'height_px'=>100,'sha256'=>str_repeat('1',64),'compression_quality'=>100];
    $db->transaction(static function () use ($mediaRepo, $context, $image, $id): void { $mediaRepo->storeRealNameMedia($context, $image, $id); });
};
$saveOcr('front','new-front'); $upload('id_front','new-front');
$partial = $summary();
check($partial['status'] === 'approved' && $partial['media']['id_front']['id'] === 'original-id_front', 'Draft replaced current approval or photo');
check($partial['draft']['real_name'] === '新姓名' && $partial['draft']['ocr_front_ok'] && !$partial['draft']['ocr_back_ok'], 'Partial draft did not restore');
check($row()['ocr_provider'] === 'original-provider' && $row()['ocr_updated_at'] === '2026-01-01', 'Draft changed current OCR metadata');
fails(fn() => $submit->submit($token,['real_name'=>'新姓名','id_number'=>$newId]), 422);
$submit->saveDraft($token,['real_name'=>'修正姓名','id_number'=>$newId]);
$reauth->restartApproved(12,1);
check($summary()['draft']['real_name'] === '修正姓名', 'Re-entering lost edited draft fields');
$saveOcr('back','new-back'); $upload('id_back','new-back');
check($summary()['draft']['ocr_back_ok'] && $summary()['draft']['real_name'] === '修正姓名', 'Second side lost first-side draft edits');
check(!isset($payload()['history']) && $row()['status'] === 'approved', 'Saved draft archived current verification');
$ocr->record($token, ['scene'=>'realname_front','ocr_type'=>1,'client_msg_id'=>'failed-replacement','normalized'=>['name'=>'替换姓名','id'=>$newId],'raw'=>[]]);
check($summary()['draft']['real_name'] === '修正姓名' && $summary()['draft']['media']['id_front']['id'] === 'new-front', 'Failed replacement mixed new OCR into saved draft photos');
$context = $db->transaction(fn(): array => $mediaRepo->prepareRealNameUpload($actor,'id_front',true));
check(in_array('draft_id_front',$purposes(),true), 'Preparing a replacement deleted existing draft');
$allBefore = [$row(), $db->fetchAll('SELECT * FROM media_links ORDER BY id')];
$settings->limit = 0;
fails(fn() => $submit->submit($token,['real_name'=>'修正姓名','id_number'=>$newId]),409);
check([$row(), $db->fetchAll('SELECT * FROM media_links ORDER BY id')] === $allBefore, 'Changed reauth policy caused partial writes');
$settings->limit = 1; $db->failPending = true;
fails(fn() => $submit->submit($token,['real_name'=>'修正姓名','id_number'=>$newId]),500);
check([$row(), $db->fetchAll('SELECT * FROM media_links ORDER BY id')] === $allBefore && $notifier->sent === 0, 'Failed submission did not roll back history/photos');
$db->failPending = false;
$result = $submit->submit($token,['real_name'=>'修正姓名','id_number'=>$newId]);
check($result['status'] === 'pending' && $notifier->sent === 1, 'Official submission did not notify once');
$history = $payload()['history'];
check(count($history) === 1 && $history[0]['real_name'] === '原姓名' && $history[0]['ocr'] === $originalOcr, 'Original record not archived exactly once');
check(!isset($payload()['reauth_draft']) && !isset($history[0]['ocr']['reauth_draft']), 'Draft mixed into archived original OCR');
check($purposes() === ['history_1_id_front','history_1_id_back','id_front','id_back'], 'Photos not switched atomically');
check((int)$db->fetchValue('SELECT SUM(reference_count) FROM media_files') === 4, 'Archiving changed media reference counts');
$submit->submit($token,['real_name'=>'修正姓名','id_number'=>$newId]);
check(count($payload()['history']) === 1 && $notifier->sent === 1, 'Duplicate submission consumed another history/notification');
fails(fn() => $submit->submit($token,['real_name'=>'其他姓名','id_number'=>$newId]),409);
fails(fn() => $saveOcr('front','pending-front'),409);
fails(fn() => $mediaRepo->prepareRealNameUpload($actor,'id_front',true),409);
fails(fn() => $mediaRepo->storeRealNameMedia($context, [], 'late-photo'),409);
$db->execute("UPDATE worker_real_name_verifications SET status='approved' WHERE id=21");
fails(fn() => $reauth->restartApproved(12,1),409);
check($reauth->policy(12)['self_reauth_used'] === 1, 'Official reauth did not consume one use');
fails(fn() => $saveOcr('front','limit-front'),409);

// First authentication drafts also resume without being turned into history.
$db->execute('DELETE FROM media_links');
$db->execute('DELETE FROM worker_real_name_verifications');
$saveOcr('front','first-front'); $upload('id_front','first-front');
check($summary()['status'] === 'draft' && $summary()['draft']['real_name'] === '新姓名', 'First authentication draft did not resume');
check($reauth->policy(12)['self_reauth_used'] === 0, 'First authentication consumed reauthentication allowance');
$saveOcr('back','first-back'); $upload('id_back','first-back');
$submit->submit($token,['real_name'=>'新姓名','id_number'=>$newId]);
check($summary()['status'] === 'pending' && $reauth->policy(12)['self_reauth_used'] === 0, 'First submission created a historical reauthentication');
fails(fn() => $submit->saveDraft($token,['real_name'=>'不能修改','id_number'=>$newId]),409);
fails(fn() => $submit->saveDraft(str_repeat('b',64),['real_name'=>'其他用户','id_number'=>$newId]),401);
// Admin cancellation also preserves a saved reauth draft without leaving stale draft photo links.
$db->execute("UPDATE worker_real_name_verifications SET status='approved'");
$saveOcr('front','admin-draft-front'); $upload('id_front','admin-draft-front');
$saveOcr('back','admin-draft-back'); $upload('id_back','admin-draft-back');
$submit->saveDraft($token,['real_name'=>'保留的草稿','id_number'=>$newId]);
$reauth->revokeByAdmin(12,7,'测试取消');
check($summary()['status'] === 'revoked' && $summary()['draft']['real_name'] === '保留的草稿' && $summary()['draft']['ocr_back_ok'], 'Admin cancellation lost saved draft');
$submit->submit($token,['real_name'=>'保留的草稿','id_number'=>$newId]);
$db->execute("UPDATE worker_real_name_verifications SET status='approved'");
check(!isset($summary()['draft']), 'Old draft photos reopened an already-submitted draft');
echo "Real-name draft, history, policy, media and rollback regression passed.\n";
