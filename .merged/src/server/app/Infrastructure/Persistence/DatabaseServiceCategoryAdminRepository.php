<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\ServiceCategoryAdminRepository;
use WorkerAdmin\Core\Database;

final class DatabaseServiceCategoryAdminRepository implements ServiceCategoryAdminRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function all(): array
    {
        return $this->db->fetchAll(
            "SELECT c.*,c.category_key AS code,p.name AS parent_name,
                (SELECT COUNT(*) FROM service_categories ch WHERE ch.parent_id=c.id) AS children_count,
                (SELECT COUNT(*) FROM orders o WHERE o.category_id=c.id) AS order_count
             FROM service_categories c
             LEFT JOIN service_categories p ON p.id=c.parent_id
             ORDER BY COALESCE(p.sort_order,c.sort_order),COALESCE(p.id,c.id),c.parent_id IS NOT NULL,c.sort_order,c.id"
        );
    }

    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT c.*,c.category_key AS code FROM service_categories c WHERE c.id=:id', ['id'=>$id]);
        return $row ?: null;
    }

    public function exists(int $id): bool
    {
        return (bool)$this->db->fetchValue('SELECT 1 FROM service_categories WHERE id=:id LIMIT 1', ['id'=>$id]);
    }

    public function codeExistsExcept(string $code, int $exceptId): bool
    {
        return (int)$this->db->fetchValue(
            'SELECT COUNT(*) FROM service_categories WHERE category_key=:code AND id<>:id',
            ['code'=>$code,'id'=>$exceptId]
        ) > 0;
    }

    public function orderCodeExistsExcept(string $orderCode, int $exceptId): bool
    {
        return (int)$this->db->fetchValue(
            'SELECT COUNT(*) FROM service_categories WHERE order_code=:order_code AND id<>:id',
            ['order_code'=>$orderCode,'id'=>$exceptId]
        ) > 0;
    }

    public function parentId(int $id): ?int
    {
        $parent = $this->db->fetchValue('SELECT parent_id FROM service_categories WHERE id=:id', ['id'=>$id]);
        if ($parent === false || $parent === null) return null;
        return (int)$parent;
    }

    public function save(int $id, array $values): int
    {
        if ($id > 0) {
            $params = $values + ['id'=>$id];
            $this->db->execute(
                'UPDATE service_categories SET parent_id=:parent_id,category_key=:code,order_code=:order_code,name=:name,sort_order=:sort_order,description=:description,updated_at=NOW() WHERE id=:id',
                $params
            );
            return $id;
        }

        return $this->db->insert(
            "INSERT INTO service_categories(parent_id,category_key,order_code,name,sort_order,status,description,created_at,updated_at) VALUES (:parent_id,:code,:order_code,:name,:sort_order,'active',:description,NOW(),NOW())",
            $values
        );
    }

    public function toggle(int $id): bool
    {
        return $this->db->execute(
            "UPDATE service_categories SET status=IF(status='active','disabled','active'),updated_at=NOW() WHERE id=:id",
            ['id'=>$id]
        ) > 0;
    }
}
