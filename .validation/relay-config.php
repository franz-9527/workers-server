<?php
declare(strict_types=1);

namespace WorkerAdmin\Core {
    final class Database {
        public array $rows = [];
        public function fetchAll(string $query): array {
            $rows = [];
            foreach ($this->rows as $key => $value) $rows[]=['setting_key'=>$key,'setting_value'=>$value];
            return $rows;
        }
        public function execute(string $query, array $params): void {
            $this->rows[$params['key']]=$params['value'];
        }
    }
}

namespace {
    function app_config(string $key, string $default): string {return $GLOBALS['env'];}
    function check(bool $ok, string $label): void {if(!$ok) throw new \RuntimeException($label); echo "PASS $label\n";}
    require __DIR__.'/PrivateValueCipher.php';
    require __DIR__.'/ChatMediaCallbackSettings.php';
    $GLOBALS['env']='testing';
    $db=new \WorkerAdmin\Core\Database();
    $cipher=new class implements \WorkerAdmin\Contracts\PrivateValueCipher {
        public function hmac(string $value,string $context): string {return hash_hmac('sha256',$value,$context);}
        public function encrypt(string $value,string $context): string {return 'fixture:'.base64_encode($value);}
        public function decrypt(string $value,string $context): string {return (string)base64_decode(substr($value,8),true);}
    };
    $app='wx0123456789abcdef'; $test='https://test.example.invalid';
    $s=new \WorkerAdmin\Application\Chat\ChatMediaCallbackSettings($db,$cipher);
    $key=str_repeat('a',64);
    $s->saveLocked(['wechat_callback_mode'=>'relay','wechat_callback_relay_key'=>strtoupper($key),'wechat_callback_confirmed'=>'1'],1,$app,$test);
    $r=$s->runtime($app,$test);
    check($r['mode']==='relay'&&$r['ready']&&$r['relay_key']===$key&&$r['token']===''&&$r['aes_key']==='', 'relay enables AV without formal WeChat secrets');
    check(!in_array($key,$db->rows,true),'relay key is encrypted at rest');
    check(!$s->runtime('wx1111111111111111',$test)['ready'],'AppID change invalidates confirmation');
    check(!$s->runtime($app,'https://other.example.invalid')['ready'],'site change invalidates confirmation');
    $s->saveLocked(['wechat_callback_mode'=>'relay'],1,$app,$test);
    check(!$s->runtime($app,$test)['ready'],'unconfirmed relay stays closed');
    $s->saveLocked(['wechat_callback_mode'=>'relay','wechat_callback_confirmed'=>'1'],1,$app,$test);
    check($s->runtime($app,$test)['relay_key']===$key,'blank field preserves existing key');
    $GLOBALS['env']='production';
    check($s->runtime($app,$test)['mode']==='direct','production cannot become a relay receiver');
    $rejected=false;
    try {$s->saveLocked(['wechat_callback_mode'=>'relay'],1,$app,$test);} catch (\RuntimeException $e) {$rejected=true;}
    check($rejected,'production rejects receiving mode');
}
