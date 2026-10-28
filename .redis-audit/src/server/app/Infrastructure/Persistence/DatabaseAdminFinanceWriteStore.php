<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\OrderNotificationService;

final class DatabaseAdminFinanceWriteStore
{
    private Database $db;
    private bool $demoGuardHeld=false;

    public function __construct(Database $db){$this->db=$db;}

    public function guardDemoWrite(string $route,int $id): void
    {
        if($id<1)return;
        if(!$this->demoGuardHeld){
            $acquired=(int)$this->db->fetchValue('SELECT GET_LOCK(:lock_name,5)',['lock_name'=>'wall_worker_admin_demo_guard']);
            if($acquired!==1)throw new RuntimeException('演示数据设置正在变更，请稍后重试。');
            $this->demoGuardHeld=true;
            register_shutdown_function(function():void{
                if(!$this->demoGuardHeld)return;
                try{$this->db->fetchValue('SELECT RELEASE_LOCK(:lock_name)',['lock_name'=>'wall_worker_admin_demo_guard']);}catch(Throwable $exception){}
                $this->demoGuardHeld=false;
            });
        }
        if((bool)\setting('demo.data_enabled',true))return;
        $queries=[
            'refunds.approve'=>"SELECT CONCAT_WS('|',r.public_no,o.public_no) FROM refunds r LEFT JOIN orders o ON o.id=r.order_id WHERE r.id=:id LIMIT 1",
            'wallets.recharge.refund'=>"SELECT CONCAT_WS('|',r.public_no,u.public_id,u.cloud_user_id) FROM user_wallet_recharges r INNER JOIN users u ON u.id=r.user_id WHERE r.id=:id LIMIT 1",
            'withdrawals.transition'=>"SELECT CONCAT_WS('|',w.public_no,p.public_id,u.public_id,u.cloud_user_id) FROM withdrawal_requests w LEFT JOIN worker_profiles p ON p.id=w.worker_id LEFT JOIN users u ON u.id=p.user_id WHERE w.id=:id LIMIT 1",
        ];
        if(!isset($queries[$route]))return;
        $marker=$this->db->fetchValue($queries[$route],['id'=>$id]);
        if($marker!==false&&stripos((string)$marker,'DEMO')!==false)throw new RuntimeException('演示数据已关闭，该演示记录不可继续处理。');
    }

    public function walletRechargeSnapshot(int $id): ?array
    {
        $row=$this->db->fetch("SELECT r.id,r.user_id,r.payment_order_id,w.id AS wallet_id,u.public_id AS user_public_id,p.api_version,p.public_no AS payment_no FROM user_wallet_recharges r INNER JOIN users u ON u.id=r.user_id INNER JOIN user_wallets w ON w.user_id=r.user_id LEFT JOIN payment_orders p ON p.id=r.payment_order_id WHERE r.id=:id LIMIT 1",['id'=>$id]);
        return $row?:null;
    }

    public function lockWalletRecharge(int $id): ?array
    { $row=$this->db->fetch('SELECT * FROM user_wallet_recharges WHERE id=:id FOR UPDATE',['id'=>$id]);return $row?:null; }

    public function lockPayment(int $id): ?array
    { $row=$this->db->fetch('SELECT * FROM payment_orders WHERE id=:id FOR UPDATE',['id'=>$id]);return $row?:null; }

    public function activeRefundForPayment(int $paymentId): ?array
    {
        $row=$this->db->fetch("SELECT * FROM refunds WHERE payment_order_id=:payment_id AND status NOT IN ('failed','closed','cancelled','rejected') ORDER BY id DESC LIMIT 1 FOR UPDATE",['payment_id'=>$paymentId]);
        return $row?:null;
    }

