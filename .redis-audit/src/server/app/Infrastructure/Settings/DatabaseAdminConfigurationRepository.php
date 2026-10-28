<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Settings;

use Throwable;
use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\AdminConfigurationRepository;

final class DatabaseAdminConfigurationRepository implements AdminConfigurationRepository
{
    private Database $db;
    public function __construct(Database $db) { $this->db=$db; }
    public function pageRows(array $groups,bool $messages=false):array
    {
        if(in_array('development',$groups,true))$this->ensureTestingDefaults();
        $marks=implode(',',array_fill(0,count($groups),'?'));
        $sql='SELECT * FROM system_settings WHERE group_name IN ('.$marks.')';
        $sql.=$messages?" AND setting_key<>'sms.admin_mobile' ORDER BY FIELD(group_name,'notification','sms','wecom'),setting_key":' ORDER BY group_name,setting_key';
        return $this->db->fetchAll($sql,$groups);
    }
    public function ensureInternalDefaults():void
    {
        $this->db->execute("INSERT IGNORE INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,updated_by,created_at,updated_at) VALUES('notification.internal_event_switches','{}','json','notification',0,'小程序消息中心内部通知开关；缺失事件默认开启',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    }
    private function ensureTestingDefaults():void
    {
        $rows=[
            ['testing.qiniu_path_prefix','test','string','测试环境七牛新上传对象根目录；正式 qiniu.path_prefix 不受影响'],
            ['testing.sms_enabled','0','bool','测试环境真实短信总开关；开启后仍只允许白名单手机号'],
            ['testing.allowed_sms_mobiles','','string','测试环境允许真实发送短信的手机号白名单，逗号、分号或换行分隔'],
            ['testing.subscribe_enabled','0','bool','测试环境微信订阅消息总开关；开启后仍只允许白名单 OpenID'],
            ['testing.allowed_wechat_openids','','string','测试环境允许真实发送订阅消息的 OpenID 白名单，逗号、分号或换行分隔'],
            ['testing.allow_wecom','0','bool','允许测试环境向管理员企业微信群机器人发送消息；消息自动标注测试环境'],
            ['testing.allow_wechat_account_writes','0','bool','允许测试环境修改当前小程序账号的订阅模板等账号级配置'],
            ['testing.allow_cloud_writes','0','bool','允许测试环境写入当前微信云开发师傅数据；正式云环境下应保持关闭'],
        ];
        foreach($rows as $row)$this->db->execute(
            'INSERT IGNORE INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,updated_by,created_at,updated_at) VALUES(:key,:value,:type,\'development\',0,:description,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
            ['key'=>$row[0],'value'=>$row[1],'type'=>$row[2],'description'=>$row[3]]
        );
    }
    public function categories():array { return $this->db->fetchAll("SELECT id,name FROM service_categories WHERE parent_id IS NULL AND status='active' ORDER BY sort_order,id"); }
    public function fieldDictionary():array { try{return $this->db->fetchAll('SELECT table_name,field_name,chinese_name,description FROM database_field_dictionary ORDER BY table_name,id');}catch(Throwable $e){return [];} }
    public function settingsForGroup(string $group):array
    { return $this->db->fetchAll('SELECT setting_key,value_type,setting_value FROM system_settings WHERE group_name=:group_name',['group_name'=>$group]); }
    public function saveMany(array $updates,int $adminId):void
    {
        $this->db->transaction(function(Database $db)use($updates,$adminId):void{
            foreach($updates as $key=>$value)$db->execute('UPDATE system_settings SET setting_value=:value,updated_by=:admin,updated_at=NOW() WHERE setting_key=:key',['value'=>$value,'admin'=>$adminId,'key'=>$key]);
        });
    }
    public function set(string $key,string $value,int $adminId):void
    { $this->db->execute('UPDATE system_settings SET setting_value=:value,updated_by=:admin,updated_at=NOW() WHERE setting_key=:key',['value'=>$value,'admin'=>$adminId,'key'=>$key]); }
    public function userForOpenid(string $openid):int
    { $row=$this->db->fetch("SELECT user_id FROM wechat_identities WHERE BINARY openid=BINARY :openid AND status='active' ORDER BY id DESC LIMIT 1",['openid'=>$openid]);return (int)($row['user_id']??0); }
    public function runtimeConfigRows():array
    { return $this->db->fetchAll("SELECT setting_key,setting_value,value_type FROM system_settings WHERE setting_key IN (?,?,?,?,?)",['wechat.admin_openids','worker.phone_reveal_enabled','worker.phone_reveal_admin_enabled','worker.phone_reveal_member_enabled','worker.phone_reveal_member_24h_limit']); }
    public function assertSendBoundary():void { if($this->db->pdo()->inTransaction())throw new RuntimeException('外部消息发送不能在数据库事务中执行。'); }

}
