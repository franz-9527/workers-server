<?php
declare(strict_types=1);

namespace WorkerAdmin\Application\Chat;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\RedisRuntime;

/** Administrator-only, bounded read-only observation; never collects business payloads. */
final class ChatRuntimeDiagnostics
{
    public function __construct(private Database $db){}

    public function snapshot():array
    {
        $result=['redis'=>(new RedisRuntime($this->db))->diagnostics(),'mysql'=>[],'queues'=>[],'sampled_at'=>time()];
        try{
            $rows=$this->db->pdo()->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Threads_running','Innodb_rows_inserted','Innodb_rows_updated','Innodb_rows_deleted','Binlog_cache_use','Binlog_stmt_cache_use')")->fetchAll();
            foreach($rows as $row)if(isset($row['Variable_name'],$row['Value'])&&ctype_digit((string)$row['Value']))$result['mysql'][(string)$row['Variable_name']]=(int)$row['Value'];
        }catch(\Throwable $e){}
        // Optional: do not grant elevated replication privileges merely for this dashboard.
        try{
            $row=$this->db->pdo()->query('SHOW MASTER STATUS')->fetch();
            if(is_array($row)&&preg_match('/^[a-zA-Z0-9_.-]{1,128}$/D',(string)($row['File']??''))&&ctype_digit((string)($row['Position']??''))){
                $result['mysql']['binlog_file']=$row['File'];$result['mysql']['binlog_position']=(int)$row['Position'];
            }
        }catch(\Throwable $e){}
        $result['rates']=$this->sample($result['mysql'],$result['sampled_at']);
        $queues=[
            '图片待处理'=>"FROM chat_image_uploads WHERE state IN ('queued','checking') AND expires_at>UTC_TIMESTAMP(6)",
            '图片待人工审核'=>"FROM chat_image_bundles b JOIN chat_send_intents i ON i.id=b.intent_id WHERE b.review_state IN ('pending','claimed') AND i.state='review_pending' AND b.expires_at>UTC_TIMESTAMP(6)",
            '音视频待处理'=>"FROM chat_av_jobs j JOIN chat_send_intents i ON i.id=j.intent_id WHERE i.state IN ('checking','review_pending') AND j.phase NOT IN ('uploading','review_pending','failed','published','unknown') AND j.expires_at>UTC_TIMESTAMP(6)",
            '音视频待人工审核'=>"FROM chat_av_jobs j JOIN chat_send_intents i ON i.id=j.intent_id WHERE i.state='review_pending' AND j.phase='review_pending' AND j.review_state IN ('pending','claimed') AND j.expires_at>UTC_TIMESTAMP(6)",
            'ASR 待处理'=>"FROM chat_av_asr_requests WHERE state IN ('queued','started')",
            '通知队列待处理'=>"FROM message_outbox WHERE status IN ('pending','processing','failed') AND (next_attempt_at IS NOT NULL OR status IN ('pending','processing'))",
        ];
        foreach($queues as $label=>$where){
            try{
                $value=$this->db->fetchValue("SELECT /*+ MAX_EXECUTION_TIME(200) */ COUNT(*) FROM (SELECT 1 ".$where." LIMIT 1001) q");
                $result['queues'][$label]=is_numeric($value)?(int)$value:null;
            }catch(\Throwable $e){$result['queues'][$label]=null;}
        }
        return $result;
    }

    /** Site-private local sample. Counter reset, rotation or unavailable grants => no invented rate. */
    private function sample(array $current,int $now):array
    {
        if(!isset($current['Uptime']))return [];
        $path=\base_path('storage/private/chat-db-observation.json');
        $dir=dirname($path);
        if(!is_dir($dir)||is_link($dir)||is_link($path))return [];
        $file=@fopen($path,'c+b');if(!is_resource($file))return [];
        try{
            if(!@flock($file,LOCK_EX|LOCK_NB))return [];
            @chmod($path,0600);
            $stat=@fstat($file);$old=[];
            if(is_array($stat)&&(int)($stat['size']??0)<=8192){
                rewind($file);$raw=@stream_get_contents($file,8193);$parsed=is_string($raw)?json_decode($raw,true):null;
                if(is_array($parsed))$old=$parsed;
            }
            $elapsed=$now-(int)($old['at']??0);
            $previous=is_array($old['mysql']??null)?$old['mysql']:[];
            $sameEpoch=$elapsed>=0&&isset($previous['Uptime'])&&abs(($current['Uptime']-$previous['Uptime'])-$elapsed)<=5;
            $sameBinlog=isset($current['binlog_file'],$previous['binlog_file'],$current['binlog_position'],$previous['binlog_position'])&&$current['binlog_file']===$previous['binlog_file']&&$current['binlog_position']>=$previous['binlog_position'];
            $countersValid=$sameEpoch;
            foreach(['Innodb_rows_inserted','Innodb_rows_updated','Innodb_rows_deleted'] as $key){if(!isset($current[$key],$previous[$key])||$current[$key]<$previous[$key])$countersValid=false;}
            if($elapsed>=0&&$elapsed<30&&$sameEpoch&&isset($old['rates'])&&is_array($old['rates'])){
                $cached=$old['rates'];
                if(!$sameBinlog)unset($cached['binlog_mib_per_minute']);
                if(!$countersValid)unset($cached['rows_per_second']);
                return $cached;
            }
            $rates=[];
            if($elapsed>=30&&$elapsed<=86400&&isset($previous['Uptime'])&&abs(($current['Uptime']-$previous['Uptime'])-$elapsed)<=5){
                $rows=0;$valid=true;
                foreach(['Innodb_rows_inserted','Innodb_rows_updated','Innodb_rows_deleted'] as $key){
                    if(!isset($current[$key],$previous[$key])||$current[$key]<$previous[$key]){$valid=false;break;}
                    $rows+=$current[$key]-$previous[$key];
                }
                if($valid)$rates['rows_per_second']=round($rows/$elapsed,2);
                if(isset($current['binlog_file'],$previous['binlog_file'],$current['binlog_position'],$previous['binlog_position'])
                    &&$current['binlog_file']===$previous['binlog_file']&&$current['binlog_position']>=$previous['binlog_position']){
                    $rates['binlog_mib_per_minute']=round(($current['binlog_position']-$previous['binlog_position'])/$elapsed*60/1048576,3);
                }
                $rates['interval_seconds']=$elapsed;$rates['sampled_at']=$now;
            }
            $raw=json_encode(['at'=>$now,'mysql'=>$current,'rates'=>$rates],JSON_UNESCAPED_SLASHES);
            if(is_string($raw)&&strlen($raw)<8192){rewind($file);if(@ftruncate($file,0)){@fwrite($file,$raw);@fflush($file);}}
            return $rates;
        }finally{@flock($file,LOCK_UN);@fclose($file);}
    }
}
