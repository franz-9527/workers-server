<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Only a Go-created private job directory is accepted; no application bootstrap,
// FPM request, database write or arbitrary optimizer command is involved.
try {
    $job = realpath($argv[1] ?? '');
    if (!$job || !is_dir($job) || strpos(basename($job), 'bxchat-image-') !== 0 || is_link($argv[1])) throw new RuntimeException('job');
    foreach (['source','clean.input'] as $name) if (!is_file($job.'/'.$name) || is_link($job.'/'.$name)) throw new RuntimeException('input');
    $GLOBALS['chat_image_job'] = $job;
    function base_path(string $path = ''): string { return $GLOBALS['chat_image_job'].'/'.$path; }
    function setting(string $name, $default = null) {
        $values = ['media.max_long_edge_px'=>(int)getenv('CHAT_IMAGE_EDGE'),'media.max_file_bytes'=>(int)getenv('CHAT_IMAGE_BYTES')];
        return $values[$name] ?? $default;
    }
    require dirname(__DIR__).'/app/Services/ImageOptimizerService.php';
    $result = (new \WorkerAdmin\Services\ImageOptimizerService())->optimize($job.'/clean.input');
    if (!copy($result['path'], $job.'/main')) throw new RuntimeException('copy');
    $source = imagecreatefromstring((string)file_get_contents($job.'/main'));
    if (!$source) throw new RuntimeException('decode');
    $width = imagesx($source); $height = imagesy($source);
    $scale = min(1.0, 400/max($width,$height));
    $thumb = imagecreatetruecolor(max(1,(int)round($width*$scale)),max(1,(int)round($height*$scale)));
    $white = imagecolorallocate($thumb,255,255,255); imagefill($thumb,0,0,$white);
    imagecopyresampled($thumb,$source,0,0,0,0,imagesx($thumb),imagesy($thumb),$width,$height);
    if (!imagejpeg($thumb,$job.'/thumb',90)) throw new RuntimeException('thumb');
    imagedestroy($thumb);imagedestroy($source);
    fwrite(STDOUT,json_encode(['method'=>$result['method']],JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $error) {
    fwrite(STDOUT,'{"code":"CHAT_IMAGE_CHECK_UNAVAILABLE"}'."\n");exit(1);
}
