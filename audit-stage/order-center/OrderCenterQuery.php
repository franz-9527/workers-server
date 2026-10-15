<?php

declare(strict_types=1);
namespace WorkerAdmin\Application\Order;
use WorkerAdmin\Contracts\OrderCenterRepository;
use WorkerAdmin\Contracts\OrderMediaUrlSigner;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Domain\Order\OrderOperationException;
final class OrderCenterQuery
{
    private OrderCenterRepository $repository;
    private PrivateValueCipher $cipher;
    private OrderMediaUrlSigner $mediaSigner;
    public function __construct(OrderCenterRepository $repository, PrivateValueCipher $cipher, OrderMediaUrlSigner $mediaSigner, string $timezone = 'Asia/Shanghai')
    {
        $this->repository = $repository;
        $this->cipher = $cipher;
        $this->mediaSigner = $mediaSigner;
    }
    public function query(array $actor, string $kind, string $status, int $page, string $keyword = ''): array
    {
        if (!in_array($kind, ['mine','worker'], true)) throw new OrderOperationException('订单中心类型无效','ORDER_CENTER_KIND_INVALID',422);
        if (!in_array($status, ['all','todo','supplement','quoting','progress','completed','refunding'], true)) throw new OrderOperationException('订单筛选状态无效','ORDER_CENTER_STATUS_INVALID',422);
        if ($kind === 'worker' && empty($actor['worker_id'])) return ['items'=>[],'page'=>max(1,$page),'status'=>$status,'keyword'=>'','has_more'=>false,'supplement_count'=>0,'todo_count'=>0];
        $keyword = $kind === 'mine' ? $this->normalizeKeyword($keyword) : '';
        $result = $this->repository->page($actor,$kind,$status,max(1,$page),20,$keyword);
        return ['items'=>$result['rows'],'page'=>max(1,$page),'status'=>$status,'keyword'=>$keyword,'has_more'=>(bool)$result['has_more'],'supplement_count'=>$this->repository->supplementCount($actor,$kind),'todo_count'=>$this->repository->todoCount($actor,$kind)];
    }
    private function normalizeKeyword(string $keyword): string
    {
        $value = trim((string)(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $keyword) ?? $keyword));
        if ($value === '') return '';
        return function_exists('mb_substr') ? (string)mb_substr($value, 0, 80, 'UTF-8') : substr($value, 0, 80);
    }
}
