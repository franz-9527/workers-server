<?php

declare(strict_types=1);

(static function (): void {
    $root = dirname(__DIR__);
    $read = static function (string $path) use ($root): string {
        $content = @file_get_contents($root . '/' . $path);
        if (!is_string($content)) throw new RuntimeException('Identity/Worker migration: missing ' . $path);
        return $content;
    };
    $assert = static function (bool $ok, string $message): void {
        if (!$ok) throw new RuntimeException('Identity/Worker migration: ' . $message);
    };

    $module = $read('server/app/Http/Api/Modules/IdentityWorkerApiModule.php');
    $kernel = $read('server/app/Http/Api/ApiKernel.php');
    $legacy = $read('server/app/Http/Api/Modules/LegacyApiModule.php');
    $composition = $read('server/app/Foundation/IdentityWorkerComposition.php');
    $access = $read('server/app/Application/Identity/IdentityAccessOperations.php');
    $workers = $read('server/app/Application/Identity/WorkerDirectoryOperations.php');
    $repository = $read('server/app/Infrastructure/Persistence/DatabaseIdentityRepository.php');

    $routes = [
        'auth.wechat','auth.phone','membership.summary','workers.map','workers.detail',
        'workers.phone-reveal.policy','workers.phone-reveal.authorize','workers.phone-reveal',
        'workers.search','worker.profile','worker.profile.save',
    ];
    foreach ($routes as $route) {
        $assert(strpos($module, "'".$route."'") !== false, 'formal module missing route '.$route);
        $assert(strpos($legacy, "'".$route."'") !== false, 'Legacy anti-fallback missing route '.$route);
    }

    $assert(!is_file($root.'/server/app/Controllers/ApiController.php'), 'retired ApiController.php must stay deleted');
    $assert(strpos($kernel, 'new IdentityWorkerApiModule($db)') !== false, 'ApiKernel did not register formal Identity/Worker owner');
    $assert(strpos($module, "if(\$route==='auth.wechat')") !== false, 'auth.wechat must remain unauthenticated before bearer resolution');
    $assert(strpos($module, "['auth.phone','worker.profile.save']") !== false, 'demo external-side-effect protection was not retained');
    foreach (['bootstrap','admin.worker.update','admin.worker.review','admin.worker.sms-stats','admin.store.review','admin.review.records','merchant.profile'] as $route) {
        $assert(strpos($module, "'".$route."'") === false, 'Identity/Worker board must not absorb '.$route);
    }

    $assert(strpos($composition, 'LegacyWeChatIdentityGateway') !== false, 'composition must isolate legacy WeChat service behind adapter');
    $assert(strpos($composition, 'LegacyWorkerCloudGateway') !== false, 'composition must isolate worker cloud gateway behind adapter');
    $assert(strpos($composition, 'DatabaseIdentityRepository') !== false, 'composition missing consolidated identity repository');
    $assert(strpos($composition, 'AgreementAcknowledgementRecorder') !== false, 'agreement acknowledgement audit must be composed outside Http');
    $assert(strpos($module, 'INSERT ') === false, 'Http module must not contain SQL');

    foreach (['WeChatCloudSyncService','WorkerCloudGatewayService','WorkerAdmin\\Infrastructure\\','WorkerAdmin\\Controllers\\'] as $forbidden) {
        $assert(strpos($access, $forbidden) === false, 'IdentityAccess Application has forbidden dependency '.$forbidden);
        $assert(strpos($workers, $forbidden) === false, 'WorkerDirectory Application has forbidden dependency '.$forbidden);
    }
    $assert(strpos($access, "server.owner.get") !== false, 'login must retain best-effort worker projection refresh');
    $assert(strpos($access, "server.membershipStatus") !== false, 'membership cloud verification was lost');
    $assert(strpos($workers, "server.publicWorkerSearch") !== false, 'public worker search cloud authority was lost');
    $assert(strpos($workers, "admin.detail") !== false, 'worker detail cloud authority was lost');
    $assert(strpos($workers, "server.owner.save") !== false, 'worker profile save cloud authority was lost');
    $assert(strpos($workers, "WORKER_PROFILE_EDIT_LIMIT") !== false, 'post-approval edit rate limit was lost');
    $assert(strpos($workers, "WORKER_PHONE_REVEAL_LIMIT_REACHED") !== false, 'phone reveal quota error was lost');
    $assert(strpos($workers, "worker-phone-reveal-mobile") !== false, 'phone reveal mobile HMAC context was lost');
    $assert(strpos($workers, "worker-phone-reveal-ip") !== false, 'phone reveal IP audit HMAC context was lost');

    $assert(strpos($repository, "INTERVAL 30 DAY") !== false, 'login session lifetime changed');
    $assert(strpos($repository, "worker_profile_edit_after_approval") !== false, 'worker profile edit reservation ledger was lost');
    $assert(strpos($repository, "FOR UPDATE") !== false, 'identity concurrency locks were lost');
    $assert(strpos($repository, "COUNT(DISTINCT worker_cloud_id)") !== false, '24h distinct-worker reveal quota was lost');
    $assert(strpos($repository, "ADMIN_VIEW") !== false, 'administrator phone reveal audit was lost');
    $assert(strpos($repository, "DATE_ADD(UTC_TIMESTAMP(),INTERVAL 90 SECOND)") !== false, '90-second reveal token lifetime changed');
    $assert(strpos($repository, "profile_source='cloud'") !== false, 'cloud-authoritative worker projection marker was lost');

    echo "Identity/Worker board migration tests passed.\n";
})();
