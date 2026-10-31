<?php
declare(strict_types=1);

namespace WorkerAdmin\Contracts {
    interface PrivateValueCipher {
        public function hmac(string $plainText,string $context):string;
        public function encrypt(string $plainText,string $context):?string;
        public function decrypt(string $cipherText,string $context):string;
    }
    interface ChatRecoveryReadiness {
        public function ensureReady(array $config,string $environment):void;
    }
}

namespace WorkerAdmin\Core {
    use PDO;
    final class Database {
        public function __construct(private PDO $pdo) {}
        public function fetch(string $sql,array $params=[]):?array {
            $s=$this->pdo->prepare($sql);$s->execute($params);$r=$s->fetch(PDO::FETCH_ASSOC);return $r===false?null:$r;
        }
        public function fetchAll(string $sql,array $params=[]):array {
            $s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);
        }
        public function fetchValue(string $sql,array $params=[]):mixed {
            $s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();
        }
        public function execute(string $sql,array $params=[]):int {
            $s=$this->pdo->prepare($sql);$s->execute($params);return $s->rowCount();
        }
        public function transaction(callable $fn):mixed {
            $this->pdo->beginTransaction();
            try{$r=$fn($this);$this->pdo->commit();return $r;}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        }
    }
}

namespace {
    use PDO;
    use RuntimeException;
    use WorkerAdmin\Application\Chat\ChatCredentialIssuer;
    use WorkerAdmin\Application\Chat\ChatSigningKeyManager;
    use WorkerAdmin\Contracts\ChatRecoveryReadiness;
    use WorkerAdmin\Contracts\PrivateValueCipher;
    use WorkerAdmin\Core\Database;

    if(getenv('CHAT_SIGNING_TEST')!=='1') throw new RuntimeException('isolated test flag required');
    if(!extension_loaded('sodium')||!extension_loaded('pdo_mysql')) throw new RuntimeException('sodium and pdo_mysql required');

    require dirname(__DIR__).'/server/app/Application/Chat/ChatSigningKeyManager.php';
    require dirname(__DIR__).'/server/app/Application/Chat/ChatCredentialIssuer.php';

    function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
    function rejected(callable $fn,string $contains=''):void{
        try{$fn();}catch(RuntimeException $e){if($contains!==''&&!str_contains($e->getMessage(),$contains))throw new RuntimeException('wrong rejection: '.$e->getMessage());return;}
        throw new RuntimeException('expected rejection');
    }

