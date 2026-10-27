<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use RuntimeException;

/**
 * 服务器 API 图片统一处理器。
 *
 * 策略：先保留原格式并尝试无损优化；只有超过长边或最终容量限制时才缩放。
 * 缩放时不降低编码质量，避免原来“所有图片转 JPEG、质量逐级降到 72”的清晰度损失。
 * 旧云开发图片导入有独立历史兼容链路，故不由本服务接管。
 */
final class ImageOptimizerService
{
    /**
     * @return array{path:string,storage_key:string,mime_type:string,extension:string,size_bytes:int,width_px:int,height_px:int,sha256:string,compression_quality:?int,method:string}
     */
    public function optimize(string $sourcePath): array
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException('待处理的图片不存在或不可读。');
        }
        $info = @getimagesize($sourcePath);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            throw new RuntimeException('仅支持有效的 JPG、PNG、WEBP 图片。');
        }
        $mime = strtolower((string) ($info['mime'] ?? ''));
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            throw new RuntimeException('仅支持 JPG、PNG、WEBP 图片。');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $maxEdge = max(800, min(2400, (int) \setting('media.max_long_edge_px', 1600)));
        $maxBytes = max(10240, min(20 * 1024 * 1024, (int) \setting('media.max_file_bytes', 1048576)));
        $folder = 'uploads/' . gmdate('Y/m');
        $directory = \base_path('storage/private/' . $folder);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('服务器图片目录不可写。');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        $method = 'original';
        $quality = null;
        try {
            if (!@copy($sourcePath, $path)) {
                throw new RuntimeException('图片暂存失败。');
            }
            $losslessTool = $this->runLosslessOptimizer($path, $mime);
            if ($losslessTool !== '') {
                $method = 'lossless-' . $losslessTool;
            }

            $currentSize = is_file($path) ? (int) filesize($path) : 0;
            if (max($width, $height) > $maxEdge || $currentSize > $maxBytes) {
                if (!function_exists('imagecreatetruecolor')) {
                    throw new RuntimeException('图片需要缩放，但服务器未安装 GD 图片扩展。');
                }
                [$width, $height] = $this->resizeUntilWithinLimit($path, $mime, $width, $height, $maxEdge, $maxBytes);
                $method = 'resize-quality-100' . ($losslessTool !== '' ? '-after-lossless' : '');
                $quality = 100;
            }

            $size = is_file($path) ? (int) filesize($path) : 0;
            if ($size < 1 || $size > $maxBytes) {
                throw new RuntimeException('图片无损优化或必要缩放后仍超过服务器容量限制，请裁剪后重新上传。');
            }
            $sha = hash_file('sha256', $path);
            if (!is_string($sha) || $sha === '') {
                throw new RuntimeException('图片摘要生成失败。');
            }
            return [
                'path' => $path,
                'storage_key' => $folder . '/' . $name,
                'mime_type' => $mime,
                'extension' => $extensions[$mime],
                'size_bytes' => $size,
                'width_px' => $width,
                'height_px' => $height,
                'sha256' => $sha,
                'compression_quality' => $quality,
                'method' => $method,
            ];
        } catch (\Throwable $exception) {
            if (is_file($path)) @unlink($path);
            throw $exception;
        }
    }

    /**
     * 使用固定命令调用服务器上已安装的无损工具。命令名不来自用户输入。
     * 返回空字符串表示工具未安装，调用方继续保留原始文件格式和字节内容。
     */
    private function runLosslessOptimizer(string $path, string $mime): string
    {
        // 服务器可能出于安全策略禁用 proc_open；无损工具属于可选优化，禁用时安全回退到原格式。
        if (!function_exists('proc_open')) return '';
        $commands = [];
        if ($mime === 'image/jpeg') {
            $commands = [
                ['name' => 'jpegoptim', 'command' => 'jpegoptim --strip-all --all-progressive --quiet ' . escapeshellarg($path)],
            ];
        } elseif ($mime === 'image/png') {
            $commands = [
                ['name' => 'oxipng', 'command' => 'oxipng -o 4 --strip safe --quiet ' . escapeshellarg($path)],
                ['name' => 'optipng', 'command' => 'optipng -o7 -strip all -quiet ' . escapeshellarg($path)],
            ];
        } elseif ($mime === 'image/webp') {
            // cwebp 必须输出到临时文件，成功后再原子替换，避免输入输出同路径导致原图损坏。
            $temporary = $path . '.lossless.webp';
            $commands = [
                ['name' => 'cwebp-lossless', 'command' => 'cwebp -lossless -quiet ' . escapeshellarg($path) . ' -o ' . escapeshellarg($temporary), 'temporary_path' => $temporary],
            ];
        }
        foreach ($commands as $item) {
            $temporary = (string) ($item['temporary_path'] ?? '');
            $pipes = [];
            $process = @proc_open($item['command'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                if ($temporary !== '' && is_file($temporary)) @unlink($temporary);
                continue;
            }
            if (isset($pipes[1]) && is_resource($pipes[1])) @fclose($pipes[1]);
            if (isset($pipes[2]) && is_resource($pipes[2])) @fclose($pipes[2]);
            $status = proc_close($process);
            if ($status === 0 && $temporary !== '' && is_file($temporary) && (int) filesize($temporary) > 0) {
                // WebP 转无损后有可能比原文件更大；只在确实缩小或等大时替换，始终保留更优结果。
                $originalSize = is_file($path) ? (int) filesize($path) : 0;
                $optimizedSize = (int) filesize($temporary);
                if ($originalSize > 0 && $optimizedSize <= $originalSize && @rename($temporary, $path) && is_file($path) && (int) filesize($path) > 0) return (string) $item['name'];
            }
            if ($temporary !== '' && is_file($temporary)) @unlink($temporary);
            if ($status === 0 && $temporary === '' && is_file($path) && (int) filesize($path) > 0) return (string) $item['name'];
        }
        return '';
    }

    /** @return array{0:int,1:int} */
    private function resizeUntilWithinLimit(string $path, string $mime, int $sourceWidth, int $sourceHeight, int $maxEdge, int $maxBytes): array
    {
        $scale = min(1.0, $maxEdge / max($sourceWidth, $sourceHeight));
        $minimumEdge = 640;
        $lastWidth = max(1, (int) round($sourceWidth * $scale));
        $lastHeight = max(1, (int) round($sourceHeight * $scale));
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $lastWidth = max(1, (int) round($sourceWidth * $scale));
            $lastHeight = max(1, (int) round($sourceHeight * $scale));
            $this->resizeOne($path, $mime, $lastWidth, $lastHeight);
            $this->runLosslessOptimizer($path, $mime);
            if (is_file($path) && (int) filesize($path) <= $maxBytes) return [$lastWidth, $lastHeight];
            if (max($lastWidth, $lastHeight) <= $minimumEdge) break;
            $scale *= 0.82;
        }
        throw new RuntimeException('图片必要缩放后仍超过服务器容量限制，请裁剪后重新上传。');
    }

    private function resizeOne(string $path, string $mime, int $width, int $height): void
    {
        $source = @imagecreatefromstring((string) @file_get_contents($path));
        if (!$source) throw new RuntimeException('图片解码失败，无法完成必要缩放。');
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas) {
            imagedestroy($source);
            throw new RuntimeException('图片缩放画布创建失败。');
        }
        if ($mime === 'image/jpeg') {
            $background = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $background);
        } else {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent);
        }
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
        if ($mime === 'image/jpeg') {
            $saved = imagejpeg($canvas, $path, 100);
        } elseif ($mime === 'image/png') {
            $saved = imagepng($canvas, $path, 9);
        } else {
            $saved = function_exists('imagewebp') && imagewebp($canvas, $path, 100);
        }
        imagedestroy($source);
        imagedestroy($canvas);
        if (!$saved || !is_file($path)) throw new RuntimeException('图片缩放保存失败。');
    }
}
