<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Chat\ChatCredentialIssuer;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\AdminModule;

final class ChatCredentialAdminModule implements AdminModule
{
    private Database $db;
    private Auth $auth;
    public function __construct(Database $db, Auth $auth, Csrf $csrf) { $this->db=$db;$this->auth=$auth; }
    public function supports(string $route): bool { return $route === 'chat.credentials'; }
    public function dispatch(string $route): void
    {
        try {
            $issuer = new ChatCredentialIssuer($this->db,(array)\app_config('chat',[]),(string)\app_config('app.env','production'));
            \json_response(['ok'=>true,'data'=>$issuer->exchangeAdmin($this->auth)]);
        } catch (Throwable $error) {
            $status = $error instanceof RuntimeException && in_array($error->getCode(),[401,403],true) ? $error->getCode() : 503;
            \json_response(['ok'=>false,'code'=>$status===401?'CHAT_UNAUTHORIZED':($status===403?'CHAT_FORBIDDEN':'CHAT_DEPENDENCY_UNAVAILABLE'),
                'message'=>$status===503?'聊天身份暂不可用，请稍后重试':'登录状态或聊天审计权限已变化，请刷新页面',
                'retryable'=>$status===503,'request_id'=>\request_id(),'server_time'=>gmdate('Y-m-d\TH:i:s\Z')],$status);
        }
    }
}
