<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Chat\ConversationScope;

final class ChatRelationshipBindings
{
    public function __construct(private Database $db) {}

    public function reserveDirect(string $first,string $second):string
    {
        [$low,$high]=ConversationScope::pair($first,$second);
        $this->lockActiveAccounts([$low,$high]);
        $this->db->execute('INSERT INTO chat_direct_relationships(actor_a,actor_b,created_at) VALUES(:low,:high,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE created_at=created_at',['low'=>$low,'high'=>$high]);
        return ConversationScope::direct($low,$high);
    }

    public function reserveFeedback(string $owner,bool $sandbox=false):string
    {
        $service=$sandbox?ConversationScope::FEEDBACK_TEST_SERVICE:ConversationScope::FEEDBACK_SERVICE;
        $scope=ConversationScope::support($owner,$sandbox);
        $this->lockActiveAccounts([$owner]);
        $this->db->execute('INSERT INTO chat_support_relationships(service_key,owner_id,created_at) VALUES(:service,:owner,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE created_at=created_at',['service'=>$service,'owner'=>$owner]);
        return $scope;
    }

    private function lockActiveAccounts(array $ids):void
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('会话关系须与权威变更在同一事务内保留');
        foreach($ids as $id){
            if(!$this->db->fetchValue("SELECT id FROM users WHERE id=:id AND status='active' LOCK IN SHARE MODE",['id'=>$id])){
                throw new \DomainException('会话成员不可用');
            }
        }
    }
}
