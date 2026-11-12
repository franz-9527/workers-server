<?php
namespace WorkerAdmin\Application\Chat;
final class ChatRuntimePaths { public static function binDirectory(): ?string { return null; } }
require __DIR__.'/ChatGoRuntimeStatus.php';
echo json_encode((new ChatGoRuntimeStatus('production'))->status(), JSON_THROW_ON_ERROR);
