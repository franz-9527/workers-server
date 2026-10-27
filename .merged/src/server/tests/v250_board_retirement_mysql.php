<?php
declare(strict_types=1);
if(getenv('BOARD_RETIREMENT_MYSQL_TEST')!=='1'){fwrite(STDERR,"Set BOARD_RETIREMENT_MYSQL_TEST=1 for isolated MySQL 5.7.\n");exit(2);}
$root=dirname(__DIR__,2);
spl_autoload_register(static function(string $class)use($root):void{if(strpos($class,'WorkerAdmin\\')!==0)return;$p=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;});
$checks=0;
function boardAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);++$GLOBALS['checks'];}
function boardReject(callable $call,string $part):void{try{$call();throw new LogicException('expected rejection');}catch(RuntimeException $e){boardAssert(strpos($e->getMessage(),$part)!==false,'expected '.$part.'; actual '.$e->getMessage());}}
function public_id():string{return bin2hex(random_bytes(13));}
function setting($key,$default=null){return $default;}
function app_config($key,$default=null){return $default;}
final class BoardSettings implements \WorkerAdmin\Contracts\SettingsProvider{
 public array $values=['worker.profile_edit_limit_count'=>1,'worker.profile_edit_period_days'=>30];
 public function get(string $key,$default=null){return $this->values[$key]??$default;}
 public function bool(string $key,bool $default=false):bool{return (bool)$this->get($key,$default);}
 public function int(string $key,int $default=0):int{return (int)$this->get($key,$default);}
}
final class BoardCipher implements \WorkerAdmin\Contracts\PrivateValueCipher{
 public function encrypt(string $text,string $context):?string{return $context.':'.$text;}
 public function decrypt(string $text,string $context):string{$prefix=$context.':';return strpos($text,$prefix)===0?substr($text,strlen($prefix)):'';}
 public function hmac(string $text,string $context):string{return hash('sha256',$context.':'.$text);}
}
$config=['host'=>getenv('V250_DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('V250_DB_PORT')?:3306),'username'=>getenv('V250_DB_USER')?:'root','password'=>getenv('V250_DB_PASSWORD')?:'','database'=>'worker_board_retirement_test'];
$server=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
boardAssert(strpos((string)$server->query('SELECT VERSION()')->fetchColumn(),'5.7.')===0,'MySQL 5.7 required');$created=false;
try{
 $server->exec('CREATE DATABASE worker_board_retirement_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
 $db=new \WorkerAdmin\Core\Database($config);$other=new \WorkerAdmin\Core\Database($config);
 foreach([$root.'/server/database/database.sql',$root.'/server/database/upgrades/2.5.0.sql']as$file){$sql=(string)file_get_contents($file);if(preg_match('/^\s*(?:USE |CREATE DATABASE|DROP DATABASE)/mi',$sql))throw new RuntimeException('Fixture SQL cannot switch databases');(new \WorkerAdmin\Support\SqlScriptRunner())->run($db->pdo(),$sql,$file);}
 $settings=new BoardSettings();$cipher=new BoardCipher();$identity=new \WorkerAdmin\Infrastructure\Persistence\DatabaseIdentityRepository($db,$cipher,$settings);$identityOther=new \WorkerAdmin\Infrastructure\Persistence\DatabaseIdentityRepository($other,$cipher,$settings);
 $row=$identity->createWechatIdentity(['app_id'=>'wx-board','openid'=>'board-owner-openid'],['nickname'=>'Board owner','mobile'=>'13800131234']);$user=(int)$row['user_id'];
 boardAssert((int)$identity->findWechatIdentity('wx-board','board-owner-openid')['user_id']===$user,'native identity creation and lookup');boardAssert($identity->findWechatIdentity('wx-board','BOARD-OWNER-OPENID')===null,'identity lookup remains case-sensitive');
 $hash=hash('sha256','board-session');$identity->issueLoginSession($user,$hash,'127.0.0.1','Board fixture');$session=$db->fetch('SELECT * FROM login_sessions WHERE token_hash=:hash',['hash'=>$hash]);
 boardAssert((int)$session['user_id']===$user && (int)$db->fetchValue('SELECT TIMESTAMPDIFF(SECOND,created_at,expires_at) FROM login_sessions WHERE id=:id',['id'=>$session['id']])===2592000,'hashed login session keeps 30-day lifetime');
 $db->execute("UPDATE login_sessions SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=:id",['id'=>$session['id']]);$identity->issueLoginSession($user,hash('sha256','board-session-new'),'127.0.0.1','Board fixture');boardAssert($db->fetchValue('SELECT status FROM login_sessions WHERE id=:id',['id'=>$session['id']])==='expired','issuing session expires stale sessions');
 $worker=$db->insert("INSERT INTO worker_profiles(user_id,public_id,display_name,verification_status,verified_at,created_at,updated_at) VALUES(:user,:public,'Board worker','approved',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['user'=>$user,'public'=>public_id()]);
 $identity->saveWechatPhone(['user_id'=>$user,'worker_id'=>$worker],'13900131234');$u=$db->fetch('SELECT * FROM users WHERE id=:id',['id'=>$user]);$w=$db->fetch('SELECT * FROM worker_profiles WHERE id=:id',['id'=>$worker]);
 boardAssert($u['mobile_ciphertext']==='user-mobile:13900131234' && $w['mobile_ciphertext']==='worker-mobile:13900131234','user and worker phone cipher contexts differ');boardAssert($identity->currentUserMobile($user)==='13900131234' && $u['mobile_hmac']!==$w['mobile_hmac'],'phone recovery and HMAC contexts preserved');
 $actor=['user_id'=>$user,'worker_id'=>$worker];$policy=$identity->workerProfileEditPolicy($actor);$reservation=$identity->reserveWorkerProfileEdit($actor,$policy);boardAssert($reservation>0 && $identityOther->reserveWorkerProfileEdit($actor,$policy)===0,'profile edit reservation has one quota winner');
 $identity->releaseWorkerProfileEditReservation($reservation);boardAssert($identity->workerProfileEditPolicy($actor)['can_edit'],'failed cloud save can release unused edit reservation');
 $token=hash('sha256','board-reveal-token');$digest=hash('sha256','member|worker-a|session');$identity->issuePhoneRevealToken($user,$token,$digest);
 boardAssert((int)$db->fetchValue('SELECT TIMESTAMPDIFF(SECOND,created_at,expires_at) FROM idempotency_keys WHERE idempotency_key=:key',['key'=>$token])===90,'phone reveal token lasts 90 seconds');
 boardAssert(!$identity->consumePhoneRevealToken($user,$token,hash('sha256','wrong-session')),'token bound to role/target/session digest');boardAssert(!$identity->consumePhoneRevealToken($user,strtoupper($token),$digest),'token hash lookup case-sensitive');
 boardAssert($identity->consumePhoneRevealToken($user,$token,$digest) && !$identityOther->consumePhoneRevealToken($user,$token,$digest),'phone reveal authorization consumed once across connections');
 $expired=hash('sha256','expired-token');$identity->issuePhoneRevealToken($user,$expired,$digest);$db->execute('UPDATE idempotency_keys SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE idempotency_key=:key',['key'=>$expired]);boardAssert(!$identity->consumePhoneRevealToken($user,$expired,$digest),'expired token rejected');
 $mobileHmac=$cipher->hmac('13900131234','worker-phone-reveal-mobile');$ipHmac=$cipher->hmac('127.0.0.1','worker-phone-reveal-ip');
 boardAssert($identity->recordPhoneRevealSuccess($user,'worker-a','member',1,$mobileHmac,$ipHmac),'first distinct worker allowed');boardAssert($identity->recordPhoneRevealSuccess($user,'worker-a','member',1,$mobileHmac,$ipHmac) && $identity->phoneRevealUsedCount($user,'member')===1,'same worker does not consume distinct-worker quota twice');boardAssert(!$identityOther->recordPhoneRevealSuccess($user,'worker-b','member',1,$mobileHmac,$ipHmac),'24-hour quota denies another worker');
 $log=$db->fetch('SELECT * FROM worker_phone_reveal_logs WHERE viewer_user_id=:id ORDER BY id DESC LIMIT 1',['id'=>$user]);boardAssert($log['mobile_hmac']===$mobileHmac && strpos(json_encode($log),'13900131234')===false,'reveal audit stores HMAC rather than clear phone');
 boardAssert($identity->recordPhoneRevealSuccess($user,'worker-b','admin',0,$mobileHmac,$ipHmac) && $db->fetchValue("SELECT reason_code FROM worker_phone_reveal_logs WHERE viewer_user_id=:id AND viewer_role='admin'",['id'=>$user])==='ADMIN_VIEW','administrator reveal remains separately audited');
 $db->execute("UPDATE worker_phone_reveal_logs SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 25 HOUR) WHERE viewer_user_id=:id AND viewer_role='member'",['id'=>$user]);boardAssert($identity->phoneRevealUsedCount($user,'member')===0,'distinct-worker quota uses rolling 24 hours');
 $other->execute('SET SESSION innodb_lock_wait_timeout=1');$db->pdo()->beginTransaction();try{$db->fetch('SELECT id FROM users WHERE id=:id FOR UPDATE',['id'=>$user]);boardReject(static function()use($identityOther,$user,$mobileHmac,$ipHmac):void{$identityOther->recordPhoneRevealSuccess($user,'worker-c','member',1,$mobileHmac,$ipHmac);},'Lock wait timeout');$db->pdo()->rollBack();}catch(Throwable $e){if($db->pdo()->inTransaction())$db->pdo()->rollBack();throw $e;}
 boardAssert($identityOther->recordPhoneRevealSuccess($user,'worker-c','member',1,$mobileHmac,$ipHmac),'quota writer succeeds after owning user lock is released');
 // Private image permissions run through the actual consolidated repository.
 $order=$db->insert("INSERT INTO orders(public_no,user_id,audit_status,payment_status,publish_status,created_at,updated_at) VALUES(:no,:user,'approved','paid','hidden',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['no'=>'BOARD-'.public_id(),'user'=>$user]);
 $mediaPublic=public_id();$mediaId=$db->insert("INSERT INTO media_files(public_id,storage_key,mime_type,extension,size_bytes,sha256,status,created_at) VALUES(:public,'uploads/board-fixture-not-written.png','image/png','png',1,:sha,'active',UTC_TIMESTAMP())",['public'=>$mediaPublic,'sha'=>hash('sha256','fixture')]);
 $link=$db->insert("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,created_by_type,created_by_id,created_at) VALUES(:media,'order',:order,'completion','user',:user,UTC_TIMESTAMP())",['media'=>$mediaId,'order'=>$order,'user'=>$user]);
 $repository=new \WorkerAdmin\Infrastructure\Persistence\DatabaseMediaRepository($db,$settings);$media=$repository->findPrivateMedia($mediaPublic);$access=['actor_type'=>'user','actor_id'=>$user,'user_id'=>$user,'entity_type'=>'order','entity_id'=>$order,'order_id'=>$order];
 boardAssert($repository->canAccessPrivateMedia($media,$access),'owner can access private linked order evidence');boardAssert(!$repository->canAccessPrivateMedia($media,array_replace($access,['entity_id'=>$order+1])),'entity binding cannot be substituted');boardAssert(!$repository->canAccessPrivateMedia($media,array_replace($access,['actor_type'=>'admin'])),'signed administrator claim still requires configured administrator identity');
 $outsider=$db->insert("INSERT INTO users(public_id,nickname,created_at,updated_at) VALUES(:public,'Board outsider',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>public_id()]);boardAssert(!$repository->canAccessPrivateMedia($media,array_replace($access,['user_id'=>$outsider,'actor_id'=>$outsider])),'unrelated active user cannot read order evidence');
 $settings->values['wechat.admin_openids']=['board-owner-openid'];boardAssert($repository->canAccessPrivateMedia($media,array_replace($access,['actor_type'=>'admin'])),'configured active OpenID validates administrator media access');
 $db->execute("UPDATE users SET status='disabled' WHERE id=:id",['id'=>$user]);boardAssert(!$repository->canAccessPrivateMedia($media,$access),'disabled user invalidates media actor');$db->execute("UPDATE users SET status='active' WHERE id=:id",['id'=>$user]);
 $db->execute('DELETE FROM media_links WHERE id=:id',['id'=>$link]);boardAssert(!$repository->canAccessPrivateMedia($media,$access),'unlinked image cannot be read through previous entity token');
 echo 'Board retirement MySQL: '.$checks.' checks passed.'.PHP_EOL;
}finally{if($created)$server->exec('DROP DATABASE worker_board_retirement_test');}
