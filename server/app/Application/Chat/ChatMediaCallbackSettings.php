<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;

/** Existing WeChat message-push credentials, encrypted once; never derive pretend callback credentials. */
final class ChatMediaCallbackSettings
{
    private const TOKEN='chat.go_wechat_callback_token';
    private const AES='chat.go_wechat_callback_aes';
    private const VERIFIED='chat.go_wechat_callback_verified';
    public function __construct(private Database $db,private PrivateValueCipher $cipher){}
    private function read():array
    {
        $out=[];
        foreach($this->db->fetchAll("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('".self::TOKEN."','".self::AES."','".self::VERIFIED."')") as $row)$out[$row['setting_key']]=(string)$row['setting_value'];
        return $out;
    }
    private function plain(array $values,string $key,string $env):string
    {
        if(($values[$key]??'')!=='')return $this->cipher->decrypt($values[$key],$key.'-v1');
        $value=getenv($env);return is_string($value)?$value:'';
    }
    private function valid(string $token,string $aes):bool
    {
        $raw=base64_decode($aes.'=',true);
        return preg_match('/^[A-Za-z0-9]{16,32}$/D',$token)===1&&strlen($aes)===43
            &&is_string($raw)&&strlen($raw)===32&&rtrim(base64_encode($raw),'=')===$aes;
    }
    private function binding(string $appId,string $url,string $token,string $aes):string
    {return hash('sha256',implode("\n",[$appId,$url,$token,$aes]));}
    public function runtime(string $appId,string $siteUrl):array
    {
        $url=$siteUrl!==''?$siteUrl.'/chat/api/v1/av/callback':'';
        $missing=[];$token='';$aes='';$verified=false;
        try{
            $values=$this->read();
            $token=$this->plain($values,self::TOKEN,'CHAT_WECHAT_CALLBACK_TOKEN');
            $aes=$this->plain($values,self::AES,'CHAT_WECHAT_CALLBACK_AES_KEY');
            if(!$this->valid($token,$aes))$missing[]='微信消息推送 Token（16至32位字母数字）及 EncodingAESKey（43位）未配置或格式不正确';
            elseif($url===''||$appId==='')$missing[]='小程序 AppID 或站点 HTTPS 地址未配置';
            else $verified=hash_equals($this->binding($appId,$url,$token,$aes),$values[self::VERIFIED]??'');
            if(!$verified)$missing[]='尚未确认微信后台消息推送 URL、Token 和 EncodingAESKey 与本站一致';
        }catch(\Throwable $e){$missing[]='微信消息推送加密配置不可读取';}
        return ['ready'=>!$missing,'configured'=>$this->valid($token,$aes),'verified'=>$verified,'url'=>$url,
            'missing'=>$missing,'token'=>!$missing?$token:'','aes_key'=>!$missing?$aes:''];
    }
    /** Caller holds the super-administrator transaction/lock. Blank secret inputs preserve saved values. */
    public function saveLocked(array $input,int $admin,string $appId,string $siteUrl):void
    {
        $values=$this->read();
        $token=$input['wechat_callback_token']??'';$aes=$input['wechat_callback_aes_key']??'';
        if(!is_string($token)||!is_string($aes)||strlen($token)>128||strlen($aes)>128)throw new RuntimeException('微信消息推送密钥格式不正确。');
        $confirmed=($input['wechat_callback_confirmed']??'0')==='1';
        if($token===''&&$aes===''&&!$confirmed){
            $this->put(self::VERIFIED,'',$admin);return;
        }
        $token=$token!==''?$token:$this->plain($values,self::TOKEN,'CHAT_WECHAT_CALLBACK_TOKEN');
        $aes=$aes!==''?$aes:$this->plain($values,self::AES,'CHAT_WECHAT_CALLBACK_AES_KEY');
        if(!$this->valid($token,$aes))throw new RuntimeException('请填写微信消息推送 Token（16至32位字母数字）和43位 EncodingAESKey；留空可保留已配置密钥。');
        if($confirmed&&($appId===''||$siteUrl===''))throw new RuntimeException('请先配置小程序 AppID 和站点 HTTPS 地址，再确认消息推送。');
        foreach([self::TOKEN=>$token,self::AES=>$aes] as $key=>$plain){
            $encrypted=$this->cipher->encrypt($plain,$key.'-v1');
            if(!is_string($encrypted)||$encrypted===''||$encrypted===$plain)throw new RuntimeException('微信消息推送密钥加密失败，未保存。');
            $this->put($key,$encrypted,$admin);
        }
        $this->put(self::VERIFIED,$confirmed?$this->binding($appId,$siteUrl.'/chat/api/v1/av/callback',$token,$aes):'',$admin);
    }
    private function put(string $key,string $value,int $admin):void
    {
        $this->db->execute("INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,updated_by,created_at,updated_at) VALUES(:key,:value,'string','chat_private',0,'微信媒体审核消息推送加密配置',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_public=0,updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()",['key'=>$key,'value'=>$value,'admin'=>$admin]);
    }
}
