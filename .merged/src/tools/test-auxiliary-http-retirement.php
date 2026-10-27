<?php
declare(strict_types=1);
$root=dirname(__DIR__);$router=$root.'/server/tests/support/auxiliary_http_router.php';
$socket=stream_socket_server('tcp://127.0.0.1:0',$err,$why);if(!$socket)throw new RuntimeException($why);$address=stream_socket_get_name($socket,false);fclose($socket);$log=tempnam(sys_get_temp_dir(),'aux-http-');
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
 'arrivals.report'=>'POST','orders.measurement.space.save'=>'POST','orders.measurement.space.delete'=>'POST','orders.measurement.links.eligible'=>'GET','after_sales.repair.create'=>'POST','after_sales.repair.withdraw'=>'POST',
 'admin.worker.update'=>'POST','admin.worker.review'=>'POST','admin.worker.sms-stats'=>'GET','admin.store.review'=>'POST','admin.review.records'=>'GET',
];
$input=[
 'arrivals.report'=>['order_id'=>'O1','description'=>'现场无法施工','lat'=>30,'lng'=>120],
 'orders.measurement.space.save'=>['order_id'=>'O1','name'=>' 客厅 ','sort_order'=>5000],
 'orders.measurement.space.delete'=>['order_id'=>'O1','space_id'=>'S1'],
 'orders.measurement.links.eligible'=>[],
 'after_sales.repair.create'=>['order_id'=>'O1','description'=>'墙布接缝处出现明显翘边','evidence_ids'=>['M1','M1','M2']],
 'after_sales.repair.withdraw'=>['order_id'=>'O1','after_sale_id'=>'R1'],
 'admin.worker.update'=>['cloud_id'=>'CW1','patch'=>['workerName'=>'new name','mobile'=>'13800131234']],
 'admin.worker.review'=>['cloud_id'=>'CW1','decision'=>'approved'],
 'admin.worker.sms-stats'=>['cloud_worker_id'=>'CW1'],
 'admin.store.review'=>['cloud_id'=>'CS1','decision'=>'approved'],
 'admin.review.records'=>['source'=>'worker','page'=>0,'page_size'=>99],
];
try{
 for($i=0;$i<40;$i++){[$host,$port]=explode(':',$address);$ready=@fsockopen($host,(int)$port,$e,$text,.05);if($ready){fclose($ready);break;}usleep(50000);}if(!$ready)throw new RuntimeException(file_get_contents($log));
 foreach($routes as$route=>$verb){
  [$status,$json]=$request($route,$verb==='GET'?'POST':'GET');$assert($status===405&&$json['code']==='METHOD_NOT_ALLOWED','HTTP method '.$route);
  [$status,$json]=$request($route,$verb);$assert($status===401&&$json['code']==='UNAUTHORIZED','session required '.$route);
  if(strpos($route,'admin.')===0){
   [$status,$json]=$request($route,$verb,true,'missing-openid');$assert($status===403&&$json['code']==='ADMIN_REQUIRED','server admin required '.$route);
   [$status,$json]=$request($route,$verb,true,'demo');$assert($status===403&&$json['code']==='DEMO_ADMIN_ACTION_BLOCKED','demo error contract '.$route);
  }
  [$status,$json,$body,$headers]=$request($route,$verb,true,'ok',json_encode($input[$route]),$verb==='GET'?$input[$route]:[]);
  $assert($status===200&&$json['ok']===true,'public use case dispatched '.$route);
  $guarded=strpos($route,'admin.')===0||in_array($route,['arrivals.report','orders.measurement.space.save','after_sales.repair.create'],true);
  $assert((strpos(implode(' ',$headers),'X-Test-Safety-Gate')!==false)===$guarded,'content safety selection '.$route);
  if(in_array($route,['admin.worker.sms-stats','admin.review.records'],true))$assert(strpos(implode(' ',$headers),'no-store')!==false,'sensitive reads are uncached '.$route);
  if($route==='orders.measurement.space.save')$assert($json['data']['name']==='客厅'&&$json['data']['sort_order']===999,'normalized space result');
  if($route==='orders.measurement.links.eligible')$assert($json['data']['items'][0]['hold_until']==='2026-10-09 20:00','localized measurement result');
  if($route==='after_sales.repair.create')$assert($json['data']['evidence']===['M1','M2'],'deduplicated evidence');
  if($route==='admin.worker.update')$assert($json['data']['updated_fields']===['workerName'],'worker edit excludes phone');
 }
 foreach(['arrivals.report','admin.worker.update']as$route){[$status,$json]=$request($route,'POST',true,'ok','bad json');$assert($status===422&&$json['code']==='INVALID_JSON','bad JSON rejected '.$route);}
 [$status,$json]=$request('admin.worker.review','POST',true,'ok',json_encode(['id'=>'W1','decision'=>'rejected']));$assert($status===422&&$json['code']==='WORKER_REVIEW_REASON_REQUIRED','review domain error survives HTTP');
 echo 'Auxiliary HTTP retirement: '.$checks.' checks passed.'.PHP_EOL;
}finally{proc_terminate($process);fclose($pipes[0]);proc_close($process);if(is_file($log))unlink($log);}
