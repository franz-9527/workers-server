<?php
declare(strict_types=1);
// Explicit opt-in; refuses existing schema and removes only the schema created by this run.
if(getenv('MESSAGING_MIGRATION_MYSQL_TEST')!=='1'){fwrite(STDERR,"Set MESSAGING_MIGRATION_MYSQL_TEST=1 for an isolated MySQL 5.7 test server.\n");exit(2);}
$root=dirname(__DIR__,2);
spl_autoload_register(static function(string $class)use($root):void{if(strpos($class,'WorkerAdmin\\')!==0)return;$p=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;});
function setting($key,$default=null){return $default;}
function app_config($key,$default=null){return $GLOBALS['notification_cursor_config'][$key]??($key==='app.key'?str_repeat('fixture-only-',4):$default);}
function write_log(...$args){$GLOBALS['logs'][]=$args;}
$checks=0;
function messageMysqlAssert(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);++$GLOBALS['checks'];}
function messageMysqlReject(callable $call,string $message):void{try{$call();throw new LogicException('expected rejection');}catch(RuntimeException $e){messageMysqlAssert(strpos($e->getMessage(),$message)!==false,'rejection '.$message);}}
$config=['host'=>getenv('V250_DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('V250_DB_PORT')?:3306),'username'=>getenv('V250_DB_USER')?:'root','password'=>getenv('V250_DB_PASSWORD')?:'','database'=>'worker_messaging_migration_test'];
$server=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
messageMysqlAssert(strpos((string)$server->query('SELECT VERSION()')->fetchColumn(),'5.7.')===0,'MySQL 5.7 required');
$created=false;
try{
    $server->exec('CREATE DATABASE worker_messaging_migration_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
    $db=new \WorkerAdmin\Core\Database($config);$other=new \WorkerAdmin\Core\Database($config);
    $db->execute("CREATE TABLE notifications(id INT AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) UNIQUE,recipient_type VARCHAR(20),recipient_id INT,channel VARCHAR(30),template_key VARCHAR(80),title VARCHAR(80),content TEXT,data_json TEXT,read_at DATETIME NULL,created_at DATETIME) ENGINE=InnoDB");
    $db->execute("CREATE TABLE orders(id INT PRIMARY KEY,public_no VARCHAR(80),user_id INT,lifecycle_status VARCHAR(40),refund_status VARCHAR(40),audit_status VARCHAR(40),payment_status VARCHAR(40),fulfillment_status VARCHAR(40),dispute_status VARCHAR(40),selected_quote_id INT NULL,current_assignment_id INT NULL,assigned_worker_id INT NULL,assignment_status VARCHAR(40),updated_at DATETIME) ENGINE=InnoDB");
    $db->execute('CREATE TABLE quotes(id INT PRIMARY KEY,order_id INT,status VARCHAR(20)) ENGINE=InnoDB');
    $db->execute('CREATE TABLE change_requests(id INT PRIMARY KEY,order_id INT,public_id VARCHAR(80),status VARCHAR(30),payment_status VARCHAR(40)) ENGINE=InnoDB');
    $db->execute('CREATE TABLE run_empty_fee_claims(id INT PRIMARY KEY,order_id INT,status VARCHAR(30)) ENGINE=InnoDB');
    $db->execute('CREATE TABLE assignments(id INT PRIMARY KEY,worker_id INT,status VARCHAR(30)) ENGINE=InnoDB');
    $db->execute('CREATE TABLE after_sales(id INT PRIMARY KEY,order_id INT,type VARCHAR(40),status VARCHAR(30),resolution TEXT) ENGINE=InnoDB');
    foreach([['user-1','user',42,'in_app'],['worker-1','worker',7,'in_app'],['other-user','user',99,'in_app'],['other-worker','worker',99,'in_app'],['external','user',42,'sms']]as $r){
        $db->execute("INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,content,data_json,created_at) VALUES(:id,:type,:recipient,:channel,'event','Title','Content','{\"order_id\":1}','2026-10-08 00:00:00')",['id'=>$r[0],'type'=>$r[1],'recipient'=>$r[2],'channel'=>$r[3]]);
    }
    $db->execute("INSERT INTO orders VALUES(1,'MESSAGE-USER',42,'active','none','draft','awaiting_payment','none','none',NULL,NULL,NULL,'none',UTC_TIMESTAMP()),(2,'MESSAGE-WORKER',99,'active','none','approved','paid','none','none',NULL,12,7,'offered',UTC_TIMESTAMP()),(3,'MESSAGE-REPAIR',99,'completed','none','approved','paid','accepted','none',NULL,NULL,7,'accepted',UTC_TIMESTAMP())");
    $db->execute("INSERT INTO assignments VALUES(12,7,'offered')");$db->execute("INSERT INTO after_sales VALUES(1,3,'warranty_repair','open',NULL)");
    $db->execute("ALTER TABLE orders ADD is_demo TINYINT NOT NULL DEFAULT 0,ADD order_source_type VARCHAR(30) NOT NULL DEFAULT 'consumer',ADD workflow_profile VARCHAR(30) NOT NULL DEFAULT 'construction',ADD dispatch_mode VARCHAR(30) NOT NULL DEFAULT 'single'");
    $db->execute("ALTER TABLE quotes ADD worker_id INT NOT NULL DEFAULT 0");
    $db->execute("ALTER TABLE assignments ADD order_id INT NOT NULL DEFAULT 0");
    $db->execute("UPDATE assignments SET order_id=2 WHERE id=12");
    $db->execute("INSERT INTO assignments(id,worker_id,status,order_id) VALUES(14,7,'accepted',3)");
    $db->execute("UPDATE orders SET current_assignment_id=14 WHERE id=3");
    $db->execute("CREATE TABLE invite_tokens(order_id INT,claimed_worker_id INT,status VARCHAR(30))");
    $db->execute("CREATE TABLE order_events(order_id INT,event_type VARCHAR(100))");
    $db->execute("CREATE TABLE appointment_records(order_id INT,context_type VARCHAR(30))");
    $inbox=new \WorkerAdmin\Application\Messaging\NotificationInbox(new \WorkerAdmin\Infrastructure\Persistence\DatabaseNotificationInboxRepository($db));
    $actor=['user_id'=>42,'worker_id'=>7];$user=['user_id'=>42,'worker_id'=>null];
    $rows=$inbox->list($actor,1);messageMysqlAssert(count($rows['items'])===2 && $rows['unread_count']===2,'only both trusted recipient identities visible');
    messageMysqlAssert(array_column($rows['items'],'id')===['worker-1','user-1'],'stable id ordering');
    messageMysqlAssert($rows['items'][0]['order_no']==='MESSAGE-USER' && $rows['items'][0]['created_at']==='2026-10-08 08:00','batched order resolution and timezone');
    messageMysqlAssert(count($inbox->list($user,1)['items'])===1,'ordinary account cannot see worker message');
    $summary=$inbox->summary($actor);messageMysqlAssert($summary['user_todo_count']===1 && $summary['worker_todo_count']===2 && $summary['todo_count']===3,'both role todo predicates');
    messageMysqlAssert($summary['top_todo']['order_id']==='MESSAGE-REPAIR' && $summary['top_todo']['action']==='处理待维修','repair takes top todo priority');
    messageMysqlAssert($summary['latest_notification']===['id'=>'worker-1','title'=>'Title','created_at'=>'2026-10-08T00:00:00+00:00'],'normal conversation has bounded title and exact UTC activity, no body');
    messageMysqlAssert($inbox->summary($user)['latest_notification']['id']==='user-1','worker and external-channel notifications excluded for ordinary identity');
    messageMysqlAssert($inbox->summary(['user_id'=>1000,'worker_id'=>null])['latest_notification']===null,'empty inbox does not fabricate activity');
    messageMysqlReject(static function()use($inbox,$actor){$inbox->read($actor,'other-user');},'不属于');
    messageMysqlReject(static function()use($inbox,$user){$inbox->read($user,'worker-1');},'不属于');
    messageMysqlReject(static function()use($inbox,$actor){$inbox->read($actor,'external');},'不属于');
    $inbox->read($actor,'user-1');$stamp=$db->fetchValue("SELECT read_at FROM notifications WHERE public_id='user-1'");$inbox->read($actor,'user-1');
    messageMysqlAssert($stamp!==null && $db->fetchValue("SELECT read_at FROM notifications WHERE public_id='user-1'")===$stamp,'single read idempotent');
    $changed=$inbox->readBatch($actor,['worker-1','other-user','external','worker-1']);messageMysqlAssert($changed['updated_count']===1,'batch ownership/channel filtering');
    messageMysqlAssert($inbox->readBatch($actor,['worker-1'])['updated_count']===0,'repeat batch idempotent');
    $db->execute('UPDATE notifications SET read_at=NULL');$inbox->readAll($user);
    messageMysqlAssert((int)$db->fetchValue('SELECT COUNT(*) FROM notifications WHERE read_at IS NOT NULL')===1,'read all ordinary identity only');
    $inbox->readAll($actor);messageMysqlAssert((int)$db->fetchValue('SELECT COUNT(*) FROM notifications WHERE read_at IS NOT NULL')===2,'read all both roles only');
    messageMysqlAssert($inbox->summary($actor)['latest_notification']===$summary['latest_notification'],'read acknowledgements do not reorder normal conversation');
    $through=$inbox->list($actor,1)['read_through_id'];
    $db->execute("INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,created_at) VALUES('new-during-read-all','user',42,'in_app','event','New',UTC_TIMESTAMP())");
    $inbox->readAll($actor,$through);
    messageMysqlAssert($db->fetchValue("SELECT read_at FROM notifications WHERE public_id='new-during-read-all'")===null,'new notifications beyond snapshot survive read all');
    messageMysqlReject(static function()use($inbox,$actor):void{$inbox->readAll($actor,'other-user');},'范围');
    $db->execute("DELETE FROM notifications WHERE public_id='new-during-read-all'");
    for($i=0;$i<22;$i++)$db->execute("INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,created_at) VALUES(:id,'user',42,'in_app',UTC_TIMESTAMP())",['id'=>'page-'.$i]);
    messageMysqlAssert(count($inbox->list($actor,1)['items'])===20 && $inbox->list($actor,1)['has_more']===true,'page lookahead');
    messageMysqlAssert(count($inbox->list($actor,2)['items'])===4 && $inbox->list($actor,2)['has_more']===false,'final page');
    $first=$inbox->list($actor,1);$oldSecond=$inbox->list($actor,2,$first['read_through_id']);
    $db->execute("INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,created_at) VALUES('arrived-between-pages','user',42,'in_app',UTC_TIMESTAMP())");
    $stableSecond=$inbox->list($actor,2,$first['read_through_id']);
    messageMysqlAssert(array_column($stableSecond['items'],'id')===array_column($oldSecond['items'],'id'),'notification range keeps page boundary stable during arrivals');
    $inbox->readAll($actor,$first['read_through_id']);
    messageMysqlAssert($db->fetchValue("SELECT read_at FROM notifications WHERE public_id='arrived-between-pages'")===null,'pagination range also fences read-all');
    $db->execute("DELETE FROM notifications WHERE public_id='arrived-between-pages'");
    $db->execute("CREATE TABLE message_outbox(id INT AUTO_INCREMENT PRIMARY KEY,topic VARCHAR(50),business_key VARCHAR(80),status VARCHAR(20),attempt_count INT DEFAULT 0,next_attempt_at DATETIME NULL,last_error TEXT,processed_at DATETIME NULL,created_at DATETIME,updated_at DATETIME,payload_json TEXT) ENGINE=InnoDB");
    $repo=new \WorkerAdmin\Infrastructure\Persistence\DatabaseMessageOutboxRepository($db);
    $insert=static function(string $topic,string $status,int $attempt)use($db):int{return $db->insert("INSERT INTO message_outbox(topic,business_key,status,attempt_count,last_error,next_attempt_at,created_at,payload_json) VALUES(:topic,'key',:status,:attempt,'13800131234 https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=Secret_Key',UTC_TIMESTAMP(),UTC_TIMESTAMP(),'{\"user_id\":42}')",['topic'=>$topic,'status'=>$status,'attempt'=>$attempt]);};
    $id=$insert('wechat_subscribe','failed',5);$repo->retry($id);$row=$db->fetch('SELECT * FROM message_outbox WHERE id=:id',['id'=>$id]);
    messageMysqlAssert($row['status']==='pending' && (int)$row['attempt_count']===0 && $row['last_error']===null,'retry reset fields');
    messageMysqlAssert($row['next_attempt_at']!==null && $row['processed_at']===null,'retry schedule state');
    messageMysqlReject(static function()use($repo,$id){$repo->retry($id);},'仅可');
    $id=$insert('aliyun_sms','failed',5);$repo->close($id);$row=$db->fetch('SELECT * FROM message_outbox WHERE id=:id',['id'=>$id]);
    messageMysqlAssert($row['status']==='closed' && $row['next_attempt_at']===null && $row['last_error']!==null,'close stops schedule retains evidence');
    messageMysqlReject(static function()use($repo,$id){$repo->close($id);},'仅可');
    foreach([['aliyun_paid_sms','failed',5],['unknown','failed',5],['aliyun_sms','failed',4],['aliyun_sms','processing',5]]as $case){
        $id=$insert(...$case);messageMysqlReject(static function()use($repo,$id){$repo->retry($id);},$case[0]==='aliyun_paid_sms'?'付费短信':($case[0]==='unknown'?'类型':'仅可'));
        messageMysqlAssert($db->fetchValue('SELECT status FROM message_outbox WHERE id=:id',['id'=>$id])===$case[1],'rejected retry unchanged');
    }
    $id=$insert('aliyun_sms','failed',5);
    try{$db->transaction(static function()use($repo,$id):void{$repo->retry($id);throw new RuntimeException('rollback sentinel');});}catch(RuntimeException $e){messageMysqlAssert($e->getMessage()==='rollback sentinel','outer rollback propagated');}
    messageMysqlAssert($db->fetchValue('SELECT status FROM message_outbox WHERE id=:id',['id'=>$id])==='failed','joined transaction rolls back retry');
    $other->execute('SET innodb_lock_wait_timeout=1');$otherRepo=new \WorkerAdmin\Infrastructure\Persistence\DatabaseMessageOutboxRepository($other);
    $db->transaction(static function()use($db,$otherRepo,$id):void{
        $db->fetch('SELECT id FROM message_outbox WHERE id=:id FOR UPDATE',['id'=>$id]);
        messageMysqlReject(static function()use($otherRepo,$id){$otherRepo->retry($id);},'Lock wait timeout');
        $db->execute("UPDATE message_outbox SET status='closed' WHERE id=:id",['id'=>$id]);
    });
    messageMysqlReject(static function()use($otherRepo,$id){$otherRepo->retry($id);},'仅可');
    messageMysqlAssert($other->fetchValue('SELECT status FROM message_outbox WHERE id=:id',['id'=>$id])==='closed','competing connection cannot revive closed row');
    $dashboard=$repo->dashboard('', '',1);$display=implode(' ',array_column($dashboard['result']['rows'],'last_error_display'));
    messageMysqlAssert(strpos($display,'13800131234')===false && strpos($display,'Secret_Key')===false && strpos($display,'地址已隐藏')!==false,'admin error display redaction');
    $db->execute("CREATE TABLE sms_recipient_suppressions(id INT AUTO_INCREMENT PRIMARY KEY,mobile_hmac VARCHAR(80) UNIQUE,mobile_masked VARCHAR(30),reason VARCHAR(40),note TEXT,status VARCHAR(20),created_by_admin_id INT,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB");
    $repo->addSuppression('hash','138****1234','opt_out','note',9);$repo->addSuppression('hash','138****1234','manual','again',8);
    messageMysqlAssert((int)$db->fetchValue('SELECT COUNT(*) FROM sms_recipient_suppressions')===1,'suppression duplicate upsert');
    messageMysqlAssert((int)$db->fetchValue('SELECT created_by_admin_id FROM sms_recipient_suppressions')===8,'suppression admin recorded');
    $repo->removeSuppression(1);messageMysqlAssert($db->fetchValue('SELECT status FROM sms_recipient_suppressions WHERE id=1')==='inactive','suppression removed');
    $repo->addSuppression('hash','138****1234','manual','again',9);messageMysqlAssert(count($repo->dashboard('','',1,'suppressions')['suppressionResult']['rows'])===1,'active suppression projection');
    $db->execute('CREATE TABLE scheduler_runs(id INT AUTO_INCREMENT PRIMARY KEY,job_name VARCHAR(80),status VARCHAR(30),started_at DATETIME,finished_at DATETIME,failure_count INT) ENGINE=InnoDB');
    $db->execute("INSERT INTO scheduler_runs(job_name,status,started_at,failure_count) VALUES('sms_dispatch','success',UTC_TIMESTAMP(),0)");
    $insert('wechat_subscribe','pending',0);$subRepo=new \WorkerAdmin\Infrastructure\Messaging\DatabaseSubscriptionRepository($db);$diag=$subRepo->diagnose(42);
    messageMysqlAssert($diag['pending_count']===2 && $diag['dispatcher_healthy']===true,'diagnostic scope and dispatcher health');
    messageMysqlAssert($subRepo->diagnose(99)['pending_count']===0,'other actor diagnostic excludes messages');
    $db->execute("CREATE TABLE system_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT,value_type VARCHAR(20),group_name VARCHAR(50),is_public TINYINT,description TEXT,updated_by INT NULL,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB");
    $saved=$subRepo->save(42,'reviewResult',false,'reject');messageMysqlAssert($saved['last_request_status']==='reject' && $saved['remaining_count']===0,'legacy quota adapter persistence');
    messageMysqlAssert(isset($subRepo->preferences(42)['reviewResult']) && !$subRepo->preferences(99),'preferences scope');
    $db->execute('RENAME TABLE message_outbox TO message_outbox_full');$warning=$repo->dashboard('','',1);
    messageMysqlAssert($warning['schemaWarning']!=='' && !$warning['result']['rows'],'missing schema graceful page');
    $db->execute('CREATE TABLE message_outbox(id INT PRIMARY KEY,topic VARCHAR(50),business_key VARCHAR(80),status VARCHAR(20)) ENGINE=InnoDB');
    messageMysqlAssert($repo->dashboard('','',1)['schemaWarning']==='','optional columns fallback');
    messageMysqlReject(static function()use($repo){$repo->retry(1);},'字段');

    // S14 B/S uses the full official todo scopes and a single UNION snapshot.
    $db->execute('DELETE FROM notifications');
    $notice=static function(string $id,string $role,int $receiver,string $json)use($db):void{$db->execute("INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,data_json,created_at) VALUES(:id,:role,:receiver,'in_app','event','S14',:data,UTC_TIMESTAMP())",['id'=>$id,'role'=>$role,'receiver'=>$receiver,'data'=>$json]);};
    $notice('b-user1','user',42,'{"order_id":1}');$notice('b-user2','user',42,'{"order_id":"1"}');
    $notice('b-worker1','worker',7,'{"order_id":2}');$notice('b-worker2','worker',7,'{"order_id":2}');
    foreach(['broken','{}','{"order_id":999999}','{"order_id":1.0}','{"order_id":"01"}'] as $i=>$data)$notice('standalone-'.$i,'user',42,$data);
    $notice('unowned-worker','worker',7,'{"order_id":1}');$notice('other-account','user',99,'{}');
    $s=$inbox->summary($actor);
    messageMysqlAssert($s['order_attention_count']===3 && $s['standalone_unread_count']===6,'B deduplicates todo/order notices and S retains malformed/unowned links: '.json_encode($s));
    $db->execute("INSERT INTO assignments(id,worker_id,status,order_id) VALUES(13,7,'accepted',1)");
    $db->execute("UPDATE orders SET assigned_worker_id=7,current_assignment_id=13,assignment_status='accepted' WHERE id=1");
    $s=$inbox->summary($actor);
    messageMysqlAssert($s['order_attention_count']===4 && $s['standalone_unread_count']===5,'same order in two recipient identities stays two attention items');
    $inbox->readAll($user);$s=$inbox->summary($actor);
    messageMysqlAssert($s['order_attention_count']===4 && $s['standalone_unread_count']===0,'reading user notices preserves worker attention and formal todos');
    $inbox->readAll($actor);$s=$inbox->summary($actor);
    messageMysqlAssert($s['order_attention_count']===3,'reading notices never clears outstanding tasks');
    $db->execute("UPDATE orders SET audit_status='approved',payment_status='paid' WHERE id=1");
    $db->execute("UPDATE assignments SET status='accepted' WHERE id=12");$db->execute("UPDATE orders SET assignment_status='accepted' WHERE id=2");
    $db->execute("UPDATE after_sales SET status='resolved'");
    $s=$inbox->summary($actor);messageMysqlAssert($s['order_attention_count']===0,'formal business completion clears the last B item');
    $db->execute("UPDATE orders SET order_source_type='platform',workflow_profile='construction',fulfillment_status='not_started' WHERE id=2");
    $db->execute("INSERT INTO order_events VALUES(2,'platform_measurement_pricing_reviewed'),(2,'platform_measurement_pricing_confirmed')");
    $s=$inbox->summary($actor);messageMysqlAssert($s['worker_todo_count']===1 && $s['order_attention_count']===1 && $s['top_todo']['action']==='预约施工','appointment todos use the order center scope');
    $db->execute("INSERT INTO appointment_records VALUES(2,'service')");
    $db->execute("UPDATE orders SET order_source_type='platform' WHERE id=1");
    $db->execute("INSERT INTO order_events VALUES(1,'platform_measurement_pricing_reviewed')");
    $s=$inbox->summary($actor);messageMysqlAssert($s['user_todo_count']===1 && $s['order_attention_count']===1 && $s['top_todo']['action']==='确认平台报价','pricing confirmation todo is included');
    $db->execute("UPDATE orders SET is_demo=1 WHERE id=1");
    messageMysqlAssert($inbox->summary($actor)['order_attention_count']===0 && $inbox->summary($actor+['is_demo_actor'=>true])['order_attention_count']===1,'demo and real attention scopes are isolated');


    // U15: aggregate BEFORE pagination, immutable ID range, signed keyset and lazy thread history.
    $db->execute('DELETE FROM notifications');$db->execute('UPDATE orders SET is_demo=0');
    $seed=static function(string $id,string $role,int $recipient,string $json,string $time='2026-10-22 00:00:00',string $channel='in_app')use($db):void{
        $db->execute("INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,content,data_json,created_at) VALUES(:id,:role,:recipient,:channel,'event','U15','Owned fixture',:json,:time)",
            ['id'=>$id,'role'=>$role,'recipient'=>$recipient,'channel'=>$channel,'json'=>$json,'time'=>$time]);
    };
    for($i=0;$i<65;$i++)$seed('u15-single-'.$i,'user',42,'{}');
    for($i=0;$i<75;$i++)$seed('u15-order-'.$i,'user',42,'{"order_id":1}','2026-10-23 00:00:00');
    for($i=0;$i<5;$i++)$seed('u15-worker-'.$i,'worker',7,'{"order_id":"1"}','2026-10-23 00:00:00');
    $seed('u15-private','user',99,'{}');$seed('u15-external','user',42,'{}','2026-10-23 01:00:00','sms');
    $first=$inbox->threads($actor);
    messageMysqlAssert($first['schema']===2&&count($first['items'])===20&&$first['has_more'],'thread page bounded lookahead');
    $byKey=array_column($first['items'],null,'thread_key');
    messageMysqlAssert($byKey['user:1']['thread_count']===75&&$byKey['user:1']['unread_count']===75,'all 75 messages summarized, not first raw page only');
    messageMysqlAssert($byKey['worker:1']['thread_count']===5&&$byKey['worker:1']['order_no']==='MESSAGE-USER','same order identities remain independent');
    $threadCursor=$byKey['user:1']['thread_cursor'];
    $seed('u15-arrival','user',42,'{"order_id":1}','2026-10-24 00:00:00');
    $all=$first['items'];$next=$first['next_cursor'];$pages=1;
    while($next!==''){$page=$inbox->threads($actor,$next);$all=array_merge($all,$page['items']);$next=$page['next_cursor'];messageMysqlAssert(++$pages<10,'finite thread traversal');}
    $keys=array_column($all,'thread_key');
    messageMysqlAssert(count($all)===67&&count(array_unique($keys))===67,'equal timestamps traverse 67 unique threads without duplication or omissions');
    messageMysqlAssert(!in_array('single:u15-private',$keys,true)&&!in_array('single:u15-external',$keys,true),'recipient and channel scope applied before grouping');
    $messages=$inbox->threadMessages($actor,$threadCursor);
    messageMysqlAssert(count($messages['items'])===20&&$messages['has_more'],'thread expanded lazily with 20 record page');
    $visible=array_column($messages['items'],'id');
    $inbox->readBatch($actor,$visible);
    $history=$messages['items'];$next=$messages['next_cursor'];
    while($next!==''){$page=$inbox->threadMessages($actor,$next);$history=array_merge($history,$page['items']);$next=$page['next_cursor'];}
    messageMysqlAssert(count($history)===75&&count(array_unique(array_column($history,'id')))===75,'thread history has no duplicates; later arrival excluded');
    messageMysqlAssert(!in_array('u15-arrival',array_column($history,'id'),true),'new same-thread notification is outside frozen range');
    $fresh=array_column($inbox->threads($actor)['items'],null,'thread_key');
    messageMysqlAssert($fresh['user:1']['thread_count']===76&&$fresh['user:1']['unread_count']===56,'only explicitly loaded 20 records marked read');
    $beforeTodo=$inbox->summary($actor)['todo_count'];
    $inbox->readRange($actor,$first['range_cursor']);
    messageMysqlAssert($db->fetchValue("SELECT read_at FROM notifications WHERE public_id='u15-arrival'")===null,'signed read-all cannot clear arrival');
    messageMysqlAssert($db->fetchValue("SELECT read_at FROM notifications WHERE public_id='u15-private'")===null&&$db->fetchValue("SELECT read_at FROM notifications WHERE public_id='u15-external'")===null,'range read cannot acknowledge foreign or external notifications');
    messageMysqlAssert($inbox->summary($actor)['todo_count']===$beforeTodo,'read-all never changes business todos');
    foreach([[$user,$first['next_cursor']],[$actor+['is_demo_actor'=>true],$first['next_cursor']]] as [$receiver,$token]){
        messageMysqlReject(static fn()=>$inbox->threads($receiver,$token),'范围');
    }
    messageMysqlReject(static fn()=>$inbox->threadMessages($actor,$first['next_cursor']),'范围');
    messageMysqlReject(static fn()=>$inbox->readRange($actor,$threadCursor),'范围');
    $tampered=substr($first['next_cursor'],0,-64).str_repeat('0',64);
    messageMysqlReject(static fn()=>$inbox->threads($actor,$tampered),'范围');
    messageMysqlReject(static fn()=>$inbox->threads($actor,str_repeat('a',2049)),'范围');
    messageMysqlReject(static fn()=>$inbox->threadMessages($actor,''),'刷新');
    $signer=new \WorkerAdmin\Infrastructure\Messaging\NotificationInboxCursor();
    $expired=$signer->begin($actor,'0','0');$expired['exp']=time()-1;
    messageMysqlReject(static fn()=>$inbox->threads($actor,$signer->seal($expired,'threads')),'范围');
    $GLOBALS['notification_cursor_config']['app.env']='different-environment';
    messageMysqlReject(static fn()=>$inbox->threads($actor,$first['next_cursor']),'范围');
    unset($GLOBALS['notification_cursor_config']['app.env']);
    $GLOBALS['notification_cursor_config']['app.key']='';
    messageMysqlReject(static fn()=>$inbox->threads($actor),'暂不可用');
    unset($GLOBALS['notification_cursor_config']['app.key']);
    $db->execute("DELETE FROM notifications WHERE public_id='u15-single-0'");
    messageMysqlReject(static fn()=>$inbox->threads($actor,$first['next_cursor']),'范围');
    $db->execute("UPDATE orders SET user_id=99 WHERE id=1");
    $userThreads=array_column($inbox->threads($user)['items'],null,'thread_key');
    messageMysqlAssert($userThreads['user:1']['order_no']===''&&$userThreads['user:1']['thread_count']===76,'notification ownership never grants revoked order access');
    foreach(['{"order_id":1.0}','{"order_id":"01"}','{"order_id":"18446744073709551616"}','broken'] as $i=>$json)$seed('u15-invalid-'.$i,'user',42,$json,'2026-10-24 01:00:00');
    $invalid=array_column($inbox->threads($user)['items'],null,'thread_key');
    for($i=0;$i<4;$i++)messageMysqlAssert(isset($invalid['single:u15-invalid-'.$i]),'noncanonical order association remains standalone');
    $emptyActor=['user_id'=>123456,'worker_id'=>null];$empty=$inbox->threads($emptyActor);
    messageMysqlAssert(!$empty['items']&&!$empty['has_more']&&$empty['next_cursor']==='','empty snapshot is well formed');
    $seed('u15-after-empty','user',123456,'{}');$inbox->readRange($emptyActor,$empty['range_cursor']);
    messageMysqlAssert($db->fetchValue("SELECT read_at FROM notifications WHERE public_id='u15-after-empty'")===null,'empty snapshot read-all cannot clear first arrival');

    echo 'Messaging migration MySQL: '.$checks.' checks passed.'.PHP_EOL;
}finally{
    if(isset($db) && $db->pdo()->inTransaction())$db->pdo()->rollBack();
    if(isset($other) && $other->pdo()->inTransaction())$other->pdo()->rollBack();
    if($created)$server->exec('DROP DATABASE worker_messaging_migration_test');
}
