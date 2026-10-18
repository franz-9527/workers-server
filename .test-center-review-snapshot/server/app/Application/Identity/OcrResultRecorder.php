<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Identity;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\OcrResultRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\TransactionManager;

/** 微信 OCR 认证结果保存用例。normalized 仅接受接口文档原字段名。 */
final class OcrResultRecorder
{
    private OcrResultRepository $repository;
    private SettingsProvider $settings;
    private TransactionManager $transaction;
    private PrivateValueCipher $cipher;

    public function __construct(
        OcrResultRepository $repository,
        SettingsProvider $settings,
        TransactionManager $transaction,
        PrivateValueCipher $cipher
    ) {
        $this->repository = $repository;
        $this->settings = $settings;
        $this->transaction = $transaction;
        $this->cipher = $cipher;
    }

    /** @param array<string,mixed> $body */
    public function record(string $token, array $body): array
    {
        if (preg_match('/^[A-Fa-f0-9]{64}$/', $token) !== 1) throw new RuntimeException('请重新登录', 401);
        $actor = $this->repository->findActorByTokenHash(hash('sha256', $token));
        if (!$actor) throw new RuntimeException('登录已过期，请重新进入小程序', 401);
        $this->repository->touchSession((int)$actor['session_id']);

        $scene = trim((string)($body['scene'] ?? ''));
        $clientMsgId = trim((string)($body['client_msg_id'] ?? ''));
        $ocrType = (int)($body['ocr_type'] ?? 0);
        $normalized = is_array($body['normalized'] ?? null) ? $body['normalized'] : [];
        $raw = is_array($body['raw'] ?? null) ? $body['raw'] : [];

        $scenes = ['realname_front' => 1, 'realname_back' => 1, 'merchant_license' => 7];
        if (!isset($scenes[$scene]) || $ocrType !== $scenes[$scene]) throw new RuntimeException('OCR 业务类型不正确', 422);
        if ($clientMsgId === '' || strlen($clientMsgId) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $clientMsgId) !== 1) {
            throw new RuntimeException('OCR 请求编号无效', 422);
        }

