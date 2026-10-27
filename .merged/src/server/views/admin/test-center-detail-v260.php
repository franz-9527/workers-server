<?php
$detail=is_array($detail??null)?$detail:[];
$instance=(array)($detail['instance']??[]);
$actionItems=(array)($detail['actions']['items']??[]);
$viewData=get_defined_vars();
$base=\view('admin/test-center-detail',$viewData,null);
$base=str_replace(
    '已覆盖报价、成交、预约、完工、验收、加价介入与退款协商；不提供自由改状态，真实微信退款和维修仍分阶段接入',
    '已覆盖多师傅报价、单师傅抢单竞争、普通测量及关联施工、成交、履约、加价/退款协商、售后维修、平台管理员裁决及资金型争议裁定；不提供自由改状态，真实微信支付/退款仍保持硬隔离',
    $base
);
$singleGrab=\view('admin/test-center-single-grab-actions',$viewData,null);
$measurement=\view('admin/test-center-measurement-actions',$viewData,null);
$platformMeasurement=\view('admin/test-center-platform-measurement-actions',$viewData,null);
$repair=\view('admin/test-center-repair-actions',$viewData,null);
$adminAdjudication=\view('admin/test-center-admin-adjudication-actions',$viewData,null);
$financeAdjudication=\view('admin/test-center-dispute-finance-actions',$viewData,null);
$proxyInvitation=\view('admin/test-center-proxy-invitation-actions',$viewData,null);
$failureReplay=\view('admin/test-center-failure-replay',$viewData,null);
$schedulerDiagnostics=\view('admin/test-center-scheduler-diagnostics',$viewData,null);
$messageSimulation=\view('admin/test-center-message-simulation-actions',$viewData,null);
$messageProvider=\view('admin/test-center-message-provider-actions',$viewData,null);
$paidSmsReceipt=\view('admin/test-center-paid-sms-receipt-actions',$viewData,null);
$chat=\view('admin/test-center-chat-actions',$viewData,null);
$extra=$chat.$proxyInvitation.$singleGrab.$measurement.$platformMeasurement.$repair.$adminAdjudication.$financeAdjudication.$failureReplay.$schedulerDiagnostics.$messageSimulation.$messageProvider.$paidSmsReceipt;
if($extra===''){echo $base;return;}

$flowMarker='<section class="admin-card admin-mb-3">'."\n".'  <div class="admin-card__header"><div><strong>真实流程推进（M2）</strong>';
$flowPosition=strpos($base,$flowMarker);
// 扩展动作与普通履约可同时出现，保留基础模板中的预约、完工和验收表单。
$baseKeys=['customer_accept_completion','customer_escalate_change','customer_escalate_refund','customer_refund_request','customer_reject_change','customer_repropose_refund','customer_select_quote','worker_accept_refund','worker_appointment','worker_change_request','worker_complete','worker_quote','worker_reject_refund'];
if($flowPosition!==false&&!array_intersect_key($actionItems,array_fill_keys($baseKeys,true))){
    $flowEnd=strpos($base,'</section>',$flowPosition);
    if($flowEnd!==false)$base=substr($base,0,$flowPosition).substr($base,$flowEnd+strlen('</section>'));
}

$marker='<section class="admin-card admin-mb-3">'."\n".'  <div class="admin-card__header"><div><strong>数据检查</strong>';
$position=strpos($base,$marker);
if($position===false){echo $base.$extra;return;}
echo substr($base,0,$position).$extra.substr($base,$position);
