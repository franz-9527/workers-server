<?php

declare(strict_types=1);

$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    $prefix='WorkerAdmin\\';
    if(strpos($class,$prefix)!==0)return;
    $file=$root.'/server/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($file))require_once $file;
});
require_once $root.'/server/app/Support/functions.php';
$GLOBALS['worker_admin_config']['app']['timezone']='Asia/Shanghai';

final class FakeServiceCategoryAdminRepository implements \WorkerAdmin\Contracts\ServiceCategoryAdminRepository
{
    public array $rows=[1=>['id'=>1,'name'=>'墙布'],5=>['id'=>5,'name'=>'施工'],7=>['id'=>7,'name'=>'子类']];
    public array $parents=[1=>null,5=>null,7=>5];
    public array $saved=[];
    public bool $duplicateCode=false;
    public bool $duplicateOrderCode=false;
    public bool $toggleResult=true;

    public function all(): array{return array_values($this->rows);}
    public function find(int $id): ?array{return $this->rows[$id]??null;}
    public function exists(int $id): bool{return isset($this->rows[$id]);}
    public function codeExistsExcept(string $code,int $exceptId): bool{return $this->duplicateCode;}
    public function orderCodeExistsExcept(string $orderCode,int $exceptId): bool{return $this->duplicateOrderCode;}
    public function parentId(int $id): ?int{return $this->parents[$id]??null;}
    public function save(int $id,array $values): int{$this->saved=['id'=>$id,'values'=>$values];return $id>0?$id:99;}
    public function toggle(int $id): bool{return $this->toggleResult;}
}

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Service category migration: '.$message);};
$expectFailure=static function(callable $callback,string $message)use($assert):void{
    try{$callback();}catch(RuntimeException $exception){$assert(strpos($exception->getMessage(),$message)!==false,'unexpected error: '.$exception->getMessage());return;}
    throw new RuntimeException('Service category migration: expected failure containing '.$message);
};

$repo=new FakeServiceCategoryAdminRepository();
$app=new \WorkerAdmin\Application\Catalog\ServiceCategoryAdmin($repo);
$page=$app->page(1);
$assert(count($page['categories'])===3&&($page['editing']['id']??0)===1,'page read model changed');
$result=$app->save(['name'=>' 墙纸 ','code'=>'wallpaper','order_code'=>'qz','parent_id'=>'0','sort_order'=>'20','description'=>'说明']);
$assert($result['id']===99&&$result['name']==='墙纸','new category save result changed');
$assert(($repo->saved['values']['order_code']??'')==='QZ','order code must be normalized to uppercase');
$assert(array_key_exists('parent_id',$repo->saved['values'])&&$repo->saved['values']['parent_id']===null,'root category must retain an explicit null parent');

foreach(['Q','Q1','QZZ','墙布'] as $badCode){
    $expectFailure(static function()use($app,$badCode):void{$app->save(['name'=>'测试','code'=>'valid_code','order_code'=>$badCode]);},'2 位大写字母订单号类目码');
}

$repo->duplicateCode=true;
$expectFailure(static function()use($app):void{$app->save(['name'=>'墙纸','code'=>'wallpaper','order_code'=>'QZ']);},'类目编码已经存在');
$repo->duplicateCode=false;$repo->duplicateOrderCode=true;
$expectFailure(static function()use($app):void{$app->save(['name'=>'墙纸','code'=>'wallpaper','order_code'=>'QZ']);},'订单号字母码已经被其他类目使用');
$repo->duplicateOrderCode=false;
$expectFailure(static function()use($app):void{$app->save(['id'=>5,'name'=>'施工','code'=>'build','order_code'=>'SG','parent_id'=>5]);},'类目不能把自己设为上级');
$expectFailure(static function()use($app):void{$app->save(['id'=>5,'name'=>'施工','code'=>'build','order_code'=>'SG','parent_id'=>7]);},'不能把类目移动到自己的下级类目中');
$expectFailure(static function()use($app):void{$app->toggle(0);},'缺少有效记录编号');
$repo->toggleResult=false;
$expectFailure(static function()use($app):void{$app->toggle(1);},'类目不存在或状态已变化');

$numbers=[];
for($i=0;$i<100;$i++){
    $number=order_business_no('qb');
    $assert(preg_match('/^BXQB\d{6}-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/',$number)===1,'order number format changed: '.$number);
    $numbers[$number]=true;
}
$assert(count($numbers)===100,'100 generated order numbers should be unique in smoke test');
$fallback=order_business_no('invalid-123');
$assert(strpos($fallback,'BXQT')===0,'invalid order category code should fall back to QT');

$module=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/ServiceCategoryAdminModule.php');
$legacy=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/LegacyAdminModule.php');
$kernel=(string)file_get_contents($root.'/server/app/Http/Admin/AdminKernel.php');
$view=(string)file_get_contents($root.'/server/views/admin/categories.php');
$repository=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseServiceCategoryAdminRepository.php');
foreach(['categories','categories.save','categories.toggle'] as $route){
    $assert(strpos($module,"'{$route}'")!==false,'formal module missing '.$route);
    $assert(strpos($legacy,"'{$route}'")!==false,'anti-fallback registry missing '.$route);
}
$assert(strpos($kernel,'new ServiceCategoryAdminModule($db,$auth,$csrf)')!==false,'AdminKernel did not register category owner');
$assert(strpos($module,'SELECT ')===false&&strpos($module,'UPDATE ')===false&&strpos($module,'INSERT ')===false,'Http category module contains SQL');
$assert(strpos($module,'WorkerAdmin\\Controllers\\')===false&&strpos($module,'WorkerAdmin\\Services\\')===false,'Http category module depends on legacy layer');
$assert(strpos($view,'id="categorySearch"')!==false&&strpos($view,'id="categoryNumberPreview"')!==false,'category admin usability controls missing');
$assert(strpos($repository,'children_count')!==false&&strpos($repository,'order_count')!==false,'category read model statistics missing');

echo "Admin service-category migration OK: routes, UX read model and BX+2-letter category order numbers verified.\n";
