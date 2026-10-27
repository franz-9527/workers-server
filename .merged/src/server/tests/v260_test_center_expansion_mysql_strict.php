<?php
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{throw new ErrorException($message,0,$severity,$file,$line);});
spl_autoload_register(static function(string $class):void{if(strpos($class,'WorkerAdmin\\')!==0)return;$p=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once$p;});
require __DIR__.'/v260_test_center_expansion_mysql.php';
