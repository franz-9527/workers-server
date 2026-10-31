<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;

/** Provider secrets never enter public configuration, form values or audit payloads. */
final class ChatCallCredentials
{
    private const KEY='chat.calls.provider_credentials';
    public function __construct(private Database $db,private PrivateValueCipher $cipher) {}
    private function read():array
    {
        $row=$this->db->fetch('SELECT setting_value FROM system_settings WHERE setting_key=:key',['key'=>self::KEY]);
        if(!$row)return ['schema'=>1,'managed'=>false];
        $value=json_decode($row['setting_value'],true,16,JSON_THROW_ON_ERROR);
        if(!is_array($value)||($value['schema']??null)!==1)throw new RuntimeException('通话密钥配置不可用');
        return $value+['managed'=>true];
    }
    public function current():array
    {
        $v=$this->read();$saved=[];
        foreach(['certificate','customer_id','customer_secret'] as $key)$saved[$key]=is_string($v[$key]??null)&&$v[$key]!=='';
        return ['app_id'=>(string)($v['app_id']??''),'saved'=>$saved,'managed'=>$v['managed'],
            'publish_privileges_verified'=>($v['publish_privileges_verified']??false)===true];
    }
    /** Invoked only after the administrator and shared call-settings row are locked. */
    public function saveLocked(array $input,int $admin):array
    {
        $before=$this->read();
        if(($input['clear_credentials']??'0')==='1'){
            $next=['schema'=>1,'managed'=>true,'app_id'=>'','publish_privileges_verified'=>false];
        }else{
            $app=$input['shengwang_app_id']??'';
            if(!is_string($app)||preg_match('/^[a-f0-9]{32}$/D',$app)!==1)throw new RuntimeException('声网 App ID 应为32位小写十六进制字符');
            $verified=$input['publish_privileges_verified']??'0';
            if(!in_array($verified,['0','1'],true))throw new RuntimeException('发布权限开关不正确');
            $next=['schema'=>1,'managed'=>true,'app_id'=>$app,'publish_privileges_verified'=>$verified==='1'];
            foreach(['certificate','customer_id','customer_secret'] as $key){
                $plain=$input[$key]??'';
                if(!is_string($plain)||strlen($plain)>256||preg_match('/[\x00-\x20\x7f]/',$plain))throw new RuntimeException('密钥格式不正确，请勿包含空白字符');
                if($plain===''){
                    if(($before['app_id']??'')!==$app||!is_string($before[$key]??null)||$before[$key]==='')throw new RuntimeException('首次配置或更换应用时，请填写全部密钥');
                    $next[$key]=$before[$key];
                }else{
                    if($key==='certificate'&&preg_match('/^[a-f0-9]{32}$/D',$plain)!==1)throw new RuntimeException('App Certificate 应为32位小写十六进制字符');
                    $encrypted=$this->cipher->encrypt($plain,'chat.calls.'.$key);
                    if(!is_string($encrypted)||$encrypted===''||$encrypted===$plain)throw new RuntimeException('服务器加密配置不可用，密钥未保存');
                    $next[$key]=$encrypted;
                }
            }
        }
        // Switching a signing/control identity must not orphan active or unknown supplier exposure.
        $busy=$this->db->fetch("SELECT id FROM chat_call_resource_pools WHERE provider='shengwang' AND (reserved_units>0 OR local_uncovered>0) LIMIT 1 FOR UPDATE");
        $active=$this->db->fetch("SELECT id FROM chat_call_sessions WHERE state IN ('ringing','preparing','connecting','active','stopping') LIMIT 1 FOR UPDATE");
        if($busy||$active)throw new RuntimeException('仍有活动通话、待确认预占或待对账用量，请结束并对账后再修改密钥');
        $this->db->execute("INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,updated_by,created_at,updated_at) VALUES(:key,:value,'json','chat_private',0,'通话供应商加密凭据；仅签名内部接口可读取',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at),is_public=0,group_name='chat_private'",
            ['key'=>self::KEY,'value'=>json_encode($next,JSON_THROW_ON_ERROR),'admin'=>$admin]);
        return ['provider'=>'shengwang','cleared'=>($input['clear_credentials']??'0')==='1','fields'=>['app_id','certificate','customer_id','customer_secret','publish_privileges_verified']];
    }
    /** Only the existing HMAC-authenticated internal HTTPS endpoint may expose this DTO. */
    public function runtime():array
    {
        $v=$this->read();$result=['schema'=>1,'managed'=>$v['managed']];
        if(!$v['managed'])return $result;
        $result['app_id']=(string)($v['app_id']??'');
        $result['publish_privileges_verified']=($v['publish_privileges_verified']??false)===true;
        foreach(['certificate','customer_id','customer_secret'] as $key){
            $result[$key]=isset($v[$key])?$this->cipher->decrypt($v[$key],'chat.calls.'.$key):'';
            if(isset($v[$key])&&$result[$key]==='')throw new RuntimeException('CALL_RESOURCE_UNAVAILABLE');
        }
        return $result;
    }
}
