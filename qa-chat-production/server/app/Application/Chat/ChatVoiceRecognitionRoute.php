<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use WorkerAdmin\Core\Database;

/** Read only provider outcomes. Local allowance estimates are not an account balance. */
final class ChatVoiceRecognitionRoute
{
    public function __construct(private Database $db){}

    public function read():array
    {
        $row=$this->db->fetch("SELECT state,UNIX_TIMESTAMP(created_at) called_at,metadata_json FROM chat_av_asr_requests WHERE state IN ('completed','failed','unknown') AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 DAY AND CASE WHEN JSON_VALID(metadata_json) THEN (JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.service'))='tencent_cloud_asr' OR JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.server_asr'))='budget_skipped') ELSE 0 END ORDER BY created_at DESC,intent_id DESC LIMIT 1");
        return self::decide($row?:[],time());
    }

    public static function decide(array $row,int $now):array
    {
        $primary=['provider'=>'tencent_cloud','retry_after'=>0];
        if(!in_array($row['state']??'',['failed','unknown'],true))return$primary;
        $meta=json_decode((string)($row['metadata_json']??''),true);
        if(!is_array($meta))return$primary;
        $quota=in_array($meta['provider_code']??'',['FailedOperation.UserHasNoAmount','FailedOperation.UserHasNoFreeAmount','FailedOperation.ServiceIsolate'],true);
        $budget=($meta['server_asr']??'')==='budget_skipped';
        if(($meta['service']??'')!=='tencent_cloud_asr'&&!$budget)return$primary;
        $remaining=(int)($row['called_at']??0)+($quota||$budget?300:60)-$now;
        if($remaining<=0||$remaining>300)return$primary;
        return ['provider'=>'wechat_si','reason'=>$quota?'quota':($budget?'budget':'provider_failure'),'retry_after'=>$remaining];
    }
}
