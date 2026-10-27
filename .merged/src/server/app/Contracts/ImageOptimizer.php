<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface ImageOptimizer
{
    /** @return array{path:string,storage_key:string,mime_type:string,extension:string,size_bytes:int,width_px:int,height_px:int,sha256:string,compression_quality:?int,method:string} */
    public function optimize(string $sourcePath): array;
}
