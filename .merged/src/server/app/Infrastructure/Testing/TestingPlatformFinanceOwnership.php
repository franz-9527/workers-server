<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\PlatformOrderSettlementPolicy;

/** 平台测试财务事实的精确归属；任何提现、外部凭证或未登记支付均阻断清理。 */
final class TestingPlatformFinanceOwnership
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db=$db;
    }

    public function inspect(array $i,array $o,array $t): array
    {
        $id=(int)$o['id'];
        $instance=(int)$i['id'];
        $blocking=[];
        $facts=[];
        $policy=new PlatformOrderSettlementPolicy();

        $facts['ledger']=$this->db->fetchAll(
            "SELECT * FROM worker_balance_ledger
             WHERE business_id=:id
               AND business_type IN ('platform_order','order_measurement','platform_order_income','platform_order_settlement')
             ORDER BY id",
            ['id'=>$id]
        );

        $credit=$o['lifecycle_status']==='completed'&&($t['worker_settlement_mode']??'')==='platform_balance'
            ?(int)($t['worker_labor_cents']??0)
            :0;
        $entry=$policy->workerBalanceEntry($credit,(int)($t['worker_balance_freeze_days_snapshot']??0));
        $source=null;
        $release=null;
        foreach($facts['ledger']as$l){
            if((string)$l['idempotency_key']==='platform-order:'.$id.':worker-income')$source=$l;
            elseif((string)$l['idempotency_key']==='platform-order:'.$id.':worker-income-release')$release=$l;
            else$blocking['ledger_unowned']=1;
        }

        if($credit>0){
            if(!$source){
                $blocking['ledger_source_missing']=1;
            }else{
                $validSource=(string)$source['business_type']==='platform_order'
                    &&(int)$source['worker_id']===(int)$o['assigned_worker_id']
                    &&(int)$source['amount_cents']===$credit
                    &&(string)$source['entry_type']===(string)$entry['entry_type']
                    &&(int)$source['available_delta_cents']===(int)$entry['available_delta_cents']
                    &&(int)$source['frozen_delta_cents']===(int)$entry['frozen_delta_cents']
                    &&(string)$source['status']==='effective';
                if(!$validSource)$blocking['ledger_unowned']=1;
            }

            $frozen=(int)($t['worker_balance_freeze_days_snapshot']??0)>0;
            if(!$frozen&&$release)$blocking['ledger_release_unexpected']=1;
            if($release){
                $validRelease=$frozen
                    &&(string)$release['business_type']==='platform_order'
                    &&(int)$release['worker_id']===(int)$o['assigned_worker_id']
                    &&(int)$release['amount_cents']===$credit
                    &&(string)$release['entry_type']==='platform_order_income_release'
                    &&(string)$release['direction']==='transfer'
                    &&(int)$release['available_delta_cents']===$credit
                    &&(int)$release['frozen_delta_cents']===-$credit
                    &&(string)$release['status']==='effective';
                if(!$validRelease)$blocking['ledger_release_unowned']=1;
            }
            $expectedCount=1+($release?1:0);
            if(count($facts['ledger'])!==$expectedCount)$blocking['ledger_count']=count($facts['ledger']);
        }elseif(count($facts['ledger'])!==0){
            $blocking['ledger_count']=count($facts['ledger']);
        }

        foreach($facts['ledger']as$l){
            if($this->db->tableExists('withdrawal_allocations')
                &&(int)$this->db->fetchValue(
                    'SELECT COUNT(*) FROM withdrawal_allocations WHERE ledger_entry_id=:id',
                    ['id'=>(int)$l['id']]
                )>0){
                $blocking['ledger_withdrawn']=1;
            }
        }

        $facts['payments']=$this->rows('payment_orders',$id);
        $facts['refunds']=$this->rows('refunds',$id);
        $facts['reviews']=$this->rows('platform_order_refund_reviews',$id);
        $facts['receipts']=$this->rows('platform_order_manual_receipts',$id);
        $facts['payment_facts']=$this->db->fetchAll(
            'SELECT * FROM test_payment_facts WHERE instance_id=:id ORDER BY id',
            ['id'=>$instance]
        );

        $prefix='TEST_INTERNAL:platform-refund:'.$instance;
        $no='PRT'.substr(hash('sha256',$prefix),0,25);
        if(count($facts['payments'])!==count($facts['payment_facts'])
            ||count($facts['payments'])>1
            ||count($facts['refunds'])!==count($facts['payments'])){
            $blocking['refund_fixture_count']=1;
        }

        foreach($facts['payments']as$p){
            $tf=count($facts['payment_facts'])===1?$facts['payment_facts'][0]:null;
            $r=count($facts['refunds'])===1?$facts['refunds'][0]:null;
            if(!$tf
                ||!$r
                ||(int)$tf['payment_order_id']!==(int)$p['id']
                ||$tf['simulated_provider']!=='internal_test'
                ||$tf['simulation_request_key']!==$prefix
                ||$p['public_no']!==$no
                ||$p['business_type']!=='platform_refund_fixture'
                ||$p['app_id']!=='TEST_INTERNAL'
                ||$p['mch_id']!=='TEST_INTERNAL'
                ||!empty($p['wechat_transaction_id'])
                ||!empty($p['wechat_prepay_id'])
                ||$p['status']!=='refunded'
                ||(int)$p['amount_cents']!==1000
                ||(int)$r['payment_order_id']!==(int)$p['id']
                ||$r['public_no']!==$no
                ||$r['status']!=='succeeded'
                ||(int)$r['amount_cents']!==1000){
                $blocking['refund_fixture_unowned']=1;
            }
            if((int)$this->db->fetchValue(
                'SELECT COUNT(*) FROM refunds WHERE payment_order_id=:payment AND (order_id IS NULL OR order_id<>:order)',
                ['payment'=>(int)$p['id'],'order'=>$id]
            )>0){
                $blocking['refund_shared']=1;
            }
        }

        foreach($facts['reviews']as$v){
            if(count($facts['refunds'])!==1
                ||(int)$v['refund_id']!==(int)$facts['refunds'][0]['id']
                ||(int)$v['refund_amount_cents']!==1000
                ||(int)$v['worker_labor_cents_snapshot']!==(int)($t['worker_labor_cents']??0)
                ||(int)$v['worker_balance_credit_cents_snapshot']!==(int)($t['worker_balance_credit_cents']??0)){
                $blocking['review_unowned']=1;
            }
        }
        if(count($facts['reviews'])>1)$blocking['review_multiple']=1;

        foreach($facts['receipts']as$m){
            $valid=$m['receipt_kind']==='platform_income'
                ?$m['payment_reference']==='TEST_INTERNAL:platform:'.$instance
                    &&empty($m['refund_review_id'])
                    &&(int)$m['amount_cents']===(int)($t['platform_receivable_cents']??0)
                :$m['receipt_kind']==='refund_recovery'
                    &&count($facts['reviews'])===1
                    &&(int)$m['refund_review_id']===(int)$facts['reviews'][0]['id']
                    &&in_array($m['payment_reference'],[$prefix.':recovery:250',$prefix.':recovery:500'],true)
                    &&(int)$m['amount_cents']===250;
            if(!$valid||strpos((string)$m['verification_note'],'TEST')!==0)$blocking['receipt_unowned']=1;
        }

        return[
            'facts'=>$facts,
            'blocking'=>$blocking,
            'worker_balance_released'=>$release!==null,
        ];
    }

    private function rows(string $table,int $order): array
    {
        return$this->db->tableExists($table)
            ?$this->db->fetchAll("SELECT * FROM `{$table}` WHERE order_id=:id ORDER BY id",['id'=>$order])
            :[];
    }
}
