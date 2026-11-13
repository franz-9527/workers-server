<?php
declare(strict_types=1);

use WorkerAdmin\Application\Chat\ChatGoRuntimeConfig;
use WorkerAdmin\Application\Chat\ChatMediaCallbackSettings;
use WorkerAdmin\Application\Chat\ChatSigningKeyManager;
use WorkerAdmin\Foundation\ApplicationContainer;

if (PHP_SAPI !== "cli") { http_response_code(404); exit; }
$root = realpath($argv[1] ?? "");
$chat = dirname(__DIR__);
if ($root === false || $root."/chat" !== $chat || !is_file($root."/bootstrap.php")) exit(2);
define("WORKER_BASE_PATH", $root);
try {
    require $root."/bootstrap.php";
    if (!is_installed() || app_config("app.env") !== "production") throw new RuntimeException("SITE");
    if (upgrade_required()) { fwrite(STDERR, "CHAT_PRODUCTION_PHP_UPGRADE_REQUIRED\n"); exit(3); }
    $db = db();
    $appId = trim((string) setting("wechat.miniapp_appid", ""));
    $url = rtrim(trim((string) app_config("app.url", "")), "/");
    if (!preg_match("/^[A-Za-z0-9_-]{1,64}$/D", $appId) || !preg_match("~^https://[A-Za-z0-9.-]+(?::[0-9]+)?$~D", $url)) {
        throw new RuntimeException("APP_ID_OR_URL");
    }
    $cipher = (new ApplicationContainer($db))->privateCipher();
    $callback = (new ChatMediaCallbackSettings($db, $cipher))->runtime($appId, $url);
    if (!$callback["configured"]) throw new RuntimeException("WECHAT_CALLBACK");

    umask(0077);
    $state = $argv[2] ?? "";
    if ($state !== "/var/lib/bixuan-chat-prod" || is_link($state) || !is_dir($state) || !is_writable($state)) {
        throw new RuntimeException("STATE");
    }
    $spool = $state."/wechat-receipts";
    if (is_link($spool) || (!is_dir($spool) && !mkdir($spool, 0700))) throw new RuntimeException("STATE");
    $keyPath = $state."/wechat-relay.key";
    $write = static function (string $path, string $content): void {
        $tmp = $path.".".bin2hex(random_bytes(8)).".tmp";
        if (file_put_contents($tmp, $content) !== strlen($content) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("STATE");
        }
    };
    if (is_link($keyPath)) throw new RuntimeException("STATE");
    if (!is_file($keyPath)) $write($keyPath, bin2hex(random_bytes(32))."\n");
    $key = trim((string) file_get_contents($keyPath));
    if (!preg_match("/^[a-f0-9]{64}$/D", $key)) throw new RuntimeException("RELAY_KEY");

    $config = json_decode((string) file_get_contents($chat."/chat.production.example.json"), false, 64, JSON_THROW_ON_ERROR);
    if (($config->env ?? "") !== "production" || ($config->av->callback_relay->mode ?? "") !== "dispatch") {
        throw new RuntimeException("TEMPLATE");
    }
    $config->av->callback_app_id = $appId;
    $config->av->callback_relay->spool = $spool;
    $env = ChatGoRuntimeConfig::environmentFile([
        "CHAT_WECHAT_CALLBACK_TOKEN" => $callback["token"],
        "CHAT_WECHAT_CALLBACK_AES_KEY" => $callback["aes_key"],
        "CHAT_MEDIA_RELAY_KEY" => $key,
    ]);
    $write($state."/production.runtime.env", $env);
    $write($state."/production.runtime.json", json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    echo "CHAT_PRODUCTION_CALLBACK_CONFIG_READY\n";

    $requested = getenv("CHAT_PROD_TEXT") === "1"
        || (getenv("CHAT_PROD_TEXT") !== "0" && (string) setting("chat.go_production_text_requested", "0") === "1");
    $control = $root."/storage/private/chat-runtime-control";
    if (!is_link($control) && (is_dir($control) || @mkdir($control, 0700, true))) {
        $report = static function (array $codes) use ($control, $write): void {
            try { $write($control."/production-text-status", implode(",", $codes)."\n"); }
            catch (Throwable $e) { /* runtime diagnostics are best-effort */ }
        };
    } else {
        $report = static function (array $codes): void {};
    }
    $report($requested ? ["CHECKING"] : ["NOT_REQUESTED"]);
    if ($requested) {
        try {
            $signing = (new ChatSigningKeyManager($db, $cipher))->status((array) app_config("chat", []));
            $text = (new ChatGoRuntimeConfig($db, $cipher))->productionText($signing, $appId);
            if ($text["missing"]) {
                $report($text["missing"]);
                fwrite(STDERR, "CHAT_PRODUCTION_TEXT_PENDING: ".implode(", ", $text["missing"])."\n");
                exit(4);
            }
            foreach ($text["config"] as $section => $values) {
                $original = (array) ($config->{$section} ?? []);
                if ($section === "dependencies") {
                    foreach ($values as $dependency => $settings) {
                        $values[$dependency] = array_replace((array) ($original[$dependency] ?? []), $settings);
                    }
                }
                $config->{$section} = array_replace($original, $values);
            }
            $write($state."/production.text.env", ChatGoRuntimeConfig::environmentFile($text["environment"] + ["CHAT_PROD_TEXT" => "1"]));
            $write($state."/production.text.json", json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        } catch (Throwable $e) {
            $report(["CONFIG_ERROR"]);
            fwrite(STDERR, "CHAT_PRODUCTION_TEXT_CONFIG_FAILED\n");
            exit(4);
        }
    }
} catch (Throwable $e) {
    $codes = ["SITE", "APP_ID_OR_URL", "WECHAT_CALLBACK", "STATE", "RELAY_KEY", "TEMPLATE"];
    $code = in_array($e->getMessage(), $codes, true) ? $e->getMessage() : "FAILED";
    fwrite(STDERR, "CHAT_PRODUCTION_CONFIG_".$code."\n");
    exit(2);
}
