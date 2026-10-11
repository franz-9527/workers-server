<?php

declare(strict_types=1);

$repo = file_get_contents(__DIR__ . '/../app/Infrastructure/Persistence/DatabaseOrderDiscoveryRepository.php');
$migration = file_get_contents(__DIR__ . '/../database/upgrades/2.5.0.sql');
if (!is_string($repo) || !is_string($migration)) throw new RuntimeException('review hall migration fixtures missing');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(strpos($repo, "o.is_demo=1 AND BINARY o.public_no LIKE BINARY 'BXDEMOHALL%'") !== false,
    'ordinary hall must expose only fixed review-demo prefix in addition to production rows');
$assert(strpos($repo, "?'o.is_demo=1':") !== false,
    'demo actor must continue to use the full demo data domain');

$assert(strpos($migration, '微信审核/外部体验使用的六类接单大厅演示数据直接随 2.5.0 迁移写入') !== false,
    'hall sample data must be integrated in 2.5.0.sql');
$assert(strpos($migration, 'server/database/demo') === false,
    'merged migration must not depend on a database/demo path');

$cases = [
    'BXDEMOHALL01' => ["'consumer'", "'construction'", "'multi_quote'"],
    'BXDEMOHALL02' => ["'consumer'", "'construction'", "'single_grab'"],
    'BXDEMOHALL03' => ["'consumer'", "'measurement'", "'multi_quote'"],
    'BXDEMOHALL04' => ["'merchant'", "'construction'", "'multi_quote'"],
    'BXDEMOHALL05' => ["'platform'", "'construction'", "'multi_quote'"],
    'BXDEMOHALL06' => ["'platform'", "'measurement'", "'multi_quote'"],
];
foreach ($cases as $no => $tokens) {
    $assert(strpos($migration, $no) !== false, $no . ' missing');
    foreach ($tokens as $token) $assert(strpos($migration, $token) !== false, $no . ' type token missing: ' . $token);
}

fwrite(STDOUT, "review hall merged migration regression passed\n");
