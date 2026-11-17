<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface AdminAlertGateway
{
    public function send(string $content, ?string $actionPath = null): array;
}
