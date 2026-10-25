<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/Domain/Chat/OrderConversationPolicy.php';
require_once dirname(__DIR__).'/app/Domain/Chat/CollaborationConversationPolicy.php';

use WorkerAdmin\Domain\Chat\CollaborationConversationPolicy;
use WorkerAdmin\Domain\Chat\OrderConversationPolicy;

$assertions=0;
function s24Assert(bool $ok,string $label): void {
    $GLOBALS['assertions']++;
    if (!$ok) throw new RuntimeException('S24: '.$label);
}
function s24Person(string $actor,string $source,string $display): array {
    $person=['actor_id'=>$actor,'kind'=>'mini','status'=>'active','role_source'=>$source,
        'membership_generation'=>'1','display_role'=>$display,'display_role_version'=>'1'];
    if($source==='invite'){$person['inviter_actor_id']='1';$person['invite_generation']='1';}
    return $person;
}
function s24Json(array $snapshot): string {
    return json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
$facts=['order_id'=>'77','customer_id'=>'1','customer_status'=>'active','is_demo'=>0,
    'workflow_profile'=>'construction','order_source_type'=>'merchant','lifecycle_status'=>'active','payment_status'=>'paid',
    'assignment_status'=>'accepted','current_assignment_id'=>'100','assigned_worker_id'=>'10',
    'assignment_id'=>'100','assignment_order_id'=>'77','worker_id'=>'10','worker_user_id'=>'2',
    'worker_user_status'=>'active','assignment_record_status'=>'accepted','assignment_accepted_at'=>'2026-10-26 00:00:00'];
$legacy=new OrderConversationPolicy();$policy=new CollaborationConversationPolicy();
$base=$legacy->evaluate($facts);
s24Assert($base!==null,'existing accepted merchant baseline');
$members=[
    s24Person('1','merchant_owner','merchant'),s24Person('2','assigned_worker','worker'),
    s24Person('3','owner_mobile_match','owner'),s24Person('4','invite','designer'),
];
$snapshot=$policy->evaluate($base,'merchant',$members);
s24Assert($snapshot!==null && $snapshot['schema_version']===3 && $snapshot['assignment_id']===null,'explicit nullable collaboration binding');
s24Assert($snapshot['scope_key']==='order:77:collaboration' && $snapshot['can_invite']===false,'stable scope is not Go invite authority');
s24Assert($snapshot['member_limit']===10 && $policy->canAddInvitedMember($snapshot),'default capacity admits ordinary joins');
s24Assert($snapshot['members'][3]['inviter_actor_id']==='1' && $snapshot['members'][3]['invite_generation']==='1','inviter provenance retained');
s24Assert(!isset($snapshot['staff_config_version']),'no invented staff authority');
s24Assert($policy->evaluate($base,'merchant',array_reverse($members))===$snapshot,'source query order cannot churn policy hash');
$fixtures=[['name'=>'four_party','body'=>s24Json($snapshot),'scope'=>$snapshot['scope_key'],'version'=>'7']];

foreach(['consumer','platform','', 'Merchant'] as $source) s24Assert($policy->evaluate($base,$source,$members)===null,'source isolation '.$source);
foreach(['schema_version'=>2,'order_id'=>'0','assignment_id'=>'0','scope_key'=>'order:77:collaboration',
    'can_invite'=>true,'state'=>'unknown','episode_key'=>'assignment:100:repair:0'] as $key=>$value) {
    $bad=$base;$bad[$key]=$value;
    s24Assert($policy->evaluate($bad,'merchant',$members)===null,'invalid legacy base '.$key);
}
foreach([
    ['actor_id'=>'01'],['actor_id'=>4],['actor_id'=>'18446744073709551616'],
    ['status'=>'disabled'],['kind'=>'admin'],['membership_generation'=>'0'],
    ['membership_generation'=>1],['display_role_version'=>'01'],['display_role'=>'worker'],
    ['display_role'=>'platform'],['display_role'=>'自定义'],['nickname'=>'假的商家'],
    ['avatar_url'=>'https://example.invalid/a.png'],['role_source'=>'merchant_owner'],
    ['inviter_actor_id'=>'4'],['invite_generation'=>'0'],['authority_version'=>'1'],
    ['staff_config_version'=>'1'],['display_role'=>null],
] as $change) {
    $bad=$members;$bad[3]=array_replace($bad[3],$change);
    s24Assert($policy->evaluate($base,'merchant',$bad)===null,'invalid invited facts '.json_encode($change));
}
foreach(['membership_generation','display_role_version','inviter_actor_id','invite_generation'] as $key) {
    $bad=$members;unset($bad[3][$key]);
    s24Assert($policy->evaluate($base,'merchant',$bad)===null,'missing member fact '.$key);
}
foreach(CollaborationConversationPolicy::DISPLAY_ROLES as $role) {
    $next=$members;$next[3]['display_role']=$role;$next[3]['display_role_version']='2';
    $value=$policy->evaluate($base,'merchant',$next);
    s24Assert($value!==null && $value['members'][3]['role_source']==='invite'
        && $value['members'][3]['membership_generation']==='1','label is not system authority '.$role);
    $fixtures[]=['name'=>'display_'.$role,'body'=>s24Json($value),'scope'=>$value['scope_key'],'version'=>'8'];
}
$bad=$members;$bad[]=$members[3];s24Assert($policy->evaluate($base,'merchant',$bad)===null,'same actor cannot duplicate membership');
$bad=$members;$bad[0]['actor_id']='9';s24Assert($policy->evaluate($base,'merchant',$bad)===null,'merchant must bind order customer');
$bad=$members;$bad[1]['actor_id']='9';s24Assert($policy->evaluate($base,'merchant',$bad)===null,'worker must bind accepted assignment');
$bad=$members;$bad[2]['inviter_actor_id']='1';s24Assert($policy->evaluate($base,'merchant',$bad)===null,'automatic owner cannot be an invited fact');
$bad=$members;$bad[]=s24Person('8','owner_mobile_match','owner');s24Assert($policy->evaluate($base,'merchant',$bad)===null,'single resolved automatic owner');
s24Assert($policy->evaluate($base,'merchant',array_slice($members,1))===null,'merchant authority required');
s24Assert($policy->evaluate($base,'merchant',[])===null,'empty roster denied');
s24Assert($policy->evaluate($base,'merchant',[1=>$members[0],2=>$members[1]])===null,'associative roster denied');

$reassigned=$facts;$reassigned['assignment_id']='101';$reassigned['current_assignment_id']='101';
$reassigned['worker_user_id']='5';$reassigned['worker_id']='11';$reassigned['assigned_worker_id']='11';
$next=$members;$next[1]['actor_id']='5';$next[1]['membership_generation']='2';
$replacement=$policy->evaluate($legacy->evaluate($reassigned),'merchant',$next);
s24Assert($replacement['scope_key']===$snapshot['scope_key'] && $replacement['episode_key']===$snapshot['episode_key'],'reassignment never replaces group');
s24Assert($replacement['members'][0]===$snapshot['members'][0],'independent merchant unchanged by reassignment');
$fixtures[]=['name'=>'reassigned','body'=>s24Json($replacement),'scope'=>$replacement['scope_key'],'version'=>'9'];
$next=$members;$next[3]['membership_generation']='2';$next[3]['invite_generation']='2';
$rejoined=$policy->evaluate($base,'merchant',$next);
s24Assert($rejoined['members'][3]['membership_generation']==='2','rejoin has separate authority generation');
$fixtures[]=['name'=>'rejoined','body'=>s24Json($rejoined),'scope'=>$rejoined['scope_key'],'version'=>'10'];

$closed=$facts;$closed['lifecycle_status']='completed';
$readonly=$policy->evaluate($legacy->evaluate($closed),'merchant',$members);
s24Assert($readonly['state']==='readonly' && !$policy->canAddInvitedMember($readonly),'closed group denies invitation admission');
foreach($readonly['members'] as $member)s24Assert($member['can_send']===false,'closed group cannot send');
$fixtures[]=['name'=>'readonly','body'=>s24Json($readonly),'scope'=>$readonly['scope_key'],'version'=>'11'];

$repair=$closed;$repair['repair_episode']=['id'=>'900','after_sale_id'=>'500','order_id'=>'77','assignment_id'=>'100',
    'worker_id'=>'10','accepted_user_id'=>'2','repair_order_id'=>'77','repair_user_id'=>'1','state'=>'active',
    'accepted_at'=>'2026-10-26 00:00:00','closed_at'=>null,'repair_type'=>'warranty_repair',
    'repair_status'=>'processing','repair_closed_at'=>null];
$repairBase=$legacy->evaluate($repair);s24Assert($repairBase!==null,'real accepted repair baseline');
$repairSnapshot=$policy->evaluate($repairBase,'merchant',$members);
s24Assert($repairSnapshot['scope_key']===$snapshot['scope_key'] && $repairSnapshot['episode_key']==='order:77:collaboration:repair:900','explicit repair episode preserves group');
$fixtures[]=['name'=>'repair','body'=>s24Json($repairSnapshot),'scope'=>$repairSnapshot['scope_key'],'version'=>'12'];

$staff=$members;
for($i=0;$i<8;$i++) {
    $person=s24Person((string)(20+$i),'platform_staff','platform');
    $person['authority_version']='5';$person['staff_config_version']='3';$staff[]=$person;
}
$withStaff=$policy->evaluate($base,'merchant',$staff);
s24Assert($withStaff!==null && $withStaff['staff_config_version']==='3','eight explicit staff epochs');
$fixtures[]=['name'=>'staff','body'=>s24Json($withStaff),'scope'=>$withStaff['scope_key'],'version'=>'13'];
$bad=$staff;$bad[4]['authority_version']='0';s24Assert($policy->evaluate($base,'merchant',$bad)===null,'staff authority epoch mandatory');
$bad=$staff;$bad[4]['staff_config_version']='4';s24Assert($policy->evaluate($base,'merchant',$bad)===null,'staff config epoch consistent');
$bad=$staff;$person=$staff[4];$person['actor_id']='40';$bad[]=$person;s24Assert($policy->evaluate($base,'merchant',$bad)===null,'staff roster bounded');

$large=$members;for($i=5;$i<=64;$i++)$large[]=s24Person((string)$i,'invite','other');
s24Assert(count($large)===64,'large roster fixture');
$maximum=$policy->evaluate($base,'merchant',$large,50);
s24Assert($maximum!==null && !$policy->canAddInvitedMember($maximum),'system reserve is not an invite quota');
$fixtures[]=['name'=>'maximum','body'=>s24Json($maximum),'scope'=>$maximum['scope_key'],'version'=>'14'];
$large[]=s24Person('65','invite','other');s24Assert($policy->evaluate($base,'merchant',$large,50)===null,'absolute resource bound');
foreach([2,51,0] as $limit)s24Assert($policy->evaluate($base,'merchant',$members,$limit)===null,'invalid configured limit');
$lowered=$policy->evaluate($base,'merchant',$members,3);
s24Assert(count($lowered['members'])===4 && !$policy->canAddInvitedMember($lowered),'lowering limit preserves current members');
$fixtures[]=['name'=>'lowered_limit','body'=>s24Json($lowered),'scope'=>$lowered['scope_key'],'version'=>'15'];
$equal=$policy->evaluate($base,'merchant',$members,4);s24Assert(!$policy->canAddInvitedMember($equal),'at capacity denied');
$raised=$policy->evaluate($base,'merchant',$members,5);s24Assert($policy->canAddInvitedMember($raised),'raising immediately admits next ordinary join');
s24Assert($legacy->evaluate($facts)===$base,'new evaluator has no legacy policy side effects');
s24Assert(!str_contains(s24Json($maximum),'mobile') || str_contains(s24Json($maximum),'"role_source":"owner_mobile_match"'),'no mobile value exported');

if (($argv[1]??'')==='--emit-fixtures') {
    echo json_encode($fixtures,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
} else {
    echo 'S24 collaboration policy: '.$assertions." assertions passed\n";
}