    $port=(int)getenv('MYSQL_PORT');
    check($port>0,'MYSQL_PORT required');
    $name='chat_signing_'.bin2hex(random_bytes(5));
    $root=new PDO('mysql:host=127.0.0.1;port='.$port,'root','fixture-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $root->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    try{
        $pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$name.';charset=utf8mb4','root','fixture-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        $pdo->exec("CREATE TABLE system_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT,value_type VARCHAR(20),group_name VARCHAR(32),is_public TINYINT,description TEXT,updated_by BIGINT NULL,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE admin_users(id BIGINT PRIMARY KEY,role VARCHAR(32),status VARCHAR(12),must_change_password TINYINT) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO admin_users VALUES(1,'super_admin','active',0),(2,'operator','active',0)");
        $db=new Database($pdo);

        $cipher=new class implements PrivateValueCipher {
            public function hmac(string $plainText,string $context):string{return hash('sha256',$context."\n".$plainText);}
            public function encrypt(string $plainText,string $context):?string{return 'fixture:'.base64_encode($context."\0".$plainText);}
            public function decrypt(string $cipherText,string $context):string{
                if(!str_starts_with($cipherText,'fixture:'))throw new RuntimeException('cipher invalid');
                $raw=base64_decode(substr($cipherText,8),true);$prefix=$context."\0";
                if(!is_string($raw)||!str_starts_with($raw,$prefix))throw new RuntimeException('context invalid');
                return substr($raw,strlen($prefix));
            }
        };

        $config=['key_id'=>'chat-v1','seed_env'=>'CHAT_ACCESS_SEED'];
        putenv('CHAT_ACCESS_SEED');
        $manager=new ChatSigningKeyManager($db,$cipher);

        $missing=$manager->status($config);
        check($missing['configured']===false&&$missing['reason']==='CHAT_SIGNING_NOT_CONFIGURED','missing key must fail closed');
        rejected(fn()=>$manager->generate($config,2,'operator',false),'仅超级管理员');

        $first=$manager->generate($config,1,'super_admin',false);
        check($first['configured']===true&&$first['source']==='database'&&$first['kid']==='chat-v1','database key generated');
        check(count($first['public_keys'])===1&&isset($first['public_keys']['chat-v1']),'public ring contains active key');
        $public=base64_decode($first['public_key'],true);
        check(is_string($public)&&strlen($public)===32&&base64_encode($public)===$first['public_key'],'public key canonical base64');
        check(str_contains($first['go_config'],'"enabled": true')&&str_contains($first['go_config'],'"chat-v1"'),'Go auth config emitted');
        check(!str_contains(json_encode($first,JSON_THROW_ON_ERROR),'signing_seed_ciphertext'),'status does not expose private setting name');

        $recovery=new class implements ChatRecoveryReadiness {
            public function ensureReady(array $config,string $environment):void {
                if($environment!=='test')throw new RuntimeException('wrong environment');
            }
        };
        $issuer=new ChatCredentialIssuer($db,$config,'testing',$cipher,$recovery);
        check($issuer->readiness()===['ready'=>true,'reason'=>'ready'],'issuer accepts backend-managed signing key');
        $issue=new ReflectionMethod(ChatCredentialIssuer::class,'issue');
        $credential=$issue->invoke($issuer,[
            'sub'=>'1','kind'=>'mini','source_id'=>'1',
            'session_id'=>hash('sha256','fixture-session'),
            'session_version'=>hash('sha256','fixture-version'),
            'scopes'=>['chat:use'],
        ],time()+600);
        $parts=explode('.',(string)$credential['access_token']);
        check(count($parts)===3,'issuer creates JWT');
        $decodeUrl=static function(string $value):string {
            $value=strtr($value,'-_','+/');
            $value.=str_repeat('=',(4-strlen($value)%4)%4);
            $decoded=base64_decode($value,true);
            if(!is_string($decoded))throw new RuntimeException('invalid base64url');
            return $decoded;
        };
        $header=json_decode($decodeUrl($parts[0]),true,512,JSON_THROW_ON_ERROR);
        $claims=json_decode($decodeUrl($parts[1]),true,512,JSON_THROW_ON_ERROR);
        check(($header['kid']??'')==='chat-v1'&&($claims['env']??'')==='test','issuer uses backend kid and mapped environment');
        check(sodium_crypto_sign_verify_detached($decodeUrl($parts[2]),$parts[0].'.'.$parts[1],$public),'Go public key verifies PHP JWT');

        $material=$manager->material($config);
        check($material['source']==='database'&&strlen($material['seed'])===32,'PHP can recover encrypted seed');
        $seedEncoded=base64_encode($material['seed']);sodium_memzero($material['seed']);
        $ciphertext=(string)$db->fetchValue("SELECT setting_value FROM system_settings WHERE setting_key='chat.signing_seed_ciphertext'");
        check($ciphertext!==''&&!str_contains($ciphertext,$seedEncoded),'seed stored encrypted');
        check(!str_contains(json_encode($first,JSON_THROW_ON_ERROR),$seedEncoded),'admin status never exposes seed');
        rejected(fn()=>$manager->generate($config,1,'super_admin',false),'已存在');

        $overrideSeed=random_bytes(32);$overrideEncoded=base64_encode($overrideSeed);sodium_memzero($overrideSeed);putenv('CHAT_ACCESS_SEED='.$overrideEncoded);
        $override=$manager->status($config);
        check($override['configured']===true&&$override['source']==='environment'&&$override['kid']==='chat-v1','environment override preserved');
        check($override['public_key']!==$first['public_key'],'environment override changes public key');
        rejected(fn()=>$manager->generate($config,1,'super_admin',true),'环境变量');
        putenv('CHAT_ACCESS_SEED');

        $rotated=$manager->generate($config,1,'super_admin',true);
        check($rotated['configured']===true&&$rotated['source']==='database'&&$rotated['kid']!=='chat-v1','rotation switches kid');
        check(count($rotated['public_keys'])===2&&isset($rotated['public_keys']['chat-v1'])&&isset($rotated['public_keys'][$rotated['kid']]),'rotation keeps prior public key');
        check($rotated['public_key']!==$first['public_key'],'rotation changes public key');

        $rotated2=$manager->generate($config,1,'super_admin',true);
        $rotated3=$manager->generate($config,1,'super_admin',true);
        check(count($rotated3['public_keys'])===3,'public key ring is bounded to three keys');
        check(isset($rotated3['public_keys'][$rotated3['kid']]),'current key remains in bounded ring');

        echo "PASS chat signing admin generation, encrypted PHP storage, environment override, Go public config and bounded rotation ring\n";
    } finally {
        putenv('CHAT_ACCESS_SEED');
        $root->exec('DROP DATABASE IF EXISTS `'.$name.'`');
    }
}
