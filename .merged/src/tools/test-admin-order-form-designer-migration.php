<?php

declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'WorkerAdmin\\';
    if (strpos($class, $prefix) !== 0) return;
    $file = $root.'/server/app/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) require_once $file;
});

use WorkerAdmin\Application\Form\OrderFormDesignerAdmin;
use WorkerAdmin\Contracts\OrderFormDesignerAdminRepository;

final class FakeOrderFormDesignerAdminRepository implements OrderFormDesignerAdminRepository
{
    public ?array $target = ['schema_id'=>3,'version_id'=>7];
    public array $last = [];
    public function entryTarget(): ?array { return $this->target; }
    public function designer(int $requestedVersionId): array { $this->last=['designer'=>$requestedVersionId]; return ['versionId'=>$requestedVersionId]; }
    public function saveUi(int $schemaId, int $versionId, bool $collapseOptional, string $confirmNotice, ?int $adminId): void { $this->last=compact('schemaId','versionId','collapseOptional','confirmNotice','adminId'); }
    public function reorder(int $schemaId, int $versionId, array $layout, ?int $adminId): void { $this->last=compact('schemaId','versionId','layout','adminId'); }
    public function createSchema(string $name, string $schemaKey, string $description, int $adminId): array { $this->last=compact('name','schemaKey','description','adminId'); return ['schema_id'=>4,'version_id'=>8]; }
    public function createVersion(int $schemaId, int $sourceVersionId, int $adminId): array { $this->last=compact('schemaId','sourceVersionId','adminId'); return ['id'=>9,'created'=>true,'version_no'=>2]; }
    public function saveStep(int $schemaId, int $versionId, int $stepId, string $stepKey, string $title, string $description, int $sortOrder, array $layout, ?int $adminId): void { $this->last=compact('schemaId','versionId','stepId','stepKey','title','description','sortOrder','layout','adminId'); }
    public function toggleStep(int $schemaId, int $versionId, int $stepId, ?int $adminId): void { $this->last=compact('schemaId','versionId','stepId','adminId'); }
    public function saveField(int $schemaId, int $versionId, int $fieldId, array $field, ?int $adminId): int { $this->last=['schemaId'=>$schemaId,'versionId'=>$versionId,'fieldId'=>$fieldId,'field'=>$field,'adminId'=>$adminId]; return $fieldId > 0 ? $fieldId : 99; }
    public function toggleField(int $schemaId, int $versionId, int $fieldId, ?int $adminId): void { $this->last=compact('schemaId','versionId','fieldId','adminId'); }
    public function publish(int $versionId, int $adminId): int { $this->last=compact('versionId','adminId'); return 3; }
}

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException('Admin order-form migration: '.$message); };
$expectFailure = static function (callable $callback, string $message) use ($assert): void { try { $callback(); } catch (RuntimeException $exception) { $assert(strpos($exception->getMessage(), $message) !== false, 'unexpected error: '.$exception->getMessage()); return; } throw new RuntimeException('Admin order-form migration: expected failure containing '.$message); };

