<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Agreements\PlatformAgreements;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Http\Admin\AdminModule;

final class AgreementAdminModule implements AdminModule
{
    private Auth $auth;
    private Csrf $csrf;
    private PlatformAgreements $agreements;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->agreements = (new ApplicationContainer($db))->agreements();
    }

    public function supports(string $route): bool
    {
        return $route === 'agreements' || strpos($route, 'agreements.') === 0;
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $user = $this->auth->user();
        if (!empty($user['must_change_password'])) {
            \flash('warning', '首次登录必须先修改演示密码。');
            \redirect(\admin_url('profile'));
        }
        $this->auth->requirePermission('settings.manage');

        $map = [
            'agreements' => 'index',
            'agreements.save' => 'save',
            'agreements.revise-initial' => 'reviseInitial',
            'agreements.lock-initial' => 'lockInitial',
            'agreements.publish' => 'publish',
        ];
        if (!isset($map[$route])) {
            http_response_code(404);
            echo \view('errors/404', ['pageTitle' => '页面不存在']);
            return;
        }

        try {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') $this->csrf->verifyRequest();
            $method = $map[$route];
            $this->{$method}();
        } catch (RuntimeException $exception) {
            \flash('danger', $exception->getMessage());
            \redirect(\admin_url('agreements', ['key' => (string) ($_POST['key'] ?? $_GET['key'] ?? '')]));
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('agreement-admin', '[' . date('c') . '] request_id=' . $requestId . ' ' . $exception . "\n");
            \flash('danger', '协议处理失败，请稍后重试。请求编号：' . $requestId);
            \redirect(\admin_url('agreements'));
        }
    }

    private function index(): void
    {
        $documents = $this->agreements->allDocuments();
        if (!$documents) throw new RuntimeException('协议配置初始化失败。');

        $key = trim((string) ($_GET['key'] ?? ''));
        $selected = null;
        foreach ($documents as $document) {
            if ($key !== '' && ($document['key'] ?? '') === $key) { $selected = $document; break; }
        }
        if (!$selected) { $selected = $documents[0]; $key = (string) $selected['key']; }

        $current = is_array($selected['current'] ?? null) ? $selected['current'] : [];
        $draft = is_array($selected['draft'] ?? null) ? $selected['draft'] : null;
        $editor = [
            'title' => (string) (($draft['title'] ?? '') ?: ($current['title'] ?? $selected['title'] ?? '')),
            'summary' => (string) ($selected['summary'] ?? ''),
            'document_type' => (string) ($selected['document_type'] ?? 'agreement'),
            'category' => (string) ($selected['category'] ?? 'core'),
            'confirmation_mode' => (string) ($selected['confirmation_mode'] ?? 'notice_only'),
            'enabled' => !empty($selected['enabled']),
            'important_notice' => (string) (($draft['important_notice'] ?? '') ?: ($current['important_notice'] ?? $selected['important_notice'] ?? '')),
            'content' => (string) (($draft['content'] ?? '') ?: ($current['content'] ?? '')),
            'content_format' => (string) (($draft['content_format'] ?? '') ?: ($current['content_format'] ?? $selected['content_format'] ?? 'plain')),
            'change_level' => (string) ($draft['change_level'] ?? 'standard'),
            'requires_reacceptance' => !empty($draft['requires_reacceptance']),
        ];
        $versions = array_reverse((array) ($selected['versions'] ?? []));
        $initialRevisionAllowed = !empty($selected['initial_revision_allowed']);

        $defaultNoticeDays = $editor['change_level'] === 'major' ? 15 : 7;
        $defaultEffective = (new DateTimeImmutable('+' . $defaultNoticeDays . ' days +5 minutes', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone((string) \app_config('app.timezone', 'Asia/Shanghai')))
            ->format('Y-m-d\TH:i');

        echo \view('admin/agreements', [
            'pageTitle' => '协议与规则',
            'activeMenu' => 'agreements',
            'documents' => $documents,
            'selected' => $selected,
            'editor' => $editor,
            'versions' => $versions,
            'initialRevisionAllowed' => $initialRevisionAllowed,
            'defaultEffective' => $defaultEffective,
            'defaultNoticeDays' => $defaultNoticeDays,
            'categoryLabels' => [
                'core' => '核心协议','privacy' => '隐私与数据','safety' => '安全协议',
                'transaction' => '交易规则','settlement' => '收费与结算','other' => '其他',
            ],
            'confirmationLabels' => [
                'notice_only' => '仅公示','first_use' => '首次使用确认','order_submit' => '下单前确认',
                'worker_action' => '师傅关键操作确认','worker_onboarding' => '师傅入驻确认',
            ],
        ]);
    }

    private function save(): void
    {
        $key = trim((string) ($_POST['key'] ?? ''));
        $admin = $this->auth->user();
        $this->agreements->saveDraft($key, [
            'title' => (string) ($_POST['title'] ?? ''),
            'summary' => (string) ($_POST['summary'] ?? ''),
            'document_type' => (string) ($_POST['document_type'] ?? 'agreement'),
            'category' => (string) ($_POST['category'] ?? 'core'),
            'confirmation_mode' => (string) ($_POST['confirmation_mode'] ?? 'notice_only'),
            'enabled' => isset($_POST['enabled']) ? '1' : '0',
            'important_notice' => (string) ($_POST['important_notice'] ?? ''),
            'content' => (string) ($_POST['content'] ?? ''),
            'content_format' => (string) ($_POST['content_format'] ?? 'html'),
            'change_level' => (string) ($_POST['change_level'] ?? 'standard'),
            'requires_reacceptance' => isset($_POST['requires_reacceptance']) ? '1' : '0',
        ], (int) ($admin['id'] ?? 0));
        \flash('success', '草稿已保存。');
        \redirect(\admin_url('agreements', ['key' => $key]));
    }

    private function reviseInitial(): void
    {
        $key = trim((string) ($_POST['key'] ?? ''));
        if ((string)($_POST['confirm_prelaunch'] ?? '') !== '1') throw new RuntimeException('请确认这是正式运营前的第一版修订。');
        $admin = $this->auth->user();
        $version = $this->agreements->reviseInitialVersion($key, (int)($admin['id'] ?? 0));
        \audit('agreement.initial_revision','agreement',$key,'正式运营前原地修订第一版 '.$version);
        \flash('success', '第一版 ' . $version . ' 已更新，没有新增 V2；正式运营前可继续修改。');
        \redirect(\admin_url('agreements', ['key' => $key]));
    }

    private function lockInitial(): void
    {
        $key = trim((string) ($_POST['key'] ?? ''));
        if ((string)($_POST['confirm_lock_initial'] ?? '') !== '1') throw new RuntimeException('请确认结束上线前首版定稿阶段。');
        $admin = $this->auth->user();
        $version = $this->agreements->lockInitialVersion($key, (int)($admin['id'] ?? 0));
        \audit('agreement.initial_revision.lock','agreement',$key,'结束上线前首版定稿阶段 '.$version);
        \flash('success', '首版 ' . $version . ' 已定稿。后续修改将按 V2/V3 正式版本公示发布，历史版本不再原地覆盖。');
        \redirect(\admin_url('agreements', ['key' => $key]));
    }

    private function publish(): void
    {
        $key = trim((string) ($_POST['key'] ?? ''));
        $document = $this->agreements->document($key);
        if (!empty($document['initial_revision_allowed'])) throw new RuntimeException('当前仍处于首版定稿阶段，请先“更新第一版”并结束首版定稿，再创建 V2。');

        $raw = trim((string) ($_POST['effective_at'] ?? ''));
        if ($raw === '') throw new RuntimeException('请选择生效时间。');
        $noticeDays = (int) ($_POST['notice_days'] ?? 15);
        if (!in_array($noticeDays, [7, 15], true)) throw new RuntimeException('公示期限只能选择 7 日或 15 日。');

        $draft = is_array($document['draft'] ?? null) ? $document['draft'] : null;
        if (!$draft) throw new RuntimeException('当前没有待发布草稿，请先保存草稿。');
        if ((string) ($draft['change_level'] ?? 'standard') === 'major' && $noticeDays < 15) throw new RuntimeException('重大权益变化至少按 15 日征求意见和公示，请选择 15 日后再发布。');

        $timezone = new DateTimeZone((string) \app_config('app.timezone', 'Asia/Shanghai'));
        $local = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, $timezone);
        if (!$local) throw new RuntimeException('生效时间格式不正确。');
        $minimumLocal = new DateTimeImmutable('+' . $noticeDays . ' days', $timezone);
        if ($local < $minimumLocal) throw new RuntimeException('当前选择的是至少提前 ' . $noticeDays . ' 日公示，请将生效时间调整到 ' . $noticeDays . ' 日之后。');

        $effectiveUtc = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $admin = $this->auth->user();
        $version = $this->agreements->publishDraft($key, $effectiveUtc, (int) ($admin['id'] ?? 0));
        \flash('success', '协议版本 ' . $version . ' 已发布并进入 ' . $noticeDays . ' 日征求意见 / 公示期，届时自动生效。');
        \redirect(\admin_url('agreements', ['key' => $key]));
    }
}
