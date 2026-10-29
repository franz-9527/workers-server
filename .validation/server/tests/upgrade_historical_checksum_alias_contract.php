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
$preHall='042fc8d8ea74f95ee5ff410b9ec7e16c3928448a39df72d0a719f31c9a7ab894';
legacyChecksumAssert((string)$row['checksum']===$canonical,'2.5.0 canonical checksum remains unchanged');
legacyChecksumAssert(in_array($legacy,(array)($row['accepted_applied_checksums']??[]),true),'verified legacy checksum is explicitly whitelisted');
legacyChecksumAssert(in_array($preHall,(array)($row['accepted_applied_checksums']??[]),true),'schema-complete pre-hall historical checksum is explicitly whitelisted');
legacyChecksumAssert(hash_file('sha256',$root.'/server/database/upgrades/2.5.0.sql')===$canonical,'current 2.5.0 SQL bytes still match canonical checksum');

$reflection=new ReflectionClass(UpgradeService::class);
$service=$reflection->newInstanceWithoutConstructor();

$manifestReflection=new ReflectionClass(\WorkerAdmin\Infrastructure\Upgrade\MigrationManifest::class);
$manifestReader=$manifestReflection->newInstanceWithoutConstructor();
$normalize=$manifestReflection->getMethod('normalizeAppliedChecksums');
$normalize->setAccessible(true);
$normalized=$normalize->invoke($manifestReader,[$legacy,$canonical,$legacy],'2.5.0.sql',$canonical);
legacyChecksumAssert($normalized===[$legacy],'historical checksum normalization removes canonical and duplicates');

$accept=$reflection->getMethod('acceptedAppliedChecksum');
$accept->setAccessible(true);
$migration=['checksum'=>$canonical,'accepted_applied_checksums'=>$normalized];
legacyChecksumAssert($accept->invoke($service,$legacy,$migration)===true,'verified legacy checksum is accepted');
legacyChecksumAssert($accept->invoke($service,str_repeat('f',64),$migration)===false,'unknown historical checksum remains rejected');
legacyChecksumAssert($accept->invoke($service,'not-a-sha',$migration)===false,'invalid historical checksum remains rejected');

$invalidBlocked=false;
try{$normalize->invoke($manifestReader,['bad'],'2.5.0.sql',$canonical);}
catch(Throwable $e){$invalidBlocked=true;}
legacyChecksumAssert($invalidBlocked,'invalid checksum alias metadata fails closed');

$source=(string)file_get_contents($root.'/server/app/Services/UpgradeService.php');
legacyChecksumAssert(
    strpos($source,"\$recordStatus === 'success' && \$this->acceptedAppliedChecksum")!==false,
    'checksum aliases are only considered for successful applied migrations'
);
// Unknown checksums and failed/running history are exercised through public status below.

