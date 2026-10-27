<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Media;

final class UploadedImageInput
{
    public string $temporaryPath;
    public int $sizeBytes;
    public int $uploadError;
    public string $originalName;

    public function __construct(string $temporaryPath, int $sizeBytes, int $uploadError, string $originalName = '')
    {
        $this->temporaryPath = $temporaryPath;
        $this->sizeBytes = $sizeBytes;
        $this->uploadError = $uploadError;
        $this->originalName = $originalName;
    }
}
