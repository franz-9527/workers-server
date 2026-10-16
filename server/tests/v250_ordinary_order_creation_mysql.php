<?php
declare(strict_types=1);

use WorkerAdmin\Application\Order\MiniOrdinaryOrderCreation;
use WorkerAdmin\Application\Order\OrderSubmission;
use WorkerAdmin\Contracts\ObjectStorage;
use WorkerAdmin\Contracts\OrderAgreementProvider;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMediaRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMiniOrdinaryOrderCreationRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderProductReuseRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderSubmissionRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabasePaymentCreationRepository;
use WorkerAdmin\Infrastructure\Persistence\PolicyAwareOrderSubmissionRepository;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;

$root=dirname(__DIR__,2);
spl_autoload_register(static function(string $class) use($root): void {
    if(strpos($class,'WorkerAdmin\\')!==0)return;
    $path=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
    if(is_file($path))require_once $path;
});
require_once $root.'/server/app/Support/functions.php';
function ordinarySame($actual,$expected,string $label): void {
    if($actual!==$expected)throw new RuntimeException($label.' actual='.var_export($actual,true).' expected='.var_export($expected,true));
    echo '[PASS] '.$label."\n";
}
function ordinaryBlocked(callable $action,string $code,string $label): void {
    try{$action();}catch(OrderOperationException $error){ordinarySame($error->apiCode(),$code,$label);return;}
    throw new RuntimeException($label.' was accepted');
}
function ordinaryCipher(): PrivateValueCipher {
    return new class implements PrivateValueCipher {
        public function hmac(string $text,string $context): string{return hash_hmac('sha256',$text,$context);}
        public function encrypt(string $text,string $context): ?string{return $text===''?null:base64_encode($text);}
        public function decrypt(string $text,string $context): string{return (string)base64_decode($text);}
    };
}
function ordinaryServices(Database $db): array {
    $settings=new GlobalSettingsProvider();$cipher=ordinaryCipher();
    $storage=new class implements ObjectStorage {
        public function enabled(): bool{return false;}
        public function store(string $path,string $key,string $type='general',string $mime='application/octet-stream'): array{throw new RuntimeException('No external IO in this test');}
        public function downloadUrl(string $key,int $ttl=900): string{return '';}
        public function usesHttp(): bool{return false;}
        public function read(string $key,int $ttl=900): string{return '';}
        public function delete(string $key): void{}
    };
    $agreements=new class implements OrderAgreementProvider {
        public function orderSubmitAgreements(): array{return [['key'=>'rules','version'=>'1','title'=>'规则']];}
        public function validatePublishedVersions(array $versions): array{return ['ok'=>($versions['rules']??'')==='1'];}
    };
    $base=new DatabaseOrderSubmissionRepository($db,$settings,$cipher,$storage);
    $products=new DatabaseOrderProductReuseRepository($db);
    $submission=new OrderSubmission(new PolicyAwareOrderSubmissionRepository($base,$db,$settings,$cipher),$agreements,$settings,$products);
    $proxy=new MiniOrdinaryOrderCreation(new DatabaseMiniOrdinaryOrderCreationRepository($db,$cipher,$submission));
    return [$submission,$proxy,$base,$products,new DatabaseMediaRepository($db,$settings)];
}
if(getenv('ORDINARY_CREATE_MYSQL_TEST')!=='1'){fwrite(STDERR,"Set ORDINARY_CREATE_MYSQL_TEST=1 for an isolated MySQL server.\n");exit(2);}
$config=['host'=>'127.0.0.1','port'=>(int)(getenv('V250_DB_PORT')?:3306),'database'=>'mysql','username'=>(string)(getenv('V250_DB_USER')?:'root'),'password'=>(string)getenv('V250_DB_PASSWORD')];
if(($argv[1]??'')==='--child'){
    $name=(string)getenv('ORDINARY_CHILD_DB');if(!preg_match('/^worker_ordinary_test_[a-f0-9]{10}$/D',$name))exit(2);
    $config['database']=$name;$GLOBALS['worker_admin_config']=['database'=>$config];
    [, $proxy]=ordinaryServices(new Database($config));
    echo json_encode($proxy->create(['is_admin'=>true,'user_id'=>11],json_decode((string)getenv('ORDINARY_CHILD_BODY'),true)));exit(0);
}
$adminDb=new Database($config);$name='worker_ordinary_test_'.bin2hex(random_bytes(5));
$adminDb->pdo()->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config['database']=$name;$GLOBALS['worker_admin_config']=['database'=>$config,'app'=>['key'=>'isolated-test-only','url'=>'https://isolated.invalid']];
$db=new Database($config);
try {
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach(['server/database/database.sql','server/database/upgrades/2.2.9.sql','server/database/upgrades/2.5.0.sql'] as $file){
        preg_match_all('/^CREATE TABLE(?: IF NOT EXISTS)?\s+`?([A-Za-z0-9_]+)`?\s*\([\s\S]*?;\s*$/m',(string)file_get_contents($root.'/'.$file),$matches,PREG_SET_ORDER);
        foreach($matches as $match)if(!$db->tableExists($match[1]))$db->pdo()->exec($match[0]);
    }
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    // 表单 UI 配置由后续升级添加；最小建表夹具补齐正式字段。
    if (!$db->columnExists('form_schema_versions','ui_config_json')) $db->pdo()->exec('ALTER TABLE form_schema_versions ADD COLUMN ui_config_json JSON NULL');
    $cipher=ordinaryCipher();
    foreach([11=>'管理员',22=>'真实客户',33=>'无微信客户',44=>'另一客户'] as $id=>$nickname){
        $db->execute("INSERT INTO users(id,public_id,nickname,mobile_masked,mobile_ciphertext,mobile_hmac,status,created_at,updated_at) VALUES(:id,:ref,:nickname,'138******00',:cipher,:hmac,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$id,'ref'=>str_pad((string)$id,26,'0',STR_PAD_LEFT),'nickname'=>$nickname,'cipher'=>$cipher->encrypt('13800138000','user-mobile'),'hmac'=>$id===22?$cipher->hmac('13800138000','user-mobile'):null]);
    }
    foreach([22,44] as $id)$db->execute("INSERT INTO wechat_identities(user_id,app_id,openid,status,created_at,updated_at) VALUES(:id,'test-app',:openid,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$id,'openid'=>'openid-'.$id]);
    $db->execute("INSERT INTO service_categories(id,category_key,name,order_code,status,sort_order,created_at,updated_at) VALUES(2,'wallcloth','墙布','QB','active',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $db->execute("INSERT INTO form_schemas(id,schema_key,name,status,created_at,updated_at) VALUES(1,'order_submit','普通下单','published',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $db->execute("INSERT INTO form_schema_versions(id,schema_id,version_no,status,created_at) VALUES(501,1,1,'published',UTC_TIMESTAMP())");
    $db->execute("INSERT INTO form_steps(id,schema_version_id,step_key,title,status,sort_order,created_at,updated_at) VALUES(701,501,'product','商品','active',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    foreach([801=>['product_link','商品链接','text',0],802=>['quantity','数量','text',1],803=>['material_images','商品图','image_upload',1],804=>['wall_images','现场图','image_upload',1],805=>['contact_mobile','电话','text',0]] as $id=>$field){
        $db->execute("INSERT INTO form_fields(id,step_id,field_key,label,component_type,is_required,validation_json,status,created_at,updated_at) VALUES(:id,701,:key,:label,:component,:required,:validation,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$id,'key'=>$field[0],'label'=>$field[1],'component'=>$field[2],'required'=>$field[3],'validation'=>json_encode(['min_items'=>1,'max_items'=>3])]);
    }
    [$submission,$proxy,$base,$products,$media]=ordinaryServices($db);
    $actor=['user_id'=>22,'user_public_id'=>str_pad('22',26,'0',STR_PAD_LEFT),'mobile_masked'=>'138******00'];
    $admin=['user_id'=>11,'is_admin'=>true,'user_public_id'=>str_pad('11',26,'0',STR_PAD_LEFT)];
    $ref=$actor['user_public_id'];
    $body=['client_request_id'=>'ordinary_request_123456','customer_ref'=>$ref,'schema_version_id'=>501,'answers'=>['workflow_profile'=>'construction','quantity'=>'50','product_link'=>'product-link','category_ids'=>[2],'contact_mobile'=>'13900139000'], 'address'=>['city'=>'绍兴市','district'=>'越城区','detail'=>'测试地址','contact_name'=>'客户','contact_mobile'=>'13900139000','lat'=>29.95,'lng'=>120.56], 'dispatch_mode'=>'multi_quote'];
    ordinaryBlocked(static function()use($proxy,$ref){$proxy->form(['user_id'=>22],$ref);},'ADMIN_REQUIRED','普通客户不能代下单');
    ordinaryBlocked(static function()use($proxy,$admin,$ref){$proxy->form($admin+['is_demo_actor'=>true],$ref);},'DEMO_ADMIN_ACTION_BLOCKED','演示管理员禁止真实代下单');
    ordinarySame(count($proxy->customers($admin,'13800138000')['items']),1,'完整手机号只返回匹配且可登录客户');
    ordinaryBlocked(static function()use($proxy,$admin){$proxy->form($admin,str_pad('33',26,'0',STR_PAD_LEFT));},'PROXY_CUSTOMER_INVALID','无微信客户不能绑定普通单');
    ordinarySame($proxy->form($admin,$ref)['schema']['id'],501,'代下单读取最新动态表单');
    $created=$proxy->create($admin,$body+['user_id'=>44,'payment_status'=>'paid','order_source_type'=>'platform','agreements'=>['accepted'=>true]]);
    $order=$db->fetch('SELECT * FROM orders WHERE public_no=:no',['no'=>$created['id']]);$id=(int)$order['id'];
    ordinarySame((int)$order['user_id'],22,'归属仅由真实客户公开编号确定');
    ordinarySame($order['audit_status'],'draft','创建保持普通草稿');ordinarySame($order['payment_status'],'unpaid','不伪造预约费付款');ordinarySame($order['publish_status'],'hidden','未付款不得进入大厅');
    ordinarySame($order['order_source_type'],'consumer','不接受客户端伪造平台单');
    ordinarySame((int)$order['booking_fee_cents'],5000,'预约费按普通下单配置');
    ordinarySame($proxy->create($admin,$body)['id'],$created['id'],'同请求重试复用同一订单');
    ordinaryBlocked(static function()use($proxy,$admin,$body){$proxy->create($admin,array_replace($body,['customer_ref'=>str_pad('44',26,'0',STR_PAD_LEFT)]));},'IDEMPOTENCY_CONFLICT','同请求更换客户被拒绝');
    ordinarySame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=:id AND event_type='order_terms_accepted'",['id'=>$id]),0,'管理员未代确认客户协议');
    ordinaryBlocked(static function()use($proxy,$admin,$created){$proxy->ready($admin,['order_id'=>$created['id']]);},'ORDER_IMAGES_REQUIRED','未补齐图片不能交付');
    ordinaryBlocked(static function()use($submission,$actor,$created){$submission->finalize($actor,['order_id'=>$created['id'],'agreements'=>['accepted'=>true,'versions'=>['rules'=>'1']]]);},'ORDER_IMAGES_REQUIRED','客户提前提交同样校验图片');
    $optimized=['storage_key'=>'private/test.jpg','original_name'=>'test.jpg','mime_type'=>'image/jpeg','extension'=>'jpg','size_bytes'=>100,'width_px'=>10,'height_px'=>10,'sha256'=>hash('sha256','test-image'),'compression_quality'=>80];
    // 使用真实媒体仓储插入；外部压缩/七牛链路由 Media 原有专项覆盖。
    foreach(['product'=>'product-file','wall'=>'wall-file'] as $purpose=>$publicId){
        $context=$media->prepareOrderUpload($admin,$created['id'],$purpose);
        $db->transaction(static function()use($media,$context,$optimized,$publicId){$media->storeOrderMedia($context,$optimized,[],$publicId);});
    }
    ordinaryBlocked(static function()use($submission,$actor,$created){$submission->finalize($actor,['order_id'=>$created['id'],'agreements'=>['accepted'=>true,'versions'=>['rules'=>'1']]]);},'PROXY_DRAFT_INCOMPLETE','资料未交付前不能提前确认付款');
    $ready=$proxy->ready($admin,['order_id'=>$created['id']]);$proxy->ready($admin,['order_id'=>$created['id']]);
    ordinarySame($ready['ready'],true,'资料完整后交付客户');
    ordinarySame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=:id AND event_type='mini_ordinary_ready'",['id'=>$id]),1,'重复交付只记一次经办事件');
    try{$media->prepareOrderUpload($admin,$created['id'],'product');throw new RuntimeException('upload was accepted');}catch(\WorkerAdmin\Domain\Media\MediaOperationException $error){ordinarySame($error->apiCode(),'PROXY_DRAFT_LOCKED','交付后管理员上传被锁定');}
    ordinaryBlocked(static function()use($submission,$actor,$created){$submission->finalize($actor,['order_id'=>$created['id']]);},'ORDER_AGREEMENT_REQUIRED','客户本人仍须确认协议');
    $submission->finalize($actor,['order_id'=>$created['id'],'agreements'=>['accepted'=>true,'versions'=>['rules'=>'1']]]);
    ordinarySame($db->fetchValue('SELECT payment_status FROM orders WHERE id=:id',['id'=>$id]),'awaiting_payment','客户确认后才进入待支付');
    $paymentRepo=new DatabasePaymentCreationRepository($db,new GlobalSettingsProvider());
    $payment=$db->transaction(static function()use($paymentRepo,$actor,$created){return $paymentRepo->prepare($actor+['payer_openid'=>'openid-22'],['order_id'=>$created['id']],'v3','127.0.0.1');});
    ordinarySame((int)$payment['amount_cents'],5000,'微信支付单金额来自真实预约费快照');
    ordinarySame((int)$db->fetchValue("SELECT actor_id FROM order_events WHERE order_id=:id AND event_type='mini_ordinary_created'",['id'=>$id]),11,'经办为真实小程序管理员 users.id');
    // 历史订单作为新商品来源；原订单关系和文件不转移，不复用现场或客户字段。
    $db->execute("UPDATE orders SET lifecycle_status='completed',fulfillment_status='accepted',payment_status='paid' WHERE id=:id",['id'=>$id]);
    $history=$products->history($actor,[]);ordinarySame(count($history['items']),1,'本人历史完成订单可读取商品');
    ordinarySame($history['items'][0]['answers']['product_link'],'product-link','商品链接可复用');
    ordinarySame(isset($history['items'][0]['answers']['contact_mobile']),false,'历史联系方式不进入复用资料');
    ordinarySame(isset($history['items'][0]['answers']['quantity']),false,'历史用量不进入复用资料');
    ordinarySame(array_column($history['items'][0]['media'],'id'),['product-file'],'只返回商品图');
    ordinarySame(strpos($history['items'][0]['media'][0]['url'],'access_token=')!==false,true,'图片通过短时私有访问凭据读取');
    ordinarySame(count($products->history(['user_id'=>44],['source_id'=>$created['id']])['items']),0,'不能列出他人历史商品');
    $reuseBody=array_diff_key($body,['customer_ref'=>true]);$reuseBody['client_request_id']='reuse_request_12345678';$reuseBody['reuse_product_order_id']=$created['id'];$reuseBody['reuse_media_ids']=['product-file'];
    $reused=$submission->create($actor,$reuseBody);$newId=(int)$reused['data']['internal_id'];
    ordinarySame((int)$db->fetchValue("SELECT COUNT(*) FROM media_links WHERE media_file_id=(SELECT id FROM media_files WHERE public_id='product-file')"),2,'新单新增引用，原单商品图保持');
    ordinarySame((int)$db->fetchValue("SELECT reference_count FROM media_files WHERE public_id='product-file'"),2,'媒体引用计数同步增加');
    ordinarySame((int)$db->fetchValue("SELECT COUNT(*) FROM media_links WHERE entity_type='order' AND entity_id=:id AND purpose='wall'",['id'=>$newId]),0,'现场图片不跨订单复用');
    $bad=$reuseBody;$bad['client_request_id']='reuse_wall_invalid_123';$bad['reuse_media_ids']=['wall-file'];
    ordinaryBlocked(static function()use($submission,$actor,$bad){$submission->create($actor,$bad);},'REUSE_MEDIA_INVALID','伪造现场图作为商品图被拒绝');
    $bad['client_request_id']='reuse_owner_invalid_123';$bad['reuse_media_ids']=['product-file'];
    ordinaryBlocked(static function()use($submission,$bad){$submission->create(['user_id'=>44,'mobile_masked'=>'138******00'],$bad);},'REUSE_ORDER_INVALID','伪造他人商品来源被拒绝');
    $db->transaction(static function()use($media,$actor,$reused){$media->deleteOrderMedia($actor,$reused['data']['id'],'product-file');});
    ordinarySame((int)$db->fetchValue("SELECT reference_count FROM media_files WHERE public_id='product-file'"),1,'移除新单商品图只减少新引用');
    ordinarySame($db->fetchValue("SELECT status FROM media_files WHERE public_id='product-file'"),'active','历史图片仍有引用时不能进入待删除状态');
    ordinarySame((int)$db->fetchValue("SELECT COUNT(*) FROM media_links WHERE entity_type='order' AND entity_id=:id AND purpose='product'",['id'=>$id]),1,'新单删除不破坏历史证据');
    // 两个独立 PHP/MySQL 连接并发重复创建，必须只生成一张普通订单。
    $parallel=$body;$parallel['client_request_id']='parallel_ordinary_123456';
    $env=getenv();$env['ORDINARY_CHILD_DB']=$name;$env['ORDINARY_CHILD_BODY']=json_encode($parallel);$children=[];
    for($i=0;$i<2;$i++){
        $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--child'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        if(!is_resource($process))throw new RuntimeException('child spawn failed');fclose($pipes[0]);$children[]=[$process,$pipes];
    }
    $results=[];
    foreach($children as [$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);if($status!==0)throw new RuntimeException('child failed '.$output.$error);$results[]=json_decode($output,true);}
    ordinarySame($results[0]['id'],$results[1]['id'],'并发重复创建返回同一普通订单');
    ordinarySame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE event_key='mini_ordinary_create:11:parallel_ordinary_123456'"),1,'并发只新增一次管理员经办记录');
} finally {$adminDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$name.'`');}
echo "ordinary proxy creation + historical product reuse MySQL regression OK\n";
