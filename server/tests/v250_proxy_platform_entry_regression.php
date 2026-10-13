<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
function sourceText(string $root,string $path):string{$text=@file_get_contents($root.'/'.$path);if(!is_string($text))throw new RuntimeException('missing '.$path);return$text;}
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "[PASS] {$message}\n";}

$proxy=sourceText($root,'server/app/Application/Order/WebAdminOrder.php');
$platform=sourceText($root,'server/app/Infrastructure/Persistence/RepairGuardedPlatformOrderAdminRepository.php');
$facts=sourceText($root,'server/app/Infrastructure/Persistence/PlatformOrderCommonFactsWriter.php');
$payment=sourceText($root,'server/app/Http/Api/Modules/PaymentCreationApiModule.php');

check(str_contains($proxy,'$this->submission->create($actor,$body)'),'proxy order reuses ordinary OrderSubmission');
check(!str_contains($proxy,'$this->orders->create($input'),'proxy order does not use legacy fixed-field create path');
check(str_contains($proxy,'unset($input[\'approve_now\'], $input[\'publish_now\'])'),'proxy order cannot request immediate approve/publish');
check(str_contains($proxy,'payment_status\'] ?? \'\') !== \'paid\''),'unpaid proxy order cannot be approved or published');

check(str_contains($platform,'count($categoryIds)>6'),'platform order limits shared categories to six');
check(str_contains($platform,'parent_id IS NULL'),'platform categories use same top-level service category scope');
check(str_contains($platform,'DELETE FROM order_categories'),'platform order replaces legacy single-category persistence');
check(str_contains($platform,'category_names_snapshot=:names'),'platform order saves full category snapshot');
check(str_contains($platform,"payment_status='not_required'"),'platform managed order is not marked as online paid');
check(str_contains($platform,'PlatformOrderCommonFactsWriter'),'platform order connects shared dynamic facts writer');
check(str_contains($platform,"BINARY s.schema_key=BINARY 'order_submit'"),'platform order validates order_submit schema');
check(str_contains($platform,'$this->db->transaction'),'platform shared facts participate in outer transaction');

check(str_contains($facts,'INSERT INTO order_answers'),'platform shared dynamic answers are persisted');
check(str_contains($facts,'quantity_text=:quantity'),'platform shared quantity snapshot is persisted');
check(str_contains($facts,'expected_visit_at=:expected'),'platform shared visit time snapshot is persisted');
check(str_contains($facts,'FORM_REQUIRED_FIELD_MISSING'),'platform shared dynamic required fields are enforced');

check(str_contains($payment,"'ORDER_FINALIZE_REQUIRED'"),'proxy booking payment exposes stable finalize-required code');
check(str_contains($payment,'请先完整提交订单资料和必传图片'),'payment adapter maps only ordinary pre-finalize guard');

echo "proxy/platform unified entry isolated regression OK\n";
