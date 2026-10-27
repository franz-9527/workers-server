<?php

declare(strict_types=1);

require __DIR__ . '/../server/app/Contracts/ActiveSessionGateway.php';
require __DIR__ . '/../server/app/Contracts/PublicStoreContactGateway.php';
require __DIR__ . '/../server/app/Contracts/SettingsProvider.php';
require __DIR__ . '/../server/app/Application/Store/PublicStoreContactLookup.php';

use WorkerAdmin\Application\Store\PublicStoreContactLookup;
use WorkerAdmin\Contracts\ActiveSessionGateway;
use WorkerAdmin\Contracts\PublicStoreContactGateway;
use WorkerAdmin\Contracts\SettingsProvider;

final class TestSessionGateway implements ActiveSessionGateway { public int $calls=0; public function assertActive(string $token): void { $this->calls++; if ($token !== str_repeat('a',64)) throw new RuntimeException('unauthorized',401); } }
final class TestStoreGateway implements PublicStoreContactGateway { public int $calls=0; public function fetchMobile(string $storeId): string { $this->calls++; return $storeId === 'store_1' ? '13800000001' : ''; } }
final class TestSettings implements SettingsProvider {
    private bool $enabled; public function __construct(bool $enabled){$this->enabled=$enabled;}
    public function get(string $key,$default=null){return $key==='store.phone_public_enabled'?$this->enabled:$default;}
    public function bool(string $key,bool $default=false):bool{return (bool)$this->get($key,$default);}
    public function int(string $key,int $default=0):int{return (int)$this->get($key,$default);}
}

$sessions=new TestSessionGateway();$stores=new TestStoreGateway();
$disabled=(new PublicStoreContactLookup($sessions,$stores,new TestSettings(false)))->lookup(str_repeat('a',64),'store_1');
if($disabled!==['enabled'=>false,'mobile'=>'','has_mobile'=>false]||$sessions->calls!==1||$stores->calls!==0)throw new RuntimeException('disabled policy mismatch');
$enabled=(new PublicStoreContactLookup($sessions,$stores,new TestSettings(true)))->lookup(str_repeat('a',64),'store_1');
if($enabled['mobile']!=='13800000001'||!$enabled['has_mobile']||$stores->calls!==1)throw new RuntimeException('enabled lookup mismatch');
try{(new PublicStoreContactLookup($sessions,$stores,new TestSettings(true)))->lookup(str_repeat('a',64),'bad id!');throw new RuntimeException('invalid id accepted');}catch(RuntimeException $e){if($e->getCode()!==422)throw $e;}
echo "store-public-contact=ok
";
