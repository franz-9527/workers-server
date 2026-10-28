<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface MediaRepository
{
    public function newPublicId(): string;

    /** @return array<string,mixed> */
    public function prepareOrderUpload(array $actor, string $orderPublicNo, string $purpose): array;

    public function storeOrderMedia(array $context, array $optimized, array $metadata, string $publicId): void;

    /** @return string deleted purpose marker */
    public function deleteOrderMedia(array $actor, string $orderPublicNo, string $mediaPublicId): string;

    /** @return array<string,mixed> */
    public function prepareMeasurementUpload(array $actor, string $orderPublicNo, string $spacePublicId, string $purpose): array;

    public function storeMeasurementMedia(array $context, array $optimized, string $publicId): void;

    public function deleteMeasurementMedia(array $actor, string $orderPublicNo, string $spacePublicId, string $mediaPublicId): void;

    /** @return array<string,mixed> */
    public function prepareReviewUpload(array $actor, string $reviewPublicId): array;

    public function storeReviewMedia(array $context, array $optimized, string $publicId): void;

    /** @return array<string,mixed> */
    public function prepareRealNameUpload(array $actor, string $purpose, bool $watermarkApplied): array;

    public function storeRealNameMedia(array $context, array $optimized, string $publicId): void;

    /** @return array<string,mixed> */
    public function prepareCollectionCodeUpload(array $actor): array;

    public function storeCollectionCodeMedia(array $context, array $optimized, string $publicId, array $actor): void;

    /** @return array<string,mixed> */
    public function prepareMerchantLicenseUpload(array $actor): array;

    public function storeMerchantLicenseMedia(array $context, array $optimized, string $publicId): void;

    public function markStoredOnQiniu(string $publicId, string $storageKey): bool;

    /** @return array<string,mixed>|null */
    public function findPrivateMedia(string $publicId): ?array;

    public function canAccessPrivateMedia(array $media, array $token): bool;

    /** @return array<string,mixed>|null */
    public function findPublicWorkerMedia(string $publicId): ?array;
}
