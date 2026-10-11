<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderDiscoveryRepository;
use WorkerAdmin\Core\Database;

final class DatabaseOrderDiscoveryRepository implements OrderDiscoveryRepository
{
    private Database $db;

    public function __construct(Database $db) { $this->db = $db; }

    public function hallPage(array $actor,int $categoryId,string $sort,?float $lat,?float $lng,int $page,int $limit): array
    {
        $page=max(1,$page);$limit=max(1,min(50,$limit));$offset=($page-1)*$limit;$params=[];$where=[];
        $publicHallSql="o.publish_status='published' AND o.payment_status='paid' AND o.quote_status IN ('waiting','quoting','collecting') AND o.assigned_worker_id IS NULL AND o.current_assignment_id IS NULL AND o.selected_quote_id IS NULL AND COALESCE(o.refund_status,'none')='none' AND (o.quote_deadline_at IS NULL OR o.quote_deadline_at>UTC_TIMESTAMP())";
        $where[]="o.audit_status='approved' AND {$publicHallSql} AND o.lifecycle_status='active'";
        $where[]=!empty($actor['is_demo_actor'])?'o.is_demo=1':"(o.is_demo=0 OR (o.is_demo=1 AND BINARY o.public_no LIKE BINARY 'BXDEMOHALL%'))";
        $params['worker_quote_id']=(int)($actor['worker_id']??0);
        $sql='SELECT o.id,o.public_no FROM orders o WHERE '.implode(' AND ',$where).' LIMIT '.($limit+1).' OFFSET '.$offset;
        $rows=$this->db->fetchAll($sql,$params);$hasMore=count($rows)>$limit;if($hasMore)$rows=array_slice($rows,0,$limit);
        return ['rows'=>$rows,'has_more'=>$hasMore];
    }
}
