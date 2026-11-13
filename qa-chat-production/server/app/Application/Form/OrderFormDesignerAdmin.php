<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Form;

use RuntimeException;
use WorkerAdmin\Contracts\OrderFormDesignerAdminRepository;
use WorkerAdmin\Domain\Form\ComponentTypeCatalog;

/**
 * 后台下单表单设计器用例。
 *
 * 这里负责请求数据规范化和不依赖数据库的业务校验；版本锁、归属、唯一性、复制、发布等
 * 需要持久化状态的规则由 Repository 在事务中完成。
 */
final class OrderFormDesignerAdmin
{
    private OrderFormDesignerAdminRepository $repository;

    public function __construct(OrderFormDesignerAdminRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @return array<string,mixed> */
    public function entry(): array
    {
        $target = $this->repository->entryTarget();
        if (!$target) throw new RuntimeException('下单表单尚未初始化，请先执行当前版本数据库升级。');
        return $target;
    }

    /** @return array<string,mixed> */
    public function designer(int $requestedVersionId): array
    {
        return $this->repository->designer(max(0, $requestedVersionId));
    }

    /** @param array<string,mixed> $input @return array{schema_id:int,version_id:int} */
    public function saveUi(array $input, ?int $adminId): array
    {
        $schemaId = $this->positiveId($input, 'schema_id');
        $versionId = $this->positiveId($input, 'version_id');
        $notice = mb_substr($this->scalar($input, 'confirm_notice'), 0, 1000);
        $this->repository->saveUi($schemaId, $versionId, $this->scalar($input, 'collapse_optional', '0') === '1', $notice, $adminId);
        return ['schema_id'=>$schemaId,'version_id'=>$versionId];
    }

    /** @param array<string,mixed> $input @return array{schema_id:int,version_id:int} */
    public function reorder(array $input, ?int $adminId): array
    {
        $schemaId = $this->positiveId($input, 'schema_id');
        $versionId = $this->positiveId($input, 'version_id');
        $layout = $this->jsonArray($this->scalar($input, 'layout_json', '[]'), '设计器排序数据格式不正确。', true);
        $this->repository->reorder($schemaId, $versionId, $layout, $adminId);
        return ['schema_id'=>$schemaId,'version_id'=>$versionId];
    }

    /** @param array<string,mixed> $input @return array{schema_id:int,version_id:int,name:string} */
    public function createSchema(array $input, int $adminId, string $generatedKey): array
    {
        $name = trim($this->scalar($input, 'name'));
        if ($name === '') throw new RuntimeException('请填写表单名称。');
        $schemaKey = trim($this->scalar($input, 'schema_key'));
        if ($schemaKey === '') $schemaKey = $generatedKey;
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $schemaKey) !== 1) throw new RuntimeException('表单编码格式不正确。');
        $result = $this->repository->createSchema($name, $schemaKey, $this->scalar($input, 'description'), $adminId);
        return $result + ['name'=>$name];
    }

    /** @param array<string,mixed> $input @return array{id:int,created:bool,version_no:int,schema_id:int} */
    public function createVersion(array $input, int $schemaId, int $adminId): array
    {
        if ($schemaId < 1) throw new RuntimeException('缺少有效表单编号。');
        $sourceVersionId = max(0, (int)$this->scalar($input, 'source_version_id', $this->scalar($input, 'version_id', '0')));
        return $this->repository->createVersion($schemaId, $sourceVersionId, $adminId) + ['schema_id'=>$schemaId];
    }

    /** @param array<string,mixed> $input @return array{schema_id:int,version_id:int,step_id:int,title:string} */
    public function saveStep(array $input, ?int $adminId): array
    {
        $schemaId = $this->positiveId($input, 'schema_id');
        $versionId = $this->positiveId($input, 'version_id');
        $stepId = max(0, (int)$this->scalar($input, 'step_id', '0'));
        $stepKey = trim($this->scalar($input, 'step_key'));
        $title = trim($this->scalar($input, 'title'));
        $description = mb_substr($this->scalar($input, 'description'), 0, 500);
        $sortOrder = (int)$this->scalar($input, 'sort_order', '10');
        $layout = $this->jsonArray($this->scalar($input, 'layout_json', '[]'), '', false);
        if ($title === '' || preg_match('/^[a-z][a-z0-9_]{1,63}$/', $stepKey) !== 1) {
            throw new RuntimeException('步骤名称或内部编码异常。');
        }
        $this->repository->saveStep($schemaId, $versionId, $stepId, $stepKey, $title, $description, $sortOrder, $layout, $adminId);
        return ['schema_id'=>$schemaId,'version_id'=>$versionId,'step_id'=>$stepId,'title'=>$title];
    }

    /** @param array<string,mixed> $input @return array{schema_id:int,version_id:int,step_id:int} */
    public function toggleStep(array $input, int $stepId, ?int $adminId): array
    {
        if ($stepId < 1) throw new RuntimeException('缺少有效记录编号。');
        $schemaId = $this->positiveId($input, 'schema_id');
        $versionId = $this->positiveId($input, 'version_id');
        $this->repository->toggleStep($schemaId, $versionId, $stepId, $adminId);
        return ['schema_id'=>$schemaId,'version_id'=>$versionId,'step_id'=>$stepId];
    }

    /** @param array<string,mixed> $input @return array{schema_id:int,version_id:int,field_id:int,label:string,created:bool} */
    public function saveField(array $input, ?int $adminId): array
    {
        $schemaId = $this->positiveId($input, 'schema_id');
        $versionId = $this->positiveId($input, 'version_id');
        $fieldId = max(0, (int)$this->scalar($input, 'field_id', '0'));
        $key = trim($this->scalar($input, 'field_key'));
        $label = trim($this->scalar($input, 'label'));
        if ($label === '' || preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key) !== 1) throw new RuntimeException('字段配置不完整。');

        $options = $this->jsonArray($this->scalar($input, 'options_json', '[]'), '选项配置格式错误。', true);
        $type = ComponentTypeCatalog::canonical($this->scalar($input, 'component_type'), $options);
        if (!ComponentTypeCatalog::isSupported($type)) throw new RuntimeException('不支持的填写方式。');

        $rule = $this->jsonArray($this->scalar($input, 'display_rule_json', '{}'), '显示逻辑格式错误。', true);
        $dataType = $this->scalar($input, 'data_type', 'string');
        if (!in_array($dataType, ['string','integer','decimal','boolean','date','json','media_list','address'], true)) {
            throw new RuntimeException('数据类型不受支持。');
        }
        if ($type === 'category_picker') $dataType = 'json';

        $validation = $this->jsonArray($this->scalar($input, 'validation_json', '{}'), '校验配置格式错误。', true);
        $required = $this->scalar($input, 'is_required', '0') === '1';
        $status = $this->scalar($input, 'status_active', '0') === '1' ? 'active' : 'disabled';
        if ($type === 'image_upload') {
            $postedMinimum = trim($this->scalar($input, 'image_min'));
            $postedMaximum = trim($this->scalar($input, 'image_max'));
            if ($postedMinimum !== '') $validation['min_items'] = $postedMinimum;
            if ($postedMaximum !== '') $validation['max_items'] = $postedMaximum;
            $minimumValue = $validation['min_items'] ?? ($required ? 1 : 0);
            $maximumValue = $validation['max_items'] ?? 20;
            if (!is_numeric($minimumValue) || !is_numeric($maximumValue)
                || (float)$minimumValue !== (float)(int)$minimumValue
                || (float)$maximumValue !== (float)(int)$maximumValue) {
                throw new RuntimeException('图片张数必须填写整数。');
            }
            $minimum = (int)$minimumValue;
            $maximum = (int)$maximumValue;
            if ($required && $minimum < 1) $minimum = 1;
            if ($minimum < 0 || $maximum < 1 || $minimum > $maximum || $maximum > 20) {
                throw new RuntimeException('图片张数范围无效：最少 0～20 张、最多 1～20 张，且最少张数不能大于最多张数。');
            }
            $validation['min_items'] = $minimum;
            $validation['max_items'] = $maximum;
            unset($validation['min'], $validation['max']);
        }

        $ui = $this->jsonArray($this->scalar($input, 'ui_config_json', '{}'), '界面配置格式错误。', true);
        unset($ui['visible_when']);
        $defaultRaw = $this->scalar($input, 'default_value_json', 'null');
        $defaultValue = json_decode($defaultRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('默认值配置格式错误。');

        $stepId = max(0, (int)$this->scalar($input, 'step_id', '0'));
        $rawCategoryIds = isset($input['category_ids']) && is_array($input['category_ids']) ? $input['category_ids'] : [];
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $rawCategoryIds), static function (int $id): bool { return $id > 0; })));
        if ($type === 'category_picker') $categoryIds = [];
        $ui['category_ids'] = $categoryIds;

        $layout = $this->jsonArray($this->scalar($input, 'layout_json', '[]'), '', false);
        $field = [
            'step_id'=>$stepId,
            'field_key'=>$key,
            'label'=>$label,
            'component_type'=>$type,
            'data_type'=>$dataType,
            'placeholder'=>$this->scalar($input, 'placeholder'),
            'help_text'=>$this->scalar($input, 'help_text'),
            'is_required'=>$required ? 1 : 0,
            'default_value_json'=>json_encode($defaultValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'validation_json'=>json_encode($validation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ui_config_json'=>json_encode($ui, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order'=>(int)$this->scalar($input, 'sort_order', '0'),
            'status'=>$status,
            'category_ids'=>$categoryIds,
            'options'=>$options,
            'rule'=>$rule,
            'layout'=>$layout,
        ];
        $savedId = $this->repository->saveField($schemaId, $versionId, $fieldId, $field, $adminId);
        return ['schema_id'=>$schemaId,'version_id'=>$versionId,'field_id'=>$savedId,'label'=>$label,'created'=>$fieldId < 1];
    }

    /** @param array<string,mixed> $input @return array{schema_id:int,version_id:int,field_id:int} */
    public function toggleField(array $input, int $fieldId, ?int $adminId): array
    {
        if ($fieldId < 1) throw new RuntimeException('缺少有效记录编号。');
        $schemaId = $this->positiveId($input, 'schema_id');
        $versionId = $this->positiveId($input, 'version_id');
        $this->repository->toggleField($schemaId, $versionId, $fieldId, $adminId);
        return ['schema_id'=>$schemaId,'version_id'=>$versionId,'field_id'=>$fieldId];
    }

    /** @return array{schema_id:int,version_id:int} */
    public function publish(int $versionId, int $adminId): array
    {
        if ($versionId < 1) throw new RuntimeException('缺少有效记录编号。');
        return ['schema_id'=>$this->repository->publish($versionId, $adminId),'version_id'=>$versionId];
    }

    /** @param array<string,mixed> $input */
    private function positiveId(array $input, string $key): int
    {
        $id = max(0, (int)$this->scalar($input, $key, '0'));
        if ($id < 1) throw new RuntimeException('缺少有效表单版本参数。');
        return $id;
    }

    /** @param array<string,mixed> $input */
    private function scalar(array $input, string $key, string $default = ''): string
    {
        return isset($input[$key]) && !is_array($input[$key]) ? trim((string)$input[$key]) : $default;
    }

    /** @return array<mixed> */
    private function jsonArray(string $json, string $message, bool $strict): array
    {
        $value = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($value)) return $value;
        if ($strict) throw new RuntimeException($message);
        return [];
    }
}
