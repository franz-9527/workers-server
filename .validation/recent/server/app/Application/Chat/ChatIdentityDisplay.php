<?php
declare(strict_types=1);

namespace WorkerAdmin\Application\Chat;

use Closure;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;

/** Read-only chat identity projection. User-editable chat nicknames do not exist. */
final class ChatIdentityDisplay
{
    public function __construct(
        private Database $db,
        private PrivateValueCipher $privateCipher,
        private Closure $readStaffAuthority,
        private Closure $locateIp
    ) {}

    public function effective(string $actor, bool $prime = false): array
    {
        if (!$this->db->pdo()->inTransaction()) {
            return $this->db->transaction(fn()=>$this->effective($actor,$prime));
        }
        $row=$this->db->fetch(
            "SELECT RIGHT(u.public_id,6) suffix,u.mobile_masked,w.id worker,w.display_name worker_nickname,
                    rv.status real_name_status,rv.real_name_ciphertext
             FROM users u
             LEFT JOIN worker_profiles w ON w.user_id=u.id AND w.verification_status IN ('verified','approved')
             LEFT JOIN worker_real_name_verifications rv ON rv.worker_id=w.id
             WHERE u.id=:actor AND u.status='active' LIMIT 1",
            ['actor'=>$actor]
        );
        if (!$row) {
            return ['display_name'=>'用户','display_source'=>'user_fallback','roles'=>[],'real_name_approved'=>false];
        }

        $staff=($this->readStaffAuthority)();
        $staffVersion=null;
        foreach ($staff['platform_staff'] as $person) {
            if ($person['actor_id']===$actor) $staffVersion=$person['authority_version'];
        }

        $verified='';
        $cipher=$row['real_name_ciphertext'];
        if ($row['real_name_status']==='approved' && is_string($cipher) && $cipher!=='') {
            $verified=trim((string)$this->privateCipher->decrypt($cipher,'worker-real-name'));
        }

        if ($prime) {
            $this->db->execute(
                'INSERT INTO chat_profile_authorized_display(user_id,verified_name,real_name_hash,admin_user_version,admin_config_version)
                 VALUES(:actor,:name,:hash,:uv,:cv)
                 ON DUPLICATE KEY UPDATE verified_name=VALUES(verified_name),real_name_hash=VALUES(real_name_hash),
                    admin_user_version=VALUES(admin_user_version),admin_config_version=VALUES(admin_config_version)',
                [
                    'actor'=>$actor,
                    'name'=>$verified,
                    'hash'=>$verified!==''?hash('sha256',$cipher):null,
                    'uv'=>$staffVersion,
                    'cv'=>$staffVersion!==null?$staff['staff_config_version']:null,
                ]
            );
        }

        $roles=$row['worker']?['worker']:[];
        if ($staffVersion!==null) $roles[]='admin';

        $workerNickname=$this->approvedWorkerNickname($row['worker_nickname']??'');
        if ($row['worker']) {
            $display=$verified!==''?$verified:($workerNickname!==''?$workerNickname:'师傅'.$row['suffix']);
            $source=$verified!==''?'real_name':($workerNickname!==''?'worker_nickname':'worker_fallback');
        } else {
            $display=$this->userDisplay($row['mobile_masked']??'',$row['suffix']);
            $source=str_contains($display,'*')?'masked_mobile':'user_fallback';
        }

        return [
            'display_name'=>$display,
            'display_source'=>$source,
            'roles'=>$roles,
            'real_name_approved'=>$verified!=='',
        ];
    }

