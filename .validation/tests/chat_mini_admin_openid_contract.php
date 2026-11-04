<?php
declare(strict_types=1);

function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$root=dirname(__DIR__).'/source';
$authority=file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseChatPlatformStaff.php');
$sandbox=file_get_contents($root.'/server/app/Application/Chat/ChatSandbox.php');
$view=file_get_contents($root.'/server/views/admin/chat-testing.php');

check(strpos($authority,"SELECT openid payer_openid FROM wechat_identities")!==false,'authority must read current OpenID');
check(strpos($authority,"'payer_openid'=>(string)\$current['payer_openid']")!==false,'authority must carry current OpenID into platform_staff');
check(strpos($sandbox,"'name'=>\$this->label((string)\$staff['payer_openid'])")!==false,'sandbox picker must display OpenID');
check(strpos($sandbox,"小程序管理员 · 用户 '.\$staff['actor_id']")===false,'legacy users.id display must be removed');
check(strpos($view,"foreach(['管理员','小程序管理员','演示客户','演示师傅'] as \$group)")!==false,'create form must retain mini admin group');
check(substr_count($view,"<?= e(\$actor['name']) ?>")>=2,'create and add-existing selectors must render actor name');

echo "chat mini admin OpenID contract: OK\n";
