<?php

declare(strict_types=1);

$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    $prefix='WorkerAdmin\\';
    if(strpos($class,$prefix)!==0)return;
    $file=$root.'/server/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($file))require_once $file;
});

use WorkerAdmin\Application\Admin\AdminAccountSecurity;
use WorkerAdmin\Contracts\AdminAccountRepository;

final class FakeAdminAccountRepository implements AdminAccountRepository
{
    public ?string $hash;
    public ?string $savedHash=null;
    public int $savedId=0;
    public function __construct(string $password){$hash=password_hash($password,PASSWORD_BCRYPT,['cost'=>4]);if(!is_string($hash))throw new RuntimeException('fixture password hash failed');$this->hash=$hash;}
    public function passwordHash(int $adminId): ?string{return $this->hash;}
    public function replacePassword(int $adminId,string $passwordHash): void{$this->savedId=$adminId;$this->savedHash=$passwordHash;$this->hash=$passwordHash;}
}

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Admin account migration: '.$message);};
$expectFailure=static function(callable $callback,string $message)use($assert):void{try{$callback();}catch(RuntimeException $exception){$assert(strpos($exception->getMessage(),$message)!==false,'unexpected error: '.$exception->getMessage());return;}throw new RuntimeException('Admin account migration: expected failure containing '.$message);};

$repo=new FakeAdminAccountRepository('OldPassword123');$app=new AdminAccountSecurity($repo);
$expectFailure(static function()use($app):void{$app->changePassword(7,'wrong','NewPassword123','NewPassword123');},'当前密码不正确');
$expectFailure(static function()use($app):void{$app->changePassword(7,'OldPassword123','short1','short1');},'新密码至少 10 位');
$expectFailure(static function()use($app):void{$app->changePassword(7,'OldPassword123','NewPassword123','NewPassword124');},'两次输入一致');
$app->changePassword(7,'OldPassword123','NewPassword123','NewPassword123');
$assert($repo->savedId===7&&is_string($repo->savedHash)&&password_verify('NewPassword123',$repo->savedHash),'password replacement changed');

$module=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/AdminAccountAdminModule.php');
$legacy=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/LegacyAdminModule.php');
$kernel=(string)file_get_contents($root.'/server/app/Http/Admin/AdminKernel.php');
$repository=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseAdminAccountRepository.php');
$guard=(string)file_get_contents($root.'/server/app/Http/Admin/AdminRequestGuard.php');
foreach(['login','logout','profile','profile.password'] as $route){$assert(strpos($module,"'{$route}'")!==false,'formal module missing '.$route);$assert(strpos($legacy,"'{$route}'")!==false,'anti-fallback registry missing '.$route);}
$assert(strpos($kernel,'new AdminAccountAdminModule($db, $auth, $csrf)')!==false,'AdminKernel did not register admin account owner');
$assert(strpos($module,'$this->auth->attempt($username, $password)')!==false,'login no longer delegates to Auth::attempt');
$assert(strpos($module,'$this->csrf->verifyRequest()')!==false,'login POST lost explicit CSRF verification');
$assert(strpos($guard,"if (\$route === 'login') return;")!==false,'AdminRequestGuard login exception changed unexpectedly');
$assert(strpos($module,"view('admin/profile'")!==false,'profile view changed');
$assert(strpos($repository,'must_change_password=0')!==false&&strpos($repository,'failed_attempts=0')!==false&&strpos($repository,'locked_until=NULL')!==false,'password reset side effects changed');
$assert(strpos($module,'SELECT ')===false&&strpos($module,'UPDATE ')===false&&strpos($module,'INSERT ')===false&&strpos($module,'DELETE ')===false,'Http admin account module contains SQL');
$assert(strpos($module,'WorkerAdmin\\Controllers\\')===false&&strpos($module,'WorkerAdmin\\Services\\')===false,'Http admin account module depends on legacy layer');
$assert(!is_file($root.'/server/app/Controllers/AdminController.php'),'retired AdminController was restored');

echo "Admin account migration OK: login/logout/profile/password formally owned; Legacy fallback blocked; retired AdminController absent.\n";
