<?php

declare(strict_types=1);

namespace WorkerAdmin\Controllers;

use RuntimeException;
use WorkerAdmin\Core\Database;

/**
 * V2.4.0 帮助中心只读 API。
 * 独立于订单控制器，避免内容检索改动订单主链；所有公开读取仍要求现有小程序登录态。
 */
final class HelpCenterApiController
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function dispatch(string $route): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $actor = $this->authenticate();

        $map = [
            'help.bootstrap' => ['GET', 'bootstrap'],
            'help.search' => ['GET', 'search'],
            'help.detail' => ['GET', 'detail'],
            'help.search-click' => ['POST', 'searchClick'],
        ];
        if (!isset($map[$route])) {
            $this->fail('帮助中心接口不存在', 'NOT_FOUND', 404);
        }
        if ($method !== $map[$route][0]) {
            $this->fail('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
        }

        $handler = $map[$route][1];
        $this->{$handler}($actor);
    }

    private function authenticate(): array
    {
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($authorization === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authorization = trim((string) ($headers['Authorization'] ?? $headers['authorization'] ?? ''));
        }
        if (!preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $authorization, $matches)) {
            $this->fail('登录状态已失效，请重新进入', 'UNAUTHORIZED', 401);
        }

        $session = $this->db->fetch(
            "SELECT s.id AS session_id,s.user_id
             FROM login_sessions s
             INNER JOIN users u ON u.id=s.user_id
             WHERE s.token_hash=:token_hash
               AND s.status='active'
               AND s.expires_at>UTC_TIMESTAMP()
               AND u.status='active'
             LIMIT 1",
            ['token_hash' => hash('sha256', $matches[1])]
        );
        if (!$session) {
            $this->fail('登录状态已失效，请重新进入', 'UNAUTHORIZED', 401);
        }
        $this->db->execute('UPDATE login_sessions SET last_seen_at=UTC_TIMESTAMP() WHERE id=:id', ['id' => (int) $session['session_id']]);
        return ['user_id' => (int) $session['user_id']];
    }

    private function bootstrap(array $actor): void
    {
        $role = $this->roleKey((string) ($_GET['role'] ?? 'all'));
        $categories = $this->db->fetchAll(
            "SELECT c.id,c.code,c.name,c.sort_order,c.homepage_featured
             FROM help_categories c
             WHERE c.status='active'
             ORDER BY c.sort_order ASC,c.id ASC"
        );
        $items = $this->publishedItems('', $role, 100);

        \json_response([
            'ok' => true,
            'data' => [
                'role' => $role,
                'categories' => array_map([$this, 'categoryItem'], $categories),
                'items' => $items,
                'content_updated_at' => $this->latestPublishedAt(),
            ],
        ]);
    }

    private function search(array $actor): void
    {
        $role = $this->roleKey((string) ($_GET['role'] ?? 'all'));
        $query = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($query) > 60) {
            $query = mb_substr($query, 0, 60);
        }
        if ($query === '') {
            \json_response(['ok' => true, 'data' => ['items' => [], 'query' => '', 'result_count' => 0]]);
        }

        $items = $this->publishedItems($query, $role, 50);
        $safeQuery = $this->sanitizeSearchQuery($query);
        $this->db->insert(
            "INSERT INTO help_search_logs(query_text,query_hash,role_key,result_count,clicked_faq_id,created_at)
             VALUES(:query_text,:query_hash,:role_key,:result_count,NULL,UTC_TIMESTAMP())",
            [
                'query_text' => $safeQuery,
                'query_hash' => hash('sha256', mb_strtolower($safeQuery)),
                'role_key' => $role,
                'result_count' => count($items),
            ]
        );

        \json_response([
            'ok' => true,
            'data' => [
                'items' => $items,
                'query' => $query,
                'result_count' => count($items),
            ],
        ]);
    }

    private function detail(array $actor): void
    {
        $faqId = strtoupper(trim((string) ($_GET['faq_id'] ?? '')));
        if (!preg_match('/^[A-Z][0-9]{2,5}$/', $faqId)) {
            $this->fail('问题编号无效', 'INVALID_FAQ_ID', 422);
        }

        $row = $this->db->fetch(
            "SELECT f.*,c.code AS category_code,c.name AS category_name
             FROM help_faqs f
             INNER JOIN help_categories c ON c.id=f.category_id AND c.status='active'
             WHERE f.faq_id=:faq_id AND f.status='published' AND f.deleted_at IS NULL
             LIMIT 1",
            ['faq_id' => $faqId]
        );
        if (!$row) {
            $this->fail('该问题已下架或不存在', 'FAQ_NOT_FOUND', 404);
        }

        $item = $this->faqItem($row);
        $related = [];
        $relatedIds = $this->csvValues((string) ($row['related_faq_ids'] ?? ''));
        if ($relatedIds) {
            $placeholders = [];
            $params = [];
            foreach ($relatedIds as $index => $relatedId) {
                $key = 'related_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $relatedId;
            }
            $rows = $this->db->fetchAll(
                "SELECT f.*,c.code AS category_code,c.name AS category_name
                 FROM help_faqs f
                 INNER JOIN help_categories c ON c.id=f.category_id AND c.status='active'
                 WHERE f.status='published' AND f.deleted_at IS NULL
                   AND f.faq_id IN (" . implode(',', $placeholders) . ")
                 ORDER BY f.sort_order ASC,f.id ASC",
                $params
            );
            $related = array_map([$this, 'faqItem'], $rows);
        }
        $item['related'] = $related;

        \json_response(['ok' => true, 'data' => $item]);
    }

    private function searchClick(array $actor): void
    {
        $body = $this->jsonBody();
        $faqId = strtoupper(trim((string) ($body['faq_id'] ?? '')));
        $query = trim((string) ($body['query'] ?? ''));
        if (!preg_match('/^[A-Z][0-9]{2,5}$/', $faqId)) {
            $this->fail('问题编号无效', 'INVALID_FAQ_ID', 422);
        }
        if ($query !== '') {
            $safeQuery = $this->sanitizeSearchQuery(mb_substr($query, 0, 60));
            $this->db->execute(
                "UPDATE help_search_logs
                 SET clicked_faq_id=:faq_id
                 WHERE query_hash=:query_hash
                 ORDER BY id DESC LIMIT 1",
                ['faq_id' => $faqId, 'query_hash' => hash('sha256', mb_strtolower($safeQuery))]
            );
        }
        \json_response(['ok' => true, 'data' => ['recorded' => true]]);
    }

    private function publishedItems(string $query, string $role, int $limit): array
    {
        $params = [];
        $where = ["f.status='published'", 'f.deleted_at IS NULL', "c.status='active'"];
        $scoreSql = '0';

        if ($query !== '') {
            $needle = '%' . $query . '%';
            $where[] = '(f.question LIKE :q_question OR f.aliases LIKE :q_aliases OR f.keywords LIKE :q_keywords OR f.answer LIKE :q_answer)';
            $params['q_question'] = $needle;
            $params['q_aliases'] = $needle;
            $params['q_keywords'] = $needle;
            $params['q_answer'] = $needle;
            $scoreSql = "(CASE
                WHEN f.question=:score_exact THEN 100
                WHEN f.question LIKE :score_prefix THEN 90
                WHEN f.aliases LIKE :score_aliases THEN 80
                WHEN f.keywords LIKE :score_keywords THEN 70
                WHEN f.answer LIKE :score_answer THEN 50
                ELSE 30 END)";
            $params['score_exact'] = $query;
            $params['score_prefix'] = $query . '%';
            $params['score_aliases'] = $needle;
            $params['score_keywords'] = $needle;
            $params['score_answer'] = $needle;
        }

        $roleScore = '0';
        if ($role !== 'all') {
            $roleScore = "(CASE WHEN f.roles='all' OR FIND_IN_SET(:role_key,f.roles)>0 THEN 0 ELSE 1 END)";
            $params['role_key'] = $role;
        }

        $sql = "SELECT f.*,c.code AS category_code,c.name AS category_name,
                       {$scoreSql} AS search_score,{$roleScore} AS role_rank
                FROM help_faqs f
                INNER JOIN help_categories c ON c.id=f.category_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY role_rank ASC,search_score DESC,f.is_featured DESC,c.sort_order ASC,f.sort_order ASC,f.id ASC
                LIMIT " . max(1, min(100, $limit));
        $rows = $this->db->fetchAll($sql, $params);
        return array_map([$this, 'faqItem'], $rows);
    }

    private function faqItem(array $row): array
    {
        return [
            'faq_id' => (string) $row['faq_id'],
            'question' => (string) $row['question'],
            'answer' => (string) $row['answer'],
            'category_code' => (string) ($row['category_code'] ?? ''),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'roles' => $this->csvValues((string) ($row['roles'] ?? 'all')),
            'is_featured' => !empty($row['is_featured']),
            'related_faq_ids' => $this->csvValues((string) ($row['related_faq_ids'] ?? '')),
            'business_entry' => [
                'label' => (string) ($row['business_entry_label'] ?? ''),
                'path' => (string) ($row['business_entry_path'] ?? ''),
            ],
            'updated_at' => (string) (($row['published_at'] ?? '') ?: ($row['updated_at'] ?? '')),
            'published_at' => (string) ($row['published_at'] ?? ''),
        ];
    }

    private function categoryItem(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'sort_order' => (int) $row['sort_order'],
            'homepage_featured' => !empty($row['homepage_featured']),
        ];
    }

    private function latestPublishedAt(): string
    {
        return (string) ($this->db->fetchValue(
            "SELECT COALESCE(MAX(COALESCE(published_at,updated_at)),'') FROM help_faqs WHERE status='published' AND deleted_at IS NULL"
        ) ?: '');
    }

    private function roleKey(string $role): string
    {
        $role = strtolower(trim($role));
        return in_array($role, ['all', 'user', 'worker', 'merchant', 'member'], true) ? $role : 'all';
    }


    private function csvValues(string $value): array
    {
        $parts = array_filter(array_map('trim', explode(',', $value)), static function (string $item): bool {
            return $item !== '';
        });
        return array_values(array_unique($parts));
    }

    private function sanitizeSearchQuery(string $query): string
    {
        $query = preg_replace('/1\d{10}/u', '[手机号]', $query);
        $query = preg_replace('/\b\d{7,}\b/u', '[编号]', (string) $query);
        $query = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u', '[邮箱]', (string) $query);
        $query = preg_replace('/\s+/u', ' ', (string) $query);
        return mb_substr(trim((string) $query), 0, 120);
    }

    private function jsonBody(): array
    {
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            return is_array($_POST) ? $_POST : [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function fail(string $message, string $code, int $status): void
    {
        \json_response([
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'request_id' => \request_id(),
        ], $status);
    }
}
