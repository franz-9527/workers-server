<?php
$detail=is_array($detail??null)?$detail:[];
$instance=(array)($detail['instance']??[]);
$actionItems=(array)($detail['actions']['items']??[]);
$section=\WorkerAdmin\Infrastructure\Testing\TestCenterNavigation::section($section??'overview',true);
$panel=\WorkerAdmin\Infrastructure\Testing\TestCenterNavigation::panel($section,$panel??null);
$viewData=get_defined_vars();
echo \view('admin/test-center-detail',$viewData,null);
$modules=[
 'workflow'=>['single-grab'],
 'measurement'=>['proxy-invitation','measurement','platform-measurement'],
 'aftersale'=>['repair','admin-adjudication','dispute-finance'],
 'messages'=>$panel==='regression'?['chat']:($panel==='delivery'?['message-provider','paid-sms-receipt']:($panel==='notices'?['message-simulation']:[])),
 'records'=>$panel==='replay'?['failure-replay']:($panel==='scheduler'?['scheduler-diagnostics']:[]),
];
if($section==='messages'&&$panel==='chat')echo \view('admin/test-center-chat-simulation',$viewData,null);
foreach($modules[$section]??[] as $name){
 $suffix=in_array($name,['failure-replay','scheduler-diagnostics'],true)?'':'-actions';
 echo \view('admin/test-center-'.$name.$suffix,$viewData,null);
}

if(isset($detail['record_pagination']))echo \view('admin/test-center-pagination',['paginationPage'=>$detail['record_pagination']['page'],'paginationMore'=>$detail['record_pagination']['has_more'],'paginationRoute'=>'test-center.detail','paginationParams'=>['id'=>$instance['public_id'],'section'=>$section,'panel'=>$panel]],null);
