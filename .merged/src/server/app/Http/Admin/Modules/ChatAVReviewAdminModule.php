<?php
declare(strict_types=1);
namespace WorkerAdmin\Http\Admin\Modules;

use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\AdminModule;

/** PHP provides an authorized page only. All review state is owned by Go. */
final class ChatAVReviewAdminModule implements AdminModule
{
    private Auth $auth;
    public function __construct(Database $db, Auth $auth, Csrf $csrf) { $this->auth = $auth; }
    public function supports(string $route): bool { return $route === 'chat.av-review'; }
    public function dispatch(string $route): void
    {
        $this->auth->requirePermission('chat.media.review');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            http_response_code(405); throw new \RuntimeException('该页面只接受 GET 请求。');
        }
        echo \view('admin/chat-av-review', ['pageTitle'=>'聊天音视频预审','activeMenu'=>'chat.av-review']);
    }
}
