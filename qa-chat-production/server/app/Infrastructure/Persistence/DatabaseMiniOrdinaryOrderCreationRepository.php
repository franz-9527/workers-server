<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Application\Order\OrderSubmission;
use WorkerAdmin\Contracts\MiniOrdinaryOrderCreationRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 复用普通下单领域用例，客户查询、幂等经办记录和资料交付独立于平台合同开单。 */
final class DatabaseMiniOrdinaryOrderCreationRepository implements MiniOrdinaryOrderCreationRepository
{
    private Database $db;
    private PrivateValueCipher $cipher;
    private OrderSubmission $submission;
    private ?\WorkerAdmin\Contracts\ProxyOrderCustomerResolver $customerResolver;
    public function __construct(Database $db, PrivateValueCipher $cipher, OrderSubmission $submission,?\WorkerAdmin\Contracts\ProxyOrderCustomerResolver $customerResolver=null)
    { $this->db=$db; $this->cipher=$cipher; $this->submission=$submission;$this->customerResolver=$customerResolver; }

    private function customerScope(): string
    {
        return "u.status='active' AND COALESCE(u.mobile_masked,'')<>'' AND EXISTS(SELECT 1 FROM wechat_identities wi WHERE wi.user_id=u.id AND wi.status='active')";
    }

    public function customers(string $query): array
    {
        $where = $this->customerScope();
        if (preg_match('/^1\d{10}$/D', $query)) {
            $where .= ' AND u.mobile_hmac=:mobile';
            $params = ['mobile'=>$this->cipher->hmac($query, 'user-mobile')];
        } else {
            $where .= ' AND (u.nickname LIKE :name OR BINARY u.public_id=BINARY :ref)';
            $params = ['name'=>'%'.strtr($query, ['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']).'%', 'ref'=>$query];
        }
        return $this->db->fetchAll('SELECT u.public_id,u.nickname,u.mobile_masked FROM users u WHERE '.$where.' ORDER BY u.id DESC LIMIT 20', $params);
    }

    private function customer(string $ref, bool $lock = false): array
    {
        if($this->customerResolver)return$this->customerResolver->resolve($ref,$lock);
        $row = $this->db->fetch('SELECT u.id user_id,u.public_id user_public_id,u.nickname,u.mobile_masked FROM users u WHERE BINARY u.public_id=BINARY :ref AND '.$this->customerScope().' LIMIT 1'.($lock ? ' FOR UPDATE' : ''), ['ref'=>$ref]);
        if (!$row) throw new OrderOperationException('请选择已授权手机号且具有有效微信身份的真实客户', 'PROXY_CUSTOMER_INVALID', 422);
        return $row + ['is_demo_actor'=>false,'admin_proxy_consumer'=>true];
    }

    public function form(string $customerRef): array
    {
        $customer = $this->customer($customerRef);
        $form = $this->submission->form($customer);
        unset($form['reusable_cancelled_order']);
        $form['customer'] = ['public_id'=>$customer['user_public_id'],'nickname'=>$customer['nickname'],'mobile_masked'=>$customer['mobile_masked']];
        return $form;
    }

