<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;
use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\ChatCollaborationAuthority;

final class ChatCollaborationAdmin
{
 private Database $db;
 public function __construct(Database $db,private ChatCollaborationAuthority $authority){$this->db=$db;}
 public function members(string $publicNo):array {
  if(preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$publicNo)!==1)return [];
  return $this->db->fetchAll("SELECT u.public_id,m.role_source,m.display_role,CAST(m.membership_generation AS CHAR) member_version
   FROM orders o JOIN chat_collaboration_members m ON m.order_id=o.id AND m.active=1 JOIN users u ON u.id=m.actor_id
   WHERE o.public_no=:no AND o.is_demo=0 AND o.order_source_type='merchant' ORDER BY m.actor_id LIMIT 64",['no'=>$publicNo]);
 }
 public function change(array $input,int $admin,string $role):void {
  if(\app_config('chat.collaboration_enabled',false)!==true||\app_config('chat.order_policy_enabled',false)!==true)throw new RuntimeException('协作群功能暂未开放');
  $no=$input['group_order_no']??null;$public=$input['member_public_id']??null;$action=$input['_action']??null;
  if(!is_string($no)||preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$no)!==1||!is_string($public)||preg_match('/^[A-Za-z0-9]{1,32}$/D',$public)!==1||!in_array($action,['group_remove','group_intervene','group_end_intervention'],true))throw new RuntimeException('群成员操作参数无效');
  $this->db->transaction(function()use($input,$no,$public,$action,$admin,$role):void{
   $a=$this->db->fetch("SELECT role FROM admin_users WHERE id=:id AND status IN ('active','1') AND must_change_password=0 FOR UPDATE",['id'=>$admin]);
   if(!$a||$a['role']!==$role)throw new RuntimeException('后台权限已变化');
   $o=$this->db->fetch("SELECT CAST(id AS CHAR) id FROM orders WHERE public_no=:no AND is_demo=0 AND order_source_type='merchant' FOR UPDATE",['no'=>$no]);
   if(!$o)throw new RuntimeException('未找到协作群');
   $bridge=$this->authority;$bridge->synchronize($o['id']);
   if(!$this->db->fetchValue('SELECT order_id FROM chat_collaboration_groups WHERE order_id=:id LOCK IN SHARE MODE',['id'=>$o['id']]))throw new RuntimeException('该订单还没有协作群');
   $u=$this->db->fetch("SELECT CAST(id AS CHAR) id FROM users WHERE public_id=:public AND status='active' LOCK IN SHARE MODE",['public'=>$public]);
   if(!$u)throw new RuntimeException('用户不存在或已停用');
   $m=$this->db->fetch('SELECT * FROM chat_collaboration_members WHERE order_id=:order AND actor_id=:actor FOR UPDATE',['order'=>$o['id'],'actor'=>$u['id']]);
   if(in_array($action,['group_remove','group_end_intervention'],true)){
    if(!$m||!(int)$m['active']||$m['role_source']!==($action==='group_remove'?'invite':'platform_staff')||!is_string($input['member_version']??null)||$input['member_version']!==(string)$m['membership_generation'])throw new RuntimeException('只能移除当前普通协作者，请刷新成员版本');
    $this->db->execute('UPDATE chat_collaboration_members SET active=0,updated_at=UTC_TIMESTAMP(6) WHERE order_id=:order AND actor_id=:actor',['order'=>$o['id'],'actor'=>$u['id']]);
   }else{
    $authority=$this->authority->platformStaffLocked();$eligible=false;
    foreach($authority['platform_staff'] as $person)if($person['actor_id']===$u['id'])$eligible=true;
    if(!$eligible)throw new RuntimeException('该用户不是当前有效的平台工作人员');
    if($m&&(int)$m['active'])throw new RuntimeException('该用户已经是当前群成员');
    $this->db->execute("INSERT INTO chat_collaboration_members(order_id,actor_id,role_source,membership_generation,display_role,display_role_version,active,updated_at)
     VALUES(:order,:actor,'platform_staff',1,'platform',1,1,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE role_source='platform_staff',membership_generation=membership_generation+1,display_role='platform',display_role_version=display_role_version+1,active=1,updated_at=VALUES(updated_at)",['order'=>$o['id'],'actor'=>$u['id']]);
   }
   $this->db->execute('UPDATE chat_collaboration_groups SET source_version=source_version+1 WHERE order_id=:id',['id'=>$o['id']]);
   $this->authority->event($o['id'],(string)$admin,$u['id'],$action==='group_remove'?'admin.member_removed':($action==='group_end_intervention'?'admin.staff_left':'admin.staff_intervened'),null,null);
   $bridge->synchronize($o['id']);
  });
 }
}
