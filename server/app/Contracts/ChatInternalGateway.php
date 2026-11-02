<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
/** Authenticated PHP-to-Go operations; construction must not contact Go. */
interface ChatInternalGateway
{
    public function eligible(string $operation):bool;
    public function smsCandidates(array $query):array;
    public function confirmCallStopped(string $call,string $epoch,string $proof):void;
}
