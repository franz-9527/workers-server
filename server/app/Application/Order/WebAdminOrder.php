<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use RuntimeException;
use WorkerAdmin\Contracts\AdminOrderRepository;

final class WebAdminOrder
{
    private AdminOrderRepository $orders;
    private OrderSubmission $submission;

    public function __construct(AdminOrderRepository $orders, OrderSubmission $submission)
    {
        $this->orders = $orders;
        $this->submission = $submission;
    }

    public function index(array $filters): array { return $this->orders->index($filters); }
    public function detail(int $orderId): array { return $this->orders->detail($orderId); }

    /** @return array<string,mixed> */
    public function proxyForm(): array
    {
        return $this->submission->form([
            'user_id'=>0,
            'user_public_id'=>'admin-proxy-preview',
            'mobile_masked'=>'138******00',
            'is_demo_actor'=>false,
        ]);
    }

    public function create(array $input, int $adminId, string $adminName): int
    {
        unset($input['approve_now'], $input['publish_now']);
        $userId=(int)($input['user_id']??0);
        $actor=$this->orders->proxyCustomerActor($userId);
        $body=[
            'client_request_id'=>$this->requestId((string)($input['client_request_id']??'')),
            'schema_version_id'=>(int)($input['schema_version_id']??0),
            'answers'=>is_array($input['answers']??null)?$input['answers']:[],
            'address'=>is_array($input['address']??null)?$input['address']:[],
            'dispatch_mode'=>trim((string)($input['dispatch_mode']??'multi_quote')),
            'measurement_ref'=>trim((string)($input['measurement_ref']??'')),
            'phone_role_confirm'=>trim((string)($input['phone_role_confirm']??'')),
        ];
        $created=$this->submission->create($actor,$body);
        $publicNo=(string)($created['data']['id']??'');
        $orderId=$this->orders->internalIdByPublicNo($publicNo,$userId);
        if($orderId<1)throw new RuntimeException('代下单已创建但未能定位订单记录，请刷新后核对。');
        $this->orders->recordProxyCreated($orderId,$adminId,$adminName);
        return $orderId;
    }

    public function update(int $orderId, array $input, int $adminId): array { return $this->orders->update($orderId, $input, $adminId); }
    public function audit(int $orderId, string $decision, string $reason, int $adminId): array
    {
        if ($decision === 'approve') {
            $order = $this->orderRow($orderId);
            if ((string)($order['order_source_type'] ?? 'consumer') !== 'platform' && (string)($order['payment_status'] ?? '') !== 'paid') {
                throw new RuntimeException('客户预约费尚未真实到账，不能提前审核通过。');
            }
        }
        return $this->orders->audit($orderId, $decision, $reason, $adminId);
    }
    public function publish(int $orderId, bool $publish): void
    {
        if ($publish) {
            $order = $this->orderRow($orderId);
            if ((string)($order['order_source_type'] ?? 'consumer') !== 'platform' && (string)($order['payment_status'] ?? '') !== 'paid') {
                throw new RuntimeException('客户预约费尚未真实到账，不能公开到接单大厅。');
            }
        }
        $this->orders->publish($orderId, $publish);
    }
    public function cancel(int $orderId, string $reason, int $adminId): array { return $this->orders->cancel($orderId, $reason, $adminId); }
    public function quote(int $orderId, array $input, int $adminId): int { return $this->orders->quote($orderId, $input, $adminId); }
    public function assign(int $orderId, int $workerId, int $rateBp, string $reason, int $adminId): void { $this->orders->assign($orderId, $workerId, $rateBp, $reason, $adminId); }
    public function invite(int $orderId, array $input, int $adminId): array { return $this->orders->invite($orderId, $input, $adminId); }
    public function revokeInvite(int $orderId, int $inviteId): void { $this->orders->revokeInvite($orderId, $inviteId); }
    public function releaseContact(int $orderId, int $adminId): void { $this->orders->releaseContact($orderId, $adminId); }
    public function uploadMedia(int $orderId, string $purpose, array $file, int $adminId): int { return $this->orders->uploadMedia($orderId, $purpose, $file, $adminId); }
    public function deleteMedia(int $orderId, int $mediaId): void { $this->orders->deleteMedia($orderId, $mediaId); }
    public function media(int $mediaId): array { return $this->orders->media($mediaId); }
    private function orderRow(int $orderId): array
    {
        $detail = $this->orders->detail($orderId);
        $order = is_array($detail['order'] ?? null) ? $detail['order'] : [];
        if (!$order) throw new RuntimeException('订单不存在。');
        return $order;
    }
    private function requestId(string $value): string
    {
        $value=trim($value);
        if(preg_match('/^[A-Za-z0-9_-]{16,128}$/',$value)===1)return $value;
        return 'ADMINPROXY_'.bin2hex(random_bytes(12));
    }
}
