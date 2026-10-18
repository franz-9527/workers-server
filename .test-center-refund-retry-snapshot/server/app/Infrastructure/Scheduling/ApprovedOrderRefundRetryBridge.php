<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\WeChatPayService;

/**
 * V2.5.0 已批准订单退款的可靠执行/重试桥。
 *
 * 全局命名锁和任务统计留在 bridge；候选资格、逐笔抢占、失败恢复和 processing 写入
 * 收敛到 ApprovedOrderRefundRetryProcessor。refunds.public_no 始终作为 out_refund_no。
 */
final class ApprovedOrderRefundRetryBridge
{
    private const LOCK='wall_worker_approved_order_refund_retry';

    private Database $db;
    private int $limit;
    /** @var callable */
    private $submitRefund;
    private ApprovedOrderRefundRetryProcessor $processor;

    public function __construct(Database $db,int $limit=100,?callable $submitRefund=null)
    {
        $this->db=$db;
        $this->limit=max(1,min(500,$limit));
        $this->processor=new ApprovedOrderRefundRetryProcessor($db);
        $this->submitRefund=$submitRefund?:static function(array $row):array{
            $pay=new WeChatPayService((string)($row['api_version']??'v3'));
            if(!$pay->enabled())throw new RuntimeException('微信支付未启用，已批准退款暂未提交');
            return$pay->createRefund(
                (string)$row['payment_no'],
                (string)$row['public_no'],
                (int)$row['amount_cents'],
                (int)$row['payment_total_cents'],
                (string)$row['reason']
            );
        };
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        if(!$this->acquireLock()){
            return[
                'status'=>'success',
                'task'=>'order_refund_retry',
                'scanned'=>0,
                'success'=>0,
                'failure'=>0,
                'error'=>null,
                'skipped'=>true,
                'message'=>'已有订单退款执行实例运行中，本次安全跳过。',
            ];
        }

        try{
            $rows=$this->processor->productionCandidates($this->limit);
            $stats=[
                'status'=>'success',
                'task'=>'order_refund_retry',
                'scanned'=>count($rows),
                'success'=>0,
                'failure'=>0,
                'error'=>null,
            ];
            foreach($rows as$row){
                try{
                    if($this->processor->executeProduction((int)$row['id'],$this->submitRefund)){
                        $stats['success']++;
                    }
                }catch(Throwable $exception){
                    $stats['failure']++;
                    $stats['status']='failed';
                    $stats['error']=mb_substr($exception->getMessage(),0,1000);
                    if(function_exists('write_log')){
                        \write_log(
                            'order-refund-retry',
                            '['.date('c').'] refund_id='.(int)$row['id'].' '.$exception->getMessage()."\n"
                        );
                    }
                }
            }
            return$stats;
        }finally{
            $this->releaseLock();
        }
    }

    private function acquireLock(): bool
    {
        return(int)$this->db->fetchValue(
            'SELECT GET_LOCK(:lock_name,0)',
            ['lock_name'=>self::LOCK]
        )===1;
    }

    private function releaseLock(): void
    {
        try{
            $this->db->fetchValue(
                'SELECT RELEASE_LOCK(:lock_name)',
                ['lock_name'=>self::LOCK]
            );
        }catch(Throwable $exception){
            if(function_exists('write_log')){
                \write_log(
                    'order-refund-retry',
                    '['.date('c').'] release_lock '.$exception->getMessage()."\n"
                );
            }
        }
    }
}
