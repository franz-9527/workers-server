<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/app/Infrastructure/External/TencentMapIpLocator.php';
use WorkerAdmin\Infrastructure\External\TencentMapIpLocator;
function write_log(string $name,string $message):void{}
$locator=new TencentMapIpLocator('fixture-only');
$method=new ReflectionMethod($locator,'project');
$cases=[
    [['nation'=>'中国','province'=>'浙江省','city'=>'杭州市','district'=>'西湖区'],'浙江省 杭州市 西湖区'],
    [['nation'=>'中国','province'=>'浙江省','city'=>'杭州市','district'=>'未知区县'],'浙江省 杭州市'],
    [['nation'=>'中国','province'=>'江苏省','city'=>'','district'=>'鼓楼区'],'江苏省'],
    [['nation'=>'中国','province'=>'北京市','city'=>'北京市','district'=>'朝阳区'],'北京市 朝阳区'],
    [['nation'=>'中国','province'=>'','city'=>'','district'=>'西湖区'],null],
    [['nation'=>'中国','province'=>'unknown','city'=>'NULL','district'=>'--'],null],
    [['nation'=>'日本','province'=>'東京都','city'=>'新宿区'],'日本 東京都 新宿区'],
    [['nation'=>'日本'],null],
    [['nation'=>'中国','province'=>['unexpected'],'city'=>false],null],
];
foreach($cases as $i=>[$ad,$expected]){
    $out=$method->invoke($locator,['status'=>0,'result'=>['ad_info'=>$ad]]);
    if(($out['display']??null)!==$expected)throw new RuntimeException('region case '.($i+1).' failed');
    if($out!==null&&array_diff(array_keys($out),['nation','province','city','district','display','detail']))throw new RuntimeException('unexpected private fields');
}
foreach([null,false,'invalid','0invalid',1,429] as $status){
    $out=$method->invoke($locator,['status'=>$status,'result'=>['ad_info'=>['province'=>'浙江省']]]);
    if($out!==null)throw new RuntimeException('invalid provider status accepted');
}
if(($method->invoke($locator,['status'=>'0','result'=>['ad_info'=>['province'=>'浙江省']]])['display']??null)!=='浙江省')throw new RuntimeException('numeric status rejected');
if($locator->locate('127.0.0.1')!==null)throw new RuntimeException('private IP must not call provider');
echo 'IP administrative hierarchy: '.count($cases).' cases, status validation and private IP guard passed'.PHP_EOL;
