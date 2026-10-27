<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$base=$root.'/server/app';
spl_autoload_register(static function (string $class) use ($base): void {
    $prefix='WorkerAdmin\\';
    if (strncmp($class,$prefix,strlen($prefix))!==0) return;
    $file=$base.'/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if (is_file($file)) require_once $file;
});

use WorkerAdmin\Application\Agreements\PlatformAgreements;
use WorkerAdmin\Contracts\AgreementDocumentRepository;
use WorkerAdmin\Domain\Agreements\DefaultAgreementDocuments;
use WorkerAdmin\Http\Admin\AdminModule;
use WorkerAdmin\Http\Admin\Modules\AgreementAdminModule;
use WorkerAdmin\Http\Api\ApiModule;
use WorkerAdmin\Http\Api\Modules\AgreementApiModule;
use WorkerAdmin\Infrastructure\Agreements\PlatformOrderAgreementProvider;
use WorkerAdmin\Infrastructure\Agreements\PlatformWorkerAgreementVersionProvider;

function agreementExpect($actual,$expected,string $label): void
{
    if($actual!==$expected){
        fwrite(STDERR,'[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
        exit(1);
    }
    echo '[PASS] '.$label."\n";
}

final class FakeAgreementDocumentRepository implements AgreementDocumentRepository
{
    public array $documents=[];
    public int $saves=0;
    public function loadDocuments(): array {return $this->documents;}
    public function saveDocument(array $document,int $adminId): void
    {
        agreementExpect($adminId,77,'应用层完整传递编辑管理员');
        foreach($this->documents as $index=>$old) if($old['key']===$document['key']){
            $this->documents[$index]=$document;
            ++$this->saves;
            return;
        }
        throw new RuntimeException('Unknown agreement');
    }
}

$seedRepository=new FakeAgreementDocumentRepository();
$seedRepository->documents=[[
    'key'=>'seed_agreement','title'=>'系统首版','enabled'=>true,'audience'=>['user'],'scenes'=>['agreement_center'],'sort_order'=>1,
    'versions'=>[[
        'version'=>'2026-10-10-v1','title'=>'系统首版','content'=>str_repeat('系统初始化正文。',30),
        'content_format'=>'html','published_by'=>0,'published_at'=>'2026-10-10 00:00:00','effective_at'=>'2026-10-10 00:00:00',
    ]],'draft'=>null,
]];
$seedApp=new PlatformAgreements($seedRepository);
agreementExpect($seedApp->document('seed_agreement')['initial_revision_allowed'],true,'只有系统种子单一 V1 可进入上线前首版修订');
$seedApp->saveDraft('seed_agreement',['title'=>'修订后的第一版','content'=>str_repeat('这是正式运营前重新整理的第一版正文。',20),'important_notice'=>'上线前继续定稿'],77);
$seedVersion=$seedApp->reviseInitialVersion('seed_agreement',77);
$seedDocument=$seedApp->document('seed_agreement');
agreementExpect($seedVersion,'2026-10-10-v1','首版修订保持原版本号');
agreementExpect(count($seedDocument['versions']),1,'首版修订不会新增 V2');
agreementExpect($seedDocument['current']['title'],'修订后的第一版','首版草稿原地写回当前 V1');
agreementExpect($seedDocument['draft']??null,null,'首版修订完成后清除草稿');
agreementExpect($seedDocument['initial_revision_allowed'],true,'系统种子 V1 在正式发布 V2 前仍可继续定稿');
$seedRepository->documents[0]['versions'][]=['version'=>'v2','title'=>'正式新版','content'=>'正式新版','published_by'=>77,'published_at'=>'2026-10-20 00:00:00','effective_at'=>'2099-01-01 00:00:00'];
agreementExpect($seedApp->document('seed_agreement')['initial_revision_allowed'],false,'出现后续版本后自动关闭首版原地修订');

$repository=new FakeAgreementDocumentRepository();
$repository->documents=[[
    'key'=>'test_order_agreement','title'=>'测试订单协议','enabled'=>true,
    'audience'=>['user'],'scenes'=>['order_submit'],'sort_order'=>10,
    'versions'=>[
        ['version'=>'v1','title'=>'旧版协议','content'=>'完整生效文本','published_by'=>77,'published_at'=>'2026-09-01 00:00:00','effective_at'=>'2026-09-01 00:00:00'],
        ['version'=>'v2','title'=>'未来协议','content'=>'未来生效文本','published_by'=>77,'published_at'=>'2026-10-01 00:00:00','effective_at'=>'2099-01-01 00:00:00'],
    ],'draft'=>null,
]];
$app=new PlatformAgreements($repository);
$visible=$app->publicDocuments('order_submit','user');
agreementExpect(count($visible),1,'业务场景和用户身份过滤');
agreementExpect($visible[0]['version'],'v1','未来版本不会提前替代生效协议');
agreementExpect($visible[0]['upcoming']['version'],'v2','未来生效版本仍可告知用户');
agreementExpect($app->validatePublishedVersions(['test_order_agreement'=>'v1'])['ok'],true,'生效版本确认通过');
agreementExpect($app->validatePublishedVersions(['test_order_agreement'=>'v2'])['outdated'],['test_order_agreement'],'未来版本不得冒充当前已发布版本');
agreementExpect($app->publicDocuments('order_submit','worker'),[],'错误角色看不到协议');
agreementExpect($app->publicDocuments('worker_flow','user'),[],'错误场景看不到协议');

$app->saveDraft('test_order_agreement',[
    'title'=>'测试更新后的标题','content'=>str_repeat('这是经过审核的协议更新正文。',20),
    'important_notice'=>'发布后按指定时间生效',
],77);
agreementExpect($repository->saves,1,'草稿通过 Contracts 持久化');
agreementExpect($app->publicDocuments('order_submit','user')[0]['version'],'v1','保存草稿不会覆盖生效协议');
$version=$app->publishDraft('test_order_agreement',gmdate('Y-m-d H:i:s',time()+9*86400),77);
agreementExpect($repository->saves,2,'延迟生效版本通过相同仓储保存');
agreementExpect($app->publicDocuments('order_submit','user')[0]['version'],'v1','已发布但尚未生效的版本不会抢先启用');
agreementExpect($app->publicDocuments('order_submit','user')[0]['upcoming']['version'],$version,'最近待生效版本可用');

agreementExpect(count(DefaultAgreementDocuments::all())>0,true,'默认协议定义已迁入纯领域模块');
agreementExpect(is_subclass_of(AgreementAdminModule::class,AdminModule::class),true,'后台协议接口保持已有 Http 模块');
agreementExpect(is_subclass_of(AgreementApiModule::class,ApiModule::class),true,'小程序协议接口保持已有 Http 模块');
agreementExpect(method_exists(PlatformOrderAgreementProvider::class,'validatePublishedVersions'),true,'下单业务协议校验端口未改变');
agreementExpect(method_exists(PlatformWorkerAgreementVersionProvider::class,'validateVersions'),true,'师傅入驻业务协议校验端口未改变');

foreach(['PlatformAgreementService.php','AgreementAdminController.php','AgreementApiController.php'] as $obsolete) {
    foreach(['Services','Controllers'] as $folder) agreementExpect(is_file($base.'/'.$folder.'/'.$obsolete),false,'未留下旧控制器或 Service：'.$obsolete.' '.$folder);
}

echo "Agreement application, contracts, domain and HTTP architecture regression: OK\n";
