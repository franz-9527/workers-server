<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderFormDesignerAdminRepository
{
    /** @return array<string,mixed>|null */
    public function entryTarget(): ?array;

    /** @return array<string,mixed> */
    public function designer(int $requestedVersionId): array;

    public function saveUi(int $schemaId, int $versionId, bool $collapseOptional, ?int $adminId): void;

    /** @param array<int,array<string,mixed>> $layout */
    public function reorder(int $schemaId, int $versionId, array $layout, ?int $adminId): void;

    /** @return array{schema_id:int,version_id:int} */
    public function createSchema(string $name, string $schemaKey, string $description, int $adminId): array;

    /** @return array{id:int,created:bool,version_no:int} */
    public function createVersion(int $schemaId, int $sourceVersionId, int $adminId): array;

    /** @param array<int,array<string,mixed>> $layout */
    public function saveStep(int $schemaId, int $versionId, int $stepId, string $stepKey, string $title, string $description, int $sortOrder, array $layout, ?int $adminId): void;

    public function toggleStep(int $schemaId, int $versionId, int $stepId, ?int $adminId): void;

    /** @param array<string,mixed> $field */
    public function saveField(int $schemaId, int $versionId, int $fieldId, array $field, ?int $adminId): int;

    public function toggleField(int $schemaId, int $versionId, int $fieldId, ?int $adminId): void;

    public function publish(int $versionId, int $adminId): int;
}
