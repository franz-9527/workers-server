<?php
declare(strict_types=1);
// Same-site test bridge: exact HMAC authentication, replay protection and recovery
// checks are shared with the private endpoint. It never opens on production.
require_once __DIR__.'/bootstrap.php';
if((string)app_config('app.env','production')!=='testing'){
    http_response_code(404);header('Cache-Control: no-store');exit;
}
require __DIR__.'/chat-calls.php';
