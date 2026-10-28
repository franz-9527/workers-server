<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Catalog\ServiceCategoryAdmin;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\ServiceCategoryAdminComposition;
use WorkerAdmin\Http\Admin\AdminModule;

final class ServiceCategoryAdminModule implements AdminModule
{
    private Auth $auth;
    private ServiceCategoryAdmin $categories;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->categories = ServiceCategoryAdminComposition::build($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, ['categories','categories.save','categories.toggle'], true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requirePermission('catalog.manage');
        $expected = $route === 'categories' ? 'GET' : 'POST';
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $expected) {
            http_response_code(405);
            throw new RuntimeException('该操作只接受 ' . $expected . ' 请求。');
        }

        try {
            if ($route === 'categories') {
                $this->index();
                return;
            }
            if ($route === 'categories.save') {
                $this->save();
                return;
            }
            $this->toggle();
        } catch (RuntimeException $exception) {
            if ($expected === 'POST') {
                \old_input($this->sanitizeOldInput($_POST));
                \flash('danger', $exception->getMessage());
                $editingId = $route === 'categories.save' ? max(0, (int)($_POST['id'] ?? 0)) : 0;
                \redirect(\admin_url('categories', $editingId > 0 ? ['id'=>$editingId] : []));
            }
            throw $exception;
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('admin-category-error', '[' . date('c') . '] request_id=' . $requestId . ' route=' . $route . ' ' . $exception->getMessage() . "\n");
            if ($expected === 'POST') {
                \flash('danger', '服务类目处理失败，请稍后重试。请求编号：' . $requestId);
                \redirect(\admin_url('categories'));
            }
            http_response_code(500);
            echo \view('errors/500', ['pageTitle'=>'系统处理失败','message'=>'服务类目读取失败，请稍后重试。请求编号：'.$requestId]);
        }
    }

    private function index(): void
    {
        $id = isset($_GET['id']) && !is_array($_GET['id']) ? max(0, (int)$_GET['id']) : 0;
        $data = $this->categories->page($id);
        echo \view('admin/categories', [
            'pageTitle'=>'服务类目',
            'activeMenu'=>'catalog',
            'categories'=>$data['categories'],
            'editing'=>$data['editing'],
        ]);
    }

    private function save(): void
    {
        $input = [
            'id'=>$this->post('id', '0'),
            'name'=>$this->post('name'),
            'code'=>$this->post('code'),
            'order_code'=>$this->post('order_code', 'QT'),
            'parent_id'=>$this->post('parent_id', '0'),
            'sort_order'=>$this->post('sort_order', '0'),
            'description'=>$this->post('description'),
        ];
        $originalId = max(0, (int)$input['id']);
        $result = $this->categories->save($input);
        \audit('category.save', 'category', $originalId > 0 ? $result['id'] : null, $result['name']);
        \flash('success', '类目已保存。');
        \redirect(\admin_url('categories'));
    }

    private function toggle(): void
    {
        $id = isset($_GET['id']) && !is_array($_GET['id']) ? (int)$_GET['id'] : 0;
        $this->categories->toggle($id);
        \audit('category.toggle', 'category', $id, '切换类目状态');
        \flash('success', '类目状态已切换。');
        \redirect(\admin_url('categories'));
    }

    private function post(string $key, string $default = ''): string
    {
        return isset($_POST[$key]) && !is_array($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
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