    public function markRechargeRefunded(int $id): void
    { $this->db->execute("UPDATE user_wallet_recharges SET status='refunded',refunded_at=COALESCE(refunded_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$id]); }

    public function markRechargeRefundProcessing(int $id): void
    { $this->db->execute("UPDATE user_wallet_recharges SET status='refund_processing',updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$id]); }

    public function createRechargeRefund(int $paymentId,int $amount,string $reason,int $adminId): array
    {
        $id=$this->db->insert("INSERT INTO refunds(public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,created_at,updated_at) VALUES(:public_no,:payment_id,NULL,NULL,:amount,:reason,'approved',:admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
            'public_no'=>\business_no('RF'),'payment_id'=>$paymentId,'amount'=>$amount,'reason'=>$reason,'admin_id'=>$adminId,
        ]);
        $row=$this->db->fetch('SELECT * FROM refunds WHERE id=:id',['id'=>$id]);
        if(!$row)throw new RuntimeException('充值纠错退款单创建失败。');
        return $row;
    }

    public function lockRefund(int $id): ?array
    { $row=$this->db->fetch('SELECT * FROM refunds WHERE id=:id FOR UPDATE',['id'=>$id]);return $row?:null; }

    public function updateRefundAmount(int $id,int $amount): void
    { $this->db->execute('UPDATE refunds SET amount_cents=:amount,updated_at=NOW() WHERE id=:id',['amount'=>$amount,'id'=>$id]); }

    /** @return array<string,int> */
    public function bookingFeeAllocations(int $paymentId,int $orderId,int $excludeRefundId): array
    {
        $rows=$this->db->fetchAll("SELECT id,amount_cents,status FROM refunds WHERE payment_order_id=:payment_id AND id<>:exclude_refund_id AND status NOT IN ('failed','closed','cancelled','rejected') FOR UPDATE",['payment_id'=>$paymentId,'exclude_refund_id'=>$excludeRefundId]);
        $refund=0;$outstanding=0;$succeeded=0;
        foreach($rows as $row){$amount=max(0,(int)$row['amount_cents']);$refund+=$amount;if((string)$row['status']==='succeeded')$succeeded+=$amount;else$outstanding+=$amount;}
        $runner=0;$business=(string)$this->db->fetchValue('SELECT business_type FROM payment_orders WHERE id=:id',['id'=>$paymentId]);
        if($orderId>0&&in_array($business,['order_booking','booking_fee'],true)){
            foreach($this->db->fetchAll("SELECT l.amount_cents FROM worker_balance_ledger l INNER JOIN disputes d ON d.id=l.business_id WHERE l.business_type='dispute' AND l.entry_type='run_empty_fee' AND l.status='effective' AND d.order_id=:order_id FOR UPDATE",['order_id'=>$orderId]) as $row)$runner+=max(0,(int)$row['amount_cents']);
        }
        return ['refund_cents'=>$refund,'outstanding_refund_cents'=>$outstanding,'succeeded_refund_cents'=>$succeeded,'runner_cents'=>$runner];
    }

    public function approveRefundForSubmission(array $refund,array $payment,int $adminId): void
    {
        $payload=json_encode([
            'refund_id'=>(int)$refund['id'],'refund_no'=>(string)$refund['public_no'],'payment_order_id'=>(int)$payment['id'],
            'wechat_transaction_id'=>$payment['wechat_transaction_id']??null,'order_id'=>$refund['order_id']===null?null:(int)$refund['order_id'],
            'amount_cents'=>(int)$refund['amount_cents'],
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->db->execute("INSERT INTO message_outbox(topic,business_key,payload_json,status,attempt_count,next_attempt_at,created_at) VALUES ('wechat.refund.request',:business_key,:payload,'pending',0,NOW(),NOW()) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json)",['business_key'=>'refund:'.$refund['id'],'payload'=>$payload]);
        $this->db->execute("UPDATE refunds SET status='approved',requested_by_admin_id=COALESCE(requested_by_admin_id,:admin),updated_at=NOW() WHERE id=:id",['admin'=>$adminId,'id'=>$refund['id']]);
        if($refund['order_id'])$this->db->execute("UPDATE orders SET refund_status='approved',updated_at=NOW(),version=version+1 WHERE id=:id",['id'=>$refund['order_id']]);
    }

    public function markRefundSubmitted(int $refundId,?int $orderId,string $wechatRefundId): void
    {
        $this->db->transaction(function(Database $db)use($refundId,$orderId,$wechatRefundId):void{
            $db->execute("UPDATE refunds SET status='processing',wechat_refund_id=:wechat_id,updated_at=NOW() WHERE id=:id AND status='approved'",['wechat_id'=>$wechatRefundId!==''?$wechatRefundId:null,'id'=>$refundId]);
            if($orderId)$db->execute("UPDATE orders SET refund_status='processing',updated_at=NOW(),version=version+1 WHERE id=:id",['id'=>$orderId]);
            $db->execute("UPDATE message_outbox SET status='processed',attempt_count=attempt_count+1,processed_at=NOW(),last_error=NULL WHERE topic='wechat.refund.request' AND business_key=:key",['key'=>'refund:'.$refundId]);
        });
    }

    public function markRefundSubmitFailed(int $refundId,string $message): void
    { $this->db->execute("UPDATE message_outbox SET status='failed',attempt_count=attempt_count+1,last_error=:error,next_attempt_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE) WHERE topic='wechat.refund.request' AND business_key=:key",['error'=>$message,'key'=>'refund:'.$refundId]); }

    public function markRechargeRefundSubmitted(int $refundId,string $wechatRefundId): void
    { $this->db->execute("UPDATE refunds SET status='processing',wechat_refund_id=:wechat_id,failure_code=NULL,failure_message=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='approved'",['wechat_id'=>$wechatRefundId!==''?$wechatRefundId:null,'id'=>$refundId]); }

    public function markRechargeRefundUncertain(int $refundId,string $message): void
    { $this->db->execute("UPDATE refunds SET failure_code='SUBMIT_UNCERTAIN',failure_message=:message,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='approved'",['message'=>$message,'id'=>$refundId]); }

    /** @return array<string,mixed> */
    public function transitionWithdrawalLocked(int $id,string $target,string $external,string $note,int $adminId): array
    {
        $request=$this->db->fetch('SELECT * FROM withdrawal_requests WHERE id=:id FOR UPDATE',['id'=>$id]);
        if(!$request)throw new RuntimeException('提现申请不存在。');
        if($target==='update_trade_no'){
            if((string)$request['status']!=='paid'||$external==='')throw new RuntimeException('只有已支付提现可以修改支付宝流水号，且流水号不能为空。');
            $payout=$this->db->fetch('SELECT * FROM worker_payouts WHERE withdrawal_request_id=:request_id FOR UPDATE',['request_id'=>$id]);
            if(!$payout)throw new RuntimeException('没有找到该提现对应的人工付款记录，请联系技术人员核对。');
            if((int)$this->db->fetchValue('SELECT COUNT(*) FROM worker_payouts WHERE external_transaction_no=:external AND id<>:id',['external'=>$external,'id'=>$payout['id']])>0)throw new RuntimeException('该支付宝流水号已用于其他提现。');
            $this->db->execute('UPDATE worker_payouts SET external_transaction_no=:external WHERE id=:id',['external'=>$external,'id'=>$payout['id']]);
            $this->db->execute('UPDATE withdrawal_requests SET reviewed_by=:admin_id,review_note=:note,reviewed_at=NOW(),updated_at=NOW() WHERE id=:id',['admin_id'=>$adminId,'note'=>$note!==''?$note:$request['review_note'],'id'=>$id]);
            $this->db->execute("INSERT INTO withdrawal_events(withdrawal_request_id,event_type,from_status,to_status,note,operator_type,operator_id,created_at) VALUES (:request_id,'trade_no_updated','paid','paid',:note,'admin',:admin_id,NOW())",['request_id'=>$id,'note'=>'支付宝流水号更正为：'.$external.($note!==''?'；'.$note:''),'admin_id'=>$adminId]);
            return ['target'=>$target,'request'=>$request];
        }
        $transitions=['pending'=>['approved','paid','rejected'],'approved'=>['paid','rejected','payment_failed'],'payment_failed'=>['paid','rejected']];
        if(!isset($transitions[$request['status']])||!in_array($target,$transitions[$request['status']],true))throw new RuntimeException('当前提现状态不能执行该操作。');
        if($target==='paid'&&$external==='')throw new RuntimeException('登记人工付款时必须填写支付宝流水号。');
        $freeze=$this->db->fetch("SELECT id,amount_cents FROM worker_balance_ledger WHERE worker_id=:worker_id AND business_type='withdrawal_request' AND business_id=:request_id AND entry_type='withdrawal_freeze' AND status='effective' LIMIT 1 FOR UPDATE",['worker_id'=>$request['worker_id'],'request_id'=>$id]);
        if(in_array($target,['paid','rejected'],true)&&(!$freeze||(int)$freeze['amount_cents']!==(int)$request['amount_cents']))throw new RuntimeException('提现冻结流水缺失或金额不一致，请先核对余额台账。');
        if($target==='paid'){
            if((int)$this->db->fetchValue('SELECT COUNT(*) FROM worker_payouts WHERE withdrawal_request_id=:request_id OR external_transaction_no=:external',['request_id'=>$id,'external'=>$external])>0)throw new RuntimeException('该提现申请或支付宝流水号已经登记过付款。');
            $payoutId=$this->db->insert("INSERT INTO worker_payouts(public_no,withdrawal_request_id,worker_id,amount_cents,channel,external_transaction_no,status,paid_by,paid_at,created_at) VALUES (:public_no,:request_id,:worker_id,:amount,'alipay_manual',:external,'paid',:admin_id,NOW(),NOW())",['public_no'=>\business_no('PO'),'request_id'=>$id,'worker_id'=>$request['worker_id'],'amount'=>$request['amount_cents'],'external'=>$external,'admin_id'=>$adminId]);
            $this->db->execute("INSERT INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,business_type,business_id,idempotency_key,description,status,created_by_admin_id,created_at) VALUES (:worker_id,'withdrawal_paid','debit',:amount,0,:frozen_delta,'worker_payout',:payout_id,:idempotency_key,:description,'effective',:admin_id,NOW())",['worker_id'=>$request['worker_id'],'amount'=>$request['amount_cents'],'frozen_delta'=>-((int)$request['amount_cents']),'payout_id'=>$payoutId,'idempotency_key'=>'withdrawal:'.$id.':paid','description'=>'管理员确认支付宝人工付款，流水号：'.$external,'admin_id'=>$adminId]);
        }elseif($target==='rejected'){
            $this->db->execute("INSERT INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,business_type,business_id,idempotency_key,description,status,created_by_admin_id,created_at) VALUES (:worker_id,'withdrawal_release','transfer',:amount,:available_delta,:frozen_delta,'withdrawal_request',:request_id,:idempotency_key,:description,'effective',:admin_id,NOW())",['worker_id'=>$request['worker_id'],'amount'=>$request['amount_cents'],'available_delta'=>$request['amount_cents'],'frozen_delta'=>-((int)$request['amount_cents']),'request_id'=>$id,'idempotency_key'=>'withdrawal:'.$id.':released','description'=>'管理员驳回提现并解冻余额：'.$note,'admin_id'=>$adminId]);
        }
        $this->db->execute("UPDATE withdrawal_requests SET status=:status,reviewed_by=:admin_id,review_note=:note,reviewed_at=NOW(),paid_at=IF(:paid='paid',NOW(),paid_at),updated_at=NOW() WHERE id=:id",['status'=>$target,'admin_id'=>$adminId,'note'=>$note,'paid'=>$target,'id'=>$id]);
        $this->db->execute("INSERT INTO withdrawal_events(withdrawal_request_id,event_type,from_status,to_status,note,operator_type,operator_id,created_at) VALUES (:request_id,'admin_transition',:from_status,:to_status,:note,'admin',:admin_id,NOW())",['request_id'=>$id,'from_status'=>$request['status'],'to_status'=>$target,'note'=>$note,'admin_id'=>$adminId]);
        OrderNotificationService::queueWorkerWithdrawalResult($this->db,(int)$request['worker_id'],(string)$request['public_no'],$target,$note,(int)$request['amount_cents']);
        return ['target'=>$target,'request'=>$request];
    }

    /** @return array<string,mixed> */
    public function markTailBadDebtLocked(int $id,string $note,int $adminId): array
    {
        $record=$this->db->fetch('SELECT * FROM offline_payment_delay_requests WHERE id=:id FOR UPDATE',['id'=>$id]);
        if(!$record||!in_array($record['status'],['pending','acknowledged','reported'],true))throw new RuntimeException('记录不存在或已标记坏账，请刷新后重试。');
        $this->db->execute("UPDATE offline_payment_delay_requests SET status='bad_debt',reviewed_by=:admin_id,review_note=:note,reviewed_at=NOW(),updated_at=NOW() WHERE id=:id",['admin_id'=>$adminId,'note'=>$note,'id'=>$id]);
        return ['order_id'=>(int)$record['order_id'],'event_type'=>'offline_collection_bad_debt','event_message'=>'管理员已将线下尾款 '.\money((int)$record['amount_cents_snapshot']).' 标记为坏账：'.$note,'event_extra'=>['amount_cents'=>(int)$record['amount_cents_snapshot']]];
    }

    /** @return array<string,mixed> */
    public function reviewTailSettlementLocked(int $id,string $target,string $note,int $adminId): array
    {
        $request=$this->db->fetch('SELECT * FROM offline_collection_adjustment_requests WHERE id=:id FOR UPDATE',['id'=>$id]);
        if(!$request||(string)$request['status']!=='auto_approved')throw new RuntimeException('记录不存在或已经完成复核，请刷新列表。');
        if($target==='rejected'){
            $assignment=$this->db->fetch('SELECT * FROM assignments WHERE id=:id FOR UPDATE',['id'=>$request['assignment_id']]);
            $receivable=$this->db->fetch('SELECT * FROM commission_receivables WHERE order_id=:id FOR UPDATE',['id'=>$request['order_id']]);
            if(!$assignment)throw new RuntimeException('订单指派记录不存在，无法恢复原金额。');
            if($receivable&&(int)$receivable['amount_paid_cents']>0)throw new RuntimeException('本单平台服务费已经支付，不能自动驳回，请先进行人工财务调整。');
            $total=(int)$request['original_total_cents'];$booking=(int)$assignment['booking_fee_cents_snapshot'];$rate=(int)$assignment['commission_rate_bp_snapshot'];$minimum=(int)$assignment['commission_min_cents_snapshot'];
            $gross=min($total,max((int)round($total*$rate/10000),$minimum));$offset=min($booking,$gross);$due=max(0,$gross-$offset);$net=$total-$gross;
            $this->db->execute('UPDATE orders SET final_amount_cents=:total,updated_at=NOW(),version=version+1 WHERE id=:id',['total'=>$total,'id'=>$request['order_id']]);
            $this->db->execute('UPDATE assignments SET final_amount_cents_snapshot=:total,estimated_commission_cents=:gross,estimated_offline_collect_cents=:offline,estimated_net_income_cents=:net,updated_at=NOW() WHERE id=:id',['total'=>$total,'gross'=>$gross,'offline'=>max(0,$total-$booking),'net'=>$net,'id'=>$assignment['id']]);
            if($receivable){
                $this->db->execute("UPDATE payment_orders SET status='closed',closed_at=NOW(),updated_at=NOW() WHERE business_type='commission' AND business_id=:id AND status IN ('created','pending')",['id'=>$receivable['id']]);
                $this->db->execute("UPDATE commission_payments SET status='failed' WHERE receivable_id=:id AND status='pending'",['id'=>$receivable['id']]);
                $this->db->execute("UPDATE commission_receivables SET final_amount_cents_snapshot=:total,gross_commission_cents=:gross,booking_fee_offset_cents=:offset,amount_due_cents=:due_amount,status=IF(:due_status=0,'settled','pending'),settled_at=IF(:due_settled=0,NOW(),NULL),updated_at=NOW() WHERE id=:id",['total'=>$total,'gross'=>$gross,'offset'=>$offset,'due_amount'=>$due,'due_status'=>$due,'due_settled'=>$due,'id'=>$receivable['id']]);
            }
            $eventType='offline_collection_adjustment_rejected';$eventMessage='管理员驳回异常尾款，已恢复 '.\money((int)$request['original_tail_cents']).'：'.$note;$eventExtra=['original_tail_cents'=>(int)$request['original_tail_cents'],'requested_tail_cents'=>(int)$request['requested_tail_cents']];
        }else{
            $eventType='offline_collection_adjustment_approved';$eventMessage='管理员复核实际尾款无误';$eventExtra=['requested_tail_cents'=>(int)$request['requested_tail_cents']];
        }
        $this->db->execute('UPDATE offline_collection_adjustment_requests SET status=:status,reviewed_by=:admin,review_note=:note,reviewed_at=NOW(),updated_at=NOW() WHERE id=:id',['status'=>$target,'admin'=>$adminId,'note'=>$note!==''?$note:null,'id'=>$id]);
        return ['order_id'=>(int)$request['order_id'],'event_type'=>$eventType,'event_message'=>$eventMessage,'event_extra'=>$eventExtra];
    }
}
