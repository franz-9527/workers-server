<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Persistence;
use RuntimeException;
use WorkerAdmin\Contracts\ChatAuditorGrantRepository;
use WorkerAdmin\Core\Database;
final class DatabaseChatAuditorGrantRepository implements ChatAuditorGrantRepository
{
    public function __construct(private Database $db) {}
    public function recent():array
    {return $this->db->fetchAll('SELECT user_id,enabled,granted_by,reason,updated_at FROM chat_mini_auditors ORDER BY updated_at DESC,user_id DESC LIMIT 50');}
    public function save(string $userId,bool $enabled,string $reason,string $adminId,string $version):void
    {
        $this->db->transaction(function()use($userId,$enabled,$reason,$adminId,$version):void{
            if(!$this->db->fetchValue("SELECT id FROM users WHERE id=:id AND status='active' LOCK IN SHARE MODE",['id'=>$userId]))throw new RuntimeException('请填写真实用户编号和授权理由');
            $this->db->execute('INSERT INTO chat_mini_auditors(user_id,enabled,authority_version,granted_by,reason,updated_at) VALUES(:id,:enabled,:version,:admin,:reason,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),authority_version=VALUES(authority_version),granted_by=VALUES(granted_by),reason=VALUES(reason),updated_at=VALUES(updated_at)',['id'=>$userId,'enabled'=>(int)$enabled,'version'=>$version,'admin'=>$adminId,'reason'=>$reason]);
            $this->db->execute('INSERT INTO chat_auditor_grant_audit(user_id,enabled,authority_version,admin_id,reason,created_at) VALUES(:id,:enabled,:version,:admin,:reason,UTC_TIMESTAMP(6))',['id'=>$userId,'enabled'=>(int)$enabled,'version'=>$version,'admin'=>$adminId,'reason'=>$reason]);
        });
    }
}
