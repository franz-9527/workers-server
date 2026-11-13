<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Messaging;
use WorkerAdmin\Contracts\ChatInternalGateway;
use WorkerAdmin\Core\Database;
/** Delay configuration checks until an internal operation is actually requested. */
final class ConfiguredChatInternalGateway implements ChatInternalGateway
{
    public function __construct(private Database $db) {}
    public function eligible(string $operation):bool
    {return ChatInternalAuth::configured($this->db)->eligible($operation);}
    public function smsCandidates(array $query):array
    {return ChatInternalAuth::configured($this->db)->smsCandidates($query);}
    public function confirmCallStopped(string $call,string $epoch,string $proof):void
    {ChatInternalAuth::configured($this->db)->confirmCallStopped($call,$epoch,$proof);}
}
