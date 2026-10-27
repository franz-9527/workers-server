<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;

final class DatabaseAdminFinanceReadStore
{
    private Database $db;
    private PrivateValueCipher $cipher;

    public function __construct(Database $db, PrivateValueCipher $cipher)
    {
        $this->db=$db;$this->cipher=$cipher;
    }

    /** @return array<string,mixed> */
    public function resourcePage(string $resource,int $page,string $status,string $search,string $businessType,bool $includeDemo): array
    {
        $definitions=$this->definitions();
        if (!isset($definitions[$resource])) throw new \RuntimeException('财务资源不存在。');
        $d=$definitions[$resource];$where=['1=1'];$params=[];
        if (!$includeDemo) {
            $where[]=$d['demo'];
            if (strpos($d['demo'],':demo_public_no')!==false) $params['demo_public_no']='%DEMO%';
        }
        if ($status!=='') {$where[]=$d['alias'].'.status=:status';$params['status']=$status;}
        if ($search!=='' && $d['search']) {
            $parts=[];
            foreach ($d['search'] as $i=>$column) {
                $key='search_'.$i;$parts[]=$column.' LIKE :'.$key;$params[$key]='%'.$search.'%';
            }
            $where[]='('.implode(' OR ',$parts).')';
        }
        if ($resource==='payments' && in_array($businessType,['booking','commission','measurement','wallet'],true)) {
            $where[]=$businessType==='commission' ? "p.business_type='commission'" :
                ($businessType==='measurement' ? "p.business_type IN ('measurement_balance','measurement_change')" :
                ($businessType==='wallet' ? "p.business_type='wallet_recharge'" : "p.business_type IN ('order_booking','booking_fee')"));
        }
        $result=$this->paginate($d['select'].' WHERE '.implode(' AND ',$where).' ORDER BY '.$d['alias'].'.id DESC',$d['count'].' WHERE '.implode(' AND ',$where),$params,$page);
        if ($resource==='withdrawals') {
            foreach ($result['rows'] as &$row) {
                $name=$this->cipher->decrypt((string)($row['holder_name_ciphertext_snapshot']??''),'worker-payout-name');
                $account=$this->cipher->decrypt((string)($row['account_ciphertext_snapshot']??''),'worker-payout-account');
                if ($name!=='') $row['holder_name_masked_snapshot']=$name;
                if ($account!=='') $row['account_masked_snapshot']=$account;
                unset($row['holder_name_ciphertext_snapshot'],$row['account_ciphertext_snapshot']);
            }
            unset($row);
        }
        return $result;
    }

