<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use DateTimeImmutable;
use DateTimeZone;
use WorkerAdmin\Core\Database;

/** Local evidence only. This never queries or claims to know a Tencent account balance. */
final class ChatAsrUsage
{
    public function __construct(private Database $db){}

    public function read(?DateTimeImmutable $now=null):array
    {
        $zone=new DateTimeZone('Asia/Shanghai');
        $now=($now??new DateTimeImmutable('now',$zone))->setTimezone($zone);
        $start=$now->modify('first day of this month')->setTime(0,0);
        $end=$start->modify('+1 month');
        $utc=new DateTimeZone('UTC');
        $out=[
            'status'=>'read_failed','month'=>$start->format('Y-m'),'timezone'=>'Asia/Shanghai',
            'total'=>null,'success'=>null,'failed'=>null,'unknown'=>null,'pending'=>null,
            'free_monthly_success_calls'=>5000,'estimated_free_remaining'=>null,
            'estimated_free_remaining_min'=>null,'official_remaining_available'=>false,
            'official_remaining_note'=>'仅按本站记录估算，未含同一腾讯云账号下其他项目的用量、资源包和后付费；结果未知的请求可能已计费。官方余量请在腾讯云语音识别控制台核对。',
        ];
        try{
            $row=$this->db->fetch(
                "SELECT COUNT(*) total,COALESCE(SUM(state='completed'),0) success,".
                "COALESCE(SUM(state='failed'),0) failed,COALESCE(SUM(state='unknown'),0) unknown,".
                "COALESCE(SUM(state IN ('queued','started')),0) pending ".
                "FROM chat_av_asr_requests WHERE created_at>=:month_start AND created_at<:month_end ".
                "AND CASE WHEN JSON_VALID(metadata_json) THEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.service')) ELSE NULL END='tencent_cloud_asr'",
                ['month_start'=>$start->setTimezone($utc)->format('Y-m-d H:i:s'),'month_end'=>$end->setTimezone($utc)->format('Y-m-d H:i:s')]
            );
            if(!is_array($row))return$out;
            foreach(['total','success','failed','unknown','pending'] as $key)$out[$key]=max(0,(int)($row[$key]??0));
            $out['status']='ready';
            $out['estimated_free_remaining']=max(0,5000-$out['success']);
            $out['estimated_free_remaining_min']=max(0,5000-$out['success']-$out['unknown']-$out['pending']);
        }catch(\Throwable $e){}
        return$out;
    }
}
