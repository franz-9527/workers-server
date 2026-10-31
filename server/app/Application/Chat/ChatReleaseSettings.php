<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use WorkerAdmin\Core\Database;

/** A database switch shared by PHP and Go; infrastructure recovery checks remain independent. */
final class ChatReleaseSettings
{
    public function __construct(private Database $db) {}
    public function enabled():bool
    {
        $value=$this->db->fetchValue("SELECT setting_value FROM system_settings WHERE setting_key='chat.enabled' LIMIT 1");
        return $value===null||$value===false||$value==='1';
    }
    public function save(array $input,int $admin,string $role):void
    {
        $value=$input['enabled']??null;
        if(!is_string($value)||!in_array($value,['0','1'],true))throw new RuntimeException('请选择聊天开放状态');
        $this->db->transaction(function()use($value,$admin,$role):void{
            $actor=$this->db->fetch("SELECT role FROM admin_users WHERE id=:id AND status IN ('active','1') AND must_change_password=0 FOR UPDATE",['id'=>$admin]);
            if(!$actor||$actor['role']!==$role||$role!=='super_admin')throw new RuntimeException('仅超级管理员可更改聊天开放状态');
            $this->db->execute("INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,updated_by,created_at,updated_at) VALUES('chat.enabled',:value,'bool','chat',0,'订单聊天总开关',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()",['value'=>$value,'admin'=>$admin]);
        });
    }
}
