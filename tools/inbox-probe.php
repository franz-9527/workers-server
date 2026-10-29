<?php
spl_autoload_register(function($class){$file=__DIR__.'/../server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($file))require $file;});
function app_config($key,$default=null){return ['app.key'=>str_repeat('fixture-only-',4),'app.env'=>'testing'][$key]??$default;}
use WorkerAdmin\Core\Database;
$config=['host'=>'127.0.0.1','port'=>(int)getenv('MYSQL_PORT'),'database'=>'mysql','username'=>'root','password'=>'fixture-only','charset'=>'utf8mb4'];
$admin=new Database($config);$name='test_inbox_'.bin2hex(random_bytes(4));$admin->execute('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$name;$db=new Database($config);
try{
$db->execute("CREATE TABLE notifications(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80),recipient_type VARCHAR(20),recipient_id BIGINT UNSIGNED,channel VARCHAR(20),template_key VARCHAR(40),title VARCHAR(200),content TEXT,data_json LONGTEXT,read_at DATETIME NULL,created_at DATETIME) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->execute("CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY,public_no VARCHAR(32),user_id BIGINT UNSIGNED,lifecycle_status VARCHAR(40),is_demo TINYINT)");$db->execute("INSERT INTO orders VALUES(77,'ORDER77',1,'active',0)");
for($i=1;$i<=25;$i++)$db->execute("INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,content,data_json,created_at) VALUES(:pub,'user',1,'in_app','update','通知','内容',:data,UTC_TIMESTAMP())",['pub'=>'n'.$i,'data'=>$i<3?'{"order_id":77}':'{}']);
$repo=new WorkerAdmin\Infrastructure\Persistence\DatabaseNotificationInboxRepository($db);$actor=['user_id'=>1,'worker_id'=>0];
$page=$repo->threads($actor);echo 'FIRST '.count($page['items'])."\n";$next=$repo->threads($actor,$page['next_cursor']);echo 'NEXT '.count($next['items'])."\n";
foreach($next['items'] as $item)if($item['is_thread']){var_export($repo->threadMessages($actor,$item['thread_cursor']));}
$repo->readRange($actor,$page['range_cursor']);echo "\nINBOX OK\n";
}finally{$admin->execute('DROP DATABASE '.$name);}
