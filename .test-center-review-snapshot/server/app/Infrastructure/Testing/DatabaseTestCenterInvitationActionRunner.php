<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Infrastructure\Messaging\DatabaseAdminOrderEventRepository;
use WorkerAdmin\Infrastructure\Messaging\LegacyOrderNotificationAdapter;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminOrderWriteStore;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminOrderWorkflowStore;
use WorkerAdmin\Infrastructure\Agreements\PlatformWorkerAgreementVersionProvider;

/** 正式邀请业务 + 明确标记的本地入驻/审核夹具；不伪造云审核或微信身份。 */
final class DatabaseTestCenterInvitationActionRunner implements TestCenterActionRunner
{
    public const SCENARIO='ordinary.construction.multi_quote.waiting.invitation';
    private const LABELS=['admin_invitation_quote'=>'管理员发布平台报价','customer_select_invitation_quote'=>'客户选择平台报价','admin_create_worker_invitation'=>'生成定向师傅邀请','worker_probe_unregistered_invite'=>'验证未入驻师傅被拒绝','fixture_invitee_onboarding'=>'生成独占待审核师傅夹具','worker_claim_invitation'=>'待审核师傅领取当前邀请','worker_accept_invitation'=>'待审核师傅接受当前指派','worker_probe_invitation_scope'=>'验证邀请不授予其它订单报价权','fixture_invitee_approval'=>'登记本地审核通过夹具'];
    private Database $db;private TestCenterActionRunner $inner;private TestingActionExecution $execution;private OrderApplicationContainer $orders;private \WorkerAdmin\Contracts\TestCenterRepository $repository;
    public function __construct(Database $db,TestCenterActionRunner $inner,\WorkerAdmin\Contracts\TestCenterRepository $repository){$this->db=$db;$this->inner=$inner;$this->repository=$repository;$this->execution=new TestingActionExecution($db);$this->orders=new OrderApplicationContainer($db);}
    public function available(string $public): array
    {
        [$i,$o]=$this->execution->context($public);if($i['scenario_key']!==self::SCENARIO)return$this->inner->available($public);if(!$o)return['items'=>[]];
        $key=$this->next($i,$o);return$key!==''?['items'=>[$key=>['key'=>$key,'label'=>self::LABELS[$key]]],'message'=>'入驻与审核仅为当前实例本地夹具；领取、接受邀请均调用正式业务。']: $this->inner->available($public);
    }
    public function run(string $public,string $action,array $input,int $admin,string $request): array
    {
        if(!isset(self::LABELS[$action]))return$this->inner->run($public,$action,$input,$admin,$request);
        return$this->execution->run($public,$action,[],$admin,$request,strpos($action,'worker_')===0?'worker':(strpos($action,'customer_')===0?'user':'admin'),strpos($action,'fixture_')===0?'local_actor_fixture':'real_service',function(array $i,array $o)use($action,$admin):array{
            if($i['scenario_key']!==self::SCENARIO||$this->next($i,$o)!==$action)throw new TestCenterException('邀请场景状态已经变化。','TEST_INVITE_STATE_CHANGED',409);
            $events=new DatabaseAdminOrderEventRepository($this->db,new LegacyOrderNotificationAdapter($this->db));
            if($action==='admin_invitation_quote')return['quote_id'=>(new DatabaseAdminOrderWriteStore($this->db,$events))->quote((int)$o['id'],['amount'=>'520.00','includes'=>'TEST 定向邀请施工'],$admin)];
            if($action==='customer_select_invitation_quote'){$q=$this->db->fetch("SELECT public_id FROM quotes WHERE order_id=:id AND quote_type='platform' AND status='active' ORDER BY id DESC LIMIT 1",['id'=>(int)$o['id']]);return$this->orders->quoteSelection()->execute(['user_id'=>(int)$o['user_id'],'real_user_id'=>(int)$o['user_id'],'is_demo_actor'=>true,'demo_role'=>'user','is_admin'=>false,'business_admin'=>false],['quote_id'=>$q['public_id']]);}
            if($action==='admin_create_worker_invitation')return$this->db->transaction(function()use($i,$o,$admin,$events):array{$this->ensureUser($i);return(new DatabaseAdminOrderWorkflowStore($this->db,$events,false))->invite((int)$o['id'],['share_type'=>'worker_invite','commission_rate'=>10,'valid_days'=>7,'reason'=>'TEST 定向邀请，非真实分享'],$admin);});
            if($action==='fixture_invitee_onboarding')return$this->onboard($i);
            $actor=$this->actor((int)$i['id']);$token=$this->token($i,$o);
            if($action==='worker_probe_unregistered_invite'){
                $before=(int)$this->db->fetchValue('SELECT COUNT(*) FROM assignments WHERE order_id=:id',['id'=>(int)$o['id']]);
                try{$this->orders->invitation()->claim($actor,['token'=>$token]);}catch(OrderOperationException $e){if($e->apiCode()==='WORKER_ONBOARDING_REQUIRED'&&$before===(int)$this->db->fetchValue('SELECT COUNT(*) FROM assignments WHERE order_id=:id',['id'=>(int)$o['id']]))return['expected_rejection'=>true,'error_code'=>$e->apiCode()];throw$e;}
                throw new TestCenterException('未入驻师傅不应领取成功。','TEST_UNREGISTERED_INVITE_BROKEN',500);
            }
            if($action==='worker_claim_invitation')return$this->orders->invitation()->claim($actor,['token'=>$token]);
            if($action==='worker_probe_invitation_scope'){
                $s=(new \WorkerAdmin\Application\Testing\TestScenarioCatalog())->find('ordinary.construction.multi_quote.waiting.zero_quotes');$params=$s['defaults'];$params['title']='TEST 邀请越权探针独立订单';
                $d=$this->repository->build($s,$params,$admin,'invite-scope:'.substr(hash('sha256',$i['public_id']),0,50),(int)$i['id']);$target=$d['order'];
                if(!$target||(int)$target['is_demo']!==1||(int)$target['id']===(int)$o['id'])throw new TestCenterException('邀请越权探针目标归属失败。','TEST_INVITATION_BOUNDARY',409);
                $before=(int)$this->db->fetchValue('SELECT COUNT(*) FROM quotes WHERE order_id=:id',['id'=>(int)$target['id']]);
                try{$this->orders->deal()->execute('quotes.create',$actor,['order_id'=>$target['public_no'],'amount_cents'=>52000,'rule_acknowledged'=>true,'agreement_versions'=>(new PlatformWorkerAgreementVersionProvider($this->db))->currentVersions(),'distance_acknowledged'=>true]);}catch(OrderOperationException $e){if($e->apiCode()==='WORKER_NOT_APPROVED'&&$before===(int)$this->db->fetchValue('SELECT COUNT(*) FROM quotes WHERE order_id=:id',['id'=>(int)$target['id']]))return['expected_rejection'=>true,'error_code'=>$e->apiCode(),'probe_instance_public_id'=>$d['instance']['public_id'],'cleanup_independently'=>true];throw$e;}
                throw new TestCenterException('当前邀请不应授予其它订单报价资格。','TEST_INVITATION_SCOPE_BROKEN',500);
            }
            if($action==='worker_accept_invitation'){$a=$this->db->fetch('SELECT public_id FROM assignments WHERE id=:id AND order_id=:order',['id'=>(int)$o['current_assignment_id'],'order'=>(int)$o['id']]);return$this->orders->deal()->execute('assignments.accept',$actor,['assignment_id'=>$a['public_id'],'rule_acknowledged'=>true,'agreement_versions'=>(new PlatformWorkerAgreementVersionProvider($this->db))->currentVersions()]);}
            // 仅修改独占、demo_local 参与者。这里不声称调用或验证正式云端审核。
            return$this->db->transaction(function()use($i,$actor):array{$this->assertExclusive($i,$actor);$this->db->execute("UPDATE worker_profiles SET verification_status='approved',verified_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND cloud_record_mode='demo_local' AND cloud_worker_id IS NULL AND verification_status='pending'",['id'=>(int)$actor['worker_id']]);$this->db->execute("UPDATE test_participants SET expected_status='approved' WHERE instance_id=:id AND slot_key='invitee'",['id'=>(int)$i['id']]);return['fixture'=>true,'cloud_review_verified'=>false,'worker_id'=>(int)$actor['worker_id']];});
        });
    }
    private function next(array $i,array $o): string
    {
        if($o['payment_status']!=='paid'||$o['audit_status']!=='approved'||$o['lifecycle_status']!=='active')return'';
        if(empty($o['selected_quote_id']))return$this->db->fetchValue("SELECT id FROM quotes WHERE order_id=:id AND quote_type='platform' AND status='active'",['id'=>(int)$o['id']])?'customer_select_invitation_quote':'admin_invitation_quote';
        if(!$this->db->fetchValue('SELECT id FROM invite_tokens WHERE order_id=:id',['id'=>(int)$o['id']]))return'admin_create_worker_invitation';
        if(!$this->passed((int)$i['id'],'worker_probe_unregistered_invite'))return'worker_probe_unregistered_invite';
        $a=$this->actor((int)$i['id']);if(empty($a['worker_id']))return'fixture_invitee_onboarding';
        if(empty($o['current_assignment_id']))return'worker_claim_invitation';
        if($o['assignment_status']==='offered')return$this->passed((int)$i['id'],'worker_probe_invitation_scope')?'worker_accept_invitation':'worker_probe_invitation_scope';
        return$a['worker_status']==='pending'?'fixture_invitee_approval':'';
    }
    private function passed(int $id,string $action): bool{return(bool)$this->db->fetchValue("SELECT id FROM test_runs WHERE instance_id=:id AND action_key=:action AND result='passed'",['id'=>$id,'action'=>$action]);}
    private function ensureUser(array $i): void
    {
        if($this->db->fetchValue("SELECT id FROM test_participants WHERE instance_id=:id AND slot_key='invitee'",['id'=>(int)$i['id']]))return;
        $public='01KTEST'.strtoupper(substr(hash('sha256',$i['public_id'].':invitee'),0,19));$user=$this->db->insert("INSERT INTO users(public_id,nickname,mobile_masked,status,created_at,updated_at) VALUES(:public,'独占邀请测试师傅','139****0009','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>$public]);
        $this->db->insert("INSERT INTO test_participants(instance_id,slot_key,actor_type,user_id,expected_status,created_at) VALUES(:instance,'invitee','worker',:user,'unregistered',UTC_TIMESTAMP())",['instance'=>(int)$i['id'],'user'=>$user]);
    }
    private function onboard(array $i): array
    {
        return$this->db->transaction(function()use($i):array{$a=$this->actor((int)$i['id']);$this->assertExclusive($i,$a);$worker=$this->db->insert("INSERT INTO worker_profiles(user_id,public_id,display_name,mobile_masked,verification_status,invited_only,city,district,lat,lng,coordinate_system,rating,completed_orders,profile_json,profile_source,cloud_record_count,cloud_record_mode,created_at,updated_at) VALUES(:user,:public,'独占邀请测试师傅','139****0009','pending',0,'杭州市','拱墅区',30.3192,120.1506,'GCJ-02',5,0,:profile,'server',0,'demo_local',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['user'=>(int)$a['user_id'],'public'=>'01KTEST'.strtoupper(substr(hash('sha256',$i['public_id'].':invitee-profile'),0,19)),'profile'=>'{"source":"test_center_invitation","fixture":true}']);$this->db->execute("UPDATE test_participants SET worker_id=:worker,expected_status='pending' WHERE instance_id=:id AND slot_key='invitee'",['worker'=>$worker,'id'=>(int)$i['id']]);return['fixture'=>true,'worker_id'=>$worker,'onboarding_http_verified'=>false];});
    }
    private function assertExclusive(array $i,array $a): void
    {
        $expected='01KTEST'.strtoupper(substr(hash('sha256',$i['public_id'].':invitee'),0,19));$u=$this->db->fetch('SELECT public_id FROM users WHERE id=:id FOR UPDATE',['id'=>(int)$a['user_id']]);$other=(int)$this->db->fetchValue('SELECT COUNT(*) FROM test_participants WHERE user_id=:user AND instance_id<>:instance',['user'=>(int)$a['user_id'],'instance'=>(int)$i['id']]);
        if(!$u||$u['public_id']!==$expected||$other!==0)throw new TestCenterException('邀请测试参与者必须独占。','TEST_INVITEE_OWNERSHIP',403);
        if(!empty($a['worker_id'])){$w=$this->db->fetch('SELECT cloud_record_mode,cloud_worker_id FROM worker_profiles WHERE id=:id FOR UPDATE',['id'=>(int)$a['worker_id']]);if(!$w||$w['cloud_record_mode']!=='demo_local'||!empty($w['cloud_worker_id']))throw new TestCenterException('禁止修改真实师傅。','TEST_INVITEE_OWNERSHIP',403);}
    }
    private function actor(int $id): array
    {
        $w=$this->db->fetch("SELECT tp.user_id,tp.worker_id,w.verification_status,w.lat,w.lng FROM test_participants tp LEFT JOIN worker_profiles w ON w.id=tp.worker_id AND w.user_id=tp.user_id WHERE tp.instance_id=:id AND tp.slot_key='invitee'",['id'=>$id]);
        return['user_id'=>(int)($w['user_id']??0),'real_user_id'=>(int)($w['user_id']??0),'worker_id'=>(int)($w['worker_id']??0),'worker_status'=>(string)($w['verification_status']??''),'worker_lat'=>$w['lat']??null,'worker_lng'=>$w['lng']??null,'is_demo_actor'=>true,'demo_role'=>'worker','is_admin'=>false,'business_admin'=>false,'invited_only'=>false];
    }
    private function token(array $i,array $o): string
    {
        $run=$this->db->fetch("SELECT after_summary_json FROM test_runs WHERE instance_id=:id AND action_key='admin_create_worker_invitation' AND result='passed' ORDER BY id DESC LIMIT 1",['id'=>(int)$i['id']]);$data=json_decode((string)($run['after_summary_json']??''),true);$token=(string)($data['business']['token']??'');
        if($token===''||!(int)$this->db->fetchValue('SELECT COUNT(*) FROM invite_tokens WHERE order_id=:id AND token_hash=:hash',['id'=>(int)$o['id'],'hash'=>hash('sha256',$token)]))throw new TestCenterException('邀请令牌不属于本实例。','TEST_INVITATION_BOUNDARY',409);return$token;
    }
}
