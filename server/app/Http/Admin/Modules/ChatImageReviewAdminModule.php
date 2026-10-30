<?php
declare(strict_types=1);
namespace WorkerAdmin\Http\Admin\Modules;

use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\AdminModule;

/** PHP provides an authorized page only. All review state is owned by Go. */
final class ChatImageReviewAdminModule implements AdminModule
{
    private Auth $auth;
    public function __construct(Database $db, Auth $auth, Csrf $csrf) { $this->auth = $auth; }
    public function supports(string $route): bool { return $route === 'chat.image-review'; }
    public function dispatch(string $route): void
    {
        $this->auth->requirePermission('chat.images.review');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            http_response_code(405); throw new \RuntimeException('该页面只接受 GET 请求。');
        }
        \redirect(\admin_url('chat.governance',['section'=>'pending','type'=>'image']));
    }
}
