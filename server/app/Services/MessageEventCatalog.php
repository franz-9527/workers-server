<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

require_once __DIR__.'/../Domain/Messaging/MessageEventCatalog.php';

final class MessageEventCatalog
{

    public static function all(): array { return \WorkerAdmin\Domain\Messaging\MessageEventCatalog::all(); }
    public static function get(string $eventType): ?array { return \WorkerAdmin\Domain\Messaging\MessageEventCatalog::get($eventType); }
    public static function switches(string $channel, array $configured): array { return \WorkerAdmin\Domain\Messaging\MessageEventCatalog::switches($channel,$configured); }
    public static function enabled(string $channel, string $eventType, array $configured): bool { return \WorkerAdmin\Domain\Messaging\MessageEventCatalog::enabled($channel,$eventType,$configured); }
}
