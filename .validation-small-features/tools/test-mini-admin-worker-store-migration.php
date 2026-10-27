<?php

declare(strict_types=1);

(static function (): void {
    $root = dirname(__DIR__);
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'WorkerAdmin\\';
        if (strpos($class, $prefix) !== 0) return;
        $file = $root.'/server/app/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) require_once $file;
    });
    $assert = static function (bool $ok, string $message): void {
        if (!$ok) throw new RuntimeException('Mini admin worker/store migration: '.$message);
    };

    final class MiniAdminWorkerStoreFakeRepository implements \WorkerAdmin\Contracts\MiniAdminWorkerStoreRepository
    {
        public array $calls = [];
        public function updateWorker(string $cloudId, array $patch): array
        {
            $this->calls[] = ['update',$cloudId,$patch];
            return ['worker'=>['status'=>'approved'],'local_worker_id'=>9];
        }
        public function workerSmsStats(array $actor, string $cloudId): array
        {
            $this->calls[] = ['sms',$actor,$cloudId];
            return ['loaded'=>true,'mobile_available'=>true,'attempt_count'=>3,'hide_threshold'=>10,'reached_threshold'=>false,'message'=>''];
        }
        public function reviewWorker(string $cloudId, string $decision, string $reason): array
        {
            $this->calls[] = ['review_worker',$cloudId,$decision,$reason];
            return ['worker'=>['workerName'=>'测试师傅','status'=>$decision],'local_worker_id'=>12];
        }
        public function reviewStore(string $cloudId, string $decision): array
        {
            $this->calls[] = ['review_store',$cloudId,$decision];
            return ['published'=>$decision === 'approved'];
        }
        public function reviewRecords(string $source, int $page, int $pageSize, string $keyword): array
        {
            $this->calls[] = ['records',$source,$page,$pageSize,$keyword];
            return ['items'=>[],'page'=>$page,'page_size'=>$pageSize,'total'=>0,'has_more'=>false];
        }
    }

    $repo = new MiniAdminWorkerStoreFakeRepository();
    $app = new \WorkerAdmin\Application\Admin\MiniAdminWorkerStoreOperations($repo);
    $actor = ['user_id'=>1,'payer_openid'=>'admin-openid','is_admin'=>true];

    $result = $app->execute('admin.worker.update', $actor, [
        'cloud_id'=>'CW-1',
        'patch'=>['workerName'=>'新名称','skills'=>['贴墙布'],'mobile'=>'13800000000'],
    ]);
    $assert($result['message'] === '资料已保存', 'worker update message changed');
    $assert($repo->calls[0][2] === ['workerName'=>'新名称','skills'=>['贴墙布']], 'worker update whitelist changed');

    try {
        $app->execute('admin.worker.update', $actor, ['cloud_id'=>'CW-1','patch'=>['skills'=>'bad']]);
        $assert(false, 'invalid skills must fail');
    } catch (\WorkerAdmin\Domain\Admin\MiniAdminOperationException $exception) {
        $assert($exception->apiCode() === 'WORKER_ADMIN_UPDATE_SKILLS_INVALID', 'skills api code changed');
    }

    try {
        $app->execute('admin.worker.review', $actor, ['cloud_id'=>'CW-2','decision'=>'rejected','reason'=>'']);
        $assert(false, 'rejected worker without reason must fail');
    } catch (\WorkerAdmin\Domain\Admin\MiniAdminOperationException $exception) {
        $assert($exception->apiCode() === 'WORKER_REVIEW_REASON_REQUIRED', 'worker review reason api code changed');
    }

    $result = $app->execute('admin.worker.review', $actor, ['cloud_id'=>'CW-2','decision'=>'approved']);
    $assert($result['message'] === '审核通过，师傅现在可以接单报价', 'approved worker message changed');
    $assert($result['data']['local_worker_id'] === 12, 'local worker projection id lost');

    $result = $app->execute('admin.store.review', $actor, ['cloud_id'=>'CS-1','decision'=>'approved']);
    $assert($result['message'] === '门店已审核通过' && $result['data']['published'] === true, 'store review response changed');

    $result = $app->execute('admin.review.records', $actor, ['source'=>'worker','page'=>0,'page_size'=>99,'keyword'=>str_repeat('a', 90)]);
    $call = $repo->calls[count($repo->calls)-1];
    $assert($call[2] === 1 && $call[3] === 50 && strlen($call[4]) === 80, 'review records normalization changed');

    $module = (string)file_get_contents($root.'/server/app/Http/Api/Modules/MiniAdminWorkerStoreApiModule.php');
    $legacy = (string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');
    $kernel = (string)file_get_contents($root.'/server/app/Http/Api/ApiKernel.php');
    $persistence = (string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseMiniAdminWorkerStoreRepository.php');
    $controller = (string)file_get_contents($root.'/server/app/Controllers/ApiController.php');
    $reviewPage = (string)file_get_contents($root.'/miniprogram/subpackages/account/review/index.js');
    $workerDetail = (string)file_get_contents($root.'/miniprogram/pages/record/detail.wxml');
    $routes = [
        'admin.worker.update'=>'adminWorkerUpdate',
        'admin.worker.review'=>'adminWorkerReview',
        'admin.worker.sms-stats'=>'adminWorkerSmsStats',
        'admin.store.review'=>'adminStoreReview',
        'admin.review.records'=>'adminReviewRecords',
    ];
    foreach ($routes as $route=>$handler) {
        $assert(strpos($module, "'".$route."'") !== false, 'formal module missing '.$route);
        $assert(strpos($legacy, "'".$route."'") !== false, 'Legacy anti-fallback missing '.$route);
        $assert(strpos($controller, "'".$route."'") === false, 'retired controller route reintroduced '.$route);
        $assert(strpos($controller, 'function '.$handler.'(') === false, 'retired controller handler reintroduced '.$handler);
    }
    $assert(strpos($kernel, 'new MiniAdminWorkerStoreApiModule($db)') !== false, 'ApiKernel missing formal module');
    $assert(strpos($persistence, "'admin.update'") !== false, 'worker update cloud action lost');
    $assert(strpos($persistence, "'admin.review'") !== false, 'worker review cloud action lost');
    $assert(strpos($persistence, "'server.storeReview'") !== false, 'store review cloud action lost');
    $assert(strpos($persistence, "'server.phoneSearchIds'") !== false, 'phone search compatibility chain lost');
    $assert(strpos($persistence, 'worker_verifications') !== false, 'worker verification history lost');
    $assert(strpos($persistence, 'OrderNotificationService::queueWorkerReviewResult') !== false, 'worker review notification lost');
    $assert(strpos($persistence, 'cloud_identity_mappings') !== false, 'worker cloud identity projection lost');

    // 发布审核交互与通知状态属于 mini-admin 同一职责，静态守卫收敛在现有迁移测试，不另建一次性 PHP 测试文件。
    $assert(strpos($reviewPage, "this.setData({ keyword: '' }") !== false, 'pull-down refresh must clear review search');
    $assert(strpos($reviewPage, "OrderApi.request('admin.review.records'") !== false, 'review list must reconcile server notification status');
    $assert(strpos($reviewPage, 'SERVER_OUTBOX_QUEUED') !== false, 'review list must recognize queued outbox state');
    $assert(strpos($persistence, 'mergeServerReviewNoticeStatus') !== false, 'server review records must merge delivery status');
    $assert(strpos($persistence, "topic='wechat_subscribe'") !== false, 'review delivery status must read subscribe outbox');
    $assert(strpos($persistence, "'reached_threshold'=>false") !== false, 'empty phone stats must keep safe threshold default');
    $assert(strpos($workerDetail, '(isOrderAdmin && detail.isRealWorker==1)') !== false, 'admin must not see review banner for imported/demo worker');

    echo "Mini admin worker/store migration tests passed.\n";
})();