// Exercise the public status path with the real manifest and a read-only ledger double.
final class HistoricalChecksumStatement extends PDOStatement
{
    private $rows;
    public function __construct(array $rows=[]){$this->rows=$rows;}
    public function execute(?array $params=null): bool{return true;}
    public function fetchColumn(int $column=0): mixed{return 1;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array{return $this->rows;}
    public function closeCursor(): bool{return true;}
}
final class HistoricalChecksumPdo extends PDO
{
    private $rows;
    public function __construct(array $rows){$this->rows=$rows;}
    public function prepare(string $query,array $options=[]): PDOStatement|false{
        if(strpos($query,'SELECT COUNT(*) FROM information_schema.tables')!==0)throw new RuntimeException('Unexpected SQL: '.$query);
        return new HistoricalChecksumStatement();
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$arguments): PDOStatement|false{
        if($query!=='SELECT version, checksum_sha256, status, error_message, applied_at FROM schema_migrations ORDER BY id ASC')throw new RuntimeException('Unexpected SQL: '.$query);
        return new HistoricalChecksumStatement($this->rows);
    }
}
$pendingVersions=array_values(array_map(static fn(array $migration):string=>(string)$migration['version'],array_filter($manifest['upgrades'],static fn(array $migration):bool=>version_compare((string)$migration['version'],'2.5.0','>'))));
$targetVersion=trim((string)file_get_contents($root.'/server/VERSION'));
$ledger=[];
foreach($manifest['upgrades'] as $migrationRow){
    if(version_compare($migrationRow['version'],'2.5.0','>'))continue;
    $ledger[]=['version'=>$migrationRow['version'],'checksum_sha256'=>$migrationRow['checksum'],'status'=>'success','error_message'=>null,'applied_at'=>'2026-10-01 00:00:00'];
}
$last=count($ledger)-1;
foreach([$canonical,$legacy,strtoupper($legacy),$preHall,strtoupper($preHall)] as $checksum){
    $ledger[$last]['checksum_sha256']=$checksum;
    $status=(new UpgradeService(new HistoricalChecksumPdo($ledger),$root.'/server'))->status();
    legacyChecksumAssert($status['integrity_errors']===[],'public status accepts canonical and verified successful history');
    legacyChecksumAssert(array_column($status['pending'],'version')===$pendingVersions,'2.5.0 is never queued for replay');
}
$unknown=str_repeat('f',64);
$ledger[$last]['checksum_sha256']=$unknown;
$status=(new UpgradeService(new HistoricalChecksumPdo($ledger),$root.'/server'))->status();
$message=implode('；',$status['integrity_errors']);
legacyChecksumAssert(count($status['integrity_errors'])===1,'public status rejects unknown successful history');
legacyChecksumAssert(strpos($message,$unknown)!==false&&strpos($message,$canonical)!==false&&strpos($message,'success')!==false,'integrity failure exposes recorded and current checksums and execution status');
foreach(['failed','running'] as $recordStatus){
    $ledger[$last]['status']=$recordStatus;
    $ledger[$last]['checksum_sha256']=$preHall;
    $status=(new UpgradeService(new HistoricalChecksumPdo($ledger),$root.'/server'))->status();
    legacyChecksumAssert(count($status['integrity_errors'])===1,'historical alias cannot bypass '.$recordStatus.' DDL interruption');
    legacyChecksumAssert(array_column($status['pending'],'version')===$pendingVersions,'interrupted 2.5.0 is never queued for replay');
}


// Optional native MySQL run: requires an empty, dedicated CI database and pinned schema-only history.
if((string)getenv('UPGRADE_HISTORICAL_MYSQL_TEST')==='1'){
    $schemaFile=(string)getenv('UPGRADE_HISTORICAL_SCHEMA');
    $legacyFile=(string)getenv('UPGRADE_HISTORICAL_SQL');
    legacyChecksumAssert(is_file($schemaFile)&&hash_file('sha256',$schemaFile)==='50061d7dd6990d19541cc7f7aaad31963ef062eca7de4d5ca6b03a2214c3baeb','schema-only fixture matches pinned pre-2.5.0 history');
    legacyChecksumAssert(is_file($legacyFile)&&hash_file('sha256',$legacyFile)===$preHall,'legacy SQL fixture matches actual recorded checksum');
    $stripComments=static function(string $sql):string{
        return implode("\n",array_filter(explode("\n",$sql),static function(string $line):bool{
            return trim($line)!==''&&preg_match('/^[ \t]*--/',$line)!==1;
        }));
    };
    $currentSql=(string)file_get_contents($root.'/server/database/upgrades/2.5.0.sql');
    $seedOffset=strpos($currentSql,'-- V2.5.0：微信审核/外部体验使用的六类接单大厅演示数据');
    legacyChecksumAssert($seedOffset!==false&&$stripComments((string)file_get_contents($legacyFile))===$stripComments(substr($currentSql,0,$seedOffset)),'all formal migration SQL is identical before optional hall seeds');
    $native=new PDO('mysql:host='.(getenv('UPGRADE_DB_HOST')?:'127.0.0.1').';port='.(getenv('UPGRADE_DB_PORT')?:'3306').';dbname=upgrade_checksum_test;charset=utf8mb4',(string)(getenv('UPGRADE_DB_USER')?:'root'),(string)getenv('UPGRADE_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    legacyChecksumAssert((int)$native->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()===0,'native upgrade test only accepts an empty isolated database');
    $runner=new \WorkerAdmin\Support\SqlScriptRunner();
    $runner->runFile($native,$schemaFile);
    $runner->runFile($native,$legacyFile);
    $insert=$native->prepare("INSERT INTO schema_migrations(version,migration_name,checksum_sha256,execution_ms,status,applied_at) VALUES(?,?,?,0,'success','2026-10-01 00:00:00')");
    foreach($manifest['upgrades'] as $migrationRow){
        if(version_compare($migrationRow['version'],'2.5.0','>'))continue;
        $insert->execute([$migrationRow['version'],$migrationRow['name'],$migrationRow['version']==='2.5.0'?$preHall:$migrationRow['checksum']]);
    }
    $ledgerBefore=$native->query("SELECT * FROM schema_migrations WHERE version='2.5.0'")->fetch(PDO::FETCH_ASSOC);
    $ordersBefore=$native->query('SELECT * FROM orders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $fixtureRoot=sys_get_temp_dir().'/upgrade-checksum-'.bin2hex(random_bytes(8));
    foreach(['/database/upgrades','/config','/storage'] as $directory)mkdir($fixtureRoot.$directory,0770,true);
    try{
        foreach(glob($root.'/server/database/upgrades/*') as $source){
            if(is_file($source))copy($source,$fixtureRoot.'/database/upgrades/'.basename($source));
        }
        copy($root.'/server/VERSION',$fixtureRoot.'/VERSION');
        file_put_contents($fixtureRoot.'/config/installed.php',"<?php return ['installed'=>true,'app'=>['version'=>'2.5.0']];\n");
        file_put_contents($fixtureRoot.'/storage/install.lock',json_encode(['version'=>'2.5.0']));
        $upgrade=new UpgradeService($native,$fixtureRoot);
        $preflight=$upgrade->preflight();
        legacyChecksumAssert($preflight['ok'],'native MySQL preflight accepts actual successful historical record: '.json_encode($preflight['checks'],JSON_UNESCAPED_UNICODE));
        legacyChecksumAssert(array_column($preflight['status']['pending'],'version')===$pendingVersions,'native preflight queues only migrations newer than applied 2.5.0');
        $result=$upgrade->applyPending('isolated-ci-fixture-backup');
        legacyChecksumAssert(array_column($result['applied'],'version')===$pendingVersions,'native upgrade executes the pending migrations without replaying 2.5.0');
        legacyChecksumAssert($native->query("SELECT * FROM schema_migrations WHERE version='2.5.0'")->fetch(PDO::FETCH_ASSOC)===$ledgerBefore,'native upgrade preserves original successful 2.5.0 ledger byte for byte');
        legacyChecksumAssert($native->query('SELECT * FROM orders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$ordersBefore,'native upgrade neither creates nor rewrites optional demo orders');
        $after=$upgrade->status();
        legacyChecksumAssert($after['current_version']===$targetVersion&&$after['pending_count']===0&&$after['integrity_errors']===[],'native upgraded database passes final integrity check');
        legacyChecksumAssert($upgrade->applyPending('isolated-ci-fixture-backup')['changed']===false,'second upgrade request is a no-op');
    }finally{
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());}
        rmdir($fixtureRoot);
    }
    fwrite(STDOUT,"Native MySQL historical checksum upgrade passed.\n");
}

fwrite(STDOUT,"Historical migration checksum compatibility contract passed.\n");
