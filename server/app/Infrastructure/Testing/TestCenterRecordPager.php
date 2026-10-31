<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Testing;
use WorkerAdmin\Core\Database;
final class TestCenterRecordPager
{
    public function __construct(private Database $db) {}
    public function apply(array $detail,string $section,string $panel,int $page):array
    {
        $query=[
            'checks'=>['assertions','test_assertion_results','instance_id',(int)$detail['instance']['id']],
            'runs'=>['runs','test_runs','instance_id',(int)$detail['instance']['id']],
            'events'=>['events','order_events','order_id',(int)($detail['instance']['order_id']??0)],
            'notices'=>['messages','test_message_outbox','instance_id',(int)$detail['instance']['id']],
        ][$panel]??null;
        if(!$query||!in_array($section,['records','messages'],true))return $detail;
        [$key,$table,$column,$id]=$query;$page=max(1,min(10000,$page));
        $rows=$this->db->fetchAll('SELECT * FROM '.$table.' WHERE '.$column.'=:id ORDER BY id DESC LIMIT 21 OFFSET '.(($page-1)*20),['id'=>$id]);
        $detail[$key]=array_slice($rows,0,20);$detail['record_pagination']=['page'=>$page,'has_more'=>count($rows)>20];return $detail;
    }
}
