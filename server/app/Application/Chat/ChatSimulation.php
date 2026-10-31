<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** Test-only text transport. Never issues a Go token, writes real chat tables or dispatches a provider. */
final class ChatSimulation
{
    public function __construct(private Database $db) {}
    private function fail(string $message='模拟会话身份已失效',int $status=403):never{throw new RuntimeException($message,$status);}
    public function identity(string $token):?array
    {
        $s=$this->db->fetch("SELECT s.*,UNIX_TIMESTAMP(s.expires_at) session_expiry,UNIX_TIMESTAMP(s.test_actor_expires_at) actor_expiry FROM login_sessions s JOIN users u ON u.id=s.user_id AND u.status='active' WHERE s.token_hash=:hash AND s.status='active' AND s.expires_at>UTC_TIMESTAMP() LIMIT 1",['hash'=>hash('sha256',$token)]);
        if(!$s||empty($s['test_instance_id'])&&empty($s['test_actor_user_id']))return null;
        if(!TestingEnvironmentPolicy::active()||!empty($s['demo_actor_user_id'])||(int)$s['actor_expiry']<=time())$this->fail();
        [$i,$o]=$this->context((int)$s['test_instance_id']);$actor=(int)$s['test_actor_user_id'];
        if(!$this->db->fetchValue("SELECT 1 FROM users WHERE id=:id AND status='active'",['id'=>$actor]))$this->fail();
        $role='customer';$slot='';
        if($actor!==(int)$o['user_id']){
            $p=$this->db->fetch("SELECT tp.slot_key FROM test_participants tp JOIN worker_profiles w ON w.id=tp.worker_id AND w.user_id=tp.user_id WHERE tp.instance_id=:instance AND tp.user_id=:actor AND tp.actor_type='worker' AND w.cloud_record_mode='demo_local' LIMIT 1",['instance'=>(int)$i['id'],'actor'=>$actor]);
            if(!$p)$this->fail();$role='worker';$slot=(string)$p['slot_key'];
        }
        return ['instance'=>$i,'order'=>$o,'actor_id'=>(string)$actor,'role'=>$role,'slot'=>$slot,'expires'=>min((int)$s['session_expiry'],(int)$s['actor_expiry'])];
    }
    public function credential(array $identity):array
    {
        $expires=min(time()+300,$identity['expires']);
        return ['transport'=>'simulation','access_token'=>'simulation','token_type'=>'Bearer','actor_id'=>$identity['actor_id'],'environment'=>'test','calls_enabled'=>false,'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',$expires),'expires_in'=>$expires-time()];
    }
    private function context(int $id):array
    {
        if(!TestingEnvironmentPolicy::active())$this->fail('模拟会话仅允许测试环境');
        $i=$this->db->fetch('SELECT * FROM test_instances WHERE id=:id',['id'=>$id]);
        $o=$i&&!empty($i['order_id'])?$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$i['order_id']]):null;
        if(!$i||!$o||$i['build_status']!=='built'||!empty($i['archived_at'])||!empty($i['deleted_at'])||(int)$o['is_demo']!==1||$o['public_no']!==$i['order_no_snapshot']||str_starts_with((string)$o['public_no'],'BXDEMOHALL'))$this->fail('测试实例当前不可操作');
        return [$i,$o];
    }
    public function workers(int $instance):array
    {
        return $this->db->fetchAll("SELECT tp.slot_key,CAST(tp.user_id AS CHAR) actor_id,w.display_name FROM test_participants tp JOIN users u ON u.id=tp.user_id AND u.status='active' JOIN worker_profiles w ON w.id=tp.worker_id AND w.user_id=tp.user_id WHERE tp.instance_id=:id AND tp.actor_type='worker' AND w.cloud_record_mode='demo_local' ORDER BY tp.id",['id'=>$instance]);
    }
    public function adminView(int $instance,string $slot,string $before=''):array
    {
        [$i,$o]=$this->context($instance);$workers=$this->workers($instance);
        $slot=$slot!==''?$slot:(string)($workers[0]['slot_key']??'');$c=$this->db->fetch('SELECT * FROM chat_test_conversations WHERE instance_id=:id AND worker_slot=:slot',['id'=>$instance,'slot'=>$slot]);
        $rows=$c?$this->db->fetchAll('SELECT * FROM chat_test_messages WHERE conversation_id=:id'.($before!==''?' AND id<:before':'').' ORDER BY id DESC LIMIT 51',array_merge(['id'=>(int)$c['id']],$before!==''?['before'=>$this->numeric($before)]:[])):[];
        $more=count($rows)>50;$rows=array_reverse(array_slice($rows,0,50));
        return ['workers'=>$workers,'slot'=>$slot,'conversation'=>$c,'messages'=>$rows,'more'=>$more,'before'=>(string)($rows[0]['id']??'')];
    }
    public function adminSend(int $instance,string $slot,string $role,string $text,string $key):array
    {return $this->locked($instance,fn()=>$this->adminSendLocked($instance,$slot,$role,$text,$key));}
    private function adminSendLocked(int $instance,string $slot,string $role,string $text,string $key):array
    {
        if(!(new ChatReleaseSettings($this->db))->enabled())$this->fail('聊天已暂停，请先开放聊天');
        [$i,$o]=$this->context($instance);$worker=null;
        foreach($this->workers($instance) as $p)if($p['slot_key']===$slot)$worker=$p;
        if(!$worker||!in_array($role,['customer','worker'],true))$this->fail('请选择当前实例的测试师傅和发送角色',400);
        $this->db->execute("INSERT IGNORE INTO chat_test_conversations(instance_id,worker_slot,customer_id,worker_id,revision,created_at) VALUES(:instance,:slot,:customer,:worker,1,UTC_TIMESTAMP())",['instance'=>$instance,'slot'=>$slot,'customer'=>(int)$o['user_id'],'worker'=>(int)$worker['actor_id']]);
        $c=$this->db->fetch('SELECT * FROM chat_test_conversations WHERE instance_id=:id AND worker_slot=:slot',['id'=>$instance,'slot'=>$slot]);
        if((string)$c['customer_id']!==(string)$o['user_id']||(string)$c['worker_id']!==$worker['actor_id'])$this->fail('测试参与者已变化，请重新建立实例');
        $identity=['instance'=>$i,'order'=>$o,'actor_id'=>(string)$c[$role==='customer'?'customer_id':'worker_id'],'role'=>$role,'slot'=>$slot];
        return $this->execute($identity,'messages/send',['conversation_id'=>(string)$c['id'],'client_message_id'=>$key,'message_type'=>'text','text'=>$text]);
    }
    private function numeric($value):string
    {
        if(!is_string($value)||preg_match('/^[1-9][0-9]{0,18}$/D',$value)!==1)$this->fail('会话参数无效',400);return $value;
    }
    private function conversation(array $identity,$id):array
    {
        $id=$this->numeric($id);$c=$this->db->fetch('SELECT * FROM chat_test_conversations WHERE id=:id AND instance_id=:instance',['id'=>$id,'instance'=>(int)$identity['instance']['id']]);
        if(!$c||(string)$c[$identity['role']==='customer'?'customer_id':'worker_id']!==$identity['actor_id']||$identity['role']==='worker'&&$c['worker_slot']!==$identity['slot'])$this->fail();
        $found=false;foreach($this->workers((int)$identity['instance']['id']) as $w)if($w['slot_key']===$c['worker_slot']&&$w['actor_id']===(string)$c['worker_id'])$found=true;
        if(!$found||(string)$c['customer_id']!==(string)$identity['order']['user_id'])$this->fail();return $c;
    }
    private function cursor(array $i,array $c,int $seq,string $kind):string
    {
        $raw=implode(':',[$i['instance']['id'],$c['id'],$i['actor_id'],$seq,$kind]);
        return base64_encode($raw).'.'.hash_hmac('sha256',$raw,(string)\app_config('app.key',''));
    }
    private function position(array $i,array $c,$cursor,string $kind):int
    {
        if(!is_string($cursor)||strlen($cursor)>512||substr_count($cursor,'.')!==1)$this->fail('消息游标无效',400);
        [$body,$mac]=explode('.',$cursor);$raw=base64_decode($body,true);$v=is_string($raw)?explode(':',$raw):[];
        if(count($v)!==5||!ctype_digit($v[3])||!hash_equals($this->cursor($i,$c,(int)$v[3],$kind),$cursor))$this->fail('消息游标已失效',400);return (int)$v[3];
    }
    private function metadata(array $i,array $c):array
    {
        return ['conversation_mode'=>'direct','conversation_state'=>'active','policy_version'=>'1','viewer_actor_id'=>$i['actor_id'],'member_role'=>$i['role'],'can_send'=>true,'capabilities'=>['cache'=>false,'images'=>false,'audio'=>false,'video'=>false],
            'order_context'=>['public_no'=>$i['order']['public_no'],'title'=>'【模拟】订单沟通','workflow'=>$i['order']['workflow_profile']??'construction']];
    }
    private function message(array $m):array
    {
        $r=['message_id'=>(string)$m['id'],'seq'=>(string)$m['id'],'sender_id'=>(string)$m['sender_id'],'policy_version'=>'1','state_version'=>(string)$m['change_seq'],'message_type'=>'text','state'=>$m['state'],'retention_mode'=>'permanent','created_at'=>gmdate('Y-m-d\TH:i:s\Z',strtotime($m['created_at'].' UTC'))];
        if($m['state']==='published')$r['text']=(string)$m['text_body'];return $r;
    }
    private function preferences(array $i,array $c):array
    {
        $v=json_decode((string)($c['preferences_json']??'{}'),true)[$i['role']]??[];
        return array_merge(['member_version'=>'1','pinned'=>false,'later'=>false,'pinned_version'=>'0','later_version'=>'0','account_revision'=>(string)$c['revision']],$v);
    }
    private function unread(array $i,array $c):array
    {
        $last=$this->db->fetch('SELECT * FROM chat_test_messages WHERE conversation_id=:id ORDER BY id DESC LIMIT 1',['id'=>(int)$c['id']]);
        $read=(int)$c[$i['role']==='customer'?'customer_read_seq':'worker_read_seq'];
        $count=(int)$this->db->fetchValue("SELECT COUNT(*) FROM chat_test_messages WHERE conversation_id=:id AND sender_id<>:actor AND state='published' AND id>:seq",['id'=>(int)$c['id'],'actor'=>$i['actor_id'],'seq'=>$read]);
        return array_merge($this->metadata($i,$c),['unread_count'=>$count,'snapshot_max_seq'=>(string)($last['id']??'0'),'read_seq'=>(string)$read,'first_unread_message_id'=>(string)($this->db->fetchValue("SELECT id FROM chat_test_messages WHERE conversation_id=:id AND sender_id<>:actor AND state='published' AND id>:seq ORDER BY id LIMIT 1",['id'=>(int)$c['id'],'actor'=>$i['actor_id'],'seq'=>$read])??''),'last_message'=>$last?$this->message($last):null,'last_message_at'=>$last?gmdate('Y-m-d\TH:i:s\Z',strtotime($last['created_at'].' UTC')):null,'preferences'=>$this->preferences($i,$c)]);
    }
    public function participants(array $i,array $requests):array
    {
        if(count($requests)>20)$this->fail('展示范围过大',400);$items=[];
        foreach($requests as $request){$c=$this->conversation($i,$request['conversation_id']??null);$items[]=['conversation_id'=>(string)$c['id'],'policy_version'=>'1','viewer_actor_id'=>$i['actor_id'],'members'=>[
            ['actor_id'=>(string)$c['customer_id'],'role'=>'user','avatar'=>'user','display_name'=>'用户TEST'],['actor_id'=>(string)$c['worker_id'],'role'=>'worker','avatar'=>'worker','display_name'=>'师傅TEST']]];}
        return ['items'=>$items];
    }
    public function execute(array $i,string $path,array $input):array
    {return $this->locked((int)$i['instance']['id'],fn()=>$this->executeLocked($i,$path,$input));}
    private function locked(int $instance,callable $fn):array
    {
        if(!TestingEnvironmentPolicy::active())$this->fail('模拟会话仅允许测试环境');
        $name='test_center_instance_'.$instance;
        if((int)$this->db->fetchValue('SELECT GET_LOCK(:name,0)',['name'=>$name])!==1)$this->fail('实例正在操作或清理，请稍后重试',409);
        try{return $fn();}finally{$this->db->fetchValue('SELECT RELEASE_LOCK(:name)',['name'=>$name]);}
    }
    private function executeLocked(array $i,string $path,array $input):array
    {
        if(!(new ChatReleaseSettings($this->db))->enabled())$this->fail('聊天已暂停',503);
        [$instance,$order]=$this->context((int)$i['instance']['id']);$i['instance']=$instance;$i['order']=$order;
        if($path==='conversations/digests'){
            $rows=$this->db->fetchAll('SELECT * FROM chat_test_conversations WHERE instance_id=:instance AND '.($i['role']==='customer'?'customer_id=:actor':'worker_id=:actor AND worker_slot=:slot').' ORDER BY id DESC LIMIT 101',array_merge(['instance'=>(int)$instance['id'],'actor'=>$i['actor_id']],$i['role']==='worker'?['slot'=>$i['slot']]:[]));
            $items=[];$badge=0;foreach(array_slice($rows,0,100) as $c){$c=$this->conversation($i,(string)$c['id']);$u=$this->unread($i,$c);$items[]=['conversation_id'=>(string)$c['id'],'unread'=>$u];if($u['unread_count']>0)$badge++;}
            return ['items'=>$items,'badge_count'=>$badge,'preference_revision'=>'0','has_more'=>false];
        }
        if($path==='governance/status')return ['can_send'=>true,'restricted'=>false,'restrictions'=>[]];
        if($path==='calls/availability')return ['enabled'=>false,'audio'=>false,'video'=>false];
        if(in_array($path,['media/pending','av/pending'],true))return ['items'=>[]];
        $c=$this->conversation($i,$input['conversation_id']??null);$id=(int)$c['id'];
        if($path==='conversations/unread')return $this->unread($i,$c);
        if($path==='conversations/preferences')return $this->preferences($i,$c);
        if($path==='conversations/readers')return ['items'=>[]];
        if($path==='conversations/read'){
            $seq=$this->position($i,$c,$input['cursor']??null,'sync');$field=$i['role']==='customer'?'customer_read_seq':'worker_read_seq';
            $this->db->execute('UPDATE chat_test_conversations SET '.$field.'=GREATEST('.$field.',:seq) WHERE id=:id',['seq'=>$seq,'id'=>$id]);return $this->unread($i,$this->conversation($i,(string)$id));
        }
        if($path==='conversations/preference')return $this->db->transaction(function()use($i,$c,$input,$id):array{
            $locked=$this->db->fetch('SELECT * FROM chat_test_conversations WHERE id=:id FOR UPDATE',['id'=>$id]);$pref=$this->preferences($i,$locked);$f=$input['field']??'';
            if(!in_array($f,['pinned','later'],true)||!is_bool($input['value']??null)||($input['member_version']??null)!=='1'||($input['expected_version']??null)!==$pref[$f.'_version'])$this->fail('会话偏好已变化，请刷新',409);
            $pref[$f]=$input['value'];$pref[$f.'_version']=(string)((int)$pref[$f.'_version']+1);$pref['account_revision']=(string)((int)$locked['revision']+1);
            $all=json_decode((string)($locked['preferences_json']??'{}'),true)??[];$all[$i['role']]=$pref;
            $this->db->execute('UPDATE chat_test_conversations SET preferences_json=:json,revision=revision+1 WHERE id=:id',['json'=>json_encode($all,JSON_THROW_ON_ERROR),'id'=>$id]);return $pref;
        });
        if(in_array($path,['messages/send','messages/result','messages/recall'],true))return $this->write($i,$c,$path,$input);
        if(!in_array($path,['conversations/bootstrap','messages/history','messages/sync','messages/changes','messages/locate'],true))$this->fail('模拟会话当前支持文字收发、已读、撤回及会话偏好',400);
        $after=0;$before=PHP_INT_MAX;$changes=$path==='messages/changes';
        if(in_array($path,['messages/sync','messages/changes'],true))$after=$this->position($i,$c,$input['cursor']??null,$changes?'changes':'sync');
        if($path==='messages/history')$before=$this->position($i,$c,$input['cursor']??null,'history');
        if($path==='messages/locate'){$target=$this->numeric($input['message_id']??null);$before=(int)$target+26;}
        $field=$changes?'change_seq':'id';$forward=in_array($path,['messages/sync','messages/changes'],true);$rows=$this->db->fetchAll('SELECT * FROM chat_test_messages WHERE conversation_id=:id AND '.$field.'>:after AND id<:before ORDER BY '.$field.($forward?' ASC':' DESC').' LIMIT 51',['id'=>$id,'after'=>$after,'before'=>$before]);
        $more=count($rows)>50;$rows=array_slice($rows,0,50);if(!$forward)$rows=array_reverse($rows);
        $max=(int)($this->db->fetchValue('SELECT MAX(id) FROM chat_test_messages WHERE conversation_id=:id',['id'=>$id])??0);
        $next=$rows?(int)end($rows)[$field]:$after;$sync=$forward&&!$changes?$next:$max;$change=$changes?$next:(int)$c['revision'];
        return array_merge($this->metadata($i,$c),['messages'=>$changes?[]:array_map(fn($m)=>$this->message($m),$rows),'changes'=>$changes?array_map(fn($m)=>['change_seq'=>(string)$m['change_seq'],'change_type'=>'message.changed','message'=>$this->message($m)],$rows):[],
            'snapshot_max_seq'=>(string)$sync,'snapshot_max_change_seq'=>(string)$change,'sync_cursor'=>$this->cursor($i,$c,$sync,'sync'),'change_cursor'=>$this->cursor($i,$c,$change,'changes'),'history_cursor'=>!$forward&&$more&&$rows?$this->cursor($i,$c,(int)$rows[0]['id'],'history'):'','has_more'=>$forward&&$more]);
    }
    private function write(array $i,array $c,string $path,array $input):array
    {
        return $this->db->transaction(function()use($i,$c,$path,$input):array{
            $c=$this->db->fetch('SELECT * FROM chat_test_conversations WHERE id=:id FOR UPDATE',['id'=>(int)$c['id']]);
            if($path==='messages/recall'){
                $m=$this->db->fetch('SELECT * FROM chat_test_messages WHERE id=:id AND conversation_id=:conversation',['id'=>$this->numeric($input['message_id']??null),'conversation'=>(int)$c['id']]);
                if(!$m||(string)$m['sender_id']!==$i['actor_id'])$this->fail();
                if($m['state']==='published'){$this->db->execute("UPDATE chat_test_messages SET state='recalled',text_body='',change_seq=:version WHERE id=:id",['version'=>(int)$c['revision']+1,'id'=>(int)$m['id']]);$this->db->execute('UPDATE chat_test_conversations SET revision=revision+1 WHERE id=:id',['id'=>(int)$c['id']]);}
                return $this->message($this->db->fetch('SELECT * FROM chat_test_messages WHERE id=:id',['id'=>(int)$m['id']]));
            }
            $key=$input['client_message_id']??'';if(!is_string($key)||preg_match('/^[A-Za-z0-9._:-]{1,96}$/D',$key)!==1)$this->fail('消息请求标识无效',400);
            $m=$this->db->fetch('SELECT * FROM chat_test_messages WHERE conversation_id=:id AND sender_id=:actor AND client_key=:key',['id'=>(int)$c['id'],'actor'=>$i['actor_id'],'key'=>$key]);
            if($path==='messages/result')return ['client_message_id'=>$key,'state'=>$m?'accepted':'retryable','message_id'=>(string)($m['id']??'')];
            $text=$input['text']??null;
            if(($input['message_type']??'')!=='text'||!is_string($text)||trim($text)===''||mb_strlen($text)>2000||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$text))$this->fail('请输入1至2000字文字消息',400);
            $digest=hash('sha256',$text);
            if($m){if(!hash_equals((string)$m['payload_hash'],$digest))$this->fail('同一请求的消息内容已变化',409);return ['client_message_id'=>$key,'state'=>'accepted','message_id'=>(string)$m['id']];}
            $id=$this->db->insert("INSERT INTO chat_test_messages(conversation_id,sender_id,sender_role,client_key,payload_hash,text_body,state,change_seq,created_at) VALUES(:conversation,:actor,:role,:key,:hash,:text,'published',:version,UTC_TIMESTAMP())",['conversation'=>(int)$c['id'],'actor'=>$i['actor_id'],'role'=>$i['role'],'key'=>$key,'hash'=>$digest,'text'=>$text,'version'=>(int)$c['revision']+1]);
            $this->db->execute('UPDATE chat_test_conversations SET revision=revision+1 WHERE id=:id',['id'=>(int)$c['id']]);
            return ['client_message_id'=>$key,'state'=>'accepted','message_id'=>(string)$id];
        });
    }
}
