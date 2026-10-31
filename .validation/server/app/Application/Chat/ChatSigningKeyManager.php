<?php
declare(strict_types=1);

namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;

/**
 * Owns the PHP signing seed lifecycle. Private seed material is either supplied
 * by the configured environment variable or encrypted at rest in system_settings.
 * Only public keys are ever returned by status()/Go configuration helpers.
 */
final class ChatSigningKeyManager
{
    private const SEED_KEY = 'chat.signing_seed_ciphertext';
    private const KID_KEY = 'chat.signing_key_id';
    private const PUBLIC_KEYS_KEY = 'chat.signing_public_keys';
    private const GENERATED_AT_KEY = 'chat.signing_generated_at';
    private const CIPHER_CONTEXT = 'chat-signing-seed-v1';

    public function __construct(private Database $db, private PrivateValueCipher $cipher) {}

    /** @return array{seed:string,kid:string,source:string} */
    public function material(array $config): array
    {
        if (!function_exists('sodium_crypto_sign_seed_keypair')) $this->unavailable();

        $envName = (string)($config['seed_env'] ?? '');
        $configuredKid = (string)($config['key_id'] ?? '');
        if (!$this->validEnvName($envName) || !$this->validKid($configuredKid)) $this->unavailable();

        $environmentValue = getenv($envName);
        if (is_string($environmentValue) && $environmentValue !== '') {
            return ['seed'=>$this->decodeSeed($environmentValue),'kid'=>$configuredKid,'source'=>'environment'];
        }

        $settings = $this->settings();
        $ciphertext = (string)($settings[self::SEED_KEY] ?? '');
        $kid = (string)($settings[self::KID_KEY] ?? '');
        if ($ciphertext === '' || !$this->validKid($kid)) $this->unavailable();

        try {
            $encoded = $this->cipher->decrypt($ciphertext, self::CIPHER_CONTEXT);
        } catch (\Throwable $exception) {
            $this->unavailable();
        }
        if (!isset($encoded) || $encoded === '') $this->unavailable();

        return ['seed'=>$this->decodeSeed($encoded),'kid'=>$kid,'source'=>'database'];
    }