    public function participantProfile(string $viewer,string $conversation,string $target,bool $ordersOnly=false): array
    {
        foreach([$viewer,$conversation,$target] as $value) {
            if(preg_match('/^[1-9][0-9]{0,19}$/D',$value)!==1)throw new \RuntimeException('CHAT_INVALID_REQUEST',400);
        }
        $profile=$this->db->transaction(function()use($viewer,$conversation,$target,$ordersOnly):array{
            $scope=$this->db->fetch(
                "SELECT c.conversation_type FROM chat_conversations c
                 JOIN chat_members viewer ON viewer.conversation_id=c.id AND viewer.actor_id=:viewer
                    AND viewer.actor_kind='mini' AND viewer.can_read=1
                 WHERE c.id=:conversation LIMIT 1 LOCK IN SHARE MODE",
                ['viewer'=>$viewer,'conversation'=>$conversation]
            );
            if(!$scope||$ordersOnly&&$scope['conversation_type']!=='order')throw new \RuntimeException('CHAT_FORBIDDEN',403);
            $visible=(bool)$this->db->fetchValue(
                "SELECT 1 FROM chat_members member
                 WHERE member.conversation_id=:conversation AND member.actor_id=:target
                    AND member.actor_kind='mini' AND member.can_read=1 LIMIT 1",
                ['conversation'=>$conversation,'target'=>$target]
            );
            if(!$visible)$visible=(bool)$this->db->fetchValue(
                "SELECT 1 FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id
                 WHERE m.conversation_id=:conversation AND m.sender_id=:target
                    AND m.seq>c.sync_floor_seq AND m.seq<=c.last_seq
                    AND EXISTS(SELECT 1 FROM chat_member_intervals v
                        WHERE v.conversation_id=m.conversation_id AND v.actor_id=:viewer
                        AND v.start_version<=m.policy_version AND (v.end_version IS NULL OR v.end_version>m.policy_version))
                 LIMIT 1",
                ['conversation'=>$conversation,'target'=>$target,'viewer'=>$viewer]
            );
            if(!$visible)throw new \RuntimeException('CHAT_FORBIDDEN',403);
            if($scope['conversation_type']==='support'){
                $owner=(string)$this->db->fetchValue(
                    "SELECT CAST(g.owner_id AS CHAR) FROM chat_conversations c
                     JOIN chat_support_guards g ON g.scope_key=c.scope_key AND g.snapshot_hash=c.snapshot_hash AND g.policy_version=c.policy_version
                     WHERE c.id=:conversation AND c.conversation_type='support' LIMIT 1",
                    ['conversation'=>$conversation]
                );
                if($owner!==''&&$target!==$owner){
                    $service=trim((string)$this->db->fetchValue("SELECT display_name FROM chat_support_service WHERE service_key='platform_feedback' LIMIT 1"));
                    return [
                        'display_name'=>$service!==''?$service:'平台反馈助手',
                        'avatar'=>'platform',
                        'roles'=>['admin'],
                        'real_name_approved'=>false,
                        'worker_profile_target'=>'',
                    ];
                }
            }
            $identity=$this->effective($target,false);
            $roles=array_values(array_intersect(['worker','admin'],is_array($identity['roles']??null)?$identity['roles']:[]));
            $avatar=in_array('worker',$roles,true)?'worker':(in_array('admin',$roles,true)?'platform':'user');
            $workerTarget='';$residenceCity='';$residenceDistrict='';
            if(in_array('worker',$roles,true)){
                $worker=$this->db->fetch(
                    "SELECT w.cloud_worker_id,w.city,w.district FROM worker_profiles w JOIN users u ON u.id=w.user_id AND u.status='active'
                     WHERE w.user_id=:target AND w.verification_status='approved' AND w.invited_only=0
                        AND COALESCE(w.cloud_record_mode,'')<>'demo_local'
                     ORDER BY w.id DESC LIMIT 1",
                    ['target'=>$target]
                );
                if($worker){
                    $cloud=trim((string)($worker['cloud_worker_id']??''));
                    if($cloud!=='')$workerTarget='/pages/record/detail?id='.rawurlencode($cloud);
                    $residenceCity=$this->safeLocationValue($worker['city']??'');
                    $residenceDistrict=$this->safeLocationValue($worker['district']??'');
                }
            }
            $ip=(string)$this->db->fetchValue(
                "SELECT client_ip FROM login_sessions
                 WHERE user_id=:target AND COALESCE(client_ip,'')<>''
                    AND COALESCE(demo_actor_user_id,0)=0 AND COALESCE(test_actor_user_id,0)=0
                    AND COALESCE(test_instance_id,0)=0 AND test_actor_expires_at IS NULL
                 ORDER BY COALESCE(last_seen_at,created_at) DESC,id DESC LIMIT 1",
                ['target'=>$target]
            );
            return [
                'display_name'=>(string)($identity['display_name']??'用户'),
                'avatar'=>$avatar,
                'roles'=>$roles,
                'real_name_approved'=>(bool)($identity['real_name_approved']??false),
                'worker_profile_target'=>$workerTarget,
                '_residence_city'=>$residenceCity,
                '_residence_district'=>$residenceDistrict,
                '_ip'=>$ip,
            ];
        });
        return $this->withLocation($profile);
    }

