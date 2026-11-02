<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMiniAdminDemoRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseApiActorRepository;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;
use WorkerAdmin\Application\Identity\ApiActorResolver;

/** Isolated text simulator. Never touches Go chat, real notifications or order ownership. */
final class ChatSandbox
{
    private const ROOM_BASE=8000000000000000000;
    private const ADMIN_BASE=7000000000000000000;
    public function __construct(private Database $db){}
    private function fail(string $text,int $code=403):never{throw new RuntimeException($text,$code);}
    private function enabled():void
    {if(!TestingEnvironmentPolicy::active())$this->fail('会话测试仅允许测试环境');}
    public function ready():bool
    {
        try{return (int)$this->db->fetchValue("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('chat_sandbox_rooms','chat_sandbox_members','chat_sandbox_messages')")===3;}
        catch(\Throwable $e){return false;}
    }
    private function id($value):int
    {
        if(!is_string($value)||preg_match('/^[1-9][0-9]{0,17}$/D',$value)!==1)$this->fail('测试会话参数无效',400);
        return (int)$value;
    }
    private function label(string $value):string
    {return mb_substr(trim((string)preg_replace('/[\x00-\x1f\x7f<>]/u','',$value)),0,60);}
    public function actors():array
    {
        $this->enabled();$out=[];
        foreach($this->db->fetchAll("SELECT id,username,real_name FROM admin_users WHERE status IN ('active','1') AND must_change_password=0 ORDER BY id LIMIT 500") as $a){
            $id=$this->id((string)$a['id']);$key='admin:'.$id;
            $out[$key]=['key'=>$key,'wire_id'=>(string)(self::ADMIN_BASE+$id),'role'=>'admin','name'=>'管理员 · '.$this->label((string)($a['real_name']?:$a['username'])),'group'=>'管理员'];
        }
        $repo=new DatabaseMiniAdminDemoRepository($this->db,new GlobalSettingsProvider());
        foreach($repo->catalog() as $role=>$option){
            if($role!=='user'&&$role!=='worker'&&!str_starts_with($role,'test_worker_'))continue;
            if(($option['public_id']??'')==='')continue;
            $u=$this->db->fetch("SELECT id,nickname FROM users WHERE public_id=:public AND status='active'",['public'=>$option['public_id']]);
            if(!$u)continue;$id=$this->id((string)$u['id']);$key='user:'.$id;
            $worker=$role!=='user';
            $out[$key]=['key'=>$key,'wire_id'=>(string)$id,'role'=>$worker?'worker':'customer',
                'name'=>mb_substr($this->label((string)$option['label']).($u['nickname']!==''?' · '.$this->label((string)$u['nickname']):''),0,80),'group'=>$worker?'演示师傅':'演示客户'];
        }
        return $out;
    }
    /** Validate the existing demo switch, without issuing/clearing any login session. */
    public function miniIdentity(array $session):?array
    {
        if(!TestingEnvironmentPolicy::active()||empty($session['demo_actor_user_id'])||!empty($session['test_actor_user_id'])||!empty($session['test_instance_id'])||!empty($session['test_actor_expires_at']))return null;
        try{
            $settings=new GlobalSettingsProvider();
            if(!$settings->bool('demo.data_enabled',false)||!$this->ready())return null;
            $app=(string)$settings->get('wechat.miniapp_appid','');
            if($app==='')return null;
            $openid=$this->db->fetchValue("SELECT openid FROM wechat_identities WHERE user_id=:user AND status='active' AND BINARY app_id=BINARY :app ORDER BY id DESC LIMIT 1",['user'=>$session['user_id'],'app'=>$app]);
            $resolver=new ApiActorResolver(new DatabaseApiActorRepository($this->db,$settings),$settings,true);
            if(!$resolver->isRealMiniAdmin(['payer_openid'=>(string)$openid]))return null;
            $key='user:'.(string)$session['demo_actor_user_id'];$actor=$this->actors()[$key]??null;
            if(!$actor)return null;
            return ['sandbox'=>true,'actor_id'=>$actor['wire_id'],'key'=>$key,'role'=>$actor['role'],
                'expires'=>(int)$session['session_expiry'],'session_hash'=>(string)$session['token_hash']];
        }catch(\Throwable $e){return null;}
    }
    private function admin(int $id):void
    {
        if(!$this->db->fetchValue("SELECT id FROM admin_users WHERE id=:id AND role='super_admin' AND status IN ('active','1') AND must_change_password=0 FOR UPDATE",['id'=>$id]))$this->fail('仅超级管理员可操作会话测试');
    }
    private function locked(callable $work):array
    {
        $this->enabled();
        if((int)$this->db->fetchValue("SELECT GET_LOCK('chat_sandbox_mutation_v1',2)")!==1)$this->fail('会话测试正在操作，请稍后重试',409);
        try{return $this->db->transaction($work);}finally{$this->db->fetchValue("SELECT RELEASE_LOCK('chat_sandbox_mutation_v1')");}
    }
    public function create(array $input,int $admin):array
    {
        return $this->locked(function()use($input,$admin):array{
            $this->admin($admin);$title=$input['title']??'';$keys=$input['members']??null;$request=$input['request_key']??'';
            if(!is_string($title)||trim($title)===''||mb_strlen($title)>60||preg_match('/[\x00-\x1f\x7f]/u',$title))$this->fail('请输入1至60字会话名称',400);
            if(!is_array($keys)||count($keys)<2||count($keys)>20||count(array_filter($keys,'is_string'))!==count($keys)||count(array_unique($keys))!==count($keys))$this->fail('请选择2至20个不同成员',400);
            if(!is_string($request)||preg_match('/^[a-f0-9]{32}$/D',$request)!==1)$this->fail('创建请求已失效，请刷新',400);
            $catalog=$this->actors();foreach($keys as $key)if(!isset($catalog[$key]))$this->fail('选择的身份已停用或不属于演示身份',400);
            sort($keys);$hash=hash('sha256',json_encode([trim($title),$keys],JSON_THROW_ON_ERROR));
            $old=$this->db->fetch('SELECT * FROM chat_sandbox_rooms WHERE request_key=:key',['key'=>$request]);
            if($old){if((int)$old['created_by']!==$admin||!hash_equals($old['request_hash'],$hash))$this->fail('同一请求的会话内容已变化',409);return ['id'=>(string)$old['id']];}
            $id=$this->db->insert('INSERT INTO chat_sandbox_rooms(title,created_by,request_key,request_hash,created_at) VALUES(:title,:admin,:key,:hash,UTC_TIMESTAMP())',['title'=>trim($title),'admin'=>$admin,'key'=>$request,'hash'=>$hash]);
            foreach($keys as $key){$a=$catalog[$key];$this->db->execute('INSERT INTO chat_sandbox_members(room_id,actor_key,wire_id,display_name,display_role) VALUES(:room,:key,:wire,:name,:role)',['room'=>$id,'key'=>$key,'wire'=>$a['wire_id'],'name'=>$a['name'],'role'=>$a['role']]);}
            return ['id'=>(string)$id];
        });
    }
    public function dashboard(int $page=1,string $room='',string $before=''):array
    {
        $this->enabled();$page=max(1,min(10000,$page));
        $rows=$this->db->fetchAll('SELECT r.*,(SELECT COUNT(*) FROM chat_sandbox_members m WHERE m.room_id=r.id) member_count,(SELECT COUNT(*) FROM chat_sandbox_messages s WHERE s.room_id=r.id) message_count FROM chat_sandbox_rooms r ORDER BY r.id DESC LIMIT 21 OFFSET '.(($page-1)*20));
        $more=count($rows)>20;$rows=array_slice($rows,0,20);
        if($room===''&&$rows)$room=(string)$rows[0]['id'];
        $selected=$room!==''?$this->room($this->id($room)):null;
        $messages=[];$members=[];$older=false;
        if($selected){
            $members=$this->members((int)$selected['id']);
            $messages=$this->db->fetchAll('SELECT * FROM chat_sandbox_messages WHERE room_id=:room'.($before!==''?' AND id<:before':'').' ORDER BY id DESC LIMIT 51',['room'=>$selected['id']]+($before!==''?['before'=>$this->id($before)]:[]));
            $older=count($messages)>50;$messages=array_reverse(array_slice($messages,0,50));
        }
        return ['rooms'=>$rows,'page'=>$page,'more'=>$more,'room'=>$selected,'members'=>$members,'messages'=>$messages,'older'=>$older,'before'=>(string)($messages[0]['id']??'')];
    }
    private function room(int $id):array
    {return $this->db->fetch('SELECT * FROM chat_sandbox_rooms WHERE id=:id',['id'=>$id])??$this->fail('测试会话已清理或不存在',404);}
    private function members(int $room):array
    {return $this->db->fetchAll('SELECT * FROM chat_sandbox_members WHERE room_id=:id ORDER BY actor_key',['id'=>$room]);}
    public function send(string $room,string $sender,string $text,string $key,int $admin):array
    {return $this->locked(function()use($room,$sender,$text,$key,$admin):array{$this->admin($admin);return $this->write($this->room($this->id($room)),$sender,$text,$key,$admin);});}
    private function write(array $room,string $sender,string $text,string $key,?int $admin):array
    {
        if(!(new ChatReleaseSettings($this->db))->enabled())$this->fail('聊天已暂停，请先开放聊天',503);
        if(!isset($this->actors()[$sender])||!$this->db->fetchValue('SELECT 1 FROM chat_sandbox_members WHERE room_id=:room AND actor_key=:actor',['room'=>$room['id'],'actor'=>$sender]))$this->fail('发送身份不属于当前会话或已停用');
        if(trim($text)===''||mb_strlen($text)>2000||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$text))$this->fail('请输入1至2000字文字消息',400);
        if(preg_match('/^[A-Za-z0-9._:-]{1,96}$/D',$key)!==1)$this->fail('消息请求标识无效',400);
        $hash=hash('sha256',$text);$old=$this->db->fetch('SELECT * FROM chat_sandbox_messages WHERE room_id=:room AND sender_key=:actor AND client_key=:key',['room'=>$room['id'],'actor'=>$sender,'key'=>$key]);
        if($old){if(!hash_equals($old['payload_hash'],$hash))$this->fail('重复请求的消息内容已变化',409);return ['client_message_id'=>$key,'state'=>'accepted','message_id'=>(string)$old['id']];}
        $member=$this->db->fetch('SELECT wire_id FROM chat_sandbox_members WHERE room_id=:room AND actor_key=:actor',['room'=>$room['id'],'actor'=>$sender]);
        $id=$this->db->insert("INSERT INTO chat_sandbox_messages(room_id,sender_key,sender_wire_id,client_key,payload_hash,text_body,change_seq,operated_by,created_at) VALUES(:room,:actor,:wire,:key,:hash,:text,:version,:admin,UTC_TIMESTAMP())",['room'=>$room['id'],'actor'=>$sender,'wire'=>$member['wire_id'],'key'=>$key,'hash'=>$hash,'text'=>$text,'version'=>(int)$room['revision']+1,'admin'=>$admin]);
        $this->db->execute('UPDATE chat_sandbox_rooms SET revision=revision+1 WHERE id=:id',['id'=>$room['id']]);
        return ['client_message_id'=>$key,'state'=>'accepted','message_id'=>(string)$id];
    }
    public function clear(int $admin,?string $room=null):array
    {
        return $this->locked(function()use($admin,$room):array{
            $this->admin($admin);$counts=[];
            foreach(['chat_sandbox_messages','chat_sandbox_members','chat_sandbox_rooms'] as $table){
                $where=$room===null?'':($table==='chat_sandbox_rooms'?' WHERE id=:id':' WHERE room_id=:id');
                $params=$room===null?[]:['id'=>$this->id($room)];
                $counts[$table]=(int)$this->db->fetchValue('SELECT COUNT(*) FROM '.$table.$where,$params);
                $this->db->execute('DELETE FROM '.$table.$where,$params);
            }
            if($room===null)foreach(['chat_test_messages','chat_test_conversations'] as $table){$counts[$table]=(int)$this->db->fetchValue('SELECT COUNT(*) FROM '.$table);$this->db->execute('DELETE FROM '.$table);}
            return $counts;
        });
    }
    private function member(array $i,array $room):array
    {
        if((int)$i['expires']<=time()||!isset($this->actors()[$i['key']]))$this->fail('演示身份已失效');
        return $this->db->fetch('SELECT * FROM chat_sandbox_members WHERE room_id=:room AND actor_key=:actor',['room'=>$room['id'],'actor'=>$i['key']])??$this->fail('当前演示身份不属于此会话');
    }
    private function wireRoom(array $input):array
    {
        $wire=$input['conversation_id']??'';
        if(!is_string($wire)||!preg_match('/^8[0-9]{18}$/D',$wire)||((int)$wire-self::ROOM_BASE)<1)$this->fail('会话参数无效',400);
        return $this->room((int)$wire-self::ROOM_BASE);
    }
    private function preferences(array $member,array $room):array
    {return array_replace(['member_version'=>'1','pinned'=>false,'later'=>false,'pinned_version'=>'0','later_version'=>'0'],json_decode($member['preferences_json']??'{}',true)??[],['account_revision'=>(string)$room['revision']]);}
    private function metadata(array $i,array $room,array $member):array
    {return ['conversation_mode'=>count($this->members((int)$room['id']))>2?'collaboration':'direct','conversation_state'=>'active','policy_version'=>'1','viewer_actor_id'=>$i['actor_id'],'member_role'=>$i['role'],'can_send'=>true,'capabilities'=>['cache'=>false,'images'=>false,'audio'=>false,'video'=>false],
        'order_context'=>['simulation'=>true,'public_no'=>'','title'=>'【测试】'.$room['title'],'workflow'=>'construction'],'preferences'=>$this->preferences($member,$room)];}
    private function message(array $row):array
    {
        $out=['message_id'=>(string)$row['id'],'seq'=>(string)$row['id'],'sender_id'=>(string)$row['sender_wire_id'],'state_version'=>(string)$row['change_seq'],'policy_version'=>'1','message_type'=>'text','state'=>$row['state'],'retention_mode'=>'permanent','created_at'=>gmdate('Y-m-d\TH:i:s\Z',strtotime($row['created_at'].' UTC'))];
        if($row['state']==='published')$out['text']=$row['text_body'];return $out;
    }
    private function unread(array $i,array $room,array $member):array
    {
        $last=$this->db->fetch('SELECT * FROM chat_sandbox_messages WHERE room_id=:id ORDER BY id DESC LIMIT 1',['id'=>$room['id']]);
        $args=['room'=>$room['id'],'actor'=>$i['key'],'read'=>$member['read_seq']];
        $count=(int)$this->db->fetchValue("SELECT COUNT(*) FROM chat_sandbox_messages WHERE room_id=:room AND sender_key<>:actor AND state='published' AND id>:read",$args);
        $first=$this->db->fetchValue("SELECT id FROM chat_sandbox_messages WHERE room_id=:room AND sender_key<>:actor AND state='published' AND id>:read ORDER BY id LIMIT 1",$args);
        return $this->metadata($i,$room,$member)+['unread_count'=>$count,'snapshot_max_seq'=>(string)($last['id']??'0'),'read_seq'=>(string)$member['read_seq'],'first_unread_message_id'=>(string)($first??''),'last_message'=>$last?$this->message($last):null,'last_message_at'=>$last?gmdate('Y-m-d\TH:i:s\Z',strtotime($last['created_at'].' UTC')):null];
    }
    private function cursor(array $i,array $room,int $seq,string $kind):string
    {$raw=$room['id'].':'.$i['actor_id'].':'.$seq.':'.$kind;return base64_encode($raw).'.'.hash_hmac('sha256',$raw,(string)\app_config('app.key',''));}
    private function position(array $i,array $room,$value,string $kind):int
    {
        if(!is_string($value)||strlen($value)>512||substr_count($value,'.')!==1)$this->fail('消息游标无效',400);
        $parts=explode('.', $value);$raw=base64_decode($parts[0],true);$fields=is_string($raw)?explode(':',$raw):[];
        if(count($fields)!==4||!ctype_digit($fields[2])||!hash_equals($this->cursor($i,$room,(int)$fields[2],$kind),$value))$this->fail('消息游标已失效',400);
        return (int)$fields[2];
    }
    public function participants(array $i,array $requests):array
    {
        $this->enabled();if(count($requests)>20)$this->fail('展示范围过大',400);$items=[];
        foreach($requests as $q){$room=$this->wireRoom($q);$this->member($i,$room);$members=[];
            foreach($this->members((int)$room['id']) as $m){$role=['admin'=>'platform','worker'=>'worker','customer'=>'user'][$m['display_role']];$members[]=['actor_id'=>$m['wire_id'],'role'=>$role,'avatar'=>$role,'display_name'=>$m['display_name'],'simulation'=>true];}
            $items[]=['conversation_id'=>(string)(self::ROOM_BASE+(int)$room['id']),'policy_version'=>'1','viewer_actor_id'=>$i['actor_id'],'members'=>$members];}
        return ['items'=>$items];
    }
    public function execute(array $i,string $path,array $input):array
    {
        return $this->locked(function()use($i,$path,$input):array{
            $s=$this->db->fetch("SELECT s.*,UNIX_TIMESTAMP(s.expires_at) session_expiry FROM login_sessions s JOIN users u ON u.id=s.user_id AND u.status='active' WHERE token_hash=:hash AND s.status='active' AND s.expires_at>UTC_TIMESTAMP() FOR UPDATE",['hash'=>$i['session_hash']??'']);
            $current=$s?$this->miniIdentity($s):null;
            if(!$current||$current['key']!==$i['key'])$this->fail('演示身份已变化，请重新进入');
            $i=$current;
            if(!(new ChatReleaseSettings($this->db))->enabled())$this->fail('聊天已暂停',503);
            if($path==='conversations/digests'){
                $rows=$this->db->fetchAll('SELECT r.* FROM chat_sandbox_rooms r JOIN chat_sandbox_members m ON m.room_id=r.id WHERE m.actor_key=:key ORDER BY r.id DESC LIMIT 101',['key'=>$i['key']]);$items=[];$badge=0;
                foreach(array_slice($rows,0,100) as $room){$unread=$this->unread($i,$room,$this->member($i,$room));$items[]=['conversation_id'=>(string)(self::ROOM_BASE+(int)$room['id']),'unread'=>$unread];if($unread['unread_count']>0)$badge++;}
                return ['items'=>$items,'badge_count'=>$badge,'preference_revision'=>'0','has_more'=>false];
            }
            if($path==='governance/status')return ['can_send'=>true,'restricted'=>false,'restrictions'=>[]];
            if($path==='calls/availability')return ['enabled'=>false,'audio'=>false,'video'=>false];
            if(in_array($path,['media/pending','av/pending'],true))return ['items'=>[]];
            $room=$this->wireRoom($input);$member=$this->member($i,$room);$rid=(int)$room['id'];
            if($path==='conversations/unread')return $this->unread($i,$room,$member);
            if($path==='conversations/preferences')return $this->preferences($member,$room);
            if($path==='conversations/readers')return ['items'=>[]];
            if($path==='conversations/read'){
                $seq=$this->position($i,$room,$input['cursor']??null,'sync');
                $this->db->execute('UPDATE chat_sandbox_members SET read_seq=GREATEST(read_seq,:seq) WHERE room_id=:room AND actor_key=:actor',['seq'=>$seq,'room'=>$rid,'actor'=>$i['key']]);
                return $this->unread($i,$room,$this->member($i,$room));
            }
            if($path==='conversations/preference'){
                $pref=$this->preferences($member,$room);$field=$input['field']??'';
                if(!in_array($field,['pinned','later'],true)||!is_bool($input['value']??null)||($input['member_version']??null)!=='1'||($input['expected_version']??null)!==$pref[$field.'_version'])$this->fail('会话偏好已变化，请刷新',409);
                $pref[$field]=$input['value'];$pref[$field.'_version']=(string)((int)$pref[$field.'_version']+1);
                $this->db->execute('UPDATE chat_sandbox_members SET preferences_json=:json WHERE room_id=:room AND actor_key=:actor',['json'=>json_encode($pref,JSON_THROW_ON_ERROR),'room'=>$rid,'actor'=>$i['key']]);
                $this->db->execute('UPDATE chat_sandbox_rooms SET revision=revision+1 WHERE id=:id',['id'=>$rid]);return $this->preferences($this->member($i,$room),$this->room($rid));
            }
            if($path==='messages/send'&&($input['message_type']??'')!=='text')$this->fail('当前仅支持测试文字消息',400);
            if($path==='messages/send')return $this->write($room,$i['key'],is_string($input['text']??null)?$input['text']:'',(string)($input['client_message_id']??''),null);
            if($path==='messages/result'){
                $m=$this->db->fetch('SELECT id FROM chat_sandbox_messages WHERE room_id=:room AND sender_key=:actor AND client_key=:key',['room'=>$rid,'actor'=>$i['key'],'key'=>(string)($input['client_message_id']??'')]);
                return ['client_message_id'=>$input['client_message_id']??'','state'=>$m?'accepted':'retryable','message_id'=>(string)($m['id']??'')];
            }
            if($path==='messages/recall'){
                $m=$this->db->fetch('SELECT * FROM chat_sandbox_messages WHERE room_id=:room AND id=:id',['room'=>$rid,'id'=>$this->id($input['message_id']??null)]);
                if(!$m||$m['sender_key']!==$i['key'])$this->fail('只能撤回当前身份的消息');
                if($m['state']==='published'){$this->db->execute("UPDATE chat_sandbox_messages SET state='recalled',text_body='',change_seq=:version WHERE id=:id",['version'=>(int)$room['revision']+1,'id'=>$m['id']]);$this->db->execute('UPDATE chat_sandbox_rooms SET revision=revision+1 WHERE id=:id',['id'=>$rid]);}
                return $this->message($this->db->fetch('SELECT * FROM chat_sandbox_messages WHERE id=:id',['id'=>$m['id']]));
            }
            if(!in_array($path,['conversations/bootstrap','messages/history','messages/sync','messages/changes','messages/locate'],true))$this->fail('会话测试仅支持文字、已读、撤回和偏好',400);
            $after=0;$before=PHP_INT_MAX;$changes=$path==='messages/changes';$forward=in_array($path,['messages/sync','messages/changes'],true);
            if($forward)$after=$this->position($i,$room,$input['cursor']??null,$changes?'changes':'sync');
            if($path==='messages/history')$before=$this->position($i,$room,$input['cursor']??null,'history');
            if($path==='messages/locate')$before=$this->id($input['message_id']??null)+26;
            $field=$changes?'change_seq':'id';$rows=$this->db->fetchAll('SELECT * FROM chat_sandbox_messages WHERE room_id=:room AND '.$field.'>:after AND id<:before ORDER BY '.$field.($forward?' ASC':' DESC').' LIMIT 51',['room'=>$rid,'after'=>$after,'before'=>$before]);
            $more=count($rows)>50;$rows=array_slice($rows,0,50);if(!$forward)$rows=array_reverse($rows);
            $max=(int)($this->db->fetchValue('SELECT MAX(id) FROM chat_sandbox_messages WHERE room_id=:id',['id'=>$rid])??0);
            $sync=$forward&&!$changes&&$rows?(int)end($rows)['id']:$max;$change=$changes&&$rows?(int)end($rows)['change_seq']:(int)$room['revision'];
            return $this->metadata($i,$room,$member)+['messages'=>$changes?[]:array_map(fn($m)=>$this->message($m),$rows),'changes'=>$changes?array_map(fn($m)=>['change_seq'=>(string)$m['change_seq'],'change_type'=>'message.changed','message'=>$this->message($m)],$rows):[],
                'snapshot_max_seq'=>(string)$sync,'snapshot_max_change_seq'=>(string)$change,'sync_cursor'=>$this->cursor($i,$room,$sync,'sync'),'change_cursor'=>$this->cursor($i,$room,$change,'changes'),'history_cursor'=>!$forward&&$more&&$rows?$this->cursor($i,$room,(int)$rows[0]['id'],'history'):'','has_more'=>$more];
        });
    }
}
