<?php

declare(strict_types=1);

function upgradeManifestAssert(bool $condition,string $label): void
{
    if(!$condition){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}

$root=dirname(__DIR__,2);
$manifestPath=$root.'/server/database/upgrades/manifest.json';
$manifest=json_decode((string)file_get_contents($manifestPath),true);
upgradeManifestAssert(is_array($manifest),'upgrade manifest parses as JSON');

$rows=(array)($manifest['upgrades']??[]);
upgradeManifestAssert($rows!==[],'upgrade manifest contains upgrades');

$seen=[];
foreach($rows as $row){
    upgradeManifestAssert(is_array($row),'upgrade row is an object');
    $version=(string)($row['version']??'');
    $file=(string)($row['file']??'');
    $expected=strtolower((string)($row['checksum']??''));
    upgradeManifestAssert($version!==''&&$file!==''&&preg_match('/^[a-f0-9]{64}$/',$expected)===1,'upgrade '.$version.' has file and SHA-256 checksum');
    upgradeManifestAssert(!isset($seen[$version]),'upgrade '.$version.' appears once');
    $seen[$version]=true;

    $path=$root.'/server/database/upgrades/'.$file;
    upgradeManifestAssert(is_file($path),'upgrade '.$version.' file exists: '.$file);
    $actual=hash_file('sha256',$path);
    upgradeManifestAssert(is_string($actual)&&hash_equals($expected,strtolower($actual)),'upgrade '.$version.' checksum matches SQL bytes');
}

upgradeManifestAssert(isset($seen['2.5.0']),'manifest checksum sweep includes 2.5.0');
upgradeManifestAssert(isset($seen['2.6.0']),'manifest checksum sweep includes 2.6.0');

fwrite(STDOUT,"Upgrade manifest checksum sweep passed.\n");
