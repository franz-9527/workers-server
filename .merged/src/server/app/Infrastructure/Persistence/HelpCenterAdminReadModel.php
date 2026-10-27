<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Core\Database;

/** 帮助中心后台列表、筛选、搜索洞察等只读聚合，避免继续堆入旧 Controller。 */
final class HelpCenterAdminReadModel
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function page(string $query, string $role, string $status, int $categoryId, int $editingId, int $editingCategoryId): array
    {
        $where = ['f.deleted_at IS NULL'];
        $params = [];
        if ($query !== '') {
            $pattern = '%'.$query.'%';
            $searchWhere = [
                'f.question LIKE :q_question','f.answer LIKE :q_answer','f.keywords LIKE :q_keywords','f.aliases LIKE :q_aliases',
            ];
            $params += [
                'q_question'=>$pattern,'q_answer'=>$pattern,'q_keywords'=>$pattern,'q_aliases'=>$pattern,
            ];
            // faq_id 是 ascii_bin；只有 ASCII 查询才参与，避免中文字符集转换异常。
            if (preg_match('/^[A-Za-z0-9_-]+$/D',$query)===1) {
                $searchWhere[] = 'f.faq_id LIKE :q_faq_id';
                $params['q_faq_id'] = $pattern;
            }
            $where[] = '('.implode(' OR ',$searchWhere).')';
        }
        if ($role !== '') {
            $where[] = "(f.roles='all' OR FIND_IN_SET(:role,f.roles)>0)";
            $params['role'] = $role;
        }
        if (in_array($status,['draft','published','withdrawn','archived'],true)) {
            $where[] = 'f.status=:status';
            $params['status'] = $status;
        }
        if ($categoryId > 0) {
            $where[] = 'f.category_id=:category_id';
            $params['category_id'] = $categoryId;
        }

        $faqs = $this->db->fetchAll(
            "SELECT f.*,c.name AS category_name,c.code AS category_code
             FROM help_faqs f INNER JOIN help_categories c ON c.id=f.category_id
             WHERE ".implode(' AND ',$where)."
             ORDER BY f.is_featured DESC,c.sort_order ASC,f.sort_order ASC,f.id ASC LIMIT 300",
            $params
        );
        foreach ($faqs as &$faq) {
            $faq['has_draft'] = trim((string)($faq['draft_payload']??'')) !== '';
            $draft = $faq['has_draft'] ? json_decode((string)$faq['draft_payload'],true) : null;
            $faq['draft_is_featured'] = is_array($draft) ? (!empty($draft['is_featured'])?1:0) : null;
            $faq['display_is_featured'] = $faq['draft_is_featured'] !== null ? (int)$faq['draft_is_featured'] : (!empty($faq['is_featured'])?1:0);
            $faq['featured_pending'] = $faq['draft_is_featured'] !== null && (int)$faq['draft_is_featured'] !== (!empty($faq['is_featured'])?1:0);
        }
        unset($faq);

        $editing = null;
        if ($editingId > 0) {
            $editing = $this->db->fetch('SELECT * FROM help_faqs WHERE id=:id AND deleted_at IS NULL LIMIT 1',['id'=>$editingId]);
            if (!$editing) throw new RuntimeException('要编辑的问答不存在或已删除。');
        }
        $editingCategory = $editingCategoryId > 0
            ? $this->db->fetch('SELECT * FROM help_categories WHERE id=:id LIMIT 1',['id'=>$editingCategoryId])
            : null;

        return [
            'faqs'=>$faqs,
            'categories'=>$this->db->fetchAll('SELECT c.* FROM help_categories c ORDER BY c.sort_order ASC,c.id ASC'),
            'editing'=>$editing,
            'editingCategory'=>$editingCategory,
            'zeroSearches'=>$this->db->fetchAll(
                "SELECT query_text,COUNT(*) AS hits,MAX(created_at) AS last_at
                 FROM help_search_logs
                 WHERE result_count=0 AND query_text<>'' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)
                 GROUP BY query_text ORDER BY hits DESC,last_at DESC LIMIT 30"
            ),
            'stats'=>$this->db->fetch(
                "SELECT COUNT(*) AS total_count,SUM(status='draft') AS draft_count,SUM(status='published') AS published_count,
                        SUM(status='withdrawn') AS withdrawn_count,SUM(status='archived') AS archived_count,
                        SUM(status='published' AND draft_payload IS NOT NULL AND draft_payload<>'') AS pending_update_count
                 FROM help_faqs WHERE deleted_at IS NULL"
            ) ?: [],
        ];
    }
}
