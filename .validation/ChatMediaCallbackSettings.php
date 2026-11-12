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
    private const MODE='chat.go_wechat_callback_mode';
    private const RELAY_KEY='chat.go_wechat_callback_relay_key';
    public function __construct(private Database $db,private PrivateValueCipher $cipher){}
    private function read():array
    {
        $out=[];
        foreach($this->db->fetchAll("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('".self::TOKEN."','".self::AES."','".self::VERIFIED."','".self::MODE."','".self::RELAY_KEY."')") as $row)$out[$row['setting_key']]=(string)$row['setting_value'];
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
    private function testSite():bool
    {return (string)\app_config('app.env','production')==='testing';}
    private function relayBinding(string $appId,string $siteUrl,string $key):string
    {return hash('sha256',implode("\n",['formal_relay',$appId,$siteUrl,$key]));}
    public function runtime(string $appId,string $siteUrl):array
    {
        $url=$siteUrl!==''?$siteUrl.'/chat/api/v1/av/callback':'';
        $missing=[];$token='';$aes='';$verified=false;$mode='direct';
        try{
            $values=$this->read();
            if($this->testSite()&&($values[self::MODE]??'')==='relay'){
                $mode='relay';
                $key=$this->plain($values,self::RELAY_KEY,'CHAT_MEDIA_RELAY_KEY');
                $configured=preg_match('/^[a-f0-9]{64}$/D',$key)===1;
                $verified=$configured&&$appId!==''&&$siteUrl!==''&&hash_equals($this->relayBinding($appId,$siteUrl,$key),$values[self::VERIFIED]??'');
                $missing=$verified?[]:[$configured?'请确认正式站已启用回执分发，并应用测试站 Go 配置':'请填写正式站与测试站共用的媒体回执分发密钥'];
                return ['ready'=>$verified,'configured'=>false,'verified'=>$verified,'url'=>$url,'missing'=>$missing,
                    'token'=>'','aes_key'=>'','mode'=>'relay','relay_configured'=>$configured,'relay_key'=>$verified?$key:''];
            }
            $token=$this->plain($values,self::TOKEN,'CHAT_WECHAT_CALLBACK_TOKEN');
            $aes=$this->plain($values,self::AES,'CHAT_WECHAT_CALLBACK_AES_KEY');
            if(!$this->valid($token,$aes))$missing[]='微信消息推送 Token（16至32位字母数字）及 EncodingAESKey（43位）未配置或格式不正确';
            elseif($url===''||$appId==='')$missing[]='小程序 AppID 或站点 HTTPS 地址未配置';
            else $verified=hash_equals($this->binding($appId,$url,$token,$aes),$values[self::VERIFIED]??'');
            if(!$verified)$missing[]='尚未确认微信后台消息推送 URL、Token 和 EncodingAESKey 与本站一致';
        }catch(\Throwable $e){$missing[]='微信消息推送加密配置不可读取';}
        return ['ready'=>!$missing,'configured'=>$this->valid($token,$aes),'verified'=>$verified,'url'=>$url,
            'missing'=>$missing,'token'=>$this->valid($token,$aes)?$token:'','aes_key'=>$this->valid($token,$aes)?$aes:'',
            'mode'=>$mode,'relay_configured'=>!empty($values[self::RELAY_KEY]),'relay_key'=>''];
    }
    /** Caller holds the super-administrator transaction/lock. Blank secret inputs preserve saved values. */
    public function saveLocked(array $input,int $admin,string $appId,string $siteUrl):void
    {
        if(!array_intersect(['wechat_callback_token','wechat_callback_aes_key','wechat_callback_confirmed','wechat_callback_mode','wechat_callback_relay_key'],array_keys($input)))return;
        $values=$this->read();
        $mode=$input['wechat_callback_mode']??($values[self::MODE]??'direct');
        if(!in_array($mode,['direct','relay'],true)||($mode==='relay'&&!$this->testSite()))throw new RuntimeException('微信回调接收方式无效。');
        $confirmed=($input['wechat_callback_confirmed']??'0')==='1';
        if($mode==='relay'){
            $key=$input['wechat_callback_relay_key']??'';
            if(!is_string($key)||strlen($key)>64)throw new RuntimeException('分发密钥须为64位十六进制字符。');
            $key=strtolower(trim($key));
            if($key==='')$key=$this->plain($values,self::RELAY_KEY,'CHAT_MEDIA_RELAY_KEY');
            if(($key!==''||$confirmed)&&preg_match('/^[a-f0-9]{64}$/D',$key)!==1)throw new RuntimeException('分发密钥须为64位十六进制字符。');
            if($confirmed&&($appId===''||$siteUrl===''))throw new RuntimeException('请先配置测试站小程序 AppID 和 HTTPS 地址。');
            if($key!=='')$this->saveSecret(self::RELAY_KEY,$key,$admin);
            $this->put(self::MODE,'relay',$admin);
            $this->put(self::VERIFIED,$confirmed?$this->relayBinding($appId,$siteUrl,$key):'',$admin);
            return;
        }
        if(($values[self::MODE]??'direct')!=='direct')$this->put(self::MODE,'direct',$admin);
        $token=$input['wechat_callback_token']??'';$aes=$input['wechat_callback_aes_key']??'';
        if(!is_string($token)||!is_string($aes)||strlen($token)>128||strlen($aes)>128)throw new RuntimeException('微信消息推送密钥格式不正确。');
        if($token===''&&$aes===''&&!$confirmed){
            $this->put(self::VERIFIED,'',$admin);return;
        }
        $token=$token!==''?$token:$this->plain($values,self::TOKEN,'CHAT_WECHAT_CALLBACK_TOKEN');
        $aes=$aes!==''?$aes:$this->plain($values,self::AES,'CHAT_WECHAT_CALLBACK_AES_KEY');
        if(!$this->valid($token,$aes))throw new RuntimeException('请填写微信消息推送 Token（16至32位字母数字）和43位 EncodingAESKey；留空可保留已配置密钥。');
        if($confirmed&&($appId===''||$siteUrl===''))throw new RuntimeException('请先配置小程序 AppID 和站点 HTTPS 地址，再确认消息推送。');
        foreach([self::TOKEN=>$token,self::AES=>$aes] as $key=>$plain)$this->saveSecret($key,$plain,$admin);
        $this->put(self::VERIFIED,$confirmed?$this->binding($appId,$siteUrl.'/chat/api/v1/av/callback',$token,$aes):'',$admin);
    }
    private function saveSecret(string $key,string $value,int $admin):void
    {
        $encrypted=$this->cipher->encrypt($value,$key.'-v1');
        if(!is_string($encrypted)||$encrypted===''||$encrypted===$value)throw new RuntimeException('微信消息推送密钥加密失败，未保存。');
        $this->put($key,$encrypted,$admin);
    }
    private function put(string $key,string $value,int $admin):void
    {
        $this->db->execute("INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,updated_by,created_at,updated_at) VALUES(:key,:value,'string','chat_private',0,'微信媒体审核消息推送加密配置',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_public=0,updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()",['key'=>$key,'value'=>$value,'admin'=>$admin]);
    }
}
