<?php
declare(strict_types=1);

use WorkerAdmin\Application\Chat\ChatGoRuntimeConfig;
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
    umask(0077);
    $state = $argv[2] ?? "";
    $revision = $argv[3] ?? "";
    if ($state !== $chat."/bin/state" || is_link($state) || !is_dir($state) || !is_writable($state)) {
        throw new RuntimeException("STATE");
    }
    foreach (["production.text.env","production.text.json","production.release.json","production.base.env","production.base.json","production.base.release.json"] as $name) {
        $path=$state."/".$name;
        if (is_link($path)) throw new RuntimeException("STATE");
        if (is_file($path) && !@unlink($path)) throw new RuntimeException("STATE");
    }

    $config = json_decode((string) file_get_contents($chat."/chat.production.example.json"), false, 64, JSON_THROW_ON_ERROR);
    if (($config->env ?? "") !== "production") throw new RuntimeException("TEMPLATE");
    // L1 text does not depend on optional audio/video media callback configuration.
    $config->av->callback_app_id = "";
    $config->av->callback_relay->mode = "";
    $config->av->callback_relay->spool = "";
    $write = static function (string $path, string $content): void {
        $tmp = $path.".".bin2hex(random_bytes(8)).".tmp";
        if (file_put_contents($tmp, $content) !== strlen($content) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("STATE");
        }
    };
    $write($state."/production.runtime.env", ChatGoRuntimeConfig::environmentFile([]));
    $write($state."/production.runtime.json", json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    echo "CHAT_PRODUCTION_RUNTIME_CONFIG_READY\n";

    // The existing chat switch is the only business activation switch.
    $requested = (string) setting("chat.enabled", "0") === "1";
    $control = $root."/storage/private/chat-runtime-control";
    if (!is_link($control) && (is_dir($control) || @mkdir($control, 0700, true))) {
        $report = static function (array $codes, string $file = "production-text-status") use ($control, $write): void {
            try { $write($control."/".$file, implode(",", $codes)."\n"); }
            catch (Throwable $e) { /* runtime diagnostics are best-effort */ }
        };
    } else {
        $report = static function (array $codes, string $file = "production-text-status"): void {};
    }
    $report($requested ? ["CHECKING"] : ["NOT_REQUESTED"]);
    $report([], "production-feature-status");
    if ($requested) {
        try {
            $signing = (new ChatSigningKeyManager($db, $cipher))->status((array) app_config("chat", []));
            $runtime = \WorkerAdmin\Foundation\ChatRuntimeComposition::build($db, $cipher);
            $text = $runtime->productionText($signing, $appId, $revision);
            if ($text["missing"]) {
                $report($text["missing"]);
                fwrite(STDERR, "CHAT_PRODUCTION_TEXT_PENDING: ".implode(", ", $text["missing"])."\n");
                exit(4);
            }
            $render = static function (array $result, string $profile, string $releasePath, bool $optional) use ($config,$state,$write): void {
                $candidate=clone $config;
                $result["config"]["release"]["file"]=$releasePath;
                foreach ($result["config"] as $section => $values) {
                    $original = (array) ($candidate->{$section} ?? []);
                    if ($section === "dependencies") {
                        foreach ($values as $dependency => $settings) {
                            $values[$dependency] = array_replace((array) ($original[$dependency] ?? []), $settings);
                        }
                    }
                    $candidate->{$section} = array_replace($original, $values);
                }
                $write($releasePath,$result["release_content"]);
                $flags=["CHAT_PROD_OPTIONAL"=>$optional?"1":"0"];
                $write($state."/production.".$profile.".json", json_encode($candidate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                $write($state."/production.".$profile.".env", ChatGoRuntimeConfig::environmentFile($result["environment"] + $flags));
            };
            // Persist an independently signed text-only fallback BEFORE probing optional capabilities.
            $render($text,"base",$state."/production.base.release.json",false);
            try {
                $selected=$runtime->productionFeatures($text,$appId,$revision);
                if(!empty($selected["missing"]))throw new RuntimeException("OPTIONAL_CONFIG");
                $render($selected,"text",$state."/production.release.json",true);
                $report($selected["pending"]??[], "production-feature-status");
            } catch (Throwable $e) {
                $render($text,"text",$state."/production.release.json",false);
                $report(["OPTIONAL_CONFIG"],"production-feature-status");
            }
        } catch (Throwable $e) {
            $report(["CONFIG_ERROR"]);
            fwrite(STDERR, "CHAT_PRODUCTION_TEXT_CONFIG_FAILED\n");
            exit(4);
        }
    }
} catch (Throwable $e) {
    $codes = ["SITE", "APP_ID_OR_URL", "STATE", "TEMPLATE"];
    $code = in_array($e->getMessage(), $codes, true) ? $e->getMessage() : "FAILED";
    fwrite(STDERR, "CHAT_PRODUCTION_CONFIG_".$code."\n");
    exit(2);
}
