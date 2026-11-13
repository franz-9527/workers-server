<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface FormSystemFieldRepository
{
    public function findFieldByKey(int $versionId, string $fieldKey): ?array;
    public function saveOtherTextField(?int $fieldId, array $values): int;
    public function replaceShowRule(int $versionId, int $targetFieldId, int $triggerFieldId, string $name, string $compareValue): void;
    public function disableFieldAndRules(int $fieldId): void;
    public function fieldsForVersion(int $versionId): array;
    public function activeShowRuleCount(int $versionId, int $fieldId): int;
    public function alignField(int $fieldId, int $stepId, int $sortOrder, string $status): void;
}
