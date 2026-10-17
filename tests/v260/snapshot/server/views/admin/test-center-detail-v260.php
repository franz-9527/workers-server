<?php
$viewData=get_defined_vars();
$base=\view('admin/test-center-detail',$viewData,null);
$base=str_replace(
    '已覆盖报价、成交、预约、完工、验收、加价介入与退款协商；不提供自由改状态，真实微信退款和维修仍分阶段接入',
    '已覆盖报价、成交、预约、完工、验收、加价/退款协商与售后维修；不提供自由改状态，真实微信退款和平台管理员裁决仍保持安全边界',
    $base
);
$repair=\view('admin/test-center-repair-actions',$viewData,null);
if($repair===''){echo $base;return;}

$flowMarker='<section class="admin-card admin-mb-3">'."\n".'  <div class="admin-card__header"><div><strong>真实流程推进（M2）</strong>';
$flowPosition=strpos($base,$flowMarker);
if($flowPosition!==false){
    $flowEnd=strpos($base,'</section>',$flowPosition);
    if($flowEnd!==false)$base=substr($base,0,$flowPosition).substr($base,$flowEnd+strlen('</section>'));
}

$marker='<section class="admin-card admin-mb-3">'."\n".'  <div class="admin-card__header"><div><strong>数据检查</strong>';
$position=strpos($base,$marker);
if($position===false){
    echo $base.$repair;
    return;
}
echo substr($base,0,$position).$repair.substr($base,$position);
