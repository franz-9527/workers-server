<?php

declare(strict_types=1);

require __DIR__.'/source/PlatformOrderAdminCreateSnapshot.php';

function failTest(string $message): void { fwrite(STDERR,"[FAIL] {$message}\n"); exit(1); }
function passTest(string $message): void { fwrite(STDOUT,"[PASS] {$message}\n"); }

try {
    (new \WorkerAdmin\Application\Order\PlatformOrderAdminCreateSnapshot())->create([
        'category_ids'=>[1,2,3,4,5,6,7],
    ],1);
    failTest('more than six categories were silently accepted');
} catch (\WorkerAdmin\Application\Order\OrderOperationException $e) {
    if ($e->businessCode!=='TOO_MANY_CATEGORIES') failTest('unexpected category limit code: '.$e->businessCode);
    passTest('more than six categories are rejected before downstream creation');
}

$measure=file_get_contents(__DIR__.'/source/MeasurementAwarePlatformOrderAdminRepository.php');
if(!is_string($measure))failTest('measurement decorator snapshot missing');
if(strpos($measure,'alignCommonFacts(')!==false||strpos($measure,'PlatformOrderCommonFactsWriter')!==false){
    failTest('measurement decorator still duplicates common facts writes');
}
passTest('measurement decorator no longer duplicates order_answers/common facts');

$module=file_get_contents(__DIR__.'/source/PlatformOrderAdminModule.php');
if(!is_string($module))failTest('admin module snapshot missing');
foreach([
    "\$rules = is_array(\$form['rules'] ?? null)",
    'rule.action_type==="show"',
    'control.disabled=!visible||!!state.disabled',
    'answers[expected_visit_period]',
    'type==="date_range"',
] as $needle){
    if(strpos($module,$needle)===false)failTest('missing admin runtime contract: '.$needle);
}
passTest('admin module contains rule, hidden-submit and date companion contracts');
