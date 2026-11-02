<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$current=json_decode(file_get_contents($root.'/server/database/upgrades/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$previous=json_decode(file_get_contents($root.'/tools/previous-manifest.json'),true,512,JSON_THROW_ON_ERROR);
$old=$previous['upgrades'];$rows=$current['upgrades'];$last=end($rows);
if(array_slice($rows,0,count($old))!==$old||count($rows)!==count($old)+1)throw new RuntimeException('Published migration entries changed');
foreach(['install_file','install_checksum'] as $key)if($current[$key]!==$previous[$key])throw new RuntimeException('Install baseline changed');
if($last['version']!=='2.7.2'||trim(file_get_contents($root.'/server/VERSION'))!=='2.7.2'||$last['checksum']!==hash_file('sha256',$root.'/server/database/upgrades/2.7.2.sql'))throw new RuntimeException('New migration checksum/version mismatch');
echo "PASS new migration checksum/version and all historical migration metadata preserved\n";
