<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface MessageTestJournal
{
    public function track(string $topic, array $metadata, callable $send): array;
}
