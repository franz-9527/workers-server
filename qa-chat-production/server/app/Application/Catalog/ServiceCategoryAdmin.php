<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Catalog;

use RuntimeException;
use WorkerAdmin\Contracts\ServiceCategoryAdminRepository;

final class ServiceCategoryAdmin
{
    private ServiceCategoryAdminRepository $categories;

    public function __construct(ServiceCategoryAdminRepository $categories)
    {
        $this->categories = $categories;
    }

    /** @return array{categories:array<int,array<string,mixed>>,editing:?array<string,mixed>} */
    public function page(int $editingId = 0): array
    {
        return [
            'categories' => $this->categories->all(),
            'editing' => $editingId > 0 ? $this->categories->find($editingId) : null,
        ];
    }

    /** @param array<string,mixed> $input @return array{id:int,name:string} */
    public function save(array $input): array
    {
        $id = max(0, (int)($input['id'] ?? 0));
        $name = trim((string)($input['name'] ?? ''));
        $code = trim((string)($input['code'] ?? ''));
        $orderCode = strtoupper(trim((string)($input['order_code'] ?? 'QT')));
        $parentId = (int)($input['parent_id'] ?? 0);
        $parentId = $parentId > 0 ? $parentId : null;
        $sortOrder = (int)($input['sort_order'] ?? 0);
        $description = (string)($input['description'] ?? '');

        if ($name === '' || preg_match('/^[a-z0-9_\-]{2,50}$/i', $code) !== 1 || preg_match('/^[A-Z]{2}$/', $orderCode) !== 1) {
            throw new RuntimeException('请填写类目名称、合法编码和 2 位大写字母订单号类目码。');
        }
        if ($this->categories->codeExistsExcept($code, $id)) {
            throw new RuntimeException('类目编码已经存在。');
        }
        if ($this->categories->orderCodeExistsExcept($orderCode, $id)) {
            throw new RuntimeException('订单号字母码已经被其他类目使用。');
        }
        if ($parentId !== null && !$this->categories->exists($parentId)) {
            throw new RuntimeException('所选上级类目不存在。');
        }
        if ($id > 0 && $parentId === $id) {
            throw new RuntimeException('类目不能把自己设为上级。');
        }
        if ($id > 0 && $parentId !== null) {
            $ancestor = $parentId;
            $depth = 0;
            while ($ancestor !== null && $depth++ < 100) {
                if ($ancestor === $id) {
                    throw new RuntimeException('不能把类目移动到自己的下级类目中。');
                }
                $ancestor = $this->categories->parentId($ancestor);
            }
        }

        $savedId = $this->categories->save($id, [
            'parent_id' => $parentId,
            'code' => $code,
            'order_code' => $orderCode,
            'name' => $name,
            'sort_order' => $sortOrder,
            'description' => $description,
        ]);

        return ['id' => $savedId, 'name' => $name];
    }

    public function toggle(int $id): void
    {
        if ($id < 1) {
            throw new RuntimeException('缺少有效记录编号。');
        }
        if (!$this->categories->toggle($id)) {
            throw new RuntimeException('类目不存在或状态已变化。');
        }
    }
}
