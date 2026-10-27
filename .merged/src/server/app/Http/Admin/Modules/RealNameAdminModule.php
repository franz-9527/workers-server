<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Admin\WorkerCredentialAdmin;
use WorkerAdmin\Application\Identity\RealNameReauthentication;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Foundation\WorkerCredentialAdminComposition;
use WorkerAdmin\Http\Admin\AdminModule;

/** 师傅服务器私密凭证、实名认证后台动作与收款码审核。 */
final class RealNameAdminModule implements AdminModule
{
    private const ROUTES = [
        'worker-credentials',
        'workers.realname.review',
        'workers.realname.update',
        'workers.realname.revoke',
        'collection-codes.review',
    ];

    private Auth $auth;
    private Csrf $csrf;
    private RealNameReauthentication $reauthentication;
    private WorkerCredentialAdmin $credentials;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->reauthentication = (new ApplicationContainer($db))->realNameReauthentication();
        $this->credentials = WorkerCredentialAdminComposition::build($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, self::ROUTES, true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission('workers.manage');
        $expected = $route === 'worker-credentials' ? 'GET' : 'POST';
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $expected) {
            http_response_code(405);
            throw new RuntimeException('该操作只接受 '.$expected.' 请求。');
        }
        if ($expected === 'POST') $this->csrf->verifyRequest();

        try {
            switch ($route) {
                case 'worker-credentials': $this->index(); return;
                case 'workers.realname.review': $this->reviewRealName(); return;
                case 'workers.realname.update': $this->updateRealName(); return;
                case 'workers.realname.revoke': $this->revokeRealName(); return;
                case 'collection-codes.review': $this->reviewCollectionCode(); return;
            }
            throw new RuntimeException('师傅凭证后台路由不存在。');
        } catch (RuntimeException $exception) {
            if ($expected === 'POST') {
                \flash('danger', $exception->getMessage());
                \redirect(\admin_url('worker-credentials'));
            }
            throw $exception;
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('admin-worker-credential-error', '['.date('c').'] request_id='.$requestId.' route='.$route.' '.$exception->getMessage()."\n");
            if ($expected === 'POST') {
                \flash('danger', '师傅认证与收款资料处理失败，请稍后重试。请求编号：'.$requestId);
                \redirect(\admin_url('worker-credentials'));
            }
            http_response_code(500);
            echo \view('errors/500', ['pageTitle'=>'系统处理失败','message'=>'师傅认证与收款资料读取失败，请稍后重试。请求编号：'.$requestId]);
        }
    }

    private function index(): void
    {
        $search = $this->query('q');
        $status = $this->query('status');
        $page = max(1, (int)$this->query('page', '1'));
        $data = $this->credentials->page($search, $status, $page);
        $stats = isset($data['stats']) && is_array($data['stats']) ? $data['stats'] : [];
        unset($data['stats']);
        echo \view('admin/worker-credentials', [
            'pageTitle'=>'师傅认证与收款','activeMenu'=>'worker-credentials','result'=>$data,
            'search'=>$search,'status'=>$status,'stats'=>$stats,
        ]);
    }

    private function reviewRealName(): void
    {
        $workerId = $this->id();
        $decision = $this->post('decision');
        $reason = mb_substr($this->post('reason'), 0, 500);
        $admin = $this->auth->user();
        $this->credentials->reviewRealName($workerId, $decision, $reason, (int)($admin['id'] ?? 0));
        \audit('worker.realname.review', 'worker', $workerId, $decision.'：'.$reason);
        \flash('success', '实名认证状态已更新。');
        \redirect(\admin_url('worker-credentials'));
    }

    private function updateRealName(): void
    {
        $workerId = $this->id();
        $this->credentials->updateRealName($workerId, $this->post('real_name'), $this->post('id_number'));
        \audit('worker.realname.update', 'worker', $workerId, '管理员更正实名文字资料');
        \flash('success', '实名认证文字资料已更新。');
        \redirect(\admin_url('worker-credentials'));
    }

    private function revokeRealName(): void
    {
        $workerId = $this->id();
        $reason = mb_substr(trim($this->post('reason')), 0, 500);
        $admin = $this->auth->user();
        $this->reauthentication->revokeByAdmin($workerId, (int)($admin['id'] ?? 0), $reason);
        \audit('worker.realname.revoke', 'worker', $workerId, '取消实名认证：'.$reason);
        \flash('success', '实名认证已取消；原认证资料已保留用于对比，师傅可重新提交。');
        \redirect(\admin_url('worker-credentials'));
    }

    private function reviewCollectionCode(): void
    {
        $id = $this->id();
        $decision = $this->post('decision');
        $reason = mb_substr($this->post('reason'), 0, 500);
        $admin = $this->auth->user();
        $this->credentials->reviewCollectionCode($id, $decision, $reason, (int)($admin['id'] ?? 0));
        \audit('worker.collection_code.review', 'worker_collection_code', $id, '收款码审核：'.$decision.'；'.$reason);
        \flash('success', '师傅收款码审核状态已更新。');
        \redirect(\admin_url('worker-credentials'));
    }

    private function query(string $key, string $default = ''): string
    {
        return isset($_GET[$key]) && !is_array($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
    }

    private function post(string $key, string $default = ''): string
    {
        return isset($_POST[$key]) && !is_array($_POST[$key]) ? (string)$_POST[$key] : $default;
    }

    private function id(): int
    {
        $raw = $_GET['id'] ?? $_POST['id'] ?? 0;
        return is_array($raw) ? 0 : max(0, (int)$raw);
    }
}
