<?php

declare(strict_types=1);

namespace WorkerAdmin\Controllers;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;

/**
 * V2.4.0 帮助中心后台。
 * 已发布问答保存草稿时只写 draft_payload，线上仍展示最后一次发布版本。
 */
final class HelpCenterAdminController
{
    private Database $db;
    private Auth $auth;
    private Csrf $csrf;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->db = $db;
        $this->auth = $auth;
        $this->csrf = $csrf;
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $user = $this->auth->user();
        if (!empty($user['must_change_password'])) {
            \flash('warning', '首次登录必须先修改演示密码。');
            \redirect(\admin_url('profile'));
        }
        $this->auth->requirePermission('help.manage');

        $routes = [
            'help' => 'index',
            'help.item' => 'item',
            'help.save' => 'save',
            'help.action' => 'action',
            'help.category.save' => 'categorySave',
            'help.category.toggle' => 'categoryToggle',
        ];
        if (!isset($routes[$route])) {
            http_response_code(404);
            echo \view('errors/404', ['pageTitle' => '页面不存在']);
            return;
        }

        try {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
                $this->csrf->verifyRequest();
            }
            $method = $routes[$route];
            $this->{$method}();
        } catch (RuntimeException $exception) {
            \flash('danger', $exception->getMessage());
            $this->redirectAfterFailure($route);
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('help-admin', '[' . date('c') . '] request_id=' . $requestId . ' ' . $exception . "\n");
            \flash('danger', '帮助中心处理失败，请稍后重试。请求编号：' . $requestId);
            $this->redirectAfterFailure($route);
        }
    }

    private function index(): void
    {
        $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
        $role = $this->roleKey((string) ($_GET['role'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $categoryId = max(0, (int) ($_GET['category_id'] ?? 0));
        $editingId = max(0, (int) ($_GET['id'] ?? 0));
        $editingCategoryId = max(0, (int) ($_GET['category_edit_id'] ?? 0));

        $where = ['f.deleted_at IS NULL'];
        $params = [];
        if ($query !== '') {
            $pattern = '%' . $query . '%';
            $searchWhere = [
                'f.question LIKE :q_question',
                'f.answer LIKE :q_answer',
                'f.keywords LIKE :q_keywords',
                'f.aliases LIKE :q_aliases',
            ];
            $params['q_question'] = $pattern;
            $params['q_answer'] = $pattern;
            $params['q_keywords'] = $pattern;
            $params['q_aliases'] = $pattern;
            // faq_id 使用 ascii_bin；中文搜索不参与该列比较，避免字符集转换异常。
            if (preg_match('/^[A-Za-z0-9_-]+$/D', $query) === 1) {
                $searchWhere[] = 'f.faq_id LIKE :q_faq_id';
                $params['q_faq_id'] = $pattern;
            }
            $where[] = '(' . implode(' OR ', $searchWhere) . ')';
        }
        if ($role !== '') {
            $where[] = "(f.roles='all' OR FIND_IN_SET(:role,f.roles)>0)";
            $params['role'] = $role;
        }
        if (in_array($status, ['draft', 'published', 'withdrawn', 'archived'], true)) {
            $where[] = 'f.status=:status';
            $params['status'] = $status;
        }
        if ($categoryId > 0) {
            $where[] = 'f.category_id=:category_id';
            $params['category_id'] = $categoryId;
        }

        $faqs = $this->db->fetchAll(
            "SELECT f.*,c.name AS category_name,c.code AS category_code
             FROM help_faqs f
             INNER JOIN help_categories c ON c.id=f.category_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY f.is_featured DESC,c.sort_order ASC,f.sort_order ASC,f.id ASC
             LIMIT 300",
            $params
        );
        foreach ($faqs as &$faq) {
            $faq['has_draft'] = trim((string) ($faq['draft_payload'] ?? '')) !== '';
            $draftPayload = $faq['has_draft'] ? json_decode((string) $faq['draft_payload'], true) : null;
            $faq['draft_is_featured'] = is_array($draftPayload) ? (!empty($draftPayload['is_featured']) ? 1 : 0) : null;
            $faq['display_is_featured'] = $faq['draft_is_featured'] !== null
                ? (int) $faq['draft_is_featured']
                : (!empty($faq['is_featured']) ? 1 : 0);
            $faq['featured_pending'] = $faq['draft_is_featured'] !== null
                && (int) $faq['draft_is_featured'] !== (!empty($faq['is_featured']) ? 1 : 0);
        }
        unset($faq);

        $categories = $this->db->fetchAll(
            "SELECT c.*
             FROM help_categories c
             ORDER BY c.sort_order ASC,c.id ASC"
        );

        $editing = null;
        $editor = $this->blankEditor();
        if ($editingId > 0) {
            $editing = $this->db->fetch('SELECT * FROM help_faqs WHERE id=:id AND deleted_at IS NULL LIMIT 1', ['id' => $editingId]);
            if (!$editing) {
                throw new RuntimeException('要编辑的问答不存在或已删除。');
            }
            $editor = $this->effectiveEditor($editing);
        }

        $editingCategory = null;
        if ($editingCategoryId > 0) {
            $editingCategory = $this->db->fetch('SELECT * FROM help_categories WHERE id=:id LIMIT 1', ['id' => $editingCategoryId]);
        }

        $zeroSearches = $this->db->fetchAll(
            "SELECT query_text,COUNT(*) AS hits,MAX(created_at) AS last_at
             FROM help_search_logs
             WHERE result_count=0 AND query_text<>'' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)
             GROUP BY query_text
             ORDER BY hits DESC,last_at DESC
             LIMIT 30"
        );

        $stats = $this->db->fetch(
            "SELECT
                COUNT(*) AS total_count,
                SUM(status='draft') AS draft_count,
                SUM(status='published') AS published_count,
                SUM(status='withdrawn') AS withdrawn_count,
                SUM(status='archived') AS archived_count,
                SUM(status='published' AND draft_payload IS NOT NULL AND draft_payload<>'') AS pending_update_count
             FROM help_faqs WHERE deleted_at IS NULL"
        ) ?: [];

        echo \view('admin/help-center', [
            'pageTitle' => '帮助中心',
            'activeMenu' => 'help',
            'faqs' => $faqs,
            'categories' => $categories,
            'editing' => $editing,
            'editor' => $editor,
            'editingCategory' => $editingCategory,
            'zeroSearches' => $zeroSearches,
            'stats' => $stats,
            'filters' => [
                'q' => $query,
                'role' => $role,
                'status' => $status,
                'category_id' => $categoryId,
            ],
            'roles' => $this->roles(),
        ]);
    }


    private function item(): void
    {
        $id = max(0, (int) ($_GET['id'] ?? 0));
        $faq = $id > 0
            ? $this->db->fetch('SELECT * FROM help_faqs WHERE id=:id AND deleted_at IS NULL LIMIT 1', ['id' => $id])
            : null;
        if (!$faq) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => '问答不存在或已删除。'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'item' => [
                'id' => (int) $faq['id'],
                'faq_id' => (string) $faq['faq_id'],
                'status' => (string) $faq['status'],
                'has_draft' => trim((string) ($faq['draft_payload'] ?? '')) !== '',
                'editor' => $this->effectiveEditor($faq),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function save(): void
    {
        $this->postOnly();
        $intent = (string) ($_POST['intent'] ?? 'draft');
        if (!in_array($intent, ['draft', 'publish'], true)) {
            throw new RuntimeException('保存方式无效。');
        }

        $id = max(0, (int) ($_POST['id'] ?? 0));
        $payload = $this->payloadFromPost();
        $existing = $id > 0 ? $this->db->fetch('SELECT * FROM help_faqs WHERE id=:id AND deleted_at IS NULL LIMIT 1', ['id' => $id]) : null;
        if ($id > 0 && !$existing) {
            throw new RuntimeException('问答不存在或已删除。');
        }
        $duplicate = $this->db->fetch(
            'SELECT id,faq_id FROM help_faqs WHERE deleted_at IS NULL AND question=:question AND id<>:id LIMIT 1',
            ['question' => $payload['question'], 'id' => $id]
        );
        if ($duplicate) {
            throw new RuntimeException('已存在相同问题：' . $duplicate['faq_id'] . '，请编辑原问答或增加搜索别名。');
        }

        $admin = $this->auth->user();
        $adminId = (int) $admin['id'];

        if (!$existing) {
            $faqId = $this->nextFaqId();
            $id = $this->db->insert(
                "INSERT INTO help_faqs(
                    faq_id,category_id,question,answer,aliases,keywords,roles,sort_order,is_featured,status,
                    related_faq_ids,business_entry_label,business_entry_path,draft_payload,version,published_version,published_at,
                    created_by,updated_by,deleted_at,created_at,updated_at
                 ) VALUES(
                    :faq_id,:category_id,:question,:answer,:aliases,:keywords,:roles,:sort_order,:is_featured,'draft',
                    :related_faq_ids,:business_entry_label,:business_entry_path,NULL,1,0,NULL,:created_by,:updated_by,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()
                 )",
                array_merge($payload, ['faq_id' => $faqId, 'created_by' => $adminId, 'updated_by' => $adminId])
            );
            $existing = $this->db->fetch('SELECT * FROM help_faqs WHERE id=:id LIMIT 1', ['id' => $id]);
        }

        if ($intent === 'draft' && (string) $existing['status'] === 'published') {
            $this->db->execute(
                'UPDATE help_faqs SET draft_payload=:draft_payload,updated_by=:admin_id,updated_at=UTC_TIMESTAMP() WHERE id=:id',
                [
                    'draft_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'admin_id' => $adminId,
                    'id' => $id,
                ]
            );
            \audit('help.draft.save', 'help_faq', $id, '保存帮助问答待发布修改');
            \flash('success', '草稿修改已保存，线上仍保持最后一次发布内容。');
            \redirect(\admin_url('help', array_merge($this->returnFilters(), ['id' => $id])));
        }

        if ($intent === 'draft') {
            $this->applyPayload($id, $payload, 'draft', $adminId, false);
            \audit('help.draft.save', 'help_faq', $id, '保存帮助问答草稿');
            \flash('success', '草稿已保存，尚未对小程序发布。');
            \redirect(\admin_url('help', array_merge($this->returnFilters(), ['id' => $id])));
        }

        $this->publishPayload($id, $payload, $adminId);
        \audit('help.publish', 'help_faq', $id, '发布帮助问答');
        \flash('success', '问答已发布，小程序将读取新版本。');
        \redirect(\admin_url('help', array_merge($this->returnFilters(), ['id' => $id])));
    }

    private function action(): void
    {
        $this->postOnly();
        $id = max(0, (int) ($_POST['id'] ?? 0));
        $action = (string) ($_POST['action'] ?? '');
        $faq = $this->db->fetch('SELECT * FROM help_faqs WHERE id=:id AND deleted_at IS NULL LIMIT 1', ['id' => $id]);
        if (!$faq) {
            throw new RuntimeException('问答不存在或已删除。');
        }
        $admin = $this->auth->user();
        $adminId = (int) $admin['id'];

        if ($action === 'publish_draft') {
            $draft = json_decode((string) ($faq['draft_payload'] ?? ''), true);
            if (!is_array($draft)) {
                throw new RuntimeException('没有待发布修改。');
            }
            $payload = $this->normalizeStoredPayload($draft);
            $this->publishPayload($id, $payload, $adminId);
            \audit('help.publish', 'help_faq', $id, '发布帮助问答待发布修改');
            \flash('success', '待发布修改已上线。');
        } elseif ($action === 'withdraw') {
            $this->db->execute("UPDATE help_faqs SET status='withdrawn',draft_payload=NULL,updated_by=:admin_id,updated_at=UTC_TIMESTAMP() WHERE id=:id", ['admin_id' => $adminId, 'id' => $id]);
            $this->recordVersion($id, 'withdraw', $adminId);
            \audit('help.withdraw', 'help_faq', $id, '撤稿帮助问答');
            \flash('success', '问答已撤稿，小程序不再展示。');
        } elseif ($action === 'archive') {
            $this->db->execute("UPDATE help_faqs SET status='archived',draft_payload=NULL,updated_by=:admin_id,updated_at=UTC_TIMESTAMP() WHERE id=:id", ['admin_id' => $adminId, 'id' => $id]);
            $this->recordVersion($id, 'archive', $adminId);
            \audit('help.archive', 'help_faq', $id, '归档帮助问答');
            \flash('success', '问答已归档。');
        } elseif ($action === 'delete') {
            $this->db->execute('UPDATE help_faqs SET deleted_at=UTC_TIMESTAMP(),updated_by=:admin_id,updated_at=UTC_TIMESTAMP() WHERE id=:id', ['admin_id' => $adminId, 'id' => $id]);
            \audit('help.delete', 'help_faq', $id, '软删除帮助问答');
            \flash('success', '问答已软删除，稳定编号仍保留在历史审计中。');
        } elseif ($action === 'copy') {
            $payload = $this->effectiveEditor($faq);
            $newId = $this->db->insert(
                "INSERT INTO help_faqs(
                    faq_id,category_id,question,answer,aliases,keywords,roles,sort_order,is_featured,status,
                    related_faq_ids,business_entry_label,business_entry_path,draft_payload,version,published_version,published_at,
                    created_by,updated_by,deleted_at,created_at,updated_at
                 ) VALUES(
                    :faq_id,:category_id,:question,:answer,:aliases,:keywords,:roles,:sort_order,0,'draft',
                    :related_faq_ids,:business_entry_label,:business_entry_path,NULL,1,0,NULL,:created_by,:updated_by,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()
                 )",
                array_merge($payload, [
                    'faq_id' => $this->nextFaqId(),
                    'question' => $payload['question'] . '（副本）',
                    'created_by' => $adminId,
                    'updated_by' => $adminId,
                ])
            );
            \audit('help.copy', 'help_faq', $newId, '复制帮助问答');
            \flash('success', '已复制为新草稿。');
            \redirect(\admin_url('help', array_merge($this->returnFilters(), ['id' => $newId])));
        } else {
            throw new RuntimeException('不支持的问答操作。');
        }

        \redirect(\admin_url('help', $this->returnFilters()));
    }

    private function categorySave(): void
    {
        $this->postOnly();
        $id = max(0, (int) ($_POST['category_id'] ?? 0));
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
        $code = strtolower(trim((string) ($_POST['code'] ?? '')));
        $sort = (int) ($_POST['sort_order'] ?? 0);
        $homepageFeatured = !empty($_POST['homepage_featured']) ? 1 : 0;
        if ($name === '' || !preg_match('/^[a-z0-9-]{2,48}$/', $code)) {
            throw new RuntimeException('场景名称不能为空，编码只允许小写字母、数字和短横线。');
        }

        if ($homepageFeatured) {
            $featuredCount = (int) ($this->db->fetchValue(
                'SELECT COUNT(*) FROM help_categories WHERE homepage_featured=1 AND id<>:id',
                ['id' => $id]
            ) ?: 0);
            if ($featuredCount >= 4) {
                throw new RuntimeException('首页优先场景最多只能设置 4 个，请先取消其他场景。');
            }
        }

        if ($id > 0) {
            $this->db->execute(
                'UPDATE help_categories SET code=:code,name=:name,sort_order=:sort_order,homepage_featured=:homepage_featured,updated_at=UTC_TIMESTAMP() WHERE id=:id',
                ['code' => $code, 'name' => $name, 'sort_order' => $sort, 'homepage_featured' => $homepageFeatured, 'id' => $id]
            );
            \audit('help.category.update', 'help_category', $id, '更新帮助场景');
        } else {
            $id = $this->db->insert(
                "INSERT INTO help_categories(code,name,sort_order,homepage_featured,status,created_at,updated_at)
                 VALUES(:code,:name,:sort_order,:homepage_featured,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['code' => $code, 'name' => $name, 'sort_order' => $sort, 'homepage_featured' => $homepageFeatured]
            );
            \audit('help.category.create', 'help_category', $id, '新增帮助场景');
        }
        \flash('success', '帮助场景已保存。');
        \redirect(\admin_url('help') . '#help-categories');
    }

    private function categoryToggle(): void
    {
        $this->postOnly();
        $id = max(0, (int) ($_POST['category_id'] ?? 0));
        $category = $this->db->fetch('SELECT * FROM help_categories WHERE id=:id LIMIT 1', ['id' => $id]);
        if (!$category) {
            throw new RuntimeException('场景不存在。');
        }
        $next = (string) $category['status'] === 'active' ? 'disabled' : 'active';
        $this->db->execute('UPDATE help_categories SET status=:status,updated_at=UTC_TIMESTAMP() WHERE id=:id', ['status' => $next, 'id' => $id]);
        \audit('help.category.toggle', 'help_category', $id, '切换帮助场景状态为 ' . $next);
        \flash('success', $next === 'active' ? '场景已启用。' : '场景已停用；其已发布问答会立即从小程序隐藏。');
        \redirect(\admin_url('help') . '#help-categories');
    }

    private function payloadFromPost(): array
    {
        $payload = [
            'category_id' => max(0, (int) ($_POST['category_id'] ?? 0)),
            'question' => mb_substr(trim((string) ($_POST['question'] ?? '')), 0, 255),
            'answer' => trim((string) ($_POST['answer'] ?? '')),
            'aliases' => trim((string) ($_POST['aliases'] ?? '')),
            'keywords' => trim((string) ($_POST['keywords'] ?? '')),
            'roles' => $this->normalizeRoles($_POST['roles'] ?? ['all']),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'is_featured' => !empty($_POST['is_featured']) ? 1 : 0,
            'related_faq_ids' => $this->normalizeFaqIds((string) ($_POST['related_faq_ids'] ?? '')),
            'business_entry_label' => mb_substr(trim((string) ($_POST['business_entry_label'] ?? '')), 0, 80),
            'business_entry_path' => mb_substr(trim((string) ($_POST['business_entry_path'] ?? '')), 0, 255),
        ];
        return $this->validatePayload($payload);
    }

    private function normalizeStoredPayload(array $payload): array
    {
        $defaults = $this->blankEditor();
        $payload = array_merge($defaults, $payload);
        $payload['category_id'] = max(0, (int) $payload['category_id']);
        $payload['sort_order'] = (int) $payload['sort_order'];
        $payload['is_featured'] = !empty($payload['is_featured']) ? 1 : 0;
        return $this->validatePayload($payload);
    }

    private function validatePayload(array $payload): array
    {
        if ($payload['question'] === '' || $payload['answer'] === '') {
            throw new RuntimeException('问题和回答不能为空。');
        }
        if (mb_strlen($payload['answer']) > 8000) {
            throw new RuntimeException('回答内容过长，请拆分为更清晰的独立问题。');
        }
        if ((int) $payload['category_id'] <= 0) {
            throw new RuntimeException('请选择有效场景。');
        }
        $category = $this->db->fetch('SELECT id FROM help_categories WHERE id=:id LIMIT 1', ['id' => $payload['category_id']]);
        if (!$category) {
            throw new RuntimeException('请选择有效场景。');
        }
        $path = (string) $payload['business_entry_path'];
        if ($path !== '' && preg_match('#^/(pages|subpackages)/[A-Za-z0-9_./?=&%-]+$#', $path) !== 1) {
            throw new RuntimeException('业务入口只允许填写小程序内部 /pages 或 /subpackages 路径。');
        }
        foreach (['answer', 'aliases', 'keywords'] as $field) {
            if (preg_match('/<\/?script|javascript:|data:text\/html/i', (string) $payload[$field])) {
                throw new RuntimeException('回答中包含不允许的脚本或可执行链接内容。');
            }
        }
        return $payload;
    }

    private function publishPayload(int $id, array $payload, int $adminId): void
    {
        $current = $this->db->fetch('SELECT version,published_version FROM help_faqs WHERE id=:id LIMIT 1', ['id' => $id]);
        if (!$current) {
            throw new RuntimeException('问答不存在。');
        }
        $newVersion = max((int) $current['version'], (int) $current['published_version']) + 1;
        $this->applyPayload($id, $payload, 'published', $adminId, true, $newVersion);
        $this->recordVersion($id, 'publish', $adminId, $newVersion);
    }

    private function applyPayload(int $id, array $payload, string $status, int $adminId, bool $published, ?int $version = null): void
    {
        $sql = "UPDATE help_faqs SET
                    category_id=:category_id,question=:question,answer=:answer,
                    aliases=:aliases,keywords=:keywords,roles=:roles,sort_order=:sort_order,is_featured=:is_featured,
                    status=:status,related_faq_ids=:related_faq_ids,business_entry_label=:business_entry_label,business_entry_path=:business_entry_path,
                    draft_payload=NULL,updated_by=:admin_id,updated_at=UTC_TIMESTAMP()";
        $params = array_merge($payload, ['status' => $status, 'admin_id' => $adminId, 'id' => $id]);
        if ($published) {
            $sql .= ',version=:version,published_version=:published_version,published_at=UTC_TIMESTAMP()';
            $params['version'] = (int) $version;
            $params['published_version'] = (int) $version;
        }
        $sql .= ' WHERE id=:id';
        $this->db->execute($sql, $params);
    }

    private function recordVersion(int $id, string $action, int $adminId, ?int $forcedVersion = null): void
    {
        $row = $this->db->fetch('SELECT * FROM help_faqs WHERE id=:id LIMIT 1', ['id' => $id]);
        if (!$row) {
            return;
        }
        $version = $forcedVersion ?: max(1, (int) $row['version']);
        if ($action !== 'publish') {
            $version = max($version, (int) $row['published_version']) + 1;
            $this->db->execute('UPDATE help_faqs SET version=:version WHERE id=:id', ['version' => $version, 'id' => $id]);
            $row['version'] = $version;
        }
        $snapshot = $this->effectiveEditor($row);
        $this->db->execute(
            "INSERT INTO help_faq_versions(faq_id,version,snapshot_json,action,operator_admin_id,created_at)
             VALUES(:faq_id,:version,:snapshot_json,:action,:admin_id,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE snapshot_json=VALUES(snapshot_json),action=VALUES(action),operator_admin_id=VALUES(operator_admin_id),created_at=VALUES(created_at)",
            [
                'faq_id' => (string) $row['faq_id'],
                'version' => $version,
                'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'action' => $action,
                'admin_id' => $adminId,
            ]
        );
    }

    private function effectiveEditor(array $row): array
    {
        $draft = json_decode((string) ($row['draft_payload'] ?? ''), true);
        if (is_array($draft)) {
            return $this->normalizeStoredPayload($draft);
        }
        return $this->normalizeStoredPayload([
            'category_id' => (int) ($row['category_id'] ?? 0),
            'question' => (string) ($row['question'] ?? ''),
            'answer' => (string) ($row['answer'] ?? ''),
            'aliases' => (string) ($row['aliases'] ?? ''),
            'keywords' => (string) ($row['keywords'] ?? ''),
            'roles' => (string) ($row['roles'] ?? 'all'),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'is_featured' => !empty($row['is_featured']) ? 1 : 0,
            'related_faq_ids' => (string) ($row['related_faq_ids'] ?? ''),
            'business_entry_label' => (string) ($row['business_entry_label'] ?? ''),
            'business_entry_path' => (string) ($row['business_entry_path'] ?? ''),
        ]);
    }

    private function blankEditor(): array
    {
        return [
            'category_id' => 0,
            'question' => '',
            'answer' => '',
            'aliases' => '',
            'keywords' => '',
            'roles' => 'all',
            'sort_order' => 0,
            'is_featured' => 0,
            'related_faq_ids' => '',
            'business_entry_label' => '',
            'business_entry_path' => '',
        ];
    }

    private function normalizeRoles($roles): string
    {
        if (!is_array($roles)) {
            $roles = explode(',', (string) $roles);
        }
        $allowed = ['all', 'user', 'worker', 'merchant', 'member'];
        $roles = array_values(array_unique(array_intersect($allowed, array_map('strtolower', array_map('trim', $roles)))));
        if (!$roles || in_array('all', $roles, true)) {
            return 'all';
        }
        return implode(',', $roles);
    }

    private function normalizeFaqIds(string $value): string
    {
        $parts = preg_split('/[\s,，、]+/u', strtoupper($value), -1, PREG_SPLIT_NO_EMPTY);
        $valid = [];
        foreach ($parts ?: [] as $part) {
            if (preg_match('/^[A-Z][0-9]{2,5}$/', $part)) {
                $valid[] = $part;
            }
        }
        return implode(',', array_values(array_unique($valid)));
    }

    private function nextFaqId(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $faqId = 'H' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
            if (!$this->db->fetch('SELECT id FROM help_faqs WHERE faq_id=:faq_id LIMIT 1', ['faq_id' => $faqId])) {
                return $faqId;
            }
        }
        throw new RuntimeException('无法生成新的问题编号，请重试。');
    }

    private function roles(): array
    {
        return [
            'all' => '所有用户',
            'user' => '下单用户',
            'worker' => '师傅',
            'merchant' => '商家',
            'member' => '会员',
        ];
    }

    private function roleKey(string $role): string
    {
        $role = strtolower(trim($role));
        return in_array($role, ['user', 'worker', 'merchant', 'member'], true) ? $role : '';
    }

    private function redirectAfterFailure(string $route): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            \redirect(\admin_url('help', $this->returnFilters()));
        }

        $hasPageState = false;
        foreach (['q', 'role', 'status', 'category_id', 'id', 'category_edit_id'] as $key) {
            if (isset($_GET[$key]) && (string) $_GET[$key] !== '') {
                $hasPageState = true;
                break;
            }
        }
        if ($route === 'help' && $hasPageState) {
            // 搜索/筛选或编辑参数异常时只回退一次到干净页面，绝不携带原参数递归跳转。
            \redirect(\admin_url('help'));
        }
        // 干净帮助页本身若仍异常，离开该路由，避免浏览器在同一地址无限重定向和重复 flash。
        \redirect(\admin_url('dashboard'));
    }

    private function returnFilters(): array
    {
        $filters = [];
        foreach (['q', 'role', 'status', 'category_id'] as $key) {
            $returnKey = 'return_' . $key;
            if (isset($_POST[$returnKey]) && (string) $_POST[$returnKey] !== '') {
                $filters[$key] = (string) $_POST[$returnKey];
            } elseif (isset($_GET[$key]) && (string) $_GET[$key] !== '') {
                $filters[$key] = (string) $_GET[$key];
            }
        }
        return $filters;
    }

    private function postOnly(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            throw new RuntimeException('请求方式不正确。');
        }
    }
}
