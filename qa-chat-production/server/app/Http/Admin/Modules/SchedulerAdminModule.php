<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\SchedulerAdminComposition;
use WorkerAdmin\Http\Admin\AdminModule;

/** 后台计划任务协议适配：查询、执行、重试均交给 Application。 */
final class SchedulerAdminModule implements AdminModule
{
    private Auth $auth;
    private Csrf $csrf;
    private SchedulerAdminComposition $composition;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->composition = new SchedulerAdminComposition($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, ['tasks','tasks.run','tasks.failure.retry'], true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission('settings.manage');

        if ($route === 'tasks') {
            $data = $this->composition->console()->dashboard();
            echo \view('admin/tasks', $data + [
                'pageTitle'=>'计划任务运行记录',
                'activeMenu'=>'tasks',
            ]);
            return;
        }

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            throw new RuntimeException('请求方式不正确');
        }
        $this->csrf->verifyRequest();

        if ($route === 'tasks.failure.retry') {
            $this->retryFailure();
            return;
        }
        $this->runTask();
    }

    private function runTask(): void
    {
        $task = trim((string)($_POST['task'] ?? ''));
        $result = $this->composition->console()->run(
            $this->composition->runner($task === 'all' ? 50 : 100),
            $task,
            $task !== 'all'
        );
        $this->finishRunResponse($task, $result, 'scheduler.manual_run', null, null);
    }

    private function retryFailure(): void
    {
        $id = isset($_GET['id']) && !is_array($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id < 1) throw new RuntimeException('计划任务失败记录不存在。');
        $execution = $this->composition->console()->retryFailure(
            $this->composition->runner(100),
            $id
        );
        $failure = $execution['failure'];
        $task = (string)($failure['job_name'] ?? '');
        $this->finishRunResponse(
            $task,
            (array)$execution['result'],
            'scheduler.failure_retry',
            (int)$failure['id'],
            (string)$failure['business_key']
        );
    }

    /** @param array<string,mixed> $result */
    private function finishRunResponse(
        string $task,
        array $result,
        string $auditAction,
        ?int $failureId,
        ?string $businessKey
    ): void {
        $status = (string)($result['status'] ?? '');
        $runs = isset($result['runs']) && is_array($result['runs']) ? $result['runs'] : [];
        $scanned = 0;
        $success = 0;
        $failure = 0;
        foreach ($runs as $run) {
            $scanned += (int)($run['scanned'] ?? 0);
            $success += (int)($run['success'] ?? 0);
            $failure += (int)($run['failure'] ?? 0);
        }

        $metadata = $this->composition->console()->taskMetadata();
        $label = $task === 'all' ? '全部计划任务' : (string)($metadata[$task]['label'] ?? $task);
        \audit(
            $auditAction,
            $failureId === null ? 'scheduler_task' : 'scheduled_job_failure',
            $failureId,
            '后台' . ($failureId === null ? '手工执行' : '立即重试') . '：' . $label
                . ($businessKey === null ? '' : '；业务键=' . $businessKey)
                . '；状态=' . $status . '；扫描=' . $scanned . '；成功=' . $success . '；失败=' . $failure
        );

        $cleanup = isset($result['wallet_recharge_cleanup']) && is_array($result['wallet_recharge_cleanup'])
            ? $result['wallet_recharge_cleanup']
            : null;
        $cleanupText = '';
        if ($cleanup) {
            $cleanupText = '；余额充值清理：扫描 ' . (int)($cleanup['scanned'] ?? 0)
                . '，关闭 ' . (int)($cleanup['closed'] ?? 0)
                . '，支付确认中 ' . (int)($cleanup['confirming'] ?? 0)
                . '，暂缓 ' . (int)($cleanup['deferred'] ?? 0);
        }

        if (strpos($status, 'skipped_') === 0) {
            \flash('warning', $label . '未执行：' . (string)($result['message'] ?? '已有任务运行或系统处于维护状态。') . $cleanupText);
        } elseif ($status === 'success') {
            if ($scanned === 0 && $success === 0 && $failure === 0) {
                \flash('success', $label . '检查完成：当前无待处理数据。' . $cleanupText);
            } else {
                \flash('success', $label . '执行完成：扫描 ' . $scanned . '，处理 ' . $success . '，失败 ' . $failure . $cleanupText . '。');
            }
        } else {
            \flash('danger', $label . '执行完成但存在失败：扫描 ' . $scanned . '，处理 ' . $success . '，失败 ' . $failure . $cleanupText . '。请查看本页错误说明。');
        }
        \redirect(\admin_url('tasks'));
    }
}
