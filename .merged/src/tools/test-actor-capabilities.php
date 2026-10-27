<?php

declare(strict_types=1);

$base = dirname(__DIR__) . '/server/app';
spl_autoload_register(static function (string $class) use ($base): void {
    $prefix = 'WorkerAdmin\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = $base . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require_once $file;
});

use WorkerAdmin\Application\Identity\ActorCapabilityResolver;
use WorkerAdmin\Contracts\SettingsProvider;

final class FakeSettingsProvider implements SettingsProvider
{
    private array $values;
    public function __construct(array $values) { $this->values = $values; }
    public function get(string $key, $default = null) { return $this->values[$key] ?? $default; }
    public function bool(string $key, bool $default = false): bool { return (bool) ($this->values[$key] ?? $default); }
    public function int(string $key, int $default = 0): int { return (int) ($this->values[$key] ?? $default); }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$resolver = new ActorCapabilityResolver(new FakeSettingsProvider([
    'wechat.admin_openids' => "openid-a, openid-b\nopenid-c",
]));
$assert($resolver->adminOpenIds() === ['openid-a','openid-b','openid-c'], '管理员 OpenID 配置解析失败');
$actor = ['payer_openid'=>'openid-b','worker_id'=>7,'user_id'=>12,'is_demo_actor'=>false];
$assert($resolver->isRealMiniAdmin($actor), '真实管理员识别失败');
$assert($resolver->isMiniAdmin($actor), '管理员能力识别失败');
$assert($resolver->isWorker($actor), '师傅能力识别失败');
$assert($resolver->isOrderOwner($actor, ['user_id'=>12]), '订单本人能力识别失败');
$actor['is_demo_actor'] = true;
$assert($resolver->isRealMiniAdmin($actor), '演示代理不应改变真实登录管理员归属');
$assert(!$resolver->isMiniAdmin($actor), '演示身份不得继承生产管理员权限');

$jsonResolver = new ActorCapabilityResolver(new FakeSettingsProvider([
    'wechat.admin_openids' => '["openid-x","openid-y"]',
]));
$assert($jsonResolver->adminOpenIds() === ['openid-x','openid-y'], 'JSON 管理员 OpenID 配置解析失败');

echo "身份能力边界回归通过。\n";
