<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use WorkerAdmin\Contracts\IdentityRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Identity\IdentityOperationException;
use WorkerAdmin\Services\OrderNotificationService;

/** Identity / Worker 前端板块的持久化与锁边界。 */
final class DatabaseIdentityRepository implements IdentityRepository
{
    private Database $db;
    private PrivateValueCipher $cipher;
    private SettingsProvider $settings;

    public function __construct(Database $db, PrivateValueCipher $cipher, SettingsProvider $settings)
    {
        $this->db=$db;$this->cipher=$cipher;$this->settings=$settings;
    }

    public function findWechatIdentity(string $appId,string $openid): ?array
    {
        $row=$this->db->fetch('SELECT * FROM wechat_identities WHERE BINARY app_id=BINARY :app_id AND BINARY openid=BINARY :openid LIMIT 1',['app_id'=>$appId,'openid'=>$openid]);
        return $row?:null;
    }

    public function createWechatIdentity(array $session,array $cloudUser): array
    {
        return $this->db->transaction(function(Database $db)use($session,$cloudUser):array{
            $nickname=mb_substr(trim((string)($cloudUser['nickname']??$cloudUser['nickName']??'微信用户'))?:'微信用户',0,80);
            $avatar=trim((string)($cloudUser['avatarUrl']??$cloudUser['avatar']??''));
            $mobile=$this->normalizeMobile((string)($cloudUser['mobile']??''));
            if($mobile==='')$mobile=$this->normalizeMobile((string)($cloudUser['lastmobile']??''));
            $userId=$db->insert("INSERT INTO users(public_id,nickname,avatar_url,mobile_masked,mobile_ciphertext,mobile_hmac,status,cloud_user_id,last_synced_at,created_at,updated_at) VALUES(:public_id,:nickname,:avatar,:masked,:cipher,:hmac,'active',:cloud_id,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
                'public_id'=>\public_id(),'nickname'=>$nickname,'avatar'=>$avatar!==''?$avatar:null,
                'masked'=>$this->maskMobile($mobile),'cipher'=>$mobile===''?null:$this->cipher->encrypt($mobile,'user-mobile'),
                'hmac'=>$mobile===''?null:$this->cipher->hmac($mobile,'user-mobile'),'cloud_id'=>$cloudUser['_id']??null,
            ]);
            $identityId=$db->insert("INSERT INTO wechat_identities(user_id,app_id,openid,unionid,status,created_at,updated_at) VALUES(:user_id,:app_id,:openid,:unionid,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
                'user_id'=>$userId,'app_id'=>(string)($session['app_id']??''),'openid'=>(string)($session['openid']??''),'unionid'=>!empty($session['unionid'])?(string)$session['unionid']:null,
            ]);
            $customerRole=(int)$db->fetchValue("SELECT id FROM roles WHERE role_key='customer' LIMIT 1");
            if($customerRole>0)$db->insert("INSERT IGNORE INTO user_roles(user_id,role_id,source,created_at) VALUES(:user_id,:role_id,'wechat_login',UTC_TIMESTAMP())",['user_id'=>$userId,'role_id'=>$customerRole]);
            $db->insert("INSERT INTO cloud_identity_mappings(user_id,wechat_identity_id,cloud_user_id,source_version,sync_status,last_synced_at,created_at,updated_at) VALUES(:user_id,:wechat_id,:cloud_user,'cloud-authoritative-v2','success',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
                'user_id'=>$userId,'wechat_id'=>$identityId,'cloud_user'=>$cloudUser['_id']??null,
            ]);
            return ['id'=>$identityId,'user_id'=>$userId];
        });
    }

    public function findActiveUser(int $userId): ?array
    {
        $row=$this->db->fetch("SELECT * FROM users WHERE id=:id AND status='active' LIMIT 1",['id'=>$userId]);
        return $row?:null;
    }

    public function issueLoginSession(int $userId,string $tokenHash,string $clientIp,string $userAgent): void
    {
        $this->db->transaction(function(Database $db)use($userId,$tokenHash,$clientIp,$userAgent):void{
            $db->execute("UPDATE login_sessions SET status='expired' WHERE user_id=:id AND status='active' AND expires_at<=UTC_TIMESTAMP()",['id'=>$userId]);
            $db->insert("INSERT INTO login_sessions(user_id,token_hash,client_ip,user_agent,status,expires_at,last_seen_at,created_at) VALUES(:user_id,:token_hash,:ip,:ua,'active',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
                'user_id'=>$userId,'token_hash'=>$tokenHash,'ip'=>$clientIp,'ua'=>mb_substr($userAgent,0,500),
            ]);
            $db->execute('UPDATE users SET last_login_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id',['id'=>$userId]);
        });
    }

    public function saveWechatPhone(array $actor,string $mobile): void
    {
        $masked=$this->maskMobile($mobile);$cipher=$this->cipher->encrypt($mobile,'user-mobile');$hmac=$this->cipher->hmac($mobile,'user-mobile');
        $this->db->transaction(function(Database $db)use($actor,$masked,$cipher,$hmac,$mobile):void{
            $db->execute('UPDATE users SET mobile_masked=:masked,mobile_ciphertext=:cipher,mobile_hmac=:hmac,updated_at=UTC_TIMESTAMP() WHERE id=:id',[
                'masked'=>$masked,'cipher'=>$cipher,'hmac'=>$hmac,'id'=>(int)$actor['user_id'],
            ]);
            $workerId=(int)($actor['worker_id']??0);
            if($workerId>0)$db->execute('UPDATE worker_profiles SET mobile_masked=:masked,mobile_ciphertext=:cipher,mobile_hmac=:hmac,updated_at=UTC_TIMESTAMP() WHERE id=:worker_id',[
                'masked'=>$masked,'cipher'=>$this->cipher->encrypt($mobile,'worker-mobile'),'hmac'=>$this->cipher->hmac($mobile,'worker-mobile'),'worker_id'=>$workerId,
            ]);
        });
    }

    public function projectCloudWorker(int $userId,string $openid,array $cloudWorker): int
    {
        $cloudId=mb_substr(trim((string)($cloudWorker['cloud_id']??$cloudWorker['_id']??'')),0,128);
        if($cloudId==='')throw new \RuntimeException('云端师傅记录缺少 _id。');
        $name=mb_substr(trim((string)($cloudWorker['workerName']??'微信师傅'))?:'微信师傅',0,80);
        $mobile=$this->normalizeMobile((string)($cloudWorker['mobile']??$cloudWorker['phone']??''));
        $location=is_array($cloudWorker['location']??null)?$cloudWorker['location']:[];
        $city=mb_substr((string)($location['city']??''),0,64);$district=mb_substr((string)($location['district']??''),0,64);
        $address=mb_substr(trim((string)($location['address']??$location['name']??'')),0,500);
        $lat=is_numeric($location['latitude']??null)?(float)$location['latitude']:null;$lng=is_numeric($location['longitude']??null)?(float)$location['longitude']:null;
        $status=(string)($cloudWorker['status']??'pending');if(!in_array($status,['pending','approved','rejected','suspended'],true))$status='pending';
        $reviewReason=mb_substr(trim((string)($cloudWorker['reviewReason']??'')),0,500);
        return $this->db->transaction(function(Database $db)use($userId,$openid,$cloudId,$name,$mobile,$city,$district,$address,$lat,$lng,$status,$reviewReason,$cloudWorker):int{
            $worker=$db->fetch('SELECT id FROM worker_profiles WHERE user_id=:user_id OR BINARY cloud_worker_id=BINARY :cloud_id ORDER BY (user_id=:user_order) DESC,id DESC LIMIT 1 FOR UPDATE',['user_id'=>$userId,'cloud_id'=>$cloudId,'user_order'=>$userId]);
            $values=[
                'user_id'=>$userId,'name'=>$name,'has_mobile'=>$mobile===''?0:1,'masked'=>$this->maskMobile($mobile),
                'cipher'=>$mobile===''?null:$this->cipher->encrypt($mobile,'worker-mobile'),'hmac'=>$mobile===''?null:$this->cipher->hmac($mobile,'worker-mobile'),
                'status'=>$status,'reason'=>in_array($status,['rejected','suspended'],true)?($reviewReason?:'管理员未填写处理说明'):null,
                'city'=>$city,'district'=>$district,'address_masked'=>$address===''?'':mb_substr($address,0,12).'…',
                'address_cipher'=>$address===''?null:$this->cipher->encrypt($address,'worker-address'),'lat'=>$lat,'lng'=>$lng,'cloud_id'=>$cloudId,
                'record_count'=>1+(int)($cloudWorker['legacy_count']??0),'verified_at'=>$status==='approved'?gmdate('Y-m-d H:i:s'):null,
            ];
            if($worker){
                $workerId=(int)$worker['id'];$values['id']=$workerId;
                $db->execute("UPDATE worker_profiles SET user_id=:user_id,display_name=:name,mobile_masked=IF(:has_mobile=1,:masked,mobile_masked),mobile_ciphertext=COALESCE(:cipher,mobile_ciphertext),mobile_hmac=COALESCE(:hmac,mobile_hmac),verification_status=:status,rejection_reason=:reason,city=:city,district=:district,address_masked=:address_masked,address_ciphertext=:address_cipher,lat=:lat,lng=:lng,profile_json=NULL,profile_source='cloud',cloud_worker_id=:cloud_id,cloud_snapshot_json=NULL,cloud_record_count=:record_count,cloud_record_mode='cloud_authoritative',cloud_last_synced_at=UTC_TIMESTAMP(),verified_at=:verified_at,updated_at=UTC_TIMESTAMP() WHERE id=:id",$values);
            }else{
                unset($values['has_mobile']);$values['public_id']=\public_id();
                $workerId=$db->insert("INSERT INTO worker_profiles(user_id,public_id,display_name,mobile_masked,mobile_ciphertext,mobile_hmac,verification_status,rejection_reason,invited_only,city,district,address_masked,address_ciphertext,lat,lng,coordinate_system,rating,completed_orders,profile_source,cloud_worker_id,cloud_record_count,cloud_record_mode,cloud_last_synced_at,verified_at,created_at,updated_at) VALUES(:user_id,:public_id,:name,:masked,:cipher,:hmac,:status,:reason,0,:city,:district,:address_masked,:address_cipher,:lat,:lng,'GCJ-02',5,0,'cloud',:cloud_id,:record_count,'cloud_authoritative',UTC_TIMESTAMP(),:verified_at,UTC_TIMESTAMP(),UTC_TIMESTAMP())",$values);
            }
            $db->execute('UPDATE users SET nickname=:nickname,updated_at=UTC_TIMESTAMP() WHERE id=:user_id',['nickname'=>$name,'user_id'=>$userId]);
            $appId=(string)$this->settings->get('wechat.miniapp_appid','');
            $identityId=(int)$db->fetchValue('SELECT id FROM wechat_identities WHERE user_id=:user_id AND BINARY app_id=BINARY :app_id AND BINARY openid=BINARY :openid LIMIT 1',['user_id'=>$userId,'app_id'=>$appId,'openid'=>$openid]);
            if($identityId>0){
                $mapping=(int)$db->fetchValue('SELECT id FROM cloud_identity_mappings WHERE user_id=:user_id LIMIT 1',['user_id'=>$userId]);
                if($mapping>0)$db->execute("UPDATE cloud_identity_mappings SET wechat_identity_id=:identity,cloud_worker_id=:cloud_id,source_version='cloud-authoritative-v2',sync_status='success',last_synced_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id",['identity'=>$identityId,'cloud_id'=>$cloudId,'id'=>$mapping]);
                else $db->insert("INSERT INTO cloud_identity_mappings(user_id,wechat_identity_id,cloud_worker_id,source_version,sync_status,last_synced_at,created_at,updated_at) VALUES(:user_id,:identity,:cloud_id,'cloud-authoritative-v2','success',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['user_id'=>$userId,'identity'=>$identityId,'cloud_id'=>$cloudId]);
            }
            $roles=$db->fetchAll("SELECT id,role_key FROM roles WHERE role_key IN ('worker','worker_pending')");
            foreach($roles as $role)$db->execute('DELETE FROM user_roles WHERE user_id=:user_id AND role_id=:role_id',['user_id'=>$userId,'role_id'=>$role['id']]);
            $roleKey=$status==='approved'?'worker':'worker_pending';
            foreach($roles as $role)if((string)$role['role_key']===$roleKey)$db->execute("INSERT IGNORE INTO user_roles(user_id,role_id,source,created_at) VALUES(:user_id,:role_id,'cloud_authoritative',UTC_TIMESTAMP())",['user_id'=>$userId,'role_id'=>$role['id']]);
            return $workerId;
        });
    }

    public function findLocalWorker(string $requested): ?array
    {
        $row=$this->db->fetch("SELECT w.*,u.public_id user_public_id,u.avatar_url,u.nickname,rv.status real_name_status,rv.real_name_ciphertext,COALESCE(rs.rating_average,w.rating,5.00) rating_average,COALESCE(rs.review_count,0) review_count FROM worker_profiles w JOIN users u ON u.id=w.user_id LEFT JOIN rating_summaries rs ON rs.worker_id=w.id LEFT JOIN worker_real_name_verifications rv ON rv.worker_id=w.id WHERE BINARY w.cloud_worker_id=BINARY :cloud_id OR BINARY w.public_id=BINARY :public_id ORDER BY (BINARY w.cloud_worker_id=BINARY :cloud_order) DESC,w.id DESC LIMIT 1",['cloud_id'=>$requested,'public_id'=>$requested,'cloud_order'=>$requested]);
        return $row?:null;
    }

    public function isDemoWorkerUser(string $userPublicId): bool
    {
        $configured=trim((string)$this->settings->get('demo.actor_worker_public_id',''));
        if($configured!==''&&hash_equals($configured,$userPublicId))return true;
        return preg_match('/^01K2DEMOV.*WORKER/i',$userPublicId)===1;
    }

    public function canUseWorkerOrderContext(array $actor,int $workerId,string $orderPublicNo,string $shareToken=''): bool
    {
        if(!empty($actor['is_admin']))return true;
        if($orderPublicNo==='')return false;
        $order=$this->db->fetch('SELECT * FROM orders WHERE public_no=:id LIMIT 1',['id'=>$orderPublicNo]);
        if(!$order)throw new IdentityOperationException('订单不存在','ORDER_NOT_FOUND',404);
        $this->assertCanViewOrder($actor,$order,$shareToken);
        return (bool)$this->db->fetchValue("SELECT 1 FROM worker_profiles w WHERE w.id=:worker_id AND (EXISTS(SELECT 1 FROM quotes q WHERE q.order_id=:order_id AND q.worker_id=w.id) OR EXISTS(SELECT 1 FROM assignments a WHERE a.order_id=:assignment_order_id AND a.worker_id=w.id)) LIMIT 1",[
            'worker_id'=>$workerId,'order_id'=>$order['id'],'assignment_order_id'=>$order['id'],
        ]);
    }

    public function activeOpenidForUser(int $userId): string
    {
        return (string)$this->db->fetchValue("SELECT openid FROM wechat_identities WHERE user_id=:user_id AND status='active' ORDER BY id DESC LIMIT 1",['user_id'=>$userId]);
    }

    public function decryptWorkerRealName(array $worker): string
    {
        if((string)($worker['real_name_status']??'')!=='approved')return '';
        return $this->cipher->decrypt((string)($worker['real_name_ciphertext']??''),'worker-real-name');
    }

    public function workerProfileEditPolicy(array $actor): array
    {
        $limit=max(0,min(12,(int)$this->settings->get('worker.profile_edit_limit_count',1)));
        $periodDays=max(1,min(365,(int)$this->settings->get('worker.profile_edit_period_days',30)));
        $workerId=(int)($actor['worker_id']??0);
        if($workerId<1)return ['limited'=>false,'ever_approved'=>false,'can_edit'=>true,'limit_count'=>$limit,'period_days'=>$periodDays,'used_count'=>0,'remaining_count'=>$limit>0?$limit:null,'next_edit_at'=>null,'message'=>'首次入驻审核通过前可随时修改资料'];
        $worker=$this->db->fetch('SELECT verification_status,verified_at FROM worker_profiles WHERE id=:id LIMIT 1',['id'=>$workerId]);
        $approved=(bool)($worker&&((string)($worker['verification_status']??'')==='approved'||!empty($worker['verified_at'])));
        if(!$approved)$approved=(bool)$this->db->fetchValue("SELECT id FROM worker_verifications WHERE worker_id=:worker_id AND (decision='approved' OR decision_reason='worker_profile_edit_after_approval') ORDER BY id DESC LIMIT 1",['worker_id'=>$workerId]);
        if(!$approved||$limit===0)return ['limited'=>$approved&&$limit>0,'ever_approved'=>$approved,'can_edit'=>true,'limit_count'=>$limit,'period_days'=>$periodDays,'used_count'=>0,'remaining_count'=>$limit>0?$limit:null,'next_edit_at'=>null,'message'=>$approved?'当前后台未限制审核通过后的资料修改次数；非必要请勿频繁修改':'首次入驻审核通过前可随时修改资料'];
        $rows=$this->db->fetchAll("SELECT submitted_at FROM worker_verifications WHERE worker_id=:worker_id AND decision='pending' AND decision_reason='worker_profile_edit_after_approval' AND submitted_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".$periodDays." DAY) ORDER BY submitted_at ASC,id ASC",['worker_id'=>$workerId]);
        $used=count($rows);$remaining=max(0,$limit-$used);$next=null;
        if($used>=$limit&&!empty($rows[0]['submitted_at']))$next=$this->localTime(gmdate('Y-m-d H:i:s',strtotime((string)$rows[0]['submitted_at'].' UTC')+$periodDays*86400));
        return ['limited'=>true,'ever_approved'=>true,'can_edit'=>$used<$limit,'limit_count'=>$limit,'period_days'=>$periodDays,'used_count'=>$used,'remaining_count'=>$remaining,'next_edit_at'=>$next,'message'=>$used<$limit?'资料审核通过后每 '.$periodDays.' 天最多修改 '.$limit.' 次；非必要请勿频繁修改，提交后需重新审核':'本周期资料修改次数已用完，请于 '.($next?:'限制期结束后').' 再修改'];
    }

    public function currentUserMobile(int $userId): string
    {
        $cipher=(string)$this->db->fetchValue('SELECT mobile_ciphertext FROM users WHERE id=:id LIMIT 1',['id'=>$userId]);
        return $cipher===''?'':$this->cipher->decrypt($cipher,'user-mobile');
    }

    public function reserveWorkerProfileEdit(array $actor,array $policy): int
    {
        if(empty($policy['limited'])||empty($policy['ever_approved'])||(int)($actor['worker_id']??0)<1)return -1;
        return $this->db->transaction(function(Database $db)use($actor,$policy):int{
            $workerId=(int)$actor['worker_id'];$db->fetch('SELECT id FROM worker_profiles WHERE id=:id FOR UPDATE',['id'=>$workerId]);
            $period=max(1,min(365,(int)$policy['period_days']));$limit=max(1,min(12,(int)$policy['limit_count']));
            $used=(int)$db->fetchValue("SELECT COUNT(*) FROM worker_verifications WHERE worker_id=:worker_id AND decision='pending' AND decision_reason='worker_profile_edit_after_approval' AND submitted_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".$period." DAY)",['worker_id'=>$workerId]);
            if($used>=$limit)return 0;
            return $db->insert("INSERT INTO worker_verifications(worker_id,submitted_snapshot_json,decision,decision_reason,reviewed_by,submitted_at,reviewed_at) VALUES(:worker_id,:snapshot,'pending','worker_profile_edit_after_approval',NULL,UTC_TIMESTAMP(),NULL)",[
                'worker_id'=>$workerId,'snapshot'=>json_encode(['source'=>'mini_worker_profile_edit','period_days'=>$period,'limit_count'=>$limit],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]);
        });
    }

    public function releaseWorkerProfileEditReservation(int $reservationId): void
    {
        if($reservationId>0)$this->db->execute("DELETE FROM worker_verifications WHERE id=:id AND decision='pending' AND decision_reason='worker_profile_edit_after_approval' AND reviewed_at IS NULL",['id'=>$reservationId]);
    }

    public function queueWorkerProfileSubmitted(array $actor,array $saved,string $name,string $type,array $location): void
    {
        OrderNotificationService::queueAdminTask($this->db,'worker-profile:'.((string)($saved['cloud_id']??$saved['_id']??($actor['payer_openid']??''))).':'.gmdate('YmdHi'),'worker_profile_submitted','新的师傅入驻资料待审核',[
            '师傅'=>$name,'类型'=>$type,'地区'=>(string)($location['address']??$location['name']??''),
        ]);
    }

    public function expireMembershipGrants(int $userId): void
    {
        $this->db->execute("UPDATE user_membership_grants SET status='expired',updated_at=UTC_TIMESTAMP() WHERE user_id=:user_id AND status='active' AND expires_at<=UTC_TIMESTAMP()",['user_id'=>$userId]);
    }

    public function activeMembershipGrant(int $userId): ?array
    {
        $row=$this->db->fetch("SELECT cloud_card_id,expires_at FROM user_membership_grants WHERE user_id=:user_id AND status='active' AND expires_at>UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1",['user_id'=>$userId]);
        return $row?:null;
    }

    public function phoneRevealSeenCurrent(int $userId,string $role,string $cloudWorkerId): bool
    {
        return (bool)$this->db->fetchValue("SELECT 1 FROM worker_phone_reveal_logs WHERE viewer_user_id=:user_id AND viewer_role=:role AND worker_cloud_id=:cloud_id AND result='success' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) LIMIT 1",['user_id'=>$userId,'role'=>$role,'cloud_id'=>$cloudWorkerId]);
    }

    public function phoneRevealUsedCount(int $userId,string $role): int
    {
        return (int)$this->db->fetchValue("SELECT COUNT(DISTINCT worker_cloud_id) FROM worker_phone_reveal_logs WHERE viewer_user_id=:user_id AND viewer_role=:role AND result='success' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)",['user_id'=>$userId,'role'=>$role]);
    }

    public function issuePhoneRevealToken(int $userId,string $tokenHash,string $digest): void
    {
        $this->db->transaction(function(Database $db)use($userId,$tokenHash,$digest):void{
            $db->fetch('SELECT id FROM users WHERE id=:id LIMIT 1 FOR UPDATE',['id'=>$userId]);
            $db->execute("DELETE FROM idempotency_keys WHERE actor_type='user' AND actor_id=:actor_id AND action='worker.phone-reveal' AND (status='processing' OR expires_at<=UTC_TIMESTAMP())",['actor_id'=>$userId]);
            $db->execute("INSERT INTO idempotency_keys(actor_type,actor_id,action,idempotency_key,request_digest,status,expires_at,created_at) VALUES('user',:actor_id,'worker.phone-reveal',:token_hash,:digest,'processing',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 90 SECOND),UTC_TIMESTAMP())",['actor_id'=>$userId,'token_hash'=>$tokenHash,'digest'=>$digest]);
        });
    }

    public function consumePhoneRevealToken(int $userId,string $tokenHash,string $digest): bool
    {
        return $this->db->transaction(function(Database $db)use($userId,$tokenHash,$digest):bool{
            $row=$db->fetch("SELECT id,request_digest,status,expires_at FROM idempotency_keys WHERE actor_type='user' AND actor_id=:actor_id AND action='worker.phone-reveal' AND BINARY idempotency_key=BINARY :token_hash LIMIT 1 FOR UPDATE",['actor_id'=>$userId,'token_hash'=>$tokenHash]);
            if(!$row||!hash_equals((string)$row['request_digest'],$digest)||(string)$row['status']!=='processing')return false;
            if(strtotime((string)$row['expires_at'].' UTC')<=time()){$db->execute("UPDATE idempotency_keys SET status='expired',completed_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$row['id']]);return false;}
            return $db->execute("UPDATE idempotency_keys SET status='completed',completed_at=UTC_TIMESTAMP() WHERE id=:id AND status='processing'",['id'=>$row['id']])===1;
        });
    }

    public function recordPhoneRevealSuccess(int $userId,string $cloudWorkerId,string $role,int $limit,string $mobileHmac,string $ipHmac): bool
    {
        if($role==='admin'){
            $this->db->insert("INSERT INTO worker_phone_reveal_logs(public_no,viewer_user_id,viewer_role,worker_cloud_id,mobile_hmac,result,reason_code,ip_hmac,created_at) VALUES(:public_no,:user_id,'admin',:cloud_id,:mobile_hmac,'success','ADMIN_VIEW',:ip_hmac,UTC_TIMESTAMP())",[
                'public_no'=>\public_id(),'user_id'=>$userId,'cloud_id'=>mb_substr($cloudWorkerId,0,128),'mobile_hmac'=>$mobileHmac,'ip_hmac'=>$ipHmac,
            ]);return true;
        }
        if($limit<1)return false;
        return $this->db->transaction(function(Database $db)use($userId,$cloudWorkerId,$role,$limit,$mobileHmac,$ipHmac):bool{
            $db->fetch('SELECT id FROM users WHERE id=:id LIMIT 1 FOR UPDATE',['id'=>$userId]);
            if($this->phoneRevealSeenCurrent($userId,$role,$cloudWorkerId))return true;
            if($this->phoneRevealUsedCount($userId,$role)>=$limit)return false;
            $db->insert("INSERT INTO worker_phone_reveal_logs(public_no,viewer_user_id,viewer_role,worker_cloud_id,mobile_hmac,result,reason_code,ip_hmac,created_at) VALUES(:public_no,:user_id,:role,:cloud_id,:mobile_hmac,'success','',:ip_hmac,UTC_TIMESTAMP())",[
                'public_no'=>\public_id(),'user_id'=>$userId,'role'=>substr($role,0,16),'cloud_id'=>mb_substr($cloudWorkerId,0,128),'mobile_hmac'=>$mobileHmac,'ip_hmac'=>$ipHmac,
            ]);return true;
        });
    }

    private function assertCanViewOrder(array $actor,array $order,string $shareToken): void
    {
        if(!empty($actor['is_admin']))return;
        $orderDemo=!empty($order['is_demo']);$actorDemo=!empty($actor['is_demo_actor']);
        if($orderDemo!==$actorDemo)throw new IdentityOperationException('当前身份不能访问另一数据环境的订单','DEMO_DATA_BOUNDARY',403);
        if($this->validOrdinaryShare($order,$shareToken))return;
        if((int)$order['user_id']===(int)$actor['user_id'])return;
        $workerId=(int)($actor['worker_id']??0);
        if($workerId>0&&(int)($order['assigned_worker_id']??0)===$workerId)return;
        if($workerId>0&&$this->db->fetchValue("SELECT id FROM assignments WHERE order_id=:order_id AND worker_id=:worker_id AND status IN ('offered','accepted') LIMIT 1",['order_id'=>$order['id'],'worker_id'=>$workerId]))return;
        $measurementWorker=(int)$this->db->fetchValue("SELECT worker_id FROM order_relations WHERE child_order_id=:order_id AND relation_type='measurement_construction' AND status='active' LIMIT 1",['order_id'=>$order['id']]);
        if($measurementWorker>0){
            if($measurementWorker===$workerId&&(string)$order['audit_status']==='approved'&&in_array((string)$order['quote_status'],['waiting','quoting','collecting'],true)&&(string)$order['lifecycle_status']==='active'&&empty($order['selected_quote_id'])&&empty($order['assigned_worker_id'])&&empty($order['current_assignment_id']))return;
            throw new IdentityOperationException('该订单已关联测量单，仅原测量师傅可查看','MEASUREMENT_WORKER_BOUND',403);
        }
        if((string)$order['audit_status']==='approved'&&(string)$order['publish_status']==='published'&&(string)$order['payment_status']==='paid'&&(string)$order['lifecycle_status']==='active'&&in_array((string)$order['quote_status'],['waiting','quoting','collecting'],true)&&in_array((string)($order['refund_status']??'none'),['','none'],true)&&empty($order['selected_quote_id'])&&empty($order['assigned_worker_id'])&&empty($order['current_assignment_id'])&&(empty($order['quote_deadline_at'])||strtotime((string)$order['quote_deadline_at'].' UTC')>time()))return;
        throw new IdentityOperationException('你无权查看该订单','FORBIDDEN',403);
    }

    private function validOrdinaryShare(array $order,string $plain): bool
    {
        if($plain===''||strlen($plain)>256)return false;$hash=hash('sha256',$plain);
        if(!$this->db->fetchValue("SELECT id FROM share_contexts WHERE order_id=:order_id AND context_type='ordinary_order' AND token_hash=:token_hash AND status='active' AND expires_at>UTC_TIMESTAMP() LIMIT 1",['order_id'=>$order['id'],'token_hash'=>$hash]))return false;
        return (string)$order['audit_status']==='approved'&&(string)$order['publish_status']==='published'&&(string)$order['payment_status']==='paid'&&(string)$order['lifecycle_status']==='active'&&in_array((string)$order['quote_status'],['waiting','quoting','collecting'],true)&&in_array((string)($order['refund_status']??'none'),['','none'],true)&&empty($order['selected_quote_id'])&&empty($order['assigned_worker_id'])&&empty($order['current_assignment_id'])&&(empty($order['quote_deadline_at'])||strtotime((string)$order['quote_deadline_at'].' UTC')>time())&&!$this->db->fetchValue("SELECT 1 FROM order_relations WHERE child_order_id=:order_id AND relation_type='measurement_construction' AND status='active' LIMIT 1",['order_id'=>$order['id']]);
    }

    private function normalizeMobile(string $value): string
    {
        $value=preg_replace('/\D+/','',$value);return is_string($value)&&preg_match('/^1\d{10}$/',$value)===1?$value:'';
    }
    private function maskMobile(string $mobile): string { return $mobile===''?'':substr($mobile,0,3).'****'.substr($mobile,-4); }
    private function localTime(?string $value): ?string
    {
        if(!$value)return null;try{return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i');}catch(\Throwable $e){return $value;}
    }
}