    private function withLocation(array $profile):array
    {
        $city=$this->safeLocationValue($profile['_residence_city']??'');
        $district=$this->safeLocationValue($profile['_residence_district']??'');
        $ip=trim((string)($profile['_ip']??''));
        unset($profile['_residence_city'],$profile['_residence_district'],$profile['_ip']);
        $rows=[];$ipShown=false;
        $worker=in_array('worker',is_array($profile['roles']??null)?$profile['roles']:[],true);
        if($worker&&($city!==''||$district!=='')){
            $rows[]=['label'=>'居住地区','value'=>trim($city.' '.$district),'kind'=>'residence'];
        }
        $located=null;
        if($ip!==''&&filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)){
            try{$located=($this->locateIp)($ip);}catch(\Throwable $e){$located=null;}
        }
        if(is_array($located)){
            $display=$this->safeLocationValue($located['display']??'');
            if($display!==''){
                $different=!$worker||($city===''&&$district==='')||!$this->sameLocation($city,$district,$located);
                if($different){
                    $value=$worker?$this->safeLocationValue($located['detail']??$display):$display;
                    $rows[]=['label'=>'IP属地','value'=>$value!==''?$value:$display,'kind'=>'ip'];$ipShown=true;
                }
            }
        }
        $profile['location_rows']=$rows;
        $profile['ip_location_notice']=$ipShown;
        return $profile;
    }

    private function sameLocation(string $city,string $district,array $ip):bool
    {
        $ipCity=$this->normalizeLocation((string)($ip['city']??''));
        $homeCity=$this->normalizeLocation($city);
        $homeDistrict=$this->normalizeLocation($district);
        $ipDistrict=$this->normalizeLocation((string)($ip['district']??''));
        if($homeCity!==''&&$ipCity!==''){
            if($homeCity!==$ipCity)return false;
            if($homeDistrict!==''&&$ipDistrict!=='')return $homeDistrict===$ipDistrict;
            return true;
        }
        return $homeDistrict!==''&&$ipDistrict!==''&&$homeDistrict===$ipDistrict;
    }

    private function normalizeLocation(string $value):string
    {
        $value=preg_replace('/[\s　]+/u','',$this->safeLocationValue($value))??'';
        return preg_replace('/(省|市|自治区|壮族自治区|回族自治区|维吾尔自治区|特别行政区|区|县)$/u','',$value)??$value;
    }

    private function safeLocationValue(mixed $input):string
    {
        $value=trim((string)$input);
        if($value===''||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>80||preg_match('/[\p{C}<>]/u',$value))return '';
        return $value;
    }

    private function approvedWorkerNickname(mixed $input): string
    {
        $name=trim((string)$input);
        if ($name==='' || !mb_check_encoding($name,'UTF-8') || mb_strlen($name,'UTF-8')>80 || preg_match('/[\p{C}<>]/u',$name)) return '';
        return $name;
    }

    private function userDisplay(mixed $masked,mixed $suffix): string
    {
        $mobile=trim((string)$masked);
        if (preg_match('/^1\d{10}$/D',$mobile)) $mobile=substr($mobile,0,3).'****'.substr($mobile,-4);
        elseif (!preg_match('/^1\d{2}\*{4,6}\d{2,4}$/D',$mobile)) $mobile='';
        $tail=preg_replace('/[^A-Za-z0-9]/','',(string)$suffix)?:'';
        return '用户'.($mobile!==''?$mobile:substr($tail,-6));
    }
}
