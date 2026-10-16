<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderProductReuseRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Infrastructure\Security\HmacOrderMediaUrlSigner;

/** 历史商品仅复用当前表单允许的商品字段和 product 图片；不带入现场、客户或费用。 */
final class DatabaseOrderProductReuseRepository implements OrderProductReuseRepository
{
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function history(array $actor, array $query): array
    {
        $page = max(1, min(1000, (int)($query['page'] ?? 1)));
        $offset = ($page - 1) * 10;
        $source = trim((string)($query['source_id'] ?? ''));
        $filter = $source !== '' ? ' AND BINARY public_no=BINARY :source' : '';
        $params = ['user'=>(int)$actor['user_id']];
        if ($source !== '') $params['source'] = $source;
        $rows = $this->db->fetchAll(
            "SELECT id,public_no,category_names_snapshot,updated_at FROM orders
             WHERE user_id=:user AND order_source_type IN ('consumer','merchant') AND lifecycle_status IN ('completed','closed')
               AND fulfillment_status='accepted' {$filter} ORDER BY id DESC LIMIT 11 OFFSET {$offset}",
            $params
        );
        $hasMore = count($rows) > 10;
        $allowed = $this->allowedKeys();
        $items = [];
        foreach (array_slice($rows, 0, 10) as $row) {
            $answers = [];
            foreach ($this->db->fetchAll('SELECT field_key_snapshot,value_json FROM order_answers WHERE order_id=:id ORDER BY id', ['id'=>$row['id']]) as $answer) {
                $key = (string)$answer['field_key_snapshot'];
                $value = json_decode((string)$answer['value_json'], true);
                if (isset($allowed[$key]) && (is_scalar($value) || $value === null || (is_array($value) && count(array_filter($value, 'is_scalar')) === count($value)))) $answers[$key] = $value;
            }
            $categoryIds = array_map('intval', array_column($this->db->fetchAll(
                "SELECT c.id FROM order_categories oc JOIN service_categories c ON c.id=oc.category_id AND c.status='active' AND c.parent_id IS NULL WHERE oc.order_id=:id ORDER BY oc.sort_order LIMIT 6", ['id'=>$row['id']]
            ), 'id'));
            $answers['category_ids'] = $categoryIds;
            $answers['category_mode'] = $categoryIds ? 'listed' : 'unknown';
            $answers['category_other'] = '';
            $media = $this->db->fetchAll(
                "SELECT m.public_id id FROM media_links l JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
                 WHERE l.entity_type='order' AND l.entity_id=:id AND l.purpose='product' ORDER BY l.sort_order,l.id LIMIT 20", ['id'=>$row['id']]
            );
            foreach ($media as &$image) $image['url'] = (new HmacOrderMediaUrlSigner())->sign((string)$image['id'], $actor, (int)$row['id']);
            unset($image);
            $items[] = ['id'=>(string)$row['public_no'], 'label'=>(string)$row['category_names_snapshot'], 'updated_at'=>(string)$row['updated_at'], 'answers'=>$answers, 'media'=>$media];
        }
        return ['items'=>$items, 'page'=>$page, 'has_more'=>$hasMore];
    }

    /** 必须在建单事务中调用。锁住来源关系及文件后增加新订单引用，不转移原图片。 */
    public function resolve(array $actor, string $sourceNo, array $publicIds): array
    {
        $source = $this->db->fetch(
            "SELECT id,public_no FROM orders WHERE BINARY public_no=BINARY :no AND user_id=:user
             AND order_source_type IN ('consumer','merchant') AND lifecycle_status IN ('completed','closed') AND fulfillment_status='accepted' LIMIT 1 FOR UPDATE",
            ['no'=>$sourceNo,'user'=>(int)$actor['user_id']]
        );
        if (!$source) throw new OrderOperationException('历史商品来源已不可用，请重新选择', 'REUSE_ORDER_INVALID', 409);
        if (count($publicIds) > 20) throw new OrderOperationException('商品图片最多复用 20 张', 'REUSE_MEDIA_LIMIT', 422);
        if (!$publicIds) return ['order'=>$source,'media'=>[]];
        foreach ($publicIds as $id) if (!is_string($id) || $id === '') throw new OrderOperationException('商品图片编号无效', 'REUSE_MEDIA_INVALID', 422);
        $publicIds = array_values(array_unique($publicIds));
        $marks = implode(',', array_fill(0, count($publicIds), '?'));
        $media = $this->db->fetchAll(
            "SELECT m.id,l.purpose,l.sort_order FROM media_links l JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
             WHERE l.entity_type='order' AND l.entity_id=? AND l.purpose='product' AND m.public_id IN ({$marks}) ORDER BY l.sort_order,l.id FOR UPDATE",
            array_merge([(int)$source['id']], $publicIds)
        );
        if (count($media) !== count($publicIds)) throw new OrderOperationException('部分商品图片已不可用，请重新选择', 'REUSE_MEDIA_INVALID', 409);
        return ['order'=>$source,'media'=>$media];
    }

    private function allowedKeys(): array
    {
        $fields = $this->db->fetchAll(
            "SELECT f.field_key,f.component_type FROM form_fields f JOIN form_steps s ON s.id=f.step_id
             WHERE f.status='active' AND s.status='active' AND s.id=(SELECT s2.id FROM form_steps s2
             JOIN form_schema_versions v ON v.id=s2.schema_version_id JOIN form_schemas fs ON fs.id=v.schema_id
             WHERE fs.schema_key='order_submit' AND v.status='published' AND s2.status='active' ORDER BY v.version_no DESC,s2.sort_order,s2.id LIMIT 1)"
        );
        $excluded = ['workflow_profile','is_renovation','material_images','wall_images','quantity','wall_height','expected_fee','expected_fee_type','contact_name','contact_mobile','address','expected_visit_date','expected_visit_period','requirement_note'];
        $keys = [];
        foreach ($fields as $field) {
            $key = (string)$field['field_key'];
            if (!in_array($key, $excluded, true) && ($key === 'product_link' || in_array($field['component_type'], ['radio','single','boolean','checkbox','multiple','category_picker'], true))) $keys[$key] = true;
        }
        return $keys;
    }
}
