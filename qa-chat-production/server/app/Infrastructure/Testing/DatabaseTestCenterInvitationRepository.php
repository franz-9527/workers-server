<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Testing;
use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;

/** 邀请链的权限与金额断言；其余工厂及清理由普通单现有适配器处理。 */
final class DatabaseTestCenterInvitationRepository implements TestCenterRepository
{
    private Database $db;private TestCenterRepository $inner;
    public function __construct(Database $db,TestCenterRepository $inner){$this->db=$db;$this->inner=$inner;}
    public function environmentDiagnostics():array{return$this->inner->environmentDiagnostics();}
    public function listInstances(array $filters=[]):array{return$this->inner->listInstances($filters);}
    public function instanceDetail(string $id):array{return$this->inner->instanceDetail($id);}
    public function rebuildSource(string $id):array{return$this->inner->rebuildSource($id);}
    public function build(array $s,array $p,int $admin,string $key,?int $source=null,?int $rebuilt=null):array{return$this->inner->build($s,$p,$admin,$key,$source,$rebuilt);}
    public function cleanupPreview(string $id):array{return$this->inner->cleanupPreview($id);}
    public function cleanup(string $id,int $admin,string $digest):array{return$this->inner->cleanup($id,$admin,$digest);}
    public function verify(string $public,int $admin):array
    {
        $i=$this->db->fetch('SELECT * FROM test_instances WHERE public_id=:id',['id'=>$public]);if(!$i||$i['scenario_key']!==DatabaseTestCenterInvitationActionRunner::SCENARIO)return$this->inner->verify($public,$admin);
        $o=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$i['order_id']]);if(!$o)return$this->inner->verify($public,$admin);
        $id=(int)$o['id'];$p=$this->db->fetch("SELECT tp.*,w.verification_status,w.cloud_record_mode,w.cloud_worker_id FROM test_participants tp LEFT JOIN worker_profiles w ON w.id=tp.worker_id WHERE tp.instance_id=:id AND tp.slot_key='invitee'",['id'=>(int)$i['id']]);
        $checks=[['invitation_domain','同一 demo 普通施工单',(int)$o['is_demo']===1&&$o['order_source_type']==='consumer'&&$o['public_no']===$i['order_no_snapshot']&&strpos($o['public_no'],'BXDEMOHALL')!==0,$o['public_no']]];
        $tokens=$this->db->fetchAll('SELECT * FROM invite_tokens WHERE order_id=:id',['id'=>$id]);
        if($tokens){$t=count($tokens)===1?$tokens[0]:null;$checks[]=['invite_scope','单次 worker_assignment 邀请指向当前订单',$t&&$t['purpose']==='worker_assignment'&&(int)$t['max_claims']===1&&(int)$t['claim_count']<=1,'tokens='.count($tokens)];}
        if($p&&!empty($p['worker_id']))$checks[]=['local_actor_fixture','独占本地师傅，无真实云身份',$p['cloud_record_mode']==='demo_local'&&empty($p['cloud_worker_id'])&&in_array($p['verification_status'],['pending','approved'],true),(string)$p['verification_status']];
        if(!empty($o['selected_quote_id'])){$q=$this->db->fetch('SELECT * FROM quotes WHERE id=:id',['id'=>(int)$o['selected_quote_id']]);$selection=$this->db->fetch('SELECT * FROM quote_selections WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>$id]);$checks[]=['customer_selected_platform_price','客户选价快照金额与订单一致',$q&&$q['quote_type']==='platform'&&$q['status']==='selected'&&$selection&&(int)$selection['amount_cents_snapshot']===(int)$o['final_amount_cents']&&(int)$q['amount_cents']===(int)$o['final_amount_cents'],'amount='.(int)$o['final_amount_cents']];}
        if(!empty($o['current_assignment_id'])){$a=$this->db->fetch('SELECT * FROM assignments WHERE id=:id',['id'=>(int)$o['current_assignment_id']]);$checks[]=['only_invited_assignment','仅邀请参与者取得本单指派，工费快照未变',$a&&$p&&(int)$a['order_id']===$id&&(int)$a['worker_id']===(int)$p['worker_id']&&$a['assignment_type']==='invite'&&(int)$a['final_amount_cents_snapshot']===(int)$o['final_amount_cents']&&(int)$this->db->fetchValue('SELECT COUNT(*) FROM assignments WHERE order_id=:id',['id'=>$id])===1,'assignment='.(int)($a['id']??0)];}
        $checks[]=['no_external_payment','预约费只有 TEST_INTERNAL，未生成微信交易',(int)$this->db->fetchValue("SELECT COUNT(*) FROM payment_orders WHERE order_id=:id AND (app_id<>'TEST_INTERNAL' OR wechat_transaction_id IS NOT NULL OR wechat_prepay_id IS NOT NULL)",['id'=>$id])===0,'internal payment'];
        return(new TestingAssertionWriter($this->db))->write((int)$i['id'],'verify_invitation_flow',$checks,$admin);
    }
}
