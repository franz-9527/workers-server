<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Money;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\PaymentGateway;
use WorkerAdmin\Contracts\WalletRechargeRepository;

/**
 * 用户余额充值支付生命周期。
 *
 * 充值业务单在用户点击“充值”后、调起微信收银台前创建，这是 JSAPI 支付生成商户单号/prepay_id 的必要步骤；
 * 本用例负责把用户主动取消和已过期未支付记录及时收口为 closed，避免长期堆积 pending。
 */
final class WalletRechargeLifecycle
{
    private WalletRechargeRepository $repository;
    private PaymentGateway $payments;

    public function __construct(WalletRechargeRepository $repository, PaymentGateway $payments)
    {
        $this->repository=$repository;
        $this->payments=$payments;
    }

    public function cancelByUser(string $token, string $paymentNo): array
    {
        if(preg_match('/^[A-Fa-f0-9]{64}$/',$token)!==1) throw new RuntimeException('请重新登录',401);
        $paymentNo=trim($paymentNo);
        if($paymentNo===''||strlen($paymentNo)>64) throw new RuntimeException('充值支付单号不正确',422);
        $actor=$this->repository->findActorByTokenHash(hash('sha256',$token));
        if(!$actor) throw new RuntimeException('登录已过期，请重新进入小程序',401);
        $this->repository->touchSession((int)$actor['session_id']);
        $row=$this->repository->findOwnedPayment($paymentNo,(int)$actor['user_id']);
        if(!$row) throw new RuntimeException('充值支付单不存在',404);

        $local=(string)$row['status'];
        if($local==='paid'||(string)$row['recharge_status']==='paid') return ['status'=>'paid'];
        if($local==='closed') return ['status'=>'closed'];

        // 没有 prepay_id 说明微信预支付单尚未成功生成，直接关闭本地失败记录。
        if(empty($row['wechat_prepay_id'])) {
            $this->repository->closeUnpaid((int)$row['id'],(int)$row['recharge_id']);
            return ['status'=>'closed'];
        }

        try {
            $remote=$this->payments->queryPayment((string)$row['public_no']);
            $state=$this->tradeState($remote);
        } catch(Throwable $exception) {
            // 不能因一次微信查单网络失败伪造“已关闭”；到 expires_at 后仍会由定时任务再次收口。
            return ['status'=>'pending','message'=>'已取消支付，系统将在支付单到期后自动关闭'];
        }

        if($state==='SUCCESS') return ['status'=>'confirming'];
        if($state==='USERPAYING') return ['status'=>'pending'];
        if(in_array($state,['NOTPAY','CLOSED','REVOKED','PAYERROR'],true)) {
            $this->repository->closeUnpaid((int)$row['id'],(int)$row['recharge_id']);
            return ['status'=>'closed'];
        }
        return ['status'=>'pending'];
    }

    public function closeExpired(int $limit=50): array
    {
        $rows=$this->repository->expiredPendingPayments($limit);
        $stats=['scanned'=>count($rows),'closed'=>0,'confirming'=>0,'deferred'=>0];
        foreach($rows as $row){
            if(empty($row['wechat_prepay_id'])){
                if($this->repository->closeUnpaid((int)$row['id'],(int)$row['recharge_id'])) $stats['closed']++;
                continue;
            }
            try{
                $state=$this->tradeState($this->payments->queryPayment((string)$row['public_no']));
                if($state==='SUCCESS'||$state==='USERPAYING'){
                    $stats['confirming']++;
                    continue;
                }
                if(in_array($state,['NOTPAY','CLOSED','REVOKED','PAYERROR'],true)){
                    if($this->repository->closeUnpaid((int)$row['id'],(int)$row['recharge_id'])) $stats['closed']++;
                    continue;
                }
                $stats['deferred']++;
            }catch(Throwable $exception){
                $stats['deferred']++;
            }
        }
        return $stats;
    }

    private function tradeState(array $response): string
    {
        return strtoupper(trim((string)($response['trade_state']??$response['tradeState']??'')));
    }
}
