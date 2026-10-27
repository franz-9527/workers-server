<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_HOST')!=='127.0.0.1')throw new RuntimeException('Isolated Actions only');
require_once __DIR__.'/src/server/app/Support/SqlScriptRunner.php';
$pdo=new PDO('mysql:host=127.0.0.1;port='.getenv('V250_DB_PORT').';charset=utf8mb4','root',(string)getenv('V250_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$arguments=array_slice($argv,1);$core=($arguments[0]??'')==='--core';if($core)array_shift($arguments);
foreach($arguments as $name){
 if(!preg_match('/^worker_test(?:_[a-z0-9_]+)?$/D',$name))throw new RuntimeException('Invalid isolated schema');
 $pdo->exec('DROP DATABASE IF EXISTS `'.$name.'`');$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$pdo->exec('USE `'.$name.'`');
 (new WorkerAdmin\Support\SqlScriptRunner())->runFile($pdo,__DIR__.($core?'/src/server/tests/v260_core_schema.sql':'/src/server/database/database.sql'));
 if($core)foreach(['2.6.0','2.7.0'] as $version)(new WorkerAdmin\Support\SqlScriptRunner())->runFile($pdo,__DIR__.'/src/server/database/upgrades/'.$version.'.sql');
}