$repo = new FakeOrderFormDesignerAdminRepository();$app = new OrderFormDesignerAdmin($repo);$entry = $app->entry();$assert($entry['schema_id'] === 3 && $entry['version_id'] === 7, 'entry target changed');$repo->target = null;$expectFailure(static function () use ($app): void { $app->entry(); }, '下单表单尚未初始化');$repo->target = ['schema_id'=>3,'version_id'=>7];
$result = $app->createSchema(['name'=>'下单配置','schema_key'=>'order_submit_v2','description'=>'说明'], 12, 'order_generated');$assert($result['schema_id'] === 4 && $repo->last['schemaKey'] === 'order_submit_v2', 'schema create normalization changed');$expectFailure(static function () use ($app): void { $app->createSchema(['name'=>'','schema_key'=>'x'], 12, 'order_generated'); }, '请填写表单名称');$expectFailure(static function () use ($app): void { $app->createSchema(['name'=>'配置','schema_key'=>'1bad'], 12, 'order_generated'); }, '表单编码格式不正确');
$app->reorder(['schema_id'=>'3','version_id'=>'7','layout_json'=>'[{"id":5,"step_id":2,"sort_order":10}]'], 12);$assert(count($repo->last['layout']) === 1 && $repo->last['layout'][0]['id'] === 5, 'designer layout normalization changed');$expectFailure(static function () use ($app): void { $app->reorder(['schema_id'=>'3','version_id'=>'7','layout_json'=>'{bad'], 12); }, '设计器排序数据格式不正确');
$field = $app->saveField(['schema_id'=>'3','version_id'=>'7','field_id'=>'0','step_id'=>'2','field_key'=>'site_photo','label'=>'现场照片','component_type'=>'image','data_type'=>'media_list','is_required'=>'1','status_active'=>'1','options_json'=>'[]','display_rule_json'=>'{}','validation_json'=>'{}','ui_config_json'=>'{}','default_value_json'=>'null','image_min'=>'0','image_max'=>'5','category_ids'=>['1','1','3','0'],'layout_json'=>'[]'], 12);$assert($field['created'] === true && $field['field_id'] === 99, 'field create result changed');$assert($repo->last['field']['component_type'] === 'image_upload', 'legacy image component was not canonicalized');$assert($repo->last['field']['is_required'] === 1 && strpos($repo->last['field']['validation_json'], '"min_items":1') !== false, 'required image minimum changed');$assert($repo->last['field']['category_ids'] === [1,3], 'category ids were not normalized');$expectFailure(static function () use ($app): void {$app->saveField(['schema_id'=>'3','version_id'=>'7','step_id'=>'2','field_key'=>'bad_field','label'=>'字段','component_type'=>'unknown','options_json'=>'[]','display_rule_json'=>'{}','validation_json'=>'{}','ui_config_json'=>'{}','default_value_json'=>'null'], 12);}, '不支持的填写方式');

$routes=['forms','forms.designer','forms.designer.reorder','forms.ui.save','forms.schema.save','forms.version.create','forms.step.save','forms.step.toggle','forms.field.save','forms.field.toggle','forms.publish'];$module=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/OrderFormDesignerAdminModule.php');$legacy=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/LegacyAdminModule.php');$kernel=(string)file_get_contents($root.'/server/app/Http/Admin/AdminKernel.php');$repository=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseOrderFormDesignerAdminRepository.php');foreach($routes as $route){$assert(strpos($module,"'{$route}'")!==false,'formal module missing '.$route);$assert(strpos($legacy,"'{$route}'")!==false,'anti-fallback registry missing '.$route);}$assert(strpos($kernel,'new OrderFormDesignerAdminModule($db,$auth,$csrf)')!==false,'AdminKernel did not register form owner');$assert(strpos($module,'SELECT ')===false&&strpos($module,'UPDATE ')===false&&strpos($module,'INSERT ')===false&&strpos($module,'DELETE ')===false,'Http form module contains SQL');$assert(strpos($module,'WorkerAdmin\\Controllers\\')===false&&strpos($module,'WorkerAdmin\\Services\\')===false&&strpos($module,'WorkerAdmin\\Infrastructure\\')===false,'Http form module depends on forbidden legacy/infrastructure layer');foreach(['form_schema_versions','form_steps','form_fields','form_options','form_rules','FOR UPDATE','snapshotBuilder','syncGeneratedFields'] as $token)$assert(strpos($repository,$token)!==false,'persistence compatibility token missing: '.$token);$assert(strpos($repository,'显示与关联存在循环依赖')!==false,'publish cycle guard was lost');$assert(strpos($repository,'已有订单关联，已锁定')!==false,'version usage lock was lost');$assert(!is_file($root.'/server/app/Controllers/AdminController.php'),'retired AdminController was restored');
echo "Admin order-form designer migration OK: 11 routes formally owned; Legacy fallback blocked; retired AdminController absent.\n";
