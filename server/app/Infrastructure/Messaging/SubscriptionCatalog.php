<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;



final class SubscriptionCatalog
{
    use OrderSubscriptionDefinitionOperations;

    private const LEGACY_REVIEW_TEMPLATE = 'b7RKsUBxQu3BWwMEwzw-oFHkw1K9m4egjVFSLQ5rWMY';
    private const REVIEW_FIELD_MAP = [
        'worker_name'=>'name1',
        'applicant_mobile'=>'phone_number2',
        'status'=>'phrase3',
        'notice_text'=>'thing5',
    ];
    private const REVIEW_SEMANTICS = ['worker_name','applicant_mobile','status','audit_note','notice_text','time'];

}
