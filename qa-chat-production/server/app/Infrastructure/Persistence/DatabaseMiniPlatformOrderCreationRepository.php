<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Application\Order\PlatformOrderAdmin;
use WorkerAdmin\Contracts\MiniPlatformOrderCreationRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 只包装共享开单用例的事务、幂等和小程序客户查询，不维护第二套开单规则。 */
final class DatabaseMiniPlatformOrderCreationRepository implements MiniPlatformOrderCreationRepository
{
    private Database $db;
    private PrivateValueCipher $cipher;
    private PlatformOrderAdmin $orders;

    public function __construct(Database $db, PrivateValueCipher $cipher, PlatformOrderAdmin $orders)
    {
        $this->db = $db;
        $this->cipher = $cipher;
        $this->orders = $orders;
    }

    public function categories(): array
    {
        return $this->db->fetchAll("SELECT id,name FROM service_categories WHERE parent_id IS NULL AND status='active' ORDER BY sort_order,id");
    }

    public function customers(string $query): array
    {
        $where = "u.status='active' AND EXISTS(SELECT 1 FROM wechat_identities wi WHERE wi.user_id=u.id AND wi.status='active')";
        $params = [];
        if (preg_match('/^1\d{10}$/D', $query) === 1) {
            $where .= ' AND u.mobile_hmac=:mobile';
            $params['mobile'] = $this->cipher->hmac($query, 'user-mobile');
        } else {
            $where .= " AND (u.nickname LIKE :name OR BINARY u.public_id=BINARY :public_id)";
            $params['name'] = '%' . strtr($query, ['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']) . '%';
            $params['public_id'] = $query;
        }
        return $this->db->fetchAll('SELECT u.public_id,u.nickname,u.mobile_masked FROM users u WHERE ' . $where . ' ORDER BY u.id DESC LIMIT 20', $params);
    }

    public function create(array $input, int $operatorUserId, string $requestId): array
    {
        $eventKey = 'mini_platform_create:' . $operatorUserId . ':' . $requestId;
        $digest = hash('sha256', (string)json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        // 同一操作者的重试串行；建单、测量阶段初始化、事件和请求凭据全部一起提交。
        return $this->db->transaction(function (Database $db) use ($input,$operatorUserId,$eventKey,$digest): array {
            $operator = $db->fetch("SELECT id FROM users WHERE id=:id AND status='active' FOR UPDATE", ['id'=>$operatorUserId]);
            if (!$operator) throw new OrderOperationException('管理员账号已失效。', 'ADMIN_REQUIRED', 403);
            $prior = $db->fetch("SELECT e.payload_json,o.public_no FROM order_events e JOIN orders o ON o.id=e.order_id WHERE e.event_key=:key AND e.event_type='platform_order_creation_request' LIMIT 1", ['key'=>$eventKey]);
            if ($prior) {
                $payload = json_decode((string)$prior['payload_json'], true);
                if (!is_array($payload) || !hash_equals((string)($payload['input_digest'] ?? ''), $digest)) {
                    throw new OrderOperationException('该开单请求已使用，资料发生变化；请先查看原订单。', 'PLATFORM_CREATE_REQUEST_CONFLICT', 409);
                }
                return ['order_id'=>(string)$prior['public_no'],'replayed'=>true];
            }
            $ref = trim((string)($input['customer_ref'] ?? ''));
            $currentSchema = (int)$db->fetchValue("SELECT v.id FROM form_schema_versions v JOIN form_schemas s ON s.id=v.schema_id WHERE s.schema_key='order_submit' AND v.status='published' ORDER BY v.version_no DESC,v.id DESC LIMIT 1");
            if (isset($input['schema_version_id']) && (int)$input['schema_version_id'] !== $currentSchema) {
                throw new OrderOperationException('下单表单已更新，请重新进入开单页填写当前资料。', 'FORM_SCHEMA_VERSION_CHANGED', 422);
            }
            $input['user_id'] = 0;
            if ($ref !== '') {
                $user = $db->fetch("SELECT u.id FROM users u WHERE BINARY u.public_id=BINARY :ref AND u.status='active' AND EXISTS(SELECT 1 FROM wechat_identities wi WHERE wi.user_id=u.id AND wi.status='active') LIMIT 1", ['ref'=>$ref]);
                if (!$user) throw new OrderOperationException('关联客户已不可用，请重新选择或使用待绑定客户。', 'PLATFORM_USER_NOT_FOUND', 422);
                $input['user_id'] = (int)$user['id'];
            }
            $orderId = $this->orders->create($input, 0, ['channel'=>'mini_admin','user_id'=>$operatorUserId]);
            $publicNo = (string)$db->fetchValue('SELECT public_no FROM orders WHERE id=:id', ['id'=>$orderId]);
            if ($publicNo === '') throw new OrderOperationException('开单结果读取失败。', 'PLATFORM_CREATE_RESULT_INVALID', 500);
            $db->insert("INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:id,'platform_order_creation_request',:key,'mini_admin',:actor,:payload,UTC_TIMESTAMP())", [
                'id'=>$orderId,'key'=>$eventKey,'actor'=>$operatorUserId,
                'payload'=>json_encode(['input_digest'=>$digest,'operator_channel'=>'mini_admin','operator_user_id'=>$operatorUserId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return ['order_id'=>$publicNo,'replayed'=>false];
        });
    }
}
