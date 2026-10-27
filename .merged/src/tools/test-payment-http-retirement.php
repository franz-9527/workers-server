<?php
declare(strict_types=1);
// Exercise the real Kernel and HTTP modules with local recording ports and actual HTTP bodies.
$root=dirname(__DIR__);$router=$root.'/server/tests/support/payment_http_router.php';
$socket=stream_socket_server('tcp://127.0.0.1:0',$error,$message);if(!$socket)throw new RuntimeException($message);
$address=stream_socket_get_name($socket,false);fclose($socket);$log=tempnam(sys_get_temp_dir(),'payment-http-');
$command=[PHP_BINARY,'-n','-d','extension_dir='.(realpath(ini_get('extension_dir'))?:ini_get('extension_dir')),'-d','extension=mbstring','-S',$address,$router];
$process=proc_open($command,[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root,null,['bypass_shell'=>true]);
if(!is_resource($process))throw new RuntimeException('Local PHP HTTP fixture did not start');
$checks=0;
$assert=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);++$checks;};
$request=static function(string $route,string $case='ok',string $body='{}',string $method='POST',bool $token=true)use($address):array{
 $headers=['Content-Type: application/json','X-Test-Signature: valid'];if($token)$headers[]='Authorization: Bearer '.str_repeat('a',64);
 $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>4]]);
 $response=file_get_contents('http://'.$address.'/?'.http_build_query(['route'=>$route,'case'=>$case]),false,$context);
 if($response===false)throw new RuntimeException('Fixture request failed');
 preg_match('/\s(\d{3})\s/',$http_response_header[0],$match);
 return [(int)$match[1],$response,json_decode($response,true),$http_response_header];
};
try{
 for($i=0;$i<40;$i++){[$host,$port]=explode(':',$address);$ready=@fsockopen($host,(int)$port,$err,$errText,.05);if($ready){fclose($ready);break;}usleep(50000);}
 if(!$ready)throw new RuntimeException('Local HTTP fixture startup failed: '.file_get_contents($log));
 foreach(['wechatpay.notify','wechatpay.refund_notify']as$route){
  [$status,$body,$json]=$request($route);$assert($status===200 && $json['code']==='SUCCESS',$route.' JSON acknowledgment without session/content gate');
  [$status,$body,$json,$headers]=$request($route,'ok','<xml><test>1</test></xml>');$assert($status===200 && strpos($body,'<![CDATA[SUCCESS]]>')!==false && strpos(implode(' ',$headers),'text/xml')!==false,$route.' XML acknowledgment');
  [$status,$body,$json]=$request($route,'duplicate');$assert($status===200 && $json['code']==='SUCCESS',$route.' duplicate acknowledged');
  foreach(['bad-sign','settle-fail']as$case){[$status,$body,$json]=$request($route,$case);$assert($status===500 && $json['code']==='FAIL',$route.' failure acknowledgment '.$case);}
  [$status,$body,$json]=$request($route,'bad-sign','<xml></xml>');$assert($status===500 && strpos($body,'<![CDATA[FAIL]]>')!==false,$route.' XML failure');
  [$status,$body,$json]=$request($route,'ok','','GET');$assert($status===500 && $json['code']==='FAIL',$route.' wrong method cannot process notice');
 }
 [$status,$body,$json]=$request('payments.create','ok','{"amount_cents":999999}');$assert($status===200 && $json['data']['payment']['server_amount']===100,'creation uses server amount and postcommit gateway');
 foreach([['demo',403,'DEMO_EXTERNAL_ACTION_BLOCKED'],['missing-appid',503,'MINIAPP_APPID_MISSING'],['missing-openid',409,'OPENID_REQUIRED'],['restricted',422,'ORDER_SUBMISSION_RESTRICTED']]as[$case,$expected,$code]){[$status,$body,$json]=$request('payments.create',$case);$assert($status===$expected && $json['code']===$code,'creation error contract '.$case);if($case==='restricted')$assert($json['data']['expires_at_utc']==='2099-01-01 00:00:00','restricted expiry data preserved outside Legacy');}
 [$status,$body,$json]=$request('payments.create','ok','{}','POST',false);$assert($status===401 && $json['code']==='UNAUTHORIZED','creation requires authenticated session');
 [$status,$body,$json]=$request('payments.create','ok','invalid');$assert($status===422 && $json['code']==='INVALID_JSON','creation rejects invalid JSON');
 [$status,$body,$json]=$request('payments.create','ok','','GET');$assert($status===405 && $json['code']==='METHOD_NOT_ALLOWED','creation rejects GET');
 [$status,$body,$json]=$request('payments.create','security-denied');$assert($status===500 && $json['code']==='SECURITY_TEST','creation still enters content safety gate');
 echo 'Payment HTTP retirement: '.$checks.' checks passed.'.PHP_EOL;
}finally{
 proc_terminate($process);fclose($pipes[0]);proc_close($process);if(is_file($log))unlink($log);
}
