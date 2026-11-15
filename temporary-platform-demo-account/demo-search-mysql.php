<?php
declare(strict_types=1);

namespace WorkerAdmin\Contracts {
    interface MiniPlatformOrderCreationRepository {
        public function categories(): array;
        public function customers(string $query): array;
        public function create(array $input, int $operatorUserId, string $requestId): array;
    }
    interface PrivateValueCipher {
        public function hmac(string $text, string $context): string;
        public function encrypt(string $text, string $context): ?string;
        public function decrypt(string $text, string $context): string;
    }
}
namespace WorkerAdmin\Application\Order { final class PlatformOrderAdmin {} }
namespace WorkerAdmin\Domain\Order {
    final class OrderOperationException extends \RuntimeException {
        public function __construct(string $message,string $codeName,int $status=422){parent::__construct($message,$status);}
    }
}
namespace WorkerAdmin\Core {
    final class Database {
        private \PDO $pdo;
        public function __construct(\PDO $pdo){$this->pdo=$pdo;}
        public function fetchAll(string $sql,array $params=[]): array {
            $s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll(\PDO::FETCH_ASSOC);
        }
        public function fetch(string $sql,array $params=[]): ?array {
            $s=$this->pdo->prepare($sql);$s->execute($params);$r=$s->fetch(\PDO::FETCH_ASSOC);return $r===false?null:$r;
        }
        public function fetchValue(string $sql,array $params=[]){$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
        public function transaction(callable $fn){return $fn($this);}
        public function insert(string $sql,array $params=[]): int {$s=$this->pdo->prepare($sql);$s->execute($params);return (int)$this->pdo->lastInsertId();}
    }
}
namespace {
    require __DIR__.'/server/app/Infrastructure/Persistence/PlatformOrderCustomerAccountScope.php';
    require __DIR__.'/server/app/Infrastructure/Persistence/DatabaseMiniPlatformOrderCreationRepository.php';

    use WorkerAdmin\Application\Order\PlatformOrderAdmin;
    use WorkerAdmin\Contracts\PrivateValueCipher;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseMiniPlatformOrderCreationRepository;

    function check(bool $ok,string $label):void{if(!$ok){fwrite(STDERR,"[FAIL] $label\n");exit(1);}echo "[PASS] $label\n";}

    $pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS worker_demo_search_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE worker_demo_search_test');
    foreach(['system_settings','merchant_profiles','worker_profiles','wechat_identities','users'] as $t)$pdo->exec("DROP TABLE IF EXISTS $t");
    $pdo->exec("CREATE TABLE users(id BIGINT PRIMARY KEY,public_id VARCHAR(80) NOT NULL,nickname VARCHAR(80),mobile_masked VARCHAR(40),mobile_hmac VARCHAR(128),status VARCHAR(24) NOT NULL)");
    $pdo->exec("CREATE TABLE wechat_identities(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT NOT NULL,status VARCHAR(24) NOT NULL)");
    $pdo->exec("CREATE TABLE worker_profiles(id BIGINT PRIMARY KEY,user_id BIGINT NOT NULL,cloud_record_mode VARCHAR(24) NOT NULL)");
    $pdo->exec("CREATE TABLE merchant_profiles(id BIGINT PRIMARY KEY,user_id BIGINT NOT NULL)");
    $pdo->exec("CREATE TABLE system_settings(setting_key VARCHAR(128) PRIMARY KEY,setting_value TEXT)");

    $cipher=new class implements PrivateValueCipher{
        public function hmac(string $text,string $context):string{return hash_hmac('sha256',$text,$context);}
        public function encrypt(string $text,string $context):?string{return $text;}
        public function decrypt(string $text,string $context):string{return $text;}
    };
    $realMobile=$cipher->hmac('13800138000','user-mobile');
    $stmt=$pdo->prepare("INSERT INTO users VALUES(?,?,?,?,?,?)");
    $stmt->execute([1,'REAL_USER_1','真实客户','138****8000',$realMobile,'active']);
    $stmt->execute([2,'01K2DEMOV228USER0000000001','演示用户','130****2201',null,'active']);
    $stmt->execute([3,'01K2DEMOV228WORKER00000001','演示师傅','130****2203',null,'active']);
    $stmt->execute([4,'NO_WECHAT_USER','普通无微信客户','139****0000',null,'active']);
    $stmt->execute([5,'CUSTOM_DEMO_MERCHANT','配置演示商家','130****2299',null,'active']);
    $pdo->exec("INSERT INTO wechat_identities(user_id,status) VALUES(1,'active')");
    $pdo->exec("INSERT INTO worker_profiles VALUES(31,3,'demo_local')");
    $pdo->exec("INSERT INTO merchant_profiles VALUES(51,5)");
    $pdo->exec("INSERT INTO system_settings VALUES('demo.actor_merchant_public_id','CUSTOM_DEMO_MERCHANT')");

    $repo=new DatabaseMiniPlatformOrderCreationRepository(new Database($pdo),$cipher,new PlatformOrderAdmin());

    $real=$repo->customers('13800138000');
    check(count($real)===1 && $real[0]['public_id']==='REAL_USER_1' && (int)$real[0]['is_demo']===0,'真实客户仍按完整手机号 HMAC 搜索');
    check($real[0]['account_role']==='user','真实客户角色标记为用户');

    $demo=$repo->customers('演示');
    $by=[];foreach($demo as $row)$by[$row['public_id']]=$row;
    check(isset($by['01K2DEMOV228USER0000000001']),'演示用户无需微信身份也可搜索');
    check(isset($by['01K2DEMOV228WORKER00000001']),'演示师傅无需微信身份也可搜索');
    check(isset($by['CUSTOM_DEMO_MERCHANT']),'后台配置指定的演示账号可搜索');
    check((int)$by['01K2DEMOV228USER0000000001']['is_demo']===1 && $by['01K2DEMOV228USER0000000001']['account_role']==='user','演示用户带演示域和用户角色');
    check((int)$by['01K2DEMOV228WORKER00000001']['is_demo']===1 && $by['01K2DEMOV228WORKER00000001']['account_role']==='worker','演示师傅带演示域和师傅角色');
    check((int)$by['CUSTOM_DEMO_MERCHANT']['is_demo']===1 && $by['CUSTOM_DEMO_MERCHANT']['account_role']==='merchant','配置演示商家带演示域和商家角色');

    $blocked=$repo->customers('无微信');
    check(count($blocked)===0,'普通无微信身份账号继续被排除');

    echo "Platform demo customer search MySQL regression: OK\n";
}
