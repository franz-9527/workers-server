<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Form\OrderFormDesignerAdmin;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderFormDesignerAdminComposition;
use WorkerAdmin\Http\Admin\AdminModule;

final class OrderFormDesignerAdminModule implements AdminModule
{
    private const ROUTES = [
        'forms','forms.designer','forms.designer.reorder','forms.ui.save','forms.schema.save',
        'forms.version.create','forms.step.save','forms.step.toggle','forms.field.save','forms.field.toggle','forms.publish',
    ];

    private const GET_ROUTES = ['forms','forms.designer'];

    private Auth $auth;
    private OrderFormDesignerAdmin $forms;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->forms = OrderFormDesignerAdminComposition::build($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, self::ROUTES, true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requirePermission('catalog.manage');
        $expected = in_array($route, self::GET_ROUTES, true) ? 'GET' : 'POST';
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $expected) {
            http_response_code(405);
            throw new RuntimeException('该操作只接受 '.$expected.' 请求。');
        }

        try {
            switch ($route) {
                case 'forms': $this->entry(); return;
                case 'forms.designer': $this->designer(); return;
                case 'forms.designer.reorder': $this->reorder(); return;
                case 'forms.ui.save': $this->saveUi(); return;
                case 'forms.schema.save': $this->createSchema(); return;
                case 'forms.version.create': $this->createVersion(); return;
                case 'forms.step.save': $this->saveStep(); return;
                case 'forms.step.toggle': $this->toggleStep(); return;
                case 'forms.field.save': $this->saveField(); return;
                case 'forms.field.toggle': $this->toggleField(); return;
                case 'forms.publish': $this->publish(); return;
            }
            throw new RuntimeException('后台表单路由不存在。');
        } catch (RuntimeException $exception) {
            if ($expected === 'POST') {
                \old_input($this->sanitizeOldInput($_POST));
                \flash('danger', $exception->getMessage());
                \redirect($this->failureRedirect());
            }
            throw $exception;
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('admin-form-designer-error', '['.date('c').'] request_id='.$requestId.' route='.$route.' '.$exception->getMessage()."\n");
            if ($expected === 'POST') {
                \flash('danger', '下单配置处理失败，请稍后重试。请求编号：'.$requestId);
                \redirect($this->failureRedirect());
            }
            http_response_code(500);
            echo \view('errors/500', ['pageTitle'=>'系统处理失败','message'=>'下单配置读取失败，请稍后重试。请求编号：'.$requestId]);
        }
    }

    private function entry(): void
    {
        $target = $this->forms->entry();
        \redirect(\admin_url('forms.designer', [
            'schema_id'=>(int)$target['schema_id'],
            'version_id'=>(int)$target['version_id'],
        ]));
    }

    private function designer(): void
    {
        $versionId = $this->queryId('version_id', false);
        $data = $this->forms->designer($versionId);
        echo \view('admin/form-designer', $data + ['pageTitle'=>'下单页配置','activeMenu'=>'forms']);
    }

    private function saveUi(): void
    {
        $result = $this->forms->saveUi($_POST, $this->adminId());
        \audit('form.ui.save', 'form_schema_version', $result['version_id'], '更新下单表单展示设置');
        \flash('success', '前端填写方式已更新。');
        $this->redirectDesigner($result['schema_id'], $result['version_id']);
    }

    private function reorder(): void
    {
        $result = $this->forms->reorder($_POST, $this->adminId());
        \audit('form.designer.reorder', 'form_schema', $result['schema_id'], '可视化设计器调整字段顺序');
        \flash('success', '下单配置已保存。');
        $this->redirectDesigner($result['schema_id'], $result['version_id']);
    }

    private function createSchema(): void
    {
        $adminId = $this->requiredAdminId();
        $result = $this->forms->createSchema($_POST, $adminId, 'order_'.strtolower(substr(\public_id(), -10)));
        \audit('form.schema.create', 'form_schema', $result['schema_id'], $result['name']);
        \flash('success', '表单草稿已创建。');
        \redirect(\admin_url('forms', ['schema_id'=>$result['schema_id']]));
    }

    private function createVersion(): void
    {
        $schemaId = $this->queryId('id');
        $result = $this->forms->createVersion($_POST, $schemaId, $this->requiredAdminId());
        if ($result['created']) {
            \audit('form.version.create', 'form_schema', $schemaId, '复制为 V'.$result['version_no'].' 草稿');
            \flash('success', 'V'.$result['version_no'].' 草稿已创建，发布前不会影响线上版本。');
        } else {
            \flash('info', '已有 V'.$result['version_no'].' 草稿，已直接打开。');
        }
        $this->redirectDesigner($schemaId, $result['id']);
    }

    private function saveStep(): void
    {
        $result = $this->forms->saveStep($_POST, $this->adminId());
        \audit('form.step.save', 'form_schema', $result['schema_id'], $result['title']);
        \flash('success', '下单配置已保存。');
        $this->redirectDesigner($result['schema_id'], $result['version_id']);
    }

    private function toggleStep(): void
    {
        $result = $this->forms->toggleStep($_POST, $this->queryId('id'), $this->adminId());
        \audit('form.step.toggle', 'form_step', $result['step_id'], '切换步骤状态');
        \flash('success', '步骤状态已更新。');
        $this->redirectDesigner($result['schema_id'], $result['version_id']);
    }

    private function saveField(): void
    {
        $result = $this->forms->saveField($_POST, $this->adminId());
        \audit($result['created'] ? 'form.field.create' : 'form.field.update', 'form_schema', $result['schema_id'], $result['label']);
        \flash('success', '草稿已保存。');
        $this->redirectDesigner($result['schema_id'], $result['version_id']);
    }

    private function toggleField(): void
    {
        $result = $this->forms->toggleField($_POST, $this->queryId('id'), $this->adminId());
        \audit('form.field.toggle', 'form_field', $result['field_id'], '切换字段状态');
        \flash('success', '字段状态已更新。');
        $this->redirectDesigner($result['schema_id'], $result['version_id']);
    }

    private function publish(): void
    {
        $result = $this->forms->publish($this->queryId('id'), $this->requiredAdminId());
        \audit('form.publish', 'form_schema', $result['schema_id'], '发布动态表单版本');
        \flash('success', '新表单版本已发布，历史订单不受影响。');
        \redirect(\admin_url('forms', ['schema_id'=>$result['schema_id'],'version_id'=>$result['version_id']]));
    }

    private function redirectDesigner(int $schemaId, int $versionId): void
    {
        \redirect(\admin_url('forms.designer', ['schema_id'=>$schemaId,'version_id'=>$versionId]));
    }

    private function failureRedirect(): string
    {
        $schemaId = isset($_POST['schema_id']) && !is_array($_POST['schema_id']) ? max(0, (int)$_POST['schema_id']) : 0;
        $versionId = isset($_POST['version_id']) && !is_array($_POST['version_id']) ? max(0, (int)$_POST['version_id']) : 0;
        if ($schemaId > 0 || $versionId > 0) {
            $params = [];
            if ($schemaId > 0) $params['schema_id'] = $schemaId;
            if ($versionId > 0) $params['version_id'] = $versionId;
            return \admin_url('forms.designer', $params);
        }
        return \admin_url('forms');
    }

    private function queryId(string $key, bool $required = true): int
    {
        $id = isset($_GET[$key]) && !is_array($_GET[$key]) ? max(0, (int)$_GET[$key]) : 0;
        if ($required && $id < 1) throw new RuntimeException('缺少有效记录编号。');
        return $id;
    }

    private function adminId(): ?int
    {
        $user = $this->auth->user();
        $id = isset($user['id']) ? (int)$user['id'] : 0;
        return $id > 0 ? $id : null;
    }

    private function requiredAdminId(): int
    {
        $id = $this->adminId();
        if ($id === null) throw new RuntimeException('管理员登录状态已失效。');
        return $id;
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function sanitizeOldInput(array $values): array
    {
        $safe = [];
        foreach ($values as $key=>$value) {
            $name = is_string($key) ? $key : (string)$key;
            if (preg_match('/(?:password|passwd|secret|token|key|certificate|credential|signature|_csrf)/i', $name)) continue;
            if (is_array($value)) $safe[$key] = $this->sanitizeOldInput($value);
            elseif (is_scalar($value) || $value === null) $safe[$key] = mb_substr((string)$value, 0, 2000);
        }
        return $safe;
    }
}
