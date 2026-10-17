<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class DatabaseDemoOrderWriteGuard
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}
    public function run(string $orderNo,callable $operation):array
    {
        $params=['lock_name'=>'wall_worker_admin_demo_guard'];if((int)$this->db->fetchValue('SELECT GET_LOCK(:lock_name,5)',$params)!==1)throw new OrderOperationException('演示数据设置正在变更，请稍后重试。','DEMO_SETTING_BUSY',409);
        try{$setting=$this->db->fetch("SELECT setting_value FROM system_settings WHERE setting_key='demo.data_enabled' LOCK IN SHARE MODE");$enabled=!$setting||in_array(strtolower((string)$setting['setting_value']),['1','true','yes','on'],true);if(!$enabled){$order=$this->db->fetch('SELECT is_demo,public_no FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1',['public_no'=>$orderNo]);if($order&&(!empty($order['is_demo'])||stripos((string)$order['public_no'],'DEMO')!==false))throw new OrderOperationException('演示数据已关闭，该演示记录不可继续处理。','DEMO_DATA_DISABLED',403);}return$operation();}
        finally{try{$this->db->fetchValue('SELECT RELEASE_LOCK(:lock_name)',$params);}catch(Throwable $exception){}}
    }
}
