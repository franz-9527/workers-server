<?php
declare(strict_types=1);
$root=dirname(__DIR__);$router=$root.'/server/tests/support/board_http_router.php';
$socket=stream_socket_server('tcp://127.0.0.1:0',$err,$why);if(!$socket)throw new RuntimeException($why);$address=stream_socket_get_name($socket,false);fclose($socket);$log=tempnam(sys_get_temp_dir(),'board-http-');
$process=proc_open([PHP_BINARY,'-n','-d','extension_dir='.(realpath(ini_get('extension_dir'))?:ini_get('extension_dir')),'-d','extension=mbstring','-S',$address,$router],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root,null,['bypass_shell'=>true]);
if(!is_resource($process))throw new RuntimeException('Cannot start local HTTP fixture');$checks=0;
$assert=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);++$checks;};
$request=static function(string $route,string $method,bool $token=false,string $case='ok',string $body='{}',array $query=[])use($address):array{
 $headers=['Content-Type: application/json'];if($token)$headers[]='Authorization: Bearer '.str_repeat('a',64);
 $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>5]]);
 $result=file_get_contents('http://'.$address.'/?'.http_build_query(['route'=>$route,'case'=>$case]+$query),false,$ctx);if($result===false)throw new RuntimeException('Fixture request failed');preg_match('/\s(\d{3})\s/',$http_response_header[0],$m);
 return [(int)$m[1],json_decode($result,true),$result,$http_response_header];
};
$routes=[
 'payments.status'=>'GET','wallet.summary'=>'GET','wallet.ledger'=>'GET','wallet.recharge.create'=>'POST','offline.delay.create'=>'POST','offline.collection.adjustment.submit'=>'POST','finance.summary'=>'GET','finance.account.save'=>'POST','finance.withdraw'=>'POST','finance.withdraw.cancel'=>'POST','commission.payment.create'=>'POST',
 'media.upload'=>'POST','media.delete'=>'POST','orders.measurement.media.upload'=>'POST','orders.measurement.media.delete'=>'POST','reviews.media.upload'=>'POST','finance.realname.upload'=>'POST','finance.collection-code.upload'=>'POST','merchant.license.upload'=>'POST',
 'auth.wechat'=>'POST','auth.phone'=>'POST','membership.summary'=>'GET','workers.map'=>'GET','workers.detail'=>'GET','workers.phone-reveal.policy'=>'GET','workers.phone-reveal.authorize'=>'POST','workers.phone-reveal'=>'POST','workers.search'=>'GET','worker.profile'=>'GET','worker.profile.save'=>'POST','media.order'=>'GET','media.worker'=>'GET',
];
try{
 for($i=0;$i<40;$i++){[$host,$port]=explode(':',$address);$ready=@fsockopen($host,(int)$port,$e,$text,.05);if($ready){fclose($ready);break;}usleep(50000);}if(!$ready)throw new RuntimeException(file_get_contents($log));
 foreach($routes as$route=>$verb){
  [$status]=$request($route,$verb==='GET'?'POST':'GET');$assert($status===405,'correct method contract '.$route);
  if(!in_array($route,['auth.wechat','media.order','media.worker'],true)){[$status,$json]=$request($route,$verb);$assert($status===401 && $json['code']==='UNAUTHORIZED','session boundary '.$route);}
 }
 foreach(['payments.status','wallet.recharge.create','finance.account.save','finance.withdraw','finance.withdraw.cancel','commission.payment.create','media.upload','orders.measurement.media.upload','reviews.media.upload','finance.realname.upload','finance.collection-code.upload','merchant.license.upload','auth.phone','worker.profile.save']as$route){[$status,$json]=$request($route,$routes[$route],true,'demo');$assert($status===403 && $json['code']==='DEMO_EXTERNAL_ACTION_BLOCKED','demo side effect rejected '.$route);}
 [$status,$json]=$request('auth.wechat','POST',false,'ok','invalid');$assert($status===422 && $json['code']==='INVALID_JSON','unauthenticated login remains a JSON-only endpoint');
 [$status,$json]=$request('workers.map','GET',true);$assert($status===200 && $json['data']['authority']==='cloud' && $json['data']['deprecated']===true,'worker map keeps cloud-authority marker');
 [$status,$json,$body,$headers]=$request('media.order','GET',false);$assert($status===403 && strpos(implode(' ',$headers),'X-Test-Safety-Gate')===false,'private media requires signed token and skips UGC gate');
 [$status]=$request('media.worker','GET',false,'ok','',['id'=>'bad']);$assert($status===404,'public worker media rejects invalid ID');
 $payload=['v'=>2,'scope'=>'media','expires'=>time()-60];$encoded=rtrim(strtr(base64_encode(json_encode($payload)),'+/','-_'),'=');$signed=$encoded.'.'.hash_hmac('sha256',$encoded,'');
 [$status]=$request('media.order','GET',false,'ok','',['access_token'=>$signed]);$assert($status===403,'expired signed media token rejected');
 echo 'Board HTTP retirement: '.$checks.' checks passed.'.PHP_EOL;
}finally{proc_terminate($process);fclose($pipes[0]);proc_close($process);if(is_file($log))unlink($log);}
