<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use Throwable;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\CancellationRiskPolicy;
use WorkerAdmin\Http\Admin\AdminModule;
use WorkerAdmin\Application\OrderCancellationRisk;
use WorkerAdmin\Foundation\OrderCancellationRiskComposition;

/** 取消风控：全局规则统一配置，单用户只做核实、人工限制、解除与误判排除。 */
final class OrderCancellationRiskAdminModule implements AdminModule
{
    private Database $db;
    private Auth $auth;
    private Csrf $csrf;
    private OrderCancellationRisk $risk;
    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    { $this->db=$db; $this->auth=$auth; $this->csrf=$csrf; $this->risk=OrderCancellationRiskComposition::build($db); }
    public function supports(string $route): bool
    { return in_array($route,['users.order-risk','users.order-risk.release','users.order-risk.restrict','users.order-risk.settings','users.order-risk.rules'],true); }
    public function dispatch(string $route): void
    {
        $this->auth->requirePermission(in_array($route,['users.order-risk','users.order-risk.rules'],true) ? 'users.view' : 'users.manage');

        if ($route==='users.order-risk.rules') {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'))!=='GET') throw new \RuntimeException('请求方式不正确');
            echo \view('admin/order-cancellation-risk-rules',[
                'pageTitle'=>'下单风控规则','activeMenu'=>'users','rules'=>$this->risk->configuration(),
                'canManage'=>$this->auth->can('users.manage'),
            ]);
            return;
        }

        if ($route==='users.order-risk.settings') {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'))!=='POST') throw new \RuntimeException('请求方式不正确');
            if ((int)($_GET['id'] ?? $_POST['id'] ?? 0)!==0) throw new \RuntimeException('风控规则只能在全局规则页修改');
            $this->csrf->verifyRequest();
            try {
                $rules=CancellationRiskPolicy::config(is_array($_POST['rules'] ?? null) ? $_POST['rules'] : []);
                $this->risk->saveConfiguration($rules);
                \audit('order-risk.settings','system',null,'调整取消风控阈值',null,$rules);
                \flash('success','风控规则已保存；已生效的限制结束时间保持不变。');
            } catch (Throwable $exception) { \flash('danger',$exception->getMessage()); }
            \redirect(\admin_url('users.order-risk.rules'));
            return;
        }

        $id=(int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $user=$this->risk->user($id);
        if (!$user) throw new \RuntimeException('用户不存在');
        if ($route==='users.order-risk') {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'))!=='GET') throw new \RuntimeException('请求方式不正确');
            echo \view('admin/order-cancellation-risk',['pageTitle'=>'下单风控','activeMenu'=>'users','user'=>$user,
                'risk'=>$this->risk->reviewData($id),'rules'=>$this->risk->configuration(),'canManage'=>$this->auth->can('users.manage')]);
            return;
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'))!=='POST') throw new \RuntimeException('请求方式不正确');
        $this->csrf->verifyRequest();
        try {
            $reason=trim((string)($_POST['reason'] ?? ''));
            if ($route==='users.order-risk.restrict') {
                $days=max(1,min(30,(int)($_POST['days'] ?? 7)));
                $this->risk->restrictManually($id,(int)$this->auth->user()['id'],$days,$reason);
                \audit('user.order-risk.restrict','user',$id,'人工限制下单 '.$days.' 天：'.$reason);
                \flash('success','已人工限制该用户新下单 '.$days.' 天；已有订单与售后不受影响。');
            } else {
                $orders=is_array($_POST['exclude_orders'] ?? null) ? $_POST['exclude_orders'] : [];
                $this->risk->release($id,(int)$this->auth->user()['id'],$reason,$orders,(int)($_POST['restriction_id'] ?? 0));
                \audit('user.order-risk.release','user',$id,$reason);
                \flash('success','下单限制已解除；被排除的命中记录不会再次触发限制。');
            }
        } catch (Throwable $exception) { \flash('danger',$exception->getMessage()); }
        \redirect(\admin_url('users.order-risk',['id'=>$id]));
    }
}
