<?php

declare(strict_types=1);

use WorkerAdmin\Services\UpgradeService;

$root=dirname(__DIR__,2);
spl_autoload_register(static function(string $class)use($root):void{
    if(strpos($class,'WorkerAdmin\\')!==0)return;
    $path=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
    if(is_file($path))require_once $path;
});

function legacyChecksumAssert(bool $condition,string $label):void
{
    if(!$condition){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}

$manifest=json_decode((string)file_get_contents($root.'/server/database/upgrades/manifest.json'),true);
legacyChecksumAssert(is_array($manifest),'manifest parses');
$row=null;
foreach((array)($manifest['upgrades']??[]) as $candidate){
    if((string)($candidate['version']??'')==='2.5.0'){$row=$candidate;break;}
}
legacyChecksumAssert(is_array($row),'manifest contains 2.5.0');

$canonical='a8fb5669fafcfbf5ea24afd338c534229b9921e10b5310af611ead60cb871bb3';
$legacy='391814af1a34f7d451664b6640843009175432ab51c3ab1788add73e10b492cd';
legacyChecksumAssert((string)$row['checksum']===$canonical,'2.5.0 canonical checksum remains unchanged');
legacyChecksumAssert(in_array($legacy,(array)($row['accepted_applied_checksums']??[]),true),'verified legacy checksum is explicitly whitelisted');
legacyChecksumAssert(hash_file('sha256',$root.'/server/database/upgrades/2.5.0.sql')===$canonical,'current 2.5.0 SQL bytes still match canonical checksum');

$reflection=new ReflectionClass(UpgradeService::class);
$service=$reflection->newInstanceWithoutConstructor();

$normalize=$reflection->getMethod('normalizeAppliedChecksums');
$normalize->setAccessible(true);
$normalized=$normalize->invoke($service,[$legacy,$canonical,$legacy],'2.5.0.sql',$canonical);
legacyChecksumAssert($normalized===[$legacy],'historical checksum normalization removes canonical and duplicates');

$accept=$reflection->getMethod('acceptedAppliedChecksum');
$accept->setAccessible(true);
$migration=['checksum'=>$canonical,'accepted_applied_checksums'=>$normalized];
legacyChecksumAssert($accept->invoke($service,$legacy,$migration)===true,'verified legacy checksum is accepted');
legacyChecksumAssert($accept->invoke($service,str_repeat('f',64),$migration)===false,'unknown historical checksum remains rejected');
legacyChecksumAssert($accept->invoke($service,'not-a-sha',$migration)===false,'invalid historical checksum remains rejected');

$invalidBlocked=false;
try{$normalize->invoke($service,['bad'],'2.5.0.sql',$canonical);}
catch(Throwable $e){$invalidBlocked=true;}
legacyChecksumAssert($invalidBlocked,'invalid checksum alias metadata fails closed');

$source=(string)file_get_contents($root.'/server/app/Services/UpgradeService.php');
legacyChecksumAssert(
    strpos($source,"\$recordStatus === 'success' && \$this->acceptedAppliedChecksum")!==false,
    'checksum aliases are only considered for successful applied migrations'
);
legacyChecksumAssert(
    strpos($source,"!\$canRetryFailed && !\$canRecoverV229 && !\$checksumAccepted")!==false,
    'unknown checksum still feeds the existing integrity error path'
);

fwrite(STDOUT,"Historical migration checksum compatibility contract passed.\n");
