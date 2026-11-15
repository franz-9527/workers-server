<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin {
    interface AdminModule
    {
        public function supports(string $route): bool;
        public function dispatch(string $route): void;
    }
}

namespace {
    require __DIR__.'/source/PlatformOrderAdminModule.php';

    $reflection=new ReflectionClass(\WorkerAdmin\Http\Admin\Modules\PlatformOrderAdminModule::class);
    $instance=$reflection->newInstanceWithoutConstructor();
    $method=$reflection->getMethod('sharedFormFieldsScript');
    $method->setAccessible(true);

    $fields=[
        [
            'field_key'=>'finish_type','label'=>'饰面类型','component_type'=>'radio','data_type'=>'string',
            'is_required'=>true,'default_value'=>null,'validation'=>[],'ui'=>[],'category_ids'=>[],
            'options'=>[
                ['option_key'=>'normal','value_text'=>'normal','label'=>'普通'],
                ['option_key'=>'other','value_text'=>'__other__','label'=>'其他'],
            ],
        ],
        [
            'field_key'=>'finish_type__other_text','label'=>'其他说明','component_type'=>'text','data_type'=>'string',
            'is_required'=>true,'default_value'=>null,'validation'=>['max_length'=>120],
            'ui'=>['system_role'=>'other_text','parent_field_key'=>'finish_type','parent_option_value'=>'__other__'],
            'category_ids'=>[],'options'=>[],
        ],
        [
            'field_key'=>'visit_window','label'=>'可上门日期范围','component_type'=>'date_range','data_type'=>'json',
            'is_required'=>false,'default_value'=>null,'validation'=>[],'ui'=>[],'category_ids'=>[],'options'=>[],
        ],
        [
            'field_key'=>'expected_visit_date','label'=>'期望上门日期','component_type'=>'date_picker','data_type'=>'date',
            'is_required'=>false,'default_value'=>null,'validation'=>[],'ui'=>[],'category_ids'=>[],'options'=>[],
        ],
        [
            'field_key'=>'need_protect','label'=>'需要保护','component_type'=>'switch','data_type'=>'boolean',
            'is_required'=>false,'default_value'=>null,'validation'=>[],'ui'=>[],'category_ids'=>[],'options'=>[],
        ],
    ];
    $rules=[
        [
            'rule_key'=>'finish_type_other','operator'=>'equals','compare_value'=>'__other__','action_type'=>'show',
            'action_payload'=>[],'trigger_key'=>'finish_type','target_key'=>'finish_type__other_text',
        ],
    ];
    $html=$method->invoke($instance,['fields'=>$fields,'rules'=>$rules]);
    if(!is_string($html)||strpos($html,'<script>')!==0||substr($html,-9)!=='</script>'){
        fwrite(STDERR,"generated script wrapper invalid\n");exit(1);
    }
    $js=substr($html,8,-9);
    if(strpos($js,'finish_type__other_text')===false||strpos($js,'rule.action_type==="show"')===false){
        fwrite(STDERR,"rules were not serialized into generated script\n");exit(1);
    }
    if(strpos($js,'answers[expected_visit_period]')===false||strpos($js,'type==="date_range"')===false){
        fwrite(STDERR,"dynamic date controls missing\n");exit(1);
    }
    file_put_contents(__DIR__.'/generated.js',$js);
    fwrite(STDOUT,"[PASS] PHP generator emitted rules/date controls\n");
}
