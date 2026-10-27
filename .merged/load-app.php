<?php
error_reporting(E_ALL);
set_error_handler(static function(int $level,string $message,string $file,int $line):bool { if(error_reporting()&$level) throw new ErrorException($message,0,$level,$file,$line);return false; });
$base=__DIR__.'/src/server/app/';
spl_autoload_register(static function(string $class)use($base):void {if(str_starts_with($class,'WorkerAdmin\\')){ $path=$base.str_replace('\\','/',substr($class,12)).'.php';if(is_file($path))require_once $path; }});
$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));$count=0;
foreach($files as $file)if($file->isFile()&&$file->getExtension()==='php'){require_once $file->getPathname();$count++;}
echo 'PHP83_APP_LOADED '.$count.PHP_EOL;
foreach(WorkerAdmin\Support\PhpRuntimeRequirements::checks() as $check) if(!$check['passed'])throw new RuntimeException($check['name'].': '.$check['message']);
echo 'PHP83_RUNTIME_REQUIREMENTS_OK'.PHP_EOL;
