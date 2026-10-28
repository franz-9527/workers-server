<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\ImageOptimizerService;
use WorkerAdmin\Services\QiniuStorageService;

final class DatabaseAdminOrderMediaStore
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}

    public function upload(int $orderId,string $purpose,array $file,int $adminId): int
    {
        if(!in_array($purpose,['product','wall'],true))throw new RuntimeException('订单图片类型不正确。');
        if(!$this->db->fetch('SELECT id FROM orders WHERE id=:id',['id'=>$orderId]))throw new RuntimeException('订单不存在。');
        $maximum=$purpose==='product'?9:12;$count=(int)$this->db->fetchValue("SELECT COUNT(*) FROM media_links WHERE entity_type='order' AND entity_id=:id AND purpose=:purpose",['id'=>$orderId,'purpose'=>$purpose]);if($count>=$maximum)throw new RuntimeException(($purpose==='product'?'材料图片':'现场环境/墙面图片').'最多 '.$maximum.' 张。');
        if(!$file||(int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||empty($file['tmp_name'])||!is_uploaded_file((string)$file['tmp_name']))throw new RuntimeException('请选择有效图片。');if((int)($file['size']??0)>10*1024*1024)throw new RuntimeException('单张图片不能超过 10MB。');
        try{$optimized=(new ImageOptimizerService())->optimize((string)$file['tmp_name']);}catch(Throwable $e){throw new RuntimeException($e->getMessage(),0,$e);}
        $path=(string)$optimized['path'];$storageKey=(string)$optimized['storage_key'];$publicId=\public_id();$metadata=json_encode(['compression_mode'=>'lossless-first','compression_method'=>(string)($optimized['method']??'original')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        try{$mediaId=$this->db->transaction(function(Database $db)use($orderId,$purpose,$count,$adminId,$optimized,$storageKey,$publicId,$metadata){$id=$db->insert("INSERT INTO media_files(public_id,storage_disk,storage_key,mime_type,extension,size_bytes,width_px,height_px,sha256,compression_quality,metadata_json,status,reference_count,created_at) VALUES(:public_id,'private_local',:storage_key,:mime_type,:extension,:size,:width,:height,:sha256,:quality,:metadata,'active',1,NOW())",['public_id'=>$publicId,'storage_key'=>$storageKey,'mime_type'=>$optimized['mime_type'],'extension'=>$optimized['extension'],'size'=>$optimized['size_bytes'],'width'=>$optimized['width_px'],'height'=>$optimized['height_px'],'sha256'=>$optimized['sha256'],'quality'=>$optimized['compression_quality'],'metadata'=>$metadata]);$db->insert("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at) VALUES(:media_id,'order',:order_id,:purpose,:sort,'admin',:admin,NOW())",['media_id'=>$id,'order_id'=>$orderId,'purpose'=>$purpose,'sort'=>$count,'admin'=>$adminId]);return$id;});}catch(Throwable $e){@unlink($path);throw$e;}
        try{$stored=(new QiniuStorageService())->store($path,$storageKey,'order',(string)$optimized['mime_type']);if(($stored['disk']??'private_local')==='qiniu'){$changed=$this->db->execute("UPDATE media_files SET storage_disk='qiniu',storage_key=:key WHERE public_id=:public_id AND status='active'",['key'=>$stored['key'],'public_id'=>$publicId]);if($changed!==1)throw new RuntimeException('七牛云已上传，但后台图片记录未能更新，已保留本地副本。');@unlink($path);}}catch(Throwable $e){\write_log('qiniu-upload','['.date('c').'] admin media '.$publicId.' '.$e->getMessage()."\n");}
        return$mediaId;
    }

    public function delete(int $orderId,int $mediaId): void
    {
        $changed=$this->db->transaction(function(Database $db)use($orderId,$mediaId){$link=$db->fetch("SELECT id FROM media_links WHERE entity_type='order' AND entity_id=:order_id AND media_file_id=:media_id FOR UPDATE",['order_id'=>$orderId,'media_id'=>$mediaId]);if(!$link)return 0;$db->execute('DELETE FROM media_links WHERE id=:id',['id'=>$link['id']]);$db->execute("UPDATE media_files SET status=IF(reference_count<=1,'pending_delete',status),pending_delete_at=IF(reference_count<=1,DATE_ADD(NOW(),INTERVAL 1 DAY),pending_delete_at),reference_count=IF(reference_count>0,reference_count-1,0) WHERE id=:id",['id'=>$mediaId]);return 1;});if(!$changed)throw new RuntimeException('图片不存在或已删除。');
    }

    public function descriptor(array $media): array
    {
        if((string)($media['storage_disk']??'')==='qiniu'){$qiniu=new QiniuStorageService();$url=$qiniu->downloadUrl((string)$media['storage_key'],900);if($qiniu->usesHttp()){if(!function_exists('curl_init'))throw new RuntimeException('服务器 PHP 未启用 cURL，无法读取七牛云图片。');$curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30]);$raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$error=curl_error($curl);curl_close($curl);if(!is_string($raw)||$status<200||$status>=300)throw new RuntimeException('七牛云 HTTP 回源失败'.($error!==''?'：'.$error:'，HTTP '.$status));return ['mode'=>'raw','body'=>$raw,'mime'=>(string)$media['mime_type']];}return ['mode'=>'redirect','url'=>$url,'mime'=>(string)$media['mime_type'],'public_id'=>(string)$media['public_id']];}
        if((string)($media['storage_disk']??'')!=='private_local')throw new RuntimeException('图片存储类型不受支持。');$key=str_replace('\\','/',trim((string)$media['storage_key']));if($key===''||$key[0]==='/'||strpos('/'.$key.'/','/../')!==false||strpos($key,"\0")!==false)throw new RuntimeException('图片路径无效。');$legacy='storage/private/';if(strpos($key,$legacy)===0)$key=substr($key,strlen($legacy));$root=realpath(\base_path('storage/private'));$file=$root!==false?realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$key)):false;$prefix=$root!==false?rtrim($root,'/\\').DIRECTORY_SEPARATOR:'';if($file===false||!is_file($file)||$prefix===''||strncmp($file,$prefix,strlen($prefix))!==0)throw new RuntimeException('图片文件不存在。');$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];$mime=strtolower((string)$media['mime_type']);if(!isset($allowed[$mime]))throw new RuntimeException('图片格式不受支持。');return ['mode'=>'file','path'=>$file,'mime'=>$mime,'size'=>(int)filesize($file),'filename'=>preg_replace('/[^A-Za-z0-9_-]/','',(string)$media['public_id']).'.'.$allowed[$mime]];
    }
}
