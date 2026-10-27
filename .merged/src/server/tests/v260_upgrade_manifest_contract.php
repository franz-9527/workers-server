<?php

declare(strict_types=1);

function manifestAssert(bool $condition,string $label): void
{
    if(!$condition){fwrite(STDERR,'[FAIL] '.$label."\n");exit(1);}
    fwrite(STDOUT,'[PASS] '.$label."\n");
}

$root=dirname(__DIR__,2);
$manifestPath=$root.'/server/database/upgrades/manifest.json';
$sqlPath=$root.'/server/database/upgrades/2.6.0.sql';

$manifest=json_decode((string)file_get_contents($manifestPath),true);
manifestAssert(is_array($manifest),'upgrade manifest parses as JSON');

$row=null;
foreach((array)($manifest['upgrades']??[]) as $candidate){
    if(is_array($candidate)&&($candidate['version']??'')==='2.6.0'){$row=$candidate;break;}
}
manifestAssert(is_array($row),'manifest contains 2.6.0');
$actual=hash_file('sha256',$sqlPath);
manifestAssert(is_string($actual)&&hash_equals(strtolower((string)$row['checksum']),strtolower($actual)),'2.6.0 manifest checksum matches SQL bytes');
manifestAssert(($row['file']??'')==='2.6.0.sql','2.6.0 manifest points at expected SQL file');
manifestAssert(!empty($row['idempotent']),'2.6.0 migration remains declared idempotent');
manifestAssert(empty($row['retry_failed']),'2.6.0 failed DDL migration is not auto-retried');

$sql=(string)file_get_contents($sqlPath);
foreach([
    'test_message_outbox',
    'test_entry_tokens',
    'test_actor_user_id',
    'test_instance_id',
    'test_actor_expires_at',
] as $needle){
    manifestAssert(strpos($sql,$needle)!==false,'2.6.0 SQL contains '.$needle);
}
manifestAssert(strpos($sql,'information_schema.columns')!==false,'login-session additions use guarded MySQL 5.7 migration');
manifestAssert(strpos((string)($row['description']??''),'一次性短期小程序测试入口')!==false,'manifest description includes short-entry schema');

fwrite(STDOUT,"V2.6.0 upgrade manifest contract passed.\n");
