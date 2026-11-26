<?php
declare(strict_types=1);
require __DIR__.'/source/server/app/Application/Chat/ChatGoRuntimeStatus.php';
$c=new WorkerAdmin\Application\Chat\ChatGoRuntimeStatus('production');
$r=new ReflectionClass($c);
$read=$r->getMethod('readCurrent');$read->setAccessible(true);
if(function_exists('readlink'))throw new RuntimeException('readlink not disabled');
if($read->invoke($c,'/nonexistent/current')!=='')throw new RuntimeException('readlink did not degrade');
$p=$r->getMethod('businessProbe');$p->setAccessible(true);
$state=$p->invoke($c,'production');
if($state['ready']||!$state['supported'])throw new RuntimeException('partial probe bypassed readiness');
echo "PASS disabled readlink and partial probe fail closed\n";
