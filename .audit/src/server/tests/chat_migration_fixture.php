<?php

declare(strict_types=1);

// Minimal policy/identity fixtures use named sections of the actual release
// package. The full 2.6.0 -> 2.7.0 path is separately tested against real schema.
function chatFixtureMigration(PDO $pdo, string $section): void
{
    if (getenv('CHAT_AUTH_TEST_INTEGRATION') !== '1'
        || !in_array($section,['identity','policy','repair','staff'],true)) {
        throw new RuntimeException('Explicit isolated chat fixture required');
    }
    $sql=(string)file_get_contents(dirname(__DIR__).'/database/upgrades/2.7.0.sql');
    $start='-- CHAT_SECTION '.$section."\n";$end='-- CHAT_END '.$section;
    $parts=explode($start,$sql);
    if(count($parts)!==2 || count(explode($end,$parts[1]))!==2)throw new RuntimeException('Chat migration section missing');
    (new \WorkerAdmin\Support\SqlScriptRunner())->run($pdo,explode($end,$parts[1])[0],'2.7.0.sql:'.$section);
}
