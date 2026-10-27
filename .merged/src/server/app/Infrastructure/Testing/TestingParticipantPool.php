<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** testing 专用共享参与者主档；不创建微信身份、不绑定真实手机号。 */
final class TestingParticipantPool
{
    private Database $db;
    private PrivateValueCipher $cipher;

    public function __construct(Database $db, PrivateValueCipher $cipher)
    {
        $this->db = $db;
        $this->cipher = $cipher;
    }

    /** @return array<string,array<string,mixed>> */
    public function ensure(int $adminId): array
    {
        if (!TestingEnvironmentPolicy::active()) {
            throw new TestCenterException('测试参与者池只允许 testing 环境。', 'TEST_PARTICIPANT_PRODUCTION_BLOCKED', 403);
        }
        $definitions = [
            'worker_1'=>['user'=>'01KTESTV260WORKER000000001','profile'=>'01KTESTV260WPROF0000000001','name'=>'测试师傅 A','mobile'=>'13900000101','status'=>'approved','lat'=>30.3198,'lng'=>120.1510],
            'worker_2'=>['user'=>'01KTESTV260WORKER000000002','profile'=>'01KTESTV260WPROF0000000002','name'=>'测试师傅 B','mobile'=>'13900000102','status'=>'approved','lat'=>30.3220,'lng'=>120.1530],
            'worker_3'=>['user'=>'01KTESTV260WORKER000000003','profile'=>'01KTESTV260WPROF0000000003','name'=>'测试师傅 C','mobile'=>'13900000103','status'=>'approved','lat'=>30.3250,'lng'=>120.1580],
            'pending_worker'=>['user'=>'01KTESTV260PENDING00000001','profile'=>'01KTESTV260PPROF0000000001','name'=>'待审核测试师傅','mobile'=>'13900000109','status'=>'pending','lat'=>30.3210,'lng'=>120.1520],
        ];
        $result = [];
        foreach ($definitions as $slot=>$definition) {
            $user = $this->db->fetch('SELECT id,public_id FROM users WHERE BINARY public_id=BINARY :public_id LIMIT 1',['public_id'=>$definition['user']]);
            if (!$user) {
                $userId = $this->db->insert(
                    "INSERT INTO users(public_id,nickname,mobile_masked,status,created_at,updated_at) VALUES(:public_id,:nickname,:mobile,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['public_id'=>$definition['user'],'nickname'=>$definition['name'],'mobile'=>$this->mask((string)$definition['mobile'])]
                );
            } else {
                $userId = (int)$user['id'];
                $this->db->execute("UPDATE users SET nickname=:nickname,mobile_masked=:mobile,status='active',updated_at=UTC_TIMESTAMP() WHERE id=:id",['nickname'=>$definition['name'],'mobile'=>$this->mask((string)$definition['mobile']),'id'=>$userId]);
            }
            $profile = $this->db->fetch('SELECT id FROM worker_profiles WHERE user_id=:user_id LIMIT 1',['user_id'=>$userId]);
            $profileJson = json_encode(['skills'=>['测试施工'],'source'=>'test_center_v260','readonly_fixture'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if (!$profile) {
                $workerId = $this->db->insert(
                    "INSERT INTO worker_profiles(user_id,public_id,display_name,mobile_masked,mobile_ciphertext,verification_status,invited_only,city,district,address_masked,lat,lng,coordinate_system,rating,completed_orders,profile_json,profile_source,cloud_record_count,cloud_record_mode,verified_at,verified_by,created_at,updated_at)
                     VALUES(:user_id,:public_id,:name,:mobile_masked,:mobile_cipher,:verification,0,'杭州市','拱墅区','测试区域（虚构）',:lat,:lng,'GCJ-02',5.00,0,:profile_json,'server',0,'demo_local',:verified_at,:verified_by,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    [
                        'user_id'=>$userId,'public_id'=>$definition['profile'],'name'=>$definition['name'],'mobile_masked'=>$this->mask((string)$definition['mobile']),
                        'mobile_cipher'=>$this->cipher->encrypt((string)$definition['mobile'],'worker-mobile'),'verification'=>$definition['status'],'lat'=>$definition['lat'],'lng'=>$definition['lng'],
                        'profile_json'=>$profileJson,'verified_at'=>$definition['status']==='approved'?gmdate('Y-m-d H:i:s'):null,'verified_by'=>$definition['status']==='approved'?$adminId:null,
                    ]
                );
            } else {
                $workerId = (int)$profile['id'];
                $this->db->execute(
                    "UPDATE worker_profiles SET display_name=:name,mobile_masked=:mobile_masked,mobile_ciphertext=:mobile_cipher,verification_status=:verification,invited_only=0,city='杭州市',district='拱墅区',address_masked='测试区域（虚构）',lat=:lat,lng=:lng,coordinate_system='GCJ-02',profile_json=:profile_json,profile_source='server',cloud_worker_id=NULL,cloud_snapshot_json=NULL,cloud_record_count=0,cloud_record_mode='demo_local',verified_at=:verified_at,verified_by=:verified_by,updated_at=UTC_TIMESTAMP() WHERE id=:id",
                    [
                        'name'=>$definition['name'],'mobile_masked'=>$this->mask((string)$definition['mobile']),'mobile_cipher'=>$this->cipher->encrypt((string)$definition['mobile'],'worker-mobile'),
                        'verification'=>$definition['status'],'lat'=>$definition['lat'],'lng'=>$definition['lng'],'profile_json'=>$profileJson,
                        'verified_at'=>$definition['status']==='approved'?gmdate('Y-m-d H:i:s'):null,'verified_by'=>$definition['status']==='approved'?$adminId:null,'id'=>$workerId,
                    ]
                );
            }
            $result[$slot] = ['slot'=>$slot,'user_id'=>$userId,'worker_id'=>$workerId,'status'=>$definition['status'],'name'=>$definition['name']];
        }
        return $result;
    }

    /** @param array<string,array<string,mixed>> $participants */
    public function attach(int $instanceId, array $participants, array $slots): void
    {
        foreach ($slots as $slot) {
            if (!isset($participants[$slot])) continue;
            $row = $participants[$slot];
            $this->db->execute(
                "INSERT INTO test_participants(instance_id,slot_key,actor_type,user_id,worker_id,expected_status,created_at)
                 VALUES(:instance,:slot,'worker',:user_id,:worker_id,:expected,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),worker_id=VALUES(worker_id),expected_status=VALUES(expected_status)",
                ['instance'=>$instanceId,'slot'=>$slot,'user_id'=>$row['user_id'],'worker_id'=>$row['worker_id'],'expected'=>$row['status']]
            );
        }
    }

    private function mask(string $mobile): string
    {
        return preg_match('/^(1\d{2})\d{4}(\d{4})$/D',$mobile,$m)===1 ? $m[1].'****'.$m[2] : '***';
    }
}
