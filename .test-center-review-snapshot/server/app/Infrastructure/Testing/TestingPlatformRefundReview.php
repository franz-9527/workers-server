<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Testing;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Persistence\DatabasePlatformOrderFinanceRepository;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 已成功退款是显式内部夹具；其后的建档、判责、分笔核账调用正式财务服务。 */
final class TestingPlatformRefundReview
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}
    public function execute(array $i,array $o,string $action,int $admin):array
    {
        if(!TestingEnvironmentPolicy::active()||!TestingPlatformScenario::supports((string)$i['scenario_key'])||(int)$o['is_demo']!==1||$o['public_no']!==$i['order_no_snapshot']||$o['order_source_type']!=='platform'||$o['lifecycle_status']!=='completed')throw new TestCenterException('平台退款核账测试边界失败。','TEST_PLATFORM_REFUND_BOUNDARY',403);
        return$this->db->transaction(function()use($i,$o,$action,$admin):array{
        $finance=new DatabasePlatformOrderFinanceRepository($this->db);$before=$this->wallet((int)$o['id']);
        $result=$this->db->transaction(function()use($i,$o,$action,$admin,$finance):array{
            $order=(int)$o['id'];$instance=(int)$i['id'];$prefix='TEST_INTERNAL:platform-refund:'.$instance;
            if($action==='fixture_platform_refund'){
                if($this->db->fetchValue('SELECT id FROM refunds WHERE order_id=:id',['id'=>$order]))throw new TestCenterException('退款夹具已经存在。','TEST_PLATFORM_REFUND_EXISTS',409);
                $amount=1000;$fixtureNo='PRT'.substr(hash('sha256',$prefix),0,25);$payment=$this->db->insert("INSERT INTO payment_orders(public_no,business_type,business_id,order_id,payer_user_id,app_id,mch_id,api_version,amount_cents,status,paid_at,created_at,updated_at) VALUES(:no,'platform_refund_fixture',:business,:order,:user,'TEST_INTERNAL','TEST_INTERNAL','v3',:amount,'refunded',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['no'=>$fixtureNo,'business'=>$order,'order'=>$order,'user'=>(int)$o['user_id'],'amount'=>$amount]);
                $this->db->insert("INSERT INTO test_payment_facts(instance_id,payment_order_id,business_type,simulated_provider,amount_cents,simulation_request_key,created_by_admin_id,created_at) VALUES(:instance,:payment,'platform_refund_fixture','internal_test',:amount,:key,:admin,UTC_TIMESTAMP())",['instance'=>$instance,'payment'=>$payment,'amount'=>$amount,'key'=>$prefix,'admin'=>$admin]);
                $refund=$this->db->insert("INSERT INTO refunds(public_no,payment_order_id,order_id,amount_cents,reason,status,requested_by_admin_id,requested_at,succeeded_at,created_at,updated_at) VALUES(:no,:payment,:order,:amount,'TEST 已退款前置夹具；不代表微信退款或外部渠道验证','succeeded',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['no'=>$fixtureNo,'payment'=>$payment,'order'=>$order,'amount'=>$amount,'admin'=>$admin]);
                return['fixture'=>true,'refund_id'=>$refund,'external_refund_verified'=>false];
            }
            $refund=$this->db->fetch("SELECT r.* FROM refunds r JOIN payment_orders p ON p.id=r.payment_order_id JOIN test_payment_facts tf ON tf.payment_order_id=p.id AND tf.instance_id=:instance WHERE r.order_id=:order AND r.public_no=:no AND p.business_type='platform_refund_fixture' AND p.app_id='TEST_INTERNAL' AND p.wechat_transaction_id IS NULL AND p.wechat_prepay_id IS NULL",['instance'=>$instance,'order'=>$order,'no'=>'PRT'.substr(hash('sha256',$prefix),0,25)]);
            if(!$refund)throw new TestCenterException('退款不属于当前内部夹具。','TEST_PLATFORM_REFUND_BOUNDARY',409);
            if($action==='admin_platform_refund_review'){$finance->openRefundReview((int)$refund['id'],$admin);return['refund_id'=>(int)$refund['id']];}
            $review=$this->db->fetch('SELECT * FROM platform_order_refund_reviews WHERE refund_id=:id AND order_id=:order',['id'=>(int)$refund['id'],'order'=>$order]);if(!$review)throw new TestCenterException('请先建立核账记录。','TEST_PLATFORM_REFUND_REVIEW_MISSING',409);
            if($action==='admin_platform_refund_decide'){$finance->decideRefundReview((int)$review['id'],'shared',500,'TEST 责任各半，保持原工费台账',$admin);return['review_id'=>(int)$review['id'],'worker_recovery_cents'=>500];}
            $amount=min(250,(int)$review['worker_recovery_cents']-(int)$review['recovered_cents']);$reference=$prefix.':recovery:'.((int)$review['recovered_cents']+$amount);
            $finance->confirmRefundRecovery((int)$review['id'],$amount,$reference,'TEST_INTERNAL 分笔核账夹具，非真实转账凭证',$admin);
            return['review_id'=>(int)$review['id'],'amount_cents'=>$amount,'external_receipt_verified'=>false];
        });
        if($before!==$this->wallet((int)$o['id']))throw new TestCenterException('退款复核不得自动扣减师傅钱包。','TEST_PLATFORM_REFUND_WALLET_CHANGED',500);
        return$result+['wallet_unchanged'=>true];
        });
    }
    private function wallet(int $order):string{return hash('sha256',(string)json_encode($this->db->fetchAll("SELECT * FROM worker_balance_ledger WHERE business_type='platform_order' AND business_id=:id ORDER BY id",['id'=>$order])));}
}
