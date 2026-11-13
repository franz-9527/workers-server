<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface RealNameSubmissionNotifier
{
    public function notifySubmitted(int $verificationId, int $workerId, string $realName): void;
}
