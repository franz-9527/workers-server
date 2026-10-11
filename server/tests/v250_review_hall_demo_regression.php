<?php

declare(strict_types=1);

$repo = file_get_contents(__DIR__ . '/../app/Infrastructure/Persistence/DatabaseOrderDiscoveryRepository.php');
$fixture = file_get_contents(__DIR__ . '/fixtures/hall-review-v250.sql');
if (!is_string($repo) || !is_string($fixture)) throw new RuntimeException('review hall fixtures missing');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(strpos($repo, "o.is_demo=1 AND BINARY o.public_no LIKE BINARY 'BXDEMOHALL%'") !== false,
    'ordinary hall must expose only fixed review-demo prefix in addition to production rows');
$assert(strpos($repo, "?'o.is_demo=1':") !== false,
    'demo actor must continue to use the full demo data domain');

$cases = [
    'BXDEMOHALL01' => ["'consumer'", "'construction'", "'multi_quote'"],
    'BXDEMOHALL02' => ["'consumer'", "'construction'", "'single_grab'"],
    'BXDEMOHALL03' => ["'consumer'", "'measurement'", "'multi_quote'"],
    'BXDEMOHALL04' => ["'merchant'", "'construction'", "'multi_quote'"],
    'BXDEMOHALL05' => ["'platform'", "'construction'", "'multi_quote'"],
    'BXDEMOHALL06' => ["'platform'", "'measurement'", "'multi_quote'"],
];
foreach ($cases as $no => $tokens) {
    $assert(strpos($fixture, $no) !== false, $no . ' missing');
    foreach ($tokens as $token) $assert(strpos($fixture, $token) !== false, $no . ' type token missing: ' . $token);
}

fwrite(STDOUT, "review hall demo regression passed\n");
