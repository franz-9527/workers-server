<?php

declare(strict_types=1);

use WorkerAdmin\Application\Identity\MerchantProfileReader;
use WorkerAdmin\Contracts\MerchantProfileRepository;
use WorkerAdmin\Contracts\SettingsProvider;

$root = dirname(__DIR__, 2);
require_once $root.'/server/app/Contracts/MerchantProfileRepository.php';
require_once $root.'/server/app/Contracts/SettingsProvider.php';
require_once $root.'/server/app/Application/Identity/MerchantProfileReader.php';

function merchantOcrSame($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, '[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

final class MerchantOcrSettings implements SettingsProvider
{
    public function get(string $key, $default = null) { return $default; }
    public function bool(string $key, bool $default = false): bool { return $default; }
    public function int(string $key, int $default = 0): int { return $default; }
}

final class MerchantOcrRepo implements MerchantProfileRepository
{
    public ?array $row = null;
    public function findForUser(int $userId): ?array { return $this->row; }
    public function signedLicenseUrl(string $mediaPublicId, array $actor, int $merchantProfileId): string { return 'signed://'.$mediaPublicId; }
}

$repo = new MerchantOcrRepo();
$reader = new MerchantProfileReader($repo, new MerchantOcrSettings());
$base = [
    'id'=>9,
    'verification_status'=>'rejected',
    'recognition_level'=>'verified',
    'review_reason'=>'地址需补充',
    'business_name'=>'示例商家',
    'business_address'=>'旧地址',
    'license_media_public_id'=>'MED-LICENSE',
    'order_count_7d'=>0,
    'same_phone_warning_count'=>0,
    'ocr_payload_ciphertext'=>'encrypted',
];

$repo->row = $base + [
    'ocr_updated_at'=>'2026-10-16 00:00:00',
    'license_media_created_at'=>'2026-10-16 00:02:00',
];
$result = $reader->read(['user_id'=>7]);
merchantOcrSame($result['license_ocr_verified'], true, '当前执照上传紧随 OCR 后时刷新可恢复识别状态');
merchantOcrSame($result['license']['id'], 'MED-LICENSE', '恢复状态仍绑定当前执照媒体');

$repo->row = $base + [
    'ocr_updated_at'=>'2026-10-15 20:00:00',
    'license_media_created_at'=>'2026-10-16 00:02:00',
];
$result = $reader->read(['user_id'=>7]);
merchantOcrSame($result['license_ocr_verified'], false, '后来替换执照不能复用过旧 OCR');

$repo->row = $base + [
    'ocr_updated_at'=>null,
    'license_media_created_at'=>'2026-10-16 00:02:00',
];
$result = $reader->read(['user_id'=>7]);
merchantOcrSame($result['license_ocr_verified'], false, '缺少 OCR 时间不得猜测已识别');

echo "V2.5.0 merchant OCR restore regression: OK\n";
