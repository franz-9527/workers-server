<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface AgreementDocumentRepository
{
    /** Load raw stored agreement document snapshots (without runtime activation). */
    public function loadDocuments(): array;

    /** Persist edited draft / future revision with its audit author. */
    public function saveDocument(array $document, int $adminId): void;
}
