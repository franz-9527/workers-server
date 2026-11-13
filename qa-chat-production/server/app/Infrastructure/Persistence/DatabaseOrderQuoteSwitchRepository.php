<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderQuoteSwitchRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 已选师傅的订单必须先完成取消协商，禁止改写原成交关系。 */
final class DatabaseOrderQuoteSwitchRepository implements OrderQuoteSwitchRepository
{
    private Database $db;
    public function __construct(Database $db) { $this->db=$db; }

    public function switchWorker(array $actor,string $orderPublicNo,string $quotePublicId,string $reason): array
    {
        $order=$this->db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :id LIMIT 1',['id'=>$orderPublicNo]);
        if(!$order)throw new OrderOperationException('订单不存在','ORDER_NOT_FOUND',404);
        if((int)$order['user_id']!==(int)($actor['user_id']??0))throw new OrderOperationException('只能处理自己的订单','FORBIDDEN',403);
        throw new OrderOperationException('选定师傅后不能直接更换。请先申请取消，与师傅协商；取消完成后可重新发布订单。','ORDER_SWITCH_REQUIRES_CANCELLATION',409);
    }
}
