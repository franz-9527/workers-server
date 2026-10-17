<?php
$viewData=get_defined_vars();
$base=\view('admin/test-center-detail',$viewData,null);
$repair=\view('admin/test-center-repair-actions',$viewData,null);
if($repair===''){echo $base;return;}
$marker='<section class="admin-card admin-mb-3">'."\n".'  <div class="admin-card__header"><div><strong>数据检查</strong>';
$position=strpos($base,$marker);
if($position===false){
    echo $base.$repair;
    return;
}
echo substr($base,0,$position).$repair.substr($base,$position);
