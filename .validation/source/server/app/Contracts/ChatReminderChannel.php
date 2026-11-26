<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
/** Template/quota adapter; locking and mutations use the caller's transaction. */
interface ChatReminderChannel
{
    public function build(array $query):array;
    public function lockRecipient(int $userId):void;
    public function consume(int $userId,string $scene):void;
    public function reject(int $userId,string $scene,string $templateId,int $errorCode):void;
}
