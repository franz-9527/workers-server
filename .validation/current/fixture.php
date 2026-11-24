<?php
declare(strict_types=1);
$root=__DIR__.'/server/app/';
require $root.'Core/Database.php';
require $root.'Contracts/PrivateValueCipher.php';
require $root.'Infrastructure/Security/GlobalPrivateValueCipher.php';
require $root.'Infrastructure/Persistence/RedisRuntime.php';
require $root.'Application/Chat/ChatRuntimeDiagnostics.php';
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\RedisRuntime;
use WorkerAdmin\Application\Chat\ChatRuntimeDiagnostics;
$env='testing';function app_config($key,$default=null){global $env;return $key==='app.env'?$env:$default;}
function base_path($path){return __DIR__.'/fixture-state/'.$path;}
function decrypt_private_value($cipher,$context){if($cipher!=='fixture-cipher'||$context!==RedisRuntime::PASSWORD_CONTEXT)throw new RuntimeException('unexpected fixture');return 'redis-ci-fixture-only';}
function ck($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$db=new Database(['database'=>'redis_review','host'=>'127.0.0.1','port'=>(int)getenv('MYSQL_PORT'),'username'=>'root','password'=>getenv('CHAT_INTEGRATION_ADMIN_PASSWORD')]);
if(($argv[1]??'')==='noext'){
    ck(!class_exists('Redis'),'extension absent');
    $r=new RedisRuntime($db);ck($r->rememberPublic('notice','home',1,fn()=>['ok'=>1])===['ok'=>1],'no extension fallback');ck($r->allow('limit','id',2,60)===null,'no extension limiter fallback');exit;
}
$db->execute('CREATE TABLE system_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT NOT NULL)');
$addr='127.0.0.1:'.getenv('REDIS_PORT');
foreach([RedisRuntime::ENABLED=>'1',RedisRuntime::ADDRESS=>$addr,RedisRuntime::PASSWORD=>''] as $key=>$value)$db->execute('INSERT INTO system_settings VALUES(:k,:v)',['k'=>$key,'v'=>$value]);
$r=new RedisRuntime($db);$loads=0;$loader=function()use(&$loads){return ['n'=>++$loads];};
ck($r->diagnostics()['state']==='ready','PHP Redis connected');
ck($r->rememberPublic('notice','home',1,$loader)===['n'=>1]&&$r->rememberPublic('notice','home',1,$loader)===['n'=>1],'cache hit');
$env='production';$prod=new RedisRuntime($db);
ck($prod->rememberPublic('notice','home',1,$loader)===['n'=>2],'environment isolation');
$env='testing';sleep(2);
ck($r->rememberPublic('notice','home',1,$loader)===['n'=>3],'cache expiry');
ck($r->allow('limit','fixture',2,60)===true&&$r->allow('limit','fixture',2,60)===true&&$r->allow('limit','fixture',2,60)===false,'atomic bounded limiter');
ck($prod->allow('limit','fixture',2,60)===true,'rate environment isolation');
$db->execute('UPDATE system_settings SET setting_value=:v WHERE setting_key=:k',['v'=>'127.0.0.1:1','k'=>RedisRuntime::ADDRESS]);$down=new RedisRuntime($db);
ck($down->allow('limit','id',1,60)===null&&$down->rememberPublic('notice','home',1,$loader)===['n'=>4],'Redis failure fallback');
$db->execute('UPDATE system_settings SET setting_value=:v WHERE setting_key=:k',['v'=>$addr,'k'=>RedisRuntime::ADDRESS]);
$env='unknown';$unknown=new RedisRuntime($db);ck($unknown->allow('limit','id',1,60)===null,'unknown environment rejected');$env='testing';
@mkdir(base_path('storage/private'),0700,true);
$d=new ChatRuntimeDiagnostics($db);$snapshot=$d->snapshot();
ck(isset($snapshot['mysql']['Uptime']),'real MySQL status');
$method=new ReflectionMethod($d,'sample');
@unlink(base_path('storage/private/chat-db-observation.json'));
$m=['Uptime'=>100,'Innodb_rows_inserted'=>10,'Innodb_rows_updated'=>20,'Innodb_rows_deleted'=>1,'binlog_file'=>'mysql-bin.000001','binlog_position'=>1000];
ck($method->invoke($d,$m,1000)===[],'first sample');
$m['Uptime']=130;$m['Innodb_rows_updated']=50;$m['binlog_position']=1049576;
$rate=$method->invoke($d,$m,1030);
ck($rate['rows_per_second']===1.0&&$rate['binlog_mib_per_minute']===2.0,'rate calculation');
$m['Uptime']=131;$m['binlog_file']='mysql-bin.000002';$m['binlog_position']=4;
ck(!isset($method->invoke($d,$m,1031)['binlog_mib_per_minute']),'rotation invalidates cached binlog rate');
$m['Uptime']=1;
ck($method->invoke($d,$m,1032)===[],'restart invalidates sample');
echo "PHP targeted runtime fixture complete\n";