        $normalized = $this->onlyDocumentedFields($scene, $normalized);
        $rawJson = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $normalizedJson = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($rawJson) || !is_string($normalizedJson) || strlen($rawJson) > 180000 || strlen($normalizedJson) > 30000) {
            throw new RuntimeException('OCR 返回数据过大或格式异常', 422);
        }
        $this->validateNormalized($scene, $normalized);
        $resultDigest = hash('sha256', json_encode([$normalized, $raw], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        $quotaDay = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $dailyQuota = max(0, min(1000000, $this->settings->int('wechat.ocr.daily_quota', 100)));

        $existing = $this->repository->findUsage($clientMsgId);
        if ($existing) {
            $this->assertSameUsage($existing, (int)$actor['user_id'], $scene, $ocrType, $resultDigest);
            return $this->quotaResult($quotaDay, $dailyQuota);
        }

        $this->transaction->run(function () use ($actor, $scene, $clientMsgId, $ocrType, $normalized, $raw, $quotaDay, $resultDigest): void {
            $existing = $this->repository->findUsage($clientMsgId);
            if ($existing) {
                $this->assertSameUsage($existing, (int)$actor['user_id'], $scene, $ocrType, $resultDigest);
                return;
            }
            if ($scene === 'realname_front' || $scene === 'realname_back') {
                $this->saveRealNamePayload($actor, $scene, $clientMsgId, $normalized, $raw);
            } else {
                $this->saveMerchantPayload($actor, $clientMsgId, $normalized, $raw);
            }
            $inserted = $this->repository->appendUsage([
                'client_msg_id' => $clientMsgId,
                'user_id' => (int)$actor['user_id'],
                'worker_id' => $actor['worker_id'] === null ? null : (int)$actor['worker_id'],
                'scene' => $scene,
                'ocr_type' => $ocrType,
                'quota_day' => $quotaDay,
                'result_digest' => $resultDigest,
            ]);
            if (!$inserted) {
                $race = $this->repository->findUsage($clientMsgId);
                if (!$race) throw new RuntimeException('OCR 调用台账写入失败，请重试。', 500);
                $this->assertSameUsage($race, (int)$actor['user_id'], $scene, $ocrType, $resultDigest);
            }
        });
        return $this->quotaResult($quotaDay, $dailyQuota);
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    private function onlyDocumentedFields(string $scene, array $normalized): array
    {
        if ($scene === 'merchant_license') {
            $allowed = [
                'reg_num','serial','legal_representative','enterprise_name','type_of_organization','address',
                'type_of_enterprise','business_scope','registered_capital','paid_in_capital','valid_period',
                'registered_date','cert_position','img_size','image_path',
            ];
        } else {
            $allowed = [
                'type','name','gender','nationality','birth','address','id','valid_date',
                'card_position','image_width','image_height','card_property',
            ];
        }
        return array_intersect_key($normalized, array_flip($allowed));
    }

    /** @param array<string,mixed> $normalized */
    private function validateNormalized(string $scene, array $normalized): void
    {
        if ($scene === 'realname_front') {
            $name = $this->text($normalized, 'name');
            $idNumber = strtoupper(preg_replace('/\s+/', '', $this->text($normalized, 'id')) ?: '');
            if ($name === '' || preg_match('/^\d{17}[0-9X]$/', $idNumber) !== 1) {
                throw new RuntimeException('身份证人像面识别结果不完整，请重新识别', 422);
            }
            return;
        }
        if ($scene === 'realname_back') {
            if ($this->text($normalized, 'valid_date') === '') {
                throw new RuntimeException('身份证国徽面未识别到有效期限，请重新识别', 422);
            }
            return;
        }
        if ($this->text($normalized, 'enterprise_name') === '' && $this->text($normalized, 'reg_num') === '') {
            throw new RuntimeException('营业执照识别结果不完整，请重新识别', 422);
        }
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $normalized @param array<string,mixed> $raw */
    private function saveRealNamePayload(array $actor, string $scene, string $clientMsgId, array $normalized, array $raw): void
    {
        if (empty($actor['worker_id'])) throw new RuntimeException('请先完成师傅身份建立后再提交实名认证。', 422);
        $verification = $this->repository->findRealNameForUpdate((int)$actor['worker_id']);
        if (!$verification) $verification = $this->repository->createRealNameDraft((int)$actor['worker_id']);
        if (in_array((string)($verification['status'] ?? ''), ['approved', 'pending'], true)) {
            throw new RuntimeException('实名认证已通过或正在审核，不能重新写入 OCR 资料。', 409);
        }

        $payload = $this->decodeEncryptedJson((string)($verification['ocr_payload_ciphertext'] ?? ''), 'worker-realname-ocr');
        $slot = $scene === 'realname_front' ? 'front' : 'back';
        $payload[$slot] = [
            'provider' => 'wechat_service_market',
            'ocr_type' => 1,
            'client_msg_id' => $clientMsgId,
            'normalized' => $normalized,
            'raw' => $raw,
            'saved_at' => gmdate('c'),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 2000000) throw new RuntimeException('实名认证 OCR 历史数据过大，已停止保存，请联系管理员处理。', 422);
        $encrypted = $this->cipher->encrypt($json, 'worker-realname-ocr');
        if (!is_string($encrypted) || $encrypted === '') throw new RuntimeException('实名认证 OCR 数据加密失败。', 500);
        $this->repository->updateRealNameOcr((int)$verification['id'], $encrypted);
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $normalized @param array<string,mixed> $raw */
    private function saveMerchantPayload(array $actor, string $clientMsgId, array $normalized, array $raw): void
    {
        $profile = $this->repository->findMerchantForUpdate((int)$actor['user_id']);
        if (!$profile) $profile = $this->repository->createMerchantDraft((int)$actor['user_id']);
        if (in_array((string)($profile['verification_status'] ?? ''), ['approved', 'pending'], true)) {
            throw new RuntimeException('商家认证已通过或正在审核，不能重新写入 OCR 资料。', 409);
        }

        $payload = ['license' => [
            'provider' => 'wechat_service_market',
            'ocr_type' => 7,
            'client_msg_id' => $clientMsgId,
            'normalized' => $normalized,
            'raw' => $raw,
            'saved_at' => gmdate('c'),
        ]];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 220000) throw new RuntimeException('营业执照 OCR 数据过大，已停止保存。', 422);
        $encryptedPayload = $this->cipher->encrypt($json, 'merchant-license-ocr');
        if (!is_string($encryptedPayload) || $encryptedPayload === '') throw new RuntimeException('营业执照 OCR 数据加密失败。', 500);

        $name = mb_substr($this->text($normalized, 'enterprise_name'), 0, 160);
        $address = mb_substr($this->text($normalized, 'address'), 0, 500);
        $legalRepresentative = mb_substr($this->text($normalized, 'legal_representative'), 0, 80);
        $businessScope = mb_substr($this->text($normalized, 'business_scope'), 0, 1000);
        $creditCode = strtoupper(preg_replace('/\s+/', '', $this->text($normalized, 'reg_num')) ?: '');
        $creditCode = preg_match('/^[0-9A-Z]{8,32}$/', $creditCode) === 1 ? $creditCode : '';
        $masked = $creditCode === '' ? '' : (strlen($creditCode) > 8
            ? substr($creditCode, 0, 4) . str_repeat('*', strlen($creditCode) - 8) . substr($creditCode, -4)
            : str_repeat('*', max(0, strlen($creditCode) - 4)) . substr($creditCode, -4));

        $this->repository->updateMerchantOcr((int)$profile['id'], [
            'payload' => $encryptedPayload,
            'name' => $name !== '' ? $name : (string)($profile['business_name'] ?? ''),
            'address' => $address !== '' ? $address : (string)($profile['business_address'] ?? ''),
            'legal' => $legalRepresentative,
            'scope' => $businessScope,
            'credit_masked' => $masked,
            'credit_cipher' => $creditCode !== '' ? $this->cipher->encrypt($creditCode, 'merchant-credit-code') : null,
            'credit_hmac' => $creditCode !== '' ? $this->cipher->hmac($creditCode, 'merchant-credit-code') : null,
        ]);
    }

    private function decodeEncryptedJson(string $ciphertext, string $context): array
    {
        if ($ciphertext === '') return [];
        try {
            $plain = $this->cipher->decrypt($ciphertext, $context);
            $decoded = json_decode($plain, true);
        } catch (Throwable $exception) {
            throw new RuntimeException('历史 OCR 数据无法解密，已停止覆盖，请联系管理员核对。', 500, $exception);
        }
        if (!is_array($decoded)) throw new RuntimeException('历史 OCR 数据格式异常，已停止覆盖，请联系管理员核对。', 500);
        return $decoded;
    }

    private function quotaResult(string $quotaDay, int $dailyQuota): array
    {
        $used = $this->repository->countSuccessfulUsage($quotaDay);
        return ['quota' => [
            'day' => $quotaDay,
            'limit' => $dailyQuota,
            'used' => $used,
            'remaining' => max(0, $dailyQuota - $used),
            'source' => 'local_counter',
        ]];
    }

    /** @param array<string,mixed> $usage */
    private function assertSameUsage(array $usage, int $userId, string $scene, int $ocrType, string $resultDigest): void
    {
        if ((int)($usage['user_id'] ?? 0) !== $userId) throw new RuntimeException('OCR 请求编号已被其他账号使用', 409);
        if ((string)($usage['scene'] ?? '') !== $scene
            || (int)($usage['ocr_type'] ?? 0) !== $ocrType
            || !hash_equals((string)($usage['result_digest'] ?? ''), $resultDigest)) {
            throw new RuntimeException('OCR 请求编号与已保存结果不一致，请重新识别。', 409);
        }
    }

    /** @param array<string,mixed> $source */
    private function text(array $source, string $key): string
    {
        $value = $source[$key] ?? '';
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
