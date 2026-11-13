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

final class RuntimeLogAdminModule implements AdminModule
{
    private Auth $auth;
    private Csrf $csrf;
    private RuntimeLogConsole $console;

    public function __construct(Database $db, Auth $auth, Csrf $csrf, ?RuntimeLogConsole $console = null)
    {
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->console = $console ?: RuntimeLogComposition::build();
    }

    public function supports(string $route): bool
    {
        return in_array($route, ['runtime-logs', 'runtime-logs.purge', 'runtime-logs.clear'], true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission('settings.manage');
        if (!$this->supports($route)) throw new RuntimeException('后台路由不存在');

        try {
            if ($route === 'runtime-logs') {
                echo \view('admin/runtime-logs', $this->console->dashboard($this->query('channel'), $this->query('q'), (int)$this->query('page', '1')) + [
                    'pageTitle'=>'运行日志', 'activeMenu'=>'runtime-logs',
                ]);
                return;
            }

            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                http_response_code(405);
                throw new RuntimeException('该操作只接受 POST 请求。');
            }
            $this->csrf->verifyRequest();

            if ($route === 'runtime-logs.purge') {
                $days = (int)$this->post('days');
                $result = $this->console->purge($days);
                \audit('runtime.logs.purge', 'runtime_log', null, '清理超过'.$days.'天的运行日志：'.$result['count'].' 个文件，释放 '.$result['size_display']);
                \flash('success', '已清理 '.$result['count'].' 个旧日志文件，释放 '.$result['size_display'].'。');
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

    private function query(string $key, string $default = ''): string
    {
        return isset($_GET[$key]) && !is_array($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
    }

    private function post(string $key): string
    {
        return isset($_POST[$key]) && !is_array($_POST[$key]) ? trim((string)$_POST[$key]) : '';
    }
}
