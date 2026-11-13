<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;
use RuntimeException;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;

/** Short-lived delegation into the real Go pipeline, confined to sandbox scopes. */
final class ChatSandboxCredentials
{
 public function __construct(private Database $db){}
 private function enabled():void{
  if(!TestingEnvironmentPolicy::active())throw new RuntimeException('完整会话测试仅供测试站使用',403);
  if(!(new ChatSandbox($this->db))->liveReady())throw new RuntimeException('请先升级后台数据库至2.7.3',503);
 }
 public function admin(Auth $auth,string $actor):array{
  $this->enabled();$auth->requirePermission('settings.manage');$owner=$auth->user();
  if(($owner['role']??'')!=='super_admin')throw new RuntimeException('仅超级管理员可操作会话测试',403);
  $parent=$this->db->fetch('SELECT id,session_version,UNIX_TIMESTAMP(expires_at) expiry FROM chat_admin_sessions WHERE id=:id AND admin_id=:admin AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()',['id'=>$_SESSION['chat_admin_session_id']??0,'admin'=>$owner['id']]);
  if(!$parent)throw new RuntimeException('请刷新管理员登录状态',401);
  return $this->create($actor,'admin',(int)$parent['id'],(string)$parent['session_version'],(int)$parent['expiry'],$this->moderator());
 }
 public function mini(string $token,array $identity):array{
  $this->enabled();
  if(empty($identity['sandbox']))throw new RuntimeException('测试身份无效',403);
  $parent=$this->db->fetch("SELECT id,user_id,token_hash,UNIX_TIMESTAMP(expires_at) expiry FROM login_sessions WHERE token_hash=:hash AND status='active' AND expires_at>UTC_TIMESTAMP()",['hash'=>hash('sha256',$token)]);
  if(!$parent)throw new RuntimeException('演示身份已失效',401);
  if(!empty($identity['mini_admin'])){
   return $this->create((string)$identity['key'],'mini_adm',(int)$parent['id'],(string)$identity['origin_version'],(int)$parent['expiry'],(int)$parent['user_id']);
  }
  return $this->create((string)$identity['key'],'mini',(int)$parent['id'],(string)$parent['token_hash'],(int)$parent['expiry'],$this->moderator((int)$parent['user_id']));
 }
 private function moderator(int $preferred=0):int{
  $settings=new GlobalSettingsProvider();$app=(string)$settings->get('wechat.miniapp_appid','');
  if($app==='')return 0;
  if($preferred&&$this->db->fetchValue("SELECT user_id FROM wechat_identities WHERE user_id=:id AND app_id=:app AND status='active' LIMIT 1",['id'=>$preferred,'app'=>$app]))return $preferred;
  // The configured real mini administrator owns the test operation; no fabricated OPENID.
  $openids=$settings->get('wechat.admin_openids',[]);
  if(is_string($openids)){$decoded=json_decode($openids,true);$openids=is_array($decoded)?$decoded:preg_split('/[\\s,;]+/',$openids,-1,PREG_SPLIT_NO_EMPTY);}
  foreach(is_array($openids)?$openids:[] as $openid){
   if(!is_string($openid))continue;
   $id=$this->db->fetchValue("SELECT wi.user_id FROM wechat_identities wi JOIN users u ON u.id=wi.user_id AND u.status='active' WHERE wi.app_id=:app AND wi.openid=:openid AND wi.status='active' LIMIT 1",['app'=>$app,'openid'=>$openid]);
   if($id)return (int)$id;
  }
  return 0;
 }
 private function create(string $actor,string $kind,int $origin,string $version,int $expiry,int $moderator):array{
  $person=(new ChatSandbox($this->db))->actors()[$actor]??null;
  if(!$person)throw new RuntimeException('选择的测试成员已停用',403);
  if(($person['group']==='小程序管理员')!==($kind==='mini_adm'))throw new RuntimeException('请在小程序以本人身份接入',403);
  $key=hash('sha256',"sandbox-v1\n".$kind."\n".$origin."\n".$version."\n".$actor);
  $until=min(time()+300,$expiry);
  if($until<=time())throw new RuntimeException('登录状态已失效',401);
  $this->db->execute("INSERT INTO chat_sandbox_sessions(session_key,actor_key,wire_id,origin_kind,origin_id,origin_version,moderation_user_id,session_version,expires_at,created_at) VALUES(:key,:actor,:wire,:kind,:origin,:version,:moderator,:session,:expiry,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE expires_at=VALUES(expires_at),moderation_user_id=VALUES(moderation_user_id)",['key'=>$key,'actor'=>$actor,'wire'=>$person['wire_id'],'kind'=>$kind,'origin'=>$origin,'version'=>$version,'moderator'=>$moderator,'session'=>bin2hex(random_bytes(32)),'expiry'=>gmdate('Y-m-d H:i:s',$until)]);
  $row=$this->db->fetch('SELECT * FROM chat_sandbox_sessions WHERE session_key=:key',['key'=>$key]);
  $source=(string)(9000000000000000000+(int)$row['id']);
  return ['identity'=>['sub'=>$person['wire_id'],'kind'=>'mini','source_id'=>$source,'session_id'=>hash('sha256',"sandbox\n".$source."\n".$key),'session_version'=>$row['session_version'],'scopes'=>['chat:use']],'expires'=>$until,'moderation_ready'=>$moderator>0];
 }
}
