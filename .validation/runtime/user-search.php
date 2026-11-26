<?php
declare(strict_types=1);
$root=__DIR__.'/source/server';
spl_autoload_register(static function($class)use($root){if(str_starts_with($class,'WorkerAdmin\\')){ $p=$root.'/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;}});
require $root.'/app/Support/functions.php';
function check(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS ".$label.PHP_EOL;}
$db=new WorkerAdmin\Core\Database(['host'=>'127.0.0.1','port'=>3306,'database'=>'runtime_fixture','username'=>'root','password'=>'fixture-only','charset'=>'utf8mb4']);
check(str_starts_with((string)$db->fetchValue('SELECT VERSION()'),'5.7.44'),'MySQL version');
foreach(explode(";\n",trim(file_get_contents(__DIR__.'/schema.sql'))) as $sql){if(trim($sql)!=='')$db->pdo()->exec($sql);}
$db->execute("INSERT INTO users(id,public_id,nickname,mobile_masked,status,created_at,updated_at) VALUES(10201,REPEAT('A',26),'夹具搜索用户','138****1234','active',UTC_TIMESTAMP(),UTC_TIMESTAMP()),(10202,REPEAT('B',26),'其他用户','','active',UTC_TIMESTAMP(),UTC_TIMESTAMP()),(10203,'DEMO-SEARCH-FIXTURE','夹具搜索用户','','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$users=new WorkerAdmin\Infrastructure\Persistence\DatabaseUserMerchantAdminRepository($db);
foreach(['utf8mb4_general_ci','utf8mb4_unicode_ci','utf8mb4_bin'] as $collation){
 $db->execute('SET NAMES utf8mb4 COLLATE '.$collation);
 foreach(['夹具搜索用户','aaaaaa','1234'] as $keyword){
  $r=$users->usersPage($keyword,'',1,20,false);
  check($r['total']===1&&count($r['rows'])===1&&(int)$r['rows'][0]['id']===10201,$collation.' search '.$keyword);
 }
 $r=$users->usersPage('不存在的夹具搜索','',1,20,false);
 check($r['total']===0&&$r['rows']===[],$collation.' no match');
 $a=$users->usersPage('夹具搜索用户','',1,1,true);$b=$users->usersPage('夹具搜索用户','',2,1,true);
 check($a['total']===2&&$b['total']===2&&$a['rows'][0]['id']!==$b['rows'][0]['id'],$collation.' pagination');
}
