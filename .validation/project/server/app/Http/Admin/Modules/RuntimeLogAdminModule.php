<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\RuntimeLogComposition;
use WorkerAdmin\Http\Admin\AdminModule;
use WorkerAdmin\Application\Admin\RuntimeLogConsole;
use WorkerAdmin\Infrastructure\Persistence\DatabaseSchedulerRunMaintenance;
use WorkerAdmin\Domain\Scheduling\SchedulerTaskCatalog;

final class RuntimeLogAdminModule implements AdminModule
{
    private Auth $auth;
    private Csrf $csrf;
    private RuntimeLogConsole $console;
    private DatabaseSchedulerRunMaintenance $runMaintenance;

    public function __construct(Database $db, Auth $auth, Csrf $csrf, ?RuntimeLogConsole $console = null)
    {
        $this->auth = $auth;
        $this->runMaintenance = new DatabaseSchedulerRunMaintenance($db);
        $this->csrf = $csrf;
        $this->console = $console ?: RuntimeLogComposition::build();
    }

    public function supports(string $route): bool
    {
        return in_array($route, ['runtime-logs', 'runtime-logs.purge', 'runtime-logs.clear', 'runtime-logs.clear-category', 'runtime-logs.purge-runs'], true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission('settings.manage');
        if (!$this->supports($route)) throw new RuntimeException('后台路由不存在');

        try {
            if ($route === 'runtime-logs') {
                echo \view('admin/runtime-logs', $this->console->dashboard($this->query('channel'), $this->query('q'), (int)$this->query('page', '1')) + [
                    'pageTitle'=>'运行日志', 'activeMenu'=>'runtime-logs', 'schedulerRuns'=>$this->runMaintenance->dashboard(),
                ]);
                return;
            }

            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                header('Allow: POST');
                http_response_code(405);
                throw new RuntimeException('该操作只接受 POST 请求。');
            }
            $this->csrf->verifyRequest();

            if ($route === 'runtime-logs.purge-runs') {
                $days = (int)$this->post('days');
                $job = $this->post('job_name');
                $result = $this->runMaintenance->purgeOlderThan($days, $job);
                $jobLabel = $job !== '' ? SchedulerTaskCatalog::label($job) : '全部任务';
                \audit('runtime.scheduler_runs.purge', 'scheduler_runs', null, '清理计划任务运行摘要：'.$jobLabel.'；'.$result['count'].' 条；保留 '.$days.' 天');
                \flash('success', '已清理'.$jobLabel.'的 '.$result['count'].' 条运行摘要（保留 '.$days.' 天）；仍有 '.$result['remaining_count'].' 条符合清理条件。业务失败记录与任务幂等记录未清理。');
                \redirect(\admin_url('runtime-logs'));
                return;
            }

            if ($route === 'runtime-logs.purge') {
                $days = (int)$this->post('days');
                $category = $this->post('category');
                $result = $this->console->purge($days, $category);
                $categoryLabel = $this->categoryLabel($category);
                $skipped = (int)($result['skipped_count'] ?? 0);
                \audit('runtime.logs.purge', 'runtime_log', null, '清理超过'.$days.'天的运行日志（'.$categoryLabel.'）：清理 '.$result['count'].' 个，跳过 '.$skipped.' 个，释放 '.$result['size_display']);
                \flash('success', '已清理'.$categoryLabel.'中超过 '.$days.' 天的 '.$result['count'].' 个日志文件，释放 '.$result['size_display'].($skipped > 0 ? '；另有 '.$skipped.' 个文件因正在写入或无法取得写锁而保留，请稍后重试。' : '。'));
                \redirect(\admin_url('runtime-logs'));
                return;
            }

            if ($route === 'runtime-logs.clear-category') {
                $category = $this->post('category');
                $result = $this->console->clearCategory($category);
                $categoryLabel = $this->categoryLabel($category);
                $skipped = (int)($result['skipped_count'] ?? 0);
                \audit('runtime.logs.clear_category', 'runtime_log', null, '清空运行日志类型 '.$categoryLabel.'：清理 '.$result['count'].' 个，跳过 '.$skipped.' 个，释放 '.$result['size_display']);
                \flash('success', '已清空'.$categoryLabel.'的 '.$result['count'].' 个日志文件，释放 '.$result['size_display'].($skipped > 0 ? '；另有 '.$skipped.' 个文件因正在写入或无法取得写锁而保留，请稍后重试。' : '。'));
                \redirect(\admin_url('runtime-logs'));
                return;
            }

            $channel = $this->post('channel');
            $result = $this->console->clear($channel);
            \audit('runtime.logs.clear', 'runtime_log', null, '清空运行日志 '.$channel.'，释放 '.$result['size_display']);
            \flash('success', '已清空 '.$channel.'，释放 '.$result['size_display'].'。');
            \redirect(\admin_url('runtime-logs', ['channel'=>$channel]));
        } catch (RuntimeException $exception) {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') throw $exception;
            \flash('danger', $exception->getMessage());
            \redirect(\admin_url('runtime-logs'));
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('admin-runtime-log-error', '['.date('c').'] request_id='.$requestId.' '.$exception->getMessage()."\n");
            $message = '日志管理操作失败，请根据请求编号排查。请求编号：'.$requestId;
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
                \flash('danger', $message);
                \redirect(\admin_url('runtime-logs'));
                return;
            }
            http_response_code(500);
            echo \view('errors/500', ['pageTitle'=>'系统处理失败', 'message'=>$message]);
        }
    }

    private function categoryLabel(string $category): string
    {
        $labels = [
            'app'=>'APP / 全局异常',
            'api'=>'API / 支付回调',
            'admin'=>'后台管理',
            'message'=>'消息通知',
            'scheduler'=>'计划任务',
            'upgrade'=>'升级维护',
            'other'=>'其他日志',
        ];
        return $labels[$category] ?? '全部类型';
    }

    private function query(string $key, string $default = ''): string
    {
        return isset($_GET[$key]) && !is_array($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
    }

    private function post(string $key): string
    {
        return isset($_POST[$key]) && !is_array($_POST[$key]) ? trim((string)$_POST[$key]) : '';
    }
}
