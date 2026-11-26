<?php
declare(strict_types=1);
require __DIR__.'/source/server/app/Contracts/ChatRecoveryReadiness.php';
require __DIR__.'/source/server/app/Infrastructure/Chat/ChatRecoveryGate.php';
use WorkerAdmin\Infrastructure\Chat\ChatRecoveryGate as Gate;
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
check(Gate::ready([],'production'),'no optional recovery baseline');
check(!Gate::ready(['recovery_directory'=>'/fixture'],'production'),'partial config blocked');
check(!Gate::ready([],'unknown'),'unknown environment rejected');
$p=tempnam(sys_get_temp_dir(),'gate');chmod($p,0600);$epoch=str_repeat('a',64);$cfg=['recovery_gate_file'=>$p,'recovery_epoch'=>$epoch];
try{
  foreach(['ready','blocked','invalid'] as $state){
    file_put_contents($p,json_encode(['schema'=>1,'environment'=>'production','epoch'=>$epoch,'state'=>$state]));
    clearstatcache();
    check(Gate::ready($cfg,'production')===($state==='ready'),'marker '.$state);
  }
  file_put_contents($p,json_encode(['schema'=>1,'environment'=>'test','epoch'=>$epoch,'state'=>'ready']));
  check(!Gate::ready($cfg,'production'),'wrong environment');
  file_put_contents($p,json_encode(['schema'=>1,'environment'=>'production','epoch'=>$epoch,'state'=>'ready']));
  chmod($p,0666);clearstatcache();check(!Gate::ready($cfg,'production'),'writable marker rejected');
}finally{unlink($p);}
check(!Gate::ready($cfg,'production'),'missing configured marker blocked');
