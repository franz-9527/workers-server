<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface MessageRecordFilters
{
    public function setRecordFilters(string $channel,string $recipient): void;
}