    /** @return array<string,int> */
    public function paymentStats(bool $includeDemo): array
    {
        $where=['1=1'];
        if (!$includeDemo) $where[]='(o.public_no IS NULL OR COALESCE(o.is_demo,0)=0)';
        $row=$this->db->fetch("SELECT
            COUNT(CASE WHEN p.status IN ('paid','refunded') AND p.business_type<>'wallet_recharge' THEN 1 END) paid_count,
            COALESCE(SUM(CASE WHEN p.status IN ('paid','refunded') AND p.business_type<>'wallet_recharge' THEN p.amount_cents ELSE 0 END),0) received_cents,
            COALESCE(SUM(CASE WHEN p.status IN ('paid','refunded') AND p.business_type='wallet_recharge' THEN p.amount_cents ELSE 0 END),0) wallet_recharge_cents,
            COALESCE(SUM(CASE WHEN p.status IN ('paid','refunded') AND p.business_type IN ('order_booking','booking_fee') THEN p.amount_cents ELSE 0 END),0) booking_cents,
            COALESCE(SUM(CASE WHEN p.status IN ('paid','refunded') AND p.business_type='commission' THEN p.amount_cents ELSE 0 END),0) commission_cents,
            COALESCE(SUM(CASE WHEN p.status IN ('paid','refunded') AND p.business_type IN ('measurement_balance','measurement_change') THEN p.amount_cents ELSE 0 END),0) measurement_cents
            FROM payment_orders p LEFT JOIN orders o ON o.id=p.order_id WHERE ".implode(' AND ',$where)) ?: [];
        $refundWhere=["r.status='succeeded'","(p.business_type IS NULL OR p.business_type<>'wallet_recharge')"];
        if (!$includeDemo) $refundWhere[]='(o.public_no IS NULL OR COALESCE(o.is_demo,0)=0)';
        $refunded=(int)$this->db->fetchValue('SELECT COALESCE(SUM(r.amount_cents),0) FROM refunds r LEFT JOIN orders o ON o.id=r.order_id LEFT JOIN payment_orders p ON p.id=r.payment_order_id WHERE '.implode(' AND ',$refundWhere));
        return [
            'paid_count'=>(int)($row['paid_count']??0),'received_cents'=>(int)($row['received_cents']??0),
            'wallet_recharge_cents'=>(int)($row['wallet_recharge_cents']??0),'booking_cents'=>(int)($row['booking_cents']??0),
            'commission_cents'=>(int)($row['commission_cents']??0),'measurement_cents'=>(int)($row['measurement_cents']??0),
            'refunded_cents'=>$refunded,
        ];
    }

    /** @return array<string,mixed> */
    public function wallets(int $page,int $walletId,string $search,bool $includeDemo): array
    {
        $empty=['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'per_page'=>20];
        if (!$this->db->tableExists('user_wallets') || !$this->db->tableExists('user_wallet_ledger')) {
            return ['result'=>$empty,'search'=>'','selected'=>null,'ledger'=>[],'recharges'=>[],'smsBatches'=>[],'reservations'=>[],
                'stats'=>['users'=>0,'available_cents'=>0,'reserved_cents'=>0,'refund_reserved_cents'=>0,'expired_reservations'=>0],
                'schemaWarning'=>'用户余额数据表尚未就绪，请先完成 V2.2.9 数据库升级。'];
        }
        $where=['1=1'];$params=[];
        if (!$includeDemo) $where[]="u.public_id NOT LIKE '%DEMO%'";
        if ($search!=='') {
            $like='%'.$search.'%';$where[]='(u.public_id LIKE :q_public OR u.nickname LIKE :q_nickname OR u.mobile_masked LIKE :q_mobile)';
            $params=['q_public'=>$like,'q_nickname'=>$like,'q_mobile'=>$like];
        }
        $result=$this->paginate("SELECT w.id,w.user_id,w.available_cents,w.reserved_cents,w.version,w.updated_at,u.public_id AS user_public_id,u.nickname,u.mobile_masked,u.status AS user_status,(SELECT MAX(l.created_at) FROM user_wallet_ledger l WHERE l.user_id=w.user_id) AS last_ledger_at FROM user_wallets w INNER JOIN users u ON u.id=w.user_id WHERE ".implode(' AND ',$where).' ORDER BY w.updated_at DESC,w.id DESC',"SELECT COUNT(*) FROM user_wallets w INNER JOIN users u ON u.id=w.user_id WHERE ".implode(' AND ',$where),$params,$page);
        $statsWhere=$includeDemo?'1=1':"u.public_id NOT LIKE '%DEMO%'";
        $stats=$this->db->fetch("SELECT COUNT(*) users,COALESCE(SUM(w.available_cents),0) available_cents,COALESCE(SUM(w.reserved_cents),0) reserved_cents FROM user_wallets w INNER JOIN users u ON u.id=w.user_id WHERE $statsWhere") ?: [];
        $stats['refund_reserved_cents']=$this->db->tableExists('user_wallet_reservations')?(int)$this->db->fetchValue("SELECT COALESCE(SUM(r.amount_cents),0) FROM user_wallet_reservations r INNER JOIN users u ON u.id=r.user_id WHERE r.business_type='wallet_recharge_refund' AND r.status='held'".($includeDemo?'':" AND u.public_id NOT LIKE '%DEMO%'")):0;
        $stats['expired_reservations']=$this->db->tableExists('user_wallet_reservations')?(int)$this->db->fetchValue("SELECT COUNT(*) FROM user_wallet_reservations r INNER JOIN users u ON u.id=r.user_id WHERE r.status='held' AND r.expires_at<=UTC_TIMESTAMP()".($includeDemo?'':" AND u.public_id NOT LIKE '%DEMO%'")):0;
        foreach(['users','available_cents','reserved_cents','refund_reserved_cents','expired_reservations'] as $key)$stats[$key]=(int)($stats[$key]??0);
        $selected=null;$ledger=[];$recharges=[];$smsBatches=[];$reservations=[];
        if ($walletId>0) {
            $selected=$this->db->fetch("SELECT w.*,u.public_id AS user_public_id,u.nickname,u.mobile_masked,u.status AS user_status FROM user_wallets w INNER JOIN users u ON u.id=w.user_id WHERE w.id=:id LIMIT 1",['id'=>$walletId]) ?: null;
            if ($selected && !$includeDemo && stripos((string)$selected['user_public_id'],'DEMO')!==false) $selected=null;
            if ($selected) {
                $userId=(int)$selected['user_id'];
                $ledger=$this->db->fetchAll("SELECT public_no,entry_type,direction,amount_cents,available_delta_cents,reserved_delta_cents,available_after_cents,reserved_after_cents,reference_type,description,status,created_at FROM user_wallet_ledger WHERE user_id=:user_id ORDER BY id DESC LIMIT 100",['user_id'=>$userId]);
                if ($this->db->tableExists('user_wallet_recharges')) $recharges=$this->db->fetchAll("SELECT r.id,r.public_no,r.amount_cents,r.status,r.created_at,r.paid_at,r.closed_at,r.refunded_at,p.public_no AS payment_no,p.status AS payment_status,p.wechat_transaction_id,rf.id AS refund_id,rf.public_no AS refund_no,rf.status AS refund_status,rf.failure_code AS refund_failure_code,rf.failure_message AS refund_failure_message,rf.reason AS refund_reason,rf.requested_at AS refund_requested_at,rf.succeeded_at AS refund_succeeded_at FROM user_wallet_recharges r LEFT JOIN payment_orders p ON p.id=r.payment_order_id LEFT JOIN refunds rf ON rf.id=(SELECT rr.id FROM refunds rr WHERE rr.payment_order_id=r.payment_order_id ORDER BY rr.id DESC LIMIT 1) WHERE r.user_id=:user_id ORDER BY r.id DESC LIMIT 50",['user_id'=>$userId]);
                if ($this->db->tableExists('user_wallet_reservations')) $reservations=$this->db->fetchAll("SELECT r.public_no,r.business_type,r.business_id,r.business_key,r.amount_cents,r.status,r.expires_at,r.consumed_at,r.released_at,r.created_at,CASE WHEN r.status='held' AND r.expires_at<=UTC_TIMESTAMP() THEN 1 ELSE 0 END AS is_expired_hold,i.public_no AS invite_no,i.delivery_status,i.billing_status,rf.public_no AS refund_no,rf.status AS refund_status FROM user_wallet_reservations r LEFT JOIN order_worker_sms_invites i ON r.business_type='nearby_worker_sms_invite' AND i.id=r.business_id LEFT JOIN refunds rf ON r.business_type='wallet_recharge_refund' AND rf.id=r.business_id WHERE r.user_id=:user_id ORDER BY r.id DESC LIMIT 100",['user_id'=>$userId]);
                if ($this->db->tableExists('order_worker_sms_batches')) $smsBatches=$this->db->fetchAll("SELECT b.public_no,o.public_no AS order_no,b.requested_count,b.valid_count,b.initial_held_cents,b.charged_cents,b.released_cents,b.status,b.created_at,b.completed_at FROM order_worker_sms_batches b LEFT JOIN orders o ON o.id=b.order_id WHERE b.user_id=:user_id ORDER BY b.id DESC LIMIT 50",['user_id'=>$userId]);
            }
        }
        return compact('result','search','selected','ledger','recharges','smsBatches','reservations','stats')+['schemaWarning'=>''];
    }

    /** @return array<string,mixed> */
    public function tailSettlement(string $search,bool $includeDemo): array
    {
        $delayWhere=['1=1'];$adjustWhere=['1=1'];$delayParams=[];$adjustParams=[];
        if (!$includeDemo) {$delayWhere[]='COALESCE(o.is_demo,0)=0';$adjustWhere[]='COALESCE(o.is_demo,0)=0';}
        if ($search!=='') {
            $like='%'.$search.'%';$delayWhere[]='(o.public_no LIKE :dq OR w.display_name LIKE :dw OR d.reason LIKE :dr)';$delayParams=['dq'=>$like,'dw'=>$like,'dr'=>$like];
            $adjustWhere[]='(o.public_no LIKE :aq OR w.display_name LIKE :aw OR a.reason LIKE :ar)';$adjustParams=['aq'=>$like,'aw'=>$like,'ar'=>$like];
        }
        $delayRows=$this->db->fetchAll('SELECT d.*,o.public_no,w.display_name AS worker_name FROM offline_payment_delay_requests d LEFT JOIN orders o ON o.id=d.order_id LEFT JOIN worker_profiles w ON w.id=d.worker_id WHERE '.implode(' AND ',$delayWhere).' ORDER BY d.id DESC LIMIT 200',$delayParams);
        $adjustmentRows=$this->db->fetchAll('SELECT a.*,o.public_no,w.display_name AS worker_name FROM offline_collection_adjustment_requests a LEFT JOIN orders o ON o.id=a.order_id LEFT JOIN worker_profiles w ON w.id=a.worker_id WHERE '.implode(' AND ',$adjustWhere).' ORDER BY a.id DESC LIMIT 200',$adjustParams);
        foreach ($adjustmentRows as &$row) {
            $ids=json_decode((string)($row['receipt_media_ids_json']??'[]'),true);$ids=is_array($ids)?array_values(array_filter(array_map('intval',$ids))):[];$row['receipt_media']=[];
            if ($ids) {$marks=implode(',',array_fill(0,count($ids),'?'));$row['receipt_media']=$this->db->fetchAll("SELECT id FROM media_files WHERE id IN ($marks) AND status='active' ORDER BY id",$ids);}
        }
        unset($row);
        return ['search'=>$search,'delayRows'=>$delayRows,'adjustmentRows'=>$adjustmentRows];
    }

    /** @return array<string,array<string,mixed>> */
    private function definitions(): array
    {
        return [
            'payments'=>['alias'=>'p','select'=>'SELECT p.id,p.public_no AS payment_no,o.public_no,p.business_type,p.api_version,p.amount_cents,p.status,p.wechat_transaction_id,p.paid_at,p.created_at FROM payment_orders p LEFT JOIN orders o ON o.id=p.order_id','count'=>'SELECT COUNT(*) FROM payment_orders p LEFT JOIN orders o ON o.id=p.order_id','demo'=>"(o.public_no IS NULL OR COALESCE(o.is_demo,0)=0)",'search'=>['p.public_no','o.public_no','p.wechat_transaction_id']],
            'refunds'=>['alias'=>'r','select'=>'SELECT r.id,r.public_no AS refund_no,o.public_no,r.amount_cents,r.reason,r.status,r.wechat_refund_id,r.requested_at,r.succeeded_at FROM refunds r LEFT JOIN orders o ON o.id=r.order_id','count'=>'SELECT COUNT(*) FROM refunds r LEFT JOIN orders o ON o.id=r.order_id','demo'=>"(o.public_no IS NULL OR COALESCE(o.is_demo,0)=0)",'search'=>['r.public_no','o.public_no','r.wechat_refund_id']],
            'commissions'=>['alias'=>'c','select'=>'SELECT c.id,o.public_no,w.display_name AS worker_name,c.gross_commission_cents,c.booking_fee_offset_cents,c.amount_due_cents,c.amount_paid_cents,c.rate_bp_snapshot,c.status,c.due_at,c.settled_at FROM commission_receivables c LEFT JOIN orders o ON o.id=c.order_id LEFT JOIN worker_profiles w ON w.id=c.worker_id','count'=>'SELECT COUNT(*) FROM commission_receivables c LEFT JOIN orders o ON o.id=c.order_id LEFT JOIN worker_profiles w ON w.id=c.worker_id','demo'=>"(o.public_no IS NULL OR COALESCE(o.is_demo,0)=0)",'search'=>['o.public_no','w.display_name']],
            'withdrawals'=>['alias'=>'w','select'=>'SELECT w.id,w.public_no AS request_no,p.display_name AS worker_name,w.amount_cents,w.account_type_snapshot,w.holder_name_masked_snapshot,w.holder_name_ciphertext_snapshot,w.account_masked_snapshot,w.account_ciphertext_snapshot,w.status,wp.external_transaction_no,w.reviewed_at,w.paid_at,w.created_at FROM withdrawal_requests w LEFT JOIN worker_profiles p ON p.id=w.worker_id LEFT JOIN worker_payouts wp ON wp.withdrawal_request_id=w.id','count'=>'SELECT COUNT(*) FROM withdrawal_requests w LEFT JOIN worker_profiles p ON p.id=w.worker_id LEFT JOIN worker_payouts wp ON wp.withdrawal_request_id=w.id','demo'=>"CONCAT_WS('|',w.public_no,p.public_id) NOT LIKE :demo_public_no",'search'=>['w.public_no','p.display_name','wp.external_transaction_no']],
        ];
    }

    /** @return array<string,mixed> */
    private function paginate(string $sql,string $countSql,array $params,int $page): array
    {
        $perPage=20;$total=(int)$this->db->fetchValue($countSql,$params);$pages=max(1,(int)ceil($total/$perPage));$page=min(max(1,$page),$pages);$offset=($page-1)*$perPage;
        return ['rows'=>$this->db->fetchAll($sql.' LIMIT '.$perPage.' OFFSET '.$offset,$params),'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$perPage];
    }
}
