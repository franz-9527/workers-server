<?php
declare(strict_types=1);
function setting($key,$default=null){return ['qiniu.budget_guard'=>true,'qiniu.private_bucket'=>true][$key]??$default;}
function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
function base_path(string $path=''):string{return getenv('FIXTURE_ROOT').'/'.$path;}
require __DIR__.'/QiniuBudget.php';
require __DIR__.'/QiniuMediaReader.php';
require __DIR__.'/QiniuBillingUsage.php';
use WorkerAdmin\Infrastructure\External\QiniuBudget as B;
use WorkerAdmin\Infrastructure\External\QiniuMediaReader as R;
use WorkerAdmin\Infrastructure\External\QiniuBillingUsage as U;
function check($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS ".$m."\n";}
function deny(callable $f){try{$f();}catch(RuntimeException $e){return;}throw new LogicException('expected rejection');}
@mkdir(base_path('storage/private'),0700,true);
switch($argv[1]??''){
case 'reserve':
    $month=B::reserve($argv[2],(int)$argv[3]);
    if(isset($argv[4]))B::releaseDownload($month,(int)$argv[4]);
    break;
case 'status': echo json_encode(B::status());break;
case 'deny': deny(fn()=>B::reserve($argv[2],(int)$argv[3]));break;
case 'cache':
    B::assertOrigin('http://example.invalid');deny(fn()=>B::assertOrigin('https://example.invalid'));
    $url='http://127.0.0.1:18080/object';$sha=hash('sha256','fixture');
    check(R::read($url,$sha,'fixture-key')==='fixture','verified cache miss');
    check(R::read($url,$sha,'fixture-key')==='fixture','verified cache hit');
    check(B::status()['state']['get_requests']===1,'cache hit did not charge another GET');
    deny(fn()=>R::read($url,str_repeat('0',64),'fixture-key'));
    check(B::status()['state']['get_requests']===2,'mismatched hash charged fetch and rejected');
    $root=base_path('storage/private/qiniu-media-cache');$slots=glob($root.'/slot-*.bin');
    check(count($slots)===1,'bounded cache contains only verified payload');
    $raw=file_get_contents($slots[0]);$parts=explode("\n",$raw,3);file_put_contents($slots[0],$parts[0]."\n1\n".$parts[2]);
    R::prune();check(!file_exists($slots[0]),'expired cache pruned');
    break;
case 'billing':
    $bill=U::summarize(['list'=>[
    ['product'=>'对象存储','item'=>'标准存储CDN回源流出流量-华南','usage_unit'=>'GB','usage_coefficient'=>1073741824,'total_usage'=>8*1073741824,'package_usage'=>8*1073741824,'item_money'=>0],
    ['product'=>'CDN加速','item'=>'CDN-HTTPS 流量-中国大陆','usage_unit'=>'GB','usage_coefficient'=>1073741824,'total_usage'=>100,'package_usage'=>0,'item_money'=>10000000],
    ]],'2026-11-28');
    check($bill['budgets'][1]['level']==='stop'&&$bill['charged']&&$bill['unknown'],'billing fixture reports warning and unknown');
    break;
default:throw new LogicException('unknown fixture action');
}