    public function create(array $input, int $operatorUserId, string $requestId): array
    {
        $key = 'mini_ordinary_create:'.$operatorUserId.':'.$requestId;
        $digest = hash('sha256', (string)json_encode($input, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $this->db->transaction(function(Database $db) use($input,$operatorUserId,$key,$digest): array {
            if (!$db->fetch("SELECT id FROM users WHERE id=:id AND status='active' FOR UPDATE", ['id'=>$operatorUserId])) throw new OrderOperationException('管理员账号已失效', 'ADMIN_REQUIRED', 403);
            $prior = $db->fetch("SELECT e.payload_json,o.public_no FROM order_events e JOIN orders o ON o.id=e.order_id WHERE e.event_key=:key AND e.event_type='mini_ordinary_created' LIMIT 1", ['key'=>$key]);
            if ($prior) {
                $payload = json_decode((string)$prior['payload_json'], true);
                if (!hash_equals((string)($payload['input_digest'] ?? ''), $digest)) throw new OrderOperationException('相同请求的客户或资料已变化，请先核对原订单', 'IDEMPOTENCY_CONFLICT', 409);
                return ['id'=>(string)$prior['public_no'],'restored'=>true];
            }
            $version = (int)$db->fetchValue("SELECT v.id FROM form_schema_versions v JOIN form_schemas s ON s.id=v.schema_id WHERE s.schema_key='order_submit' AND v.status='published' ORDER BY v.version_no DESC,v.id DESC LIMIT 1");
            if ((int)($input['schema_version_id'] ?? 0) !== $version) throw new OrderOperationException('下单表单已更新，请重新加载当前资料', 'FORM_VERSION_EXPIRED', 409);
            $customer = $this->customer(trim((string)($input['customer_ref'] ?? '')), true);
            $body = array_diff_key($input, ['customer_ref'=>true]);
            $body['client_request_id'] = 'PROXY_'.hash('sha256', $key);
            $created = $this->submission->create($customer, $body);
            $no = (string)$created['data']['id'];
            $orderId = (int)$db->fetchValue('SELECT id FROM orders WHERE public_no=:no AND user_id=:user', ['no'=>$no,'user'=>$customer['user_id']]);
            $db->insert("INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,'mini_ordinary_created',:key,'mini_admin',:actor,:payload,UTC_TIMESTAMP())", [
                'order_id'=>$orderId,'key'=>$key,'actor'=>$operatorUserId,'payload'=>json_encode(['input_digest'=>$digest,'customer_ref'=>$customer['user_public_id'],'message'=>'管理员代录普通订单，待客户本人确认并支付预约费'], JSON_UNESCAPED_UNICODE)
            ]);
            return ['id'=>$no,'restored'=>false];
        });
    }

    public function ready(string $orderNo, int $operatorUserId): array
    {
        return $this->db->transaction(function(Database $db) use($orderNo,$operatorUserId): array {
            $order = $db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :no FOR UPDATE', ['no'=>$orderNo]);
            if (!$order || !$db->fetchValue("SELECT id FROM order_events WHERE order_id=:id AND event_type='mini_ordinary_created' AND actor_type='mini_admin' AND actor_id=:actor LIMIT 1", ['id'=>$order['id'],'actor'=>$operatorUserId])) throw new OrderOperationException('代下单草稿不存在或不是本次经办订单', 'ORDER_NOT_FOUND', 404);
            if ($db->fetchValue("SELECT id FROM order_events WHERE order_id=:id AND event_type='mini_ordinary_ready' LIMIT 1", ['id'=>$order['id']])) return ['id'=>$orderNo,'ready'=>true];
            if ($order['lifecycle_status'] === 'archived') throw new OrderOperationException('代录草稿已过期，请重新提交', 'ORDER_DRAFT_EXPIRED', 409);
            if ($order['audit_status'] !== 'draft' || $order['lifecycle_status'] !== 'active' || $order['payment_status'] !== 'unpaid') throw new OrderOperationException('订单状态已变化，请刷新后核对', 'ORDER_STATE_CHANGED', 409);
            $customer = $db->fetch("SELECT public_id FROM users WHERE id=:id", ['id'=>$order['user_id']]);
            $this->customer((string)($customer['public_id'] ?? ''));
            (new DatabaseOrderCancellationRiskRepository($db))->assertCanSubmit((int)$order['user_id'], $order);
            if ($order['workflow_profile'] !== 'measurement') foreach (['product','wall'] as $purpose) {
                $rule = OrderFormMediaRules::rule($db, (int)$order['form_schema_version_id'], $purpose);
                $count = (int)$db->fetchValue("SELECT COUNT(*) FROM media_links l JOIN media_files m ON m.id=l.media_file_id AND m.status='active' WHERE l.entity_type='order' AND l.entity_id=:id AND l.purpose=:purpose", ['id'=>$order['id'],'purpose'=>$purpose]);
                if ($rule['active'] && ($count < $rule['min'] || $count > $rule['max'])) throw new OrderOperationException('请补齐“'.$rule['label'].'”图片后再交给客户', 'ORDER_IMAGES_REQUIRED', 422);
            }
            $db->insert("INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:id,'mini_ordinary_ready',:key,'mini_admin',:actor,:payload,UTC_TIMESTAMP())", [
                'id'=>$order['id'],'key'=>'mini_ordinary_ready:'.$order['id'],'actor'=>$operatorUserId,'payload'=>json_encode(['message'=>'代录资料已提交，等待客户核对并本人确认协议和支付预约费'], JSON_UNESCAPED_UNICODE)
            ]);
            return ['id'=>$orderNo,'ready'=>true];
        });
    }
}
