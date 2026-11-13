<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\AdminOversightRepository;
use WorkerAdmin\Core\Database;

final class DatabaseAdminOversightRepository implements AdminOversightRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function disputes(int $page, bool $includeDemo): array
    {
        $where = ['1=1'];
        $params = [];
        if (!$includeDemo) {
            $where[] = "o.public_no NOT LIKE :demo_public_no";
            $params['demo_public_no'] = '%DEMO%';
        }
        $select = "SELECT d.id,d.public_no AS dispute_no,o.public_no,d.dispute_type AS type,d.status,d.responsibility,d.description,d.resolution,d.refund_amount_cents AS refund_cents,d.run_empty_fee_cents AS runner_fee_cents,d.created_at
                   FROM disputes d LEFT JOIN orders o ON o.id=d.order_id
                   WHERE ".implode(' AND ', $where).' ORDER BY d.id DESC';
        $count = 'SELECT COUNT(*) FROM disputes d LEFT JOIN orders o ON o.id=d.order_id WHERE '.implode(' AND ', $where);
        return $this->paginate($select, $count, $params, $page);
    }

    public function audit(int $page, string $keyword, bool $includeDemo): array
    {
        $where = ['1=1'];
        $params = [];
        if (!$includeDemo) $where[] = "l.request_id NOT LIKE 'DEMO-%'";
        if ($keyword !== '') {
            $where[] = '(l.action LIKE :q_action OR l.entity_type LIKE :q_entity OR l.reason LIKE :q_reason OR l.request_id LIKE :q_request OR l.actor_name_snapshot LIKE :q_actor)';
            $like = '%'.$keyword.'%';
            $params = [
                'q_action'=>$like,
                'q_entity'=>$like,
                'q_reason'=>$like,
                'q_request'=>$like,
                'q_actor'=>$like,
            ];
        }
        $select = "SELECT l.*,a.username,a.real_name
                   FROM audit_logs l
                   LEFT JOIN admin_users a ON l.actor_type='admin' AND a.id=l.actor_id
                   WHERE ".implode(' AND ', $where).' ORDER BY l.id DESC';
        $count = 'SELECT COUNT(*) FROM audit_logs l WHERE '.implode(' AND ', $where);
        return $this->paginate($select, $count, $params, $page);
    }

    /** @param array<string,mixed> $params @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} */
    private function paginate(string $sql, string $countSql, array $params, int $page): array
    {
        $perPage = 20;
        $total = (int)$this->db->fetchValue($countSql, $params);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->fetchAll($sql.' LIMIT '.$perPage.' OFFSET '.$offset, $params);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$perPage];
    }
}
