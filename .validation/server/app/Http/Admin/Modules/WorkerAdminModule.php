<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Admin\WorkerAdmin;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\WorkerAdminComposition;
use WorkerAdmin\Http\Admin\AdminModule;

final class WorkerAdminModule implements AdminModule
{
    private const ROUTES = [
        'workers','workers.create','workers.update','workers.media.upload','workers.media.delete',
        'workers.delete','workers.review','workers.cloud.sync',
    ];

    private Auth $auth;
    private WorkerAdmin $workers;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->workers = WorkerAdminComposition::build($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, self::ROUTES, true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requirePermission('workers.manage');
        $expected = $route === 'workers' ? 'GET' : 'POST';
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $expected) {
            http_response_code(405);
            throw new RuntimeException('该操作只接受 '.$expected.' 请求。');
        }

        try {
            switch ($route) {
                case 'workers': $this->listing(); return;
                case 'workers.create': $this->create(); return;
                case 'workers.update': $this->update(); return;
                case 'workers.media.upload':
                case 'workers.media.delete': $this->workers->rejectLegacyMediaRoute(); return;
                case 'workers.delete': $this->delete(); return;
                case 'workers.review': $this->review(); return;
                case 'workers.cloud.sync': $this->cloudHealth(); return;
            }
            throw new RuntimeException('师傅管理路由不存在。');
        } catch (RuntimeException $exception) {
            if ($expected === 'POST') {
                \flash('danger', $exception->getMessage());
                \redirect(\admin_url('workers'));
            }
            throw $exception;
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('admin-worker-error', '['.date('c').'] request_id='.$requestId.' route='.$route.' '.$exception->getMessage()."\n");
            if ($expected === 'POST') {
                \flash('danger', '师傅管理操作失败，请稍后重试。请求编号：'.$requestId);
                \redirect(\admin_url('workers'));
            }
            http_response_code(500);
            echo \view('errors/500', ['pageTitle'=>'系统处理失败','message'=>'师傅列表读取失败，请稍后重试。请求编号：'.$requestId]);
        }
    }

    private function listing(): void
    {
        $status = $this->query('status');
        $keyword = $this->query('q');
        $data = $this->workers->listing(max(1, (int)$this->query('page', '1')), $status, $keyword);
        if ((string)$data['cloud_error'] !== '') {
            \write_log('worker-cloud-admin', '['.date('c').'] list '.(string)$data['cloud_error']."\n");
        }
        echo \view('admin/workers', [
            'pageTitle'=>'师傅管理',
            'activeMenu'=>'workers',
            'result'=>$data['result'],
            'filters'=>['status'=>$data['status'],'q'=>$data['keyword']],
            'workerStats'=>$data['stats'],
            'cloudError'=>$data['cloud_error'],
        ]);
    }

    private function create(): void
    {
        $admin = $this->auth->user();
        $result = $this->workers->create($this->post('openid'), $this->profileInput(), (int)($admin['id'] ?? 0));
        $worker = $result['cloud_worker'];
        $cloudId = (string)($worker['cloud_id'] ?? $worker['_id'] ?? '');
        \audit('worker.cloud.create', 'worker', $result['local_worker_id'] ?: null, '管理员在云数据库新增师傅', null, ['cloud_worker_id'=>$cloudId]);
        \flash('success', '师傅资料已写入云开发并进入待审核。');
        \redirect(\admin_url('workers', ['q'=>$this->post('openid')]));
    }

    private function update(): void
    {
        $cloudId = $this->post('cloud_id');
        $admin = $this->auth->user();
        $result = $this->workers->update($cloudId, $this->profileInput(), (int)($admin['id'] ?? 0));
        \audit('worker.cloud.update', 'worker', $result['local_worker_id'] ?: null, '管理员修改云端师傅资料', null, ['cloud_worker_id'=>$cloudId]);
        \flash('success', '师傅资料已直接保存到云开发，地图和详情会读取同一记录。');
        \redirect(\admin_url('workers', ['q'=>$cloudId]));
    }

    private function delete(): void
    {
        $cloudId = $this->post('cloud_id');
        $reason = mb_substr($this->post('reason'), 0, 500);
        $localId = $this->workers->delete($cloudId, $reason);
        \audit('worker.cloud.delete', 'worker', $localId ?: null, $reason, null, ['cloud_worker_id'=>$cloudId]);
        \flash('success', '师傅已在云数据库中停用；历史订单、实名和结算记录仍保留。');
        \redirect(\admin_url('workers'));
    }

    private function review(): void
    {
        $cloudId = $this->post('cloud_id');
        $decision = $this->post('decision');
        $reason = mb_substr($this->post('reason'), 0, 500);
        $admin = $this->auth->user();
        $result = $this->workers->review($cloudId, $decision, $reason, (int)($admin['id'] ?? 0));
        \audit('worker.cloud.review', 'worker', $result['local_worker_id'] ?: null, $decision.'：'.$reason, null, ['cloud_worker_id'=>$cloudId]);
        \flash('success', '云开发审核状态已更新，首页地图、师傅详情和报价权限将使用同一结果。');
        \redirect(\admin_url('workers', ['q'=>$cloudId]));
    }

    private function cloudHealth(): void
    {
        $result = $this->workers->cloudHealth();
        $stats = $result['stats'];
        $runtime = $result['runtime'];
        \audit('worker.cloud.health', 'cloud_worker', null, '管理员检查云端师傅管理链路并同步运行配置', null, ['config_version'=>(string)($runtime['version'] ?? '')]);
        \flash('success', '云开发连接正常：唯一师傅 '.(int)($stats['total'] ?? 0).' 人，已审核 '.(int)($stats['approved'] ?? 0).' 人，待审核 '.(int)($stats['pending'] ?? 0).' 人；运行配置已同步。');
        \redirect(\admin_url('workers'));
    }

    /** @return array<string,string> */
    private function profileInput(): array
    {
        $keys = [
            'display_name','worker_type','mobile','lat','lng','img_list','start_date','end_date','worker_time',
            'base_fee_amount','paid','measure_fee','pay_fixed','worker_paid','pay_oldwall','pay_oldwall_money',
            'sell','sell_goods','content','doEVA','doCurtain','doFilm','doPaint','dotArtpaint','doWallPaneling',
            'province','city','district','location_name','address','adcode',
        ];
        $input = [];
        foreach ($keys as $key) $input[$key] = $this->post($key);
        return $input;
    }

    private function post(string $key, string $default = ''): string
    {
        return isset($_POST[$key]) && !is_array($_POST[$key]) ? (string)$_POST[$key] : $default;
    }

    private function query(string $key, string $default = ''): string
    {
        return isset($_GET[$key]) && !is_array($_GET[$key]) ? (string)$_GET[$key] : $default;
    }
}