    /** Safe diagnostics. Never returns the seed, encrypted seed or a decrypt error. */
    public function status(array $config): array
    {
        $envName = (string)($config['seed_env'] ?? '');
        $environmentValue = $this->validEnvName($envName) ? getenv($envName) : false;
        $sourceHint = is_string($environmentValue) && $environmentValue !== '' ? 'environment' : 'none';

        try {
            $material = $this->material($config);
            $seed = $material['seed'];
            try {
                $pair = sodium_crypto_sign_seed_keypair($seed);
                $public = sodium_crypto_sign_publickey($pair);
            } finally {
                sodium_memzero($seed);
                if (isset($pair) && is_string($pair)) sodium_memzero($pair);
            }
            $encodedPublic = base64_encode($public);
            $ring = $material['source'] === 'database' ? $this->publicKeyRing() : [];
            unset($ring[$material['kid']]);
            $ring[$material['kid']] = $encodedPublic;
            if (count($ring) > 3) $ring = array_slice($ring, -3, null, true);
            $settings = $material['source'] === 'database' ? $this->settings() : [];
            $goConfig = json_encode(['auth'=>['enabled'=>true,'public_keys'=>$ring]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);

            return [
                'configured'=>true,
                'source'=>$material['source'],
                'kid'=>$material['kid'],
                'public_key'=>$encodedPublic,
                'fingerprint'=>'sha256:'.substr(hash('sha256',$public),0,24),
                'generated_at'=>(string)($settings[self::GENERATED_AT_KEY] ?? ''),
                'env_name'=>$envName,
                'public_keys'=>$ring,
                'go_config'=>$goConfig,
            ];
        } catch (\Throwable $exception) {
            return [
                'configured'=>false,
                'source'=>$sourceHint,
                'kid'=>'',
                'public_key'=>'',
                'fingerprint'=>'',
                'generated_at'=>'',
                'env_name'=>$envName,
                'public_keys'=>[],
                'go_config'=>'',
                'reason'=>'CHAT_SIGNING_NOT_CONFIGURED',
            ];
        }
    }

    /**
     * Generate the first database-backed key or rotate it. Environment mode
     * always wins and therefore blocks database generation to avoid ambiguity.
     */
    public function generate(array $config, int $admin, string $role, bool $rotate=false): array
    {
        if (!function_exists('sodium_crypto_sign_seed_keypair')) throw new RuntimeException('服务器缺少 sodium 扩展，无法生成聊天签名密钥。');

        $envName = (string)($config['seed_env'] ?? '');
        $configuredKid = (string)($config['key_id'] ?? '');
        if (!$this->validEnvName($envName) || !$this->validKid($configuredKid)) throw new RuntimeException('聊天签名基础配置不正确。');
        $environmentValue = getenv($envName);
        if (is_string($environmentValue) && $environmentValue !== '') {
            throw new RuntimeException('当前由环境变量 '.$envName.' 接管签名密钥；请先移除该覆盖后再使用后台生成。');
        }

        $current = $this->settings();
        if (!$rotate && (string)($current[self::SEED_KEY] ?? '') !== '') throw new RuntimeException('后台签名密钥已存在，无需重复生成。');

        $kid = !$rotate && (string)($current[self::SEED_KEY] ?? '') === ''
            ? $configuredKid
            : 'chat-'.gmdate('ymdHis').'-'.bin2hex(random_bytes(2));
        if (!$this->validKid($kid)) throw new RuntimeException('无法生成有效的聊天密钥编号。');

        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $pair = '';
        try {
            $pair = sodium_crypto_sign_seed_keypair($seed);
            $public = sodium_crypto_sign_publickey($pair);
            $encodedSeed = base64_encode($seed);
            $ciphertext = $this->cipher->encrypt($encodedSeed, self::CIPHER_CONTEXT);
            if (!is_string($ciphertext) || $ciphertext === '') throw new RuntimeException('私密值加密服务不可用，未保存聊天签名密钥。');
        } finally {
            sodium_memzero($seed);
            if ($pair !== '') sodium_memzero($pair);
        }

        $ring = $this->publicKeyRing();
        unset($ring[$kid]);
        $ring[$kid] = base64_encode($public);
        if (count($ring) > 3) $ring = array_slice($ring, -3, null, true);
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');

        $this->db->transaction(function() use($admin,$role,$rotate,$ciphertext,$kid,$ring,$generatedAt):void {
            $actor = $this->db->fetch("SELECT role FROM admin_users WHERE id=:id AND status IN ('active','1') AND must_change_password=0 FOR UPDATE",['id'=>$admin]);
            if (!$actor || (string)$actor['role'] !== $role || $role !== 'super_admin') throw new RuntimeException('仅超级管理员可生成或轮换聊天签名密钥。');
            $existing = (string)($this->db->fetchValue("SELECT setting_value FROM system_settings WHERE setting_key='".self::SEED_KEY."' LIMIT 1") ?: '');
            if (!$rotate && $existing !== '') throw new RuntimeException('后台签名密钥已存在，无需重复生成。');

            $this->upsert(self::SEED_KEY,$ciphertext,'PHP 聊天签名种子密文，仅服务端解密使用',$admin);
            $this->upsert(self::KID_KEY,$kid,'PHP 当前聊天签名密钥编号',$admin);
            $this->upsert(self::PUBLIC_KEYS_KEY,json_encode($ring,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'Go 可用的聊天签名公钥环，不含私钥',$admin);
            $this->upsert(self::GENERATED_AT_KEY,$generatedAt,'聊天签名密钥最近生成时间（UTC）',$admin);
        });

        return $this->status($config);
    }

    /** @return array<string,string> */
    private function settings(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('".self::SEED_KEY."','".self::KID_KEY."','".self::PUBLIC_KEYS_KEY."','".self::GENERATED_AT_KEY."')"
        );
        $values = [];
        foreach ($rows as $row) $values[(string)$row['setting_key']] = (string)$row['setting_value'];
        return $values;
    }

    /** @return array<string,string> */
    private function publicKeyRing(): array
    {
        $raw = (string)($this->settings()[self::PUBLIC_KEYS_KEY] ?? '');
        if ($raw === '') return [];
        $decoded = json_decode($raw,true);
        if (!is_array($decoded)) return [];
        $ring = [];
        foreach ($decoded as $kid=>$encoded) {
            if (!is_string($kid) || !is_string($encoded) || !$this->validKid($kid)) continue;
            $public = base64_decode($encoded,true);
            if (!is_string($public) || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || base64_encode($public) !== $encoded) continue;
            $ring[$kid] = $encoded;
        }
        return count($ring) > 3 ? array_slice($ring,-3,null,true) : $ring;
    }

    private function upsert(string $key,string $value,string $description,int $admin): void
    {
        $this->db->execute(
            "INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,updated_by,created_at,updated_at)
             VALUES(:key,:value,'string','chat',0,:description,:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type='string',group_name='chat',is_public=0,description=VALUES(description),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()",
            ['key'=>$key,'value'=>$value,'description'=>$description,'admin'=>$admin]
        );
    }

    private function decodeSeed(string $encoded): string
    {
        $seed = base64_decode($encoded,true);
        $valid = is_string($seed) && strlen($seed) === SODIUM_CRYPTO_SIGN_SEEDBYTES && base64_encode($seed) === $encoded;
        if (!$valid) {
            if (is_string($seed)) sodium_memzero($seed);
            $this->unavailable();
        }
        return $seed;
    }

    private function validKid(string $kid): bool { return preg_match('/^[a-z0-9_-]{1,32}$/D',$kid) === 1; }
    private function validEnvName(string $name): bool { return preg_match('/^CHAT_[A-Z0-9_]{1,60}$/D',$name) === 1; }

    private function unavailable(): never
    {
        throw new RuntimeException('CHAT_SIGNING_NOT_CONFIGURED',503);
    }
}
