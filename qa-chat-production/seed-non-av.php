<?php
declare(strict_types=1);

$site='/www/wwwroot/worker';
$installed=$site.'/config/installed.php';
$deadline=microtime(true)+120;
while(!is_file($installed)){
    if(microtime(true)>$deadline){fwrite(STDERR,"QA_NON_AV_SEED_TIMEOUT installed\n");exit(2);}
    usleep(200000);
}
require $site.'/bootstrap.php';
while(true){
    try{
        $db=db();
        if($db->tableExists('system_settings'))break;
    }catch(Throwable $e){}
    if(microtime(true)>$deadline){fwrite(STDERR,"QA_NON_AV_SEED_TIMEOUT database\n");exit(2);}
    usleep(200000);
}
$template=[
    'chat_unread'=>[
        'template_id'=>'fixture-chat-template',
        'synced_template_id'=>'fixture-chat-template',
        'template_title'=>'聊天消息提醒',
        'template_fields'=>['thing1','thing2','time3','thing4','thing5'],
        'template_field_labels'=>[
            'thing1'=>'发送人','thing2'=>'温馨提示','time3'=>'发送时间','thing4'=>'信息类型','thing5'=>'聊天信息',
        ],
        'field_map'=>[
            'sender_name'=>'thing1','notice_text'=>'thing2','time'=>'time3','message_type'=>'thing4','chat_info'=>'thing5',
        ],
    ],
];
$rows=[
    ['wechat.subscribe_enabled','1','bool'],
    ['wechat.subscribe_template_ids',json_encode($template,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'json'],
];
foreach($rows as [$key,$value,$type]){
    $db->execute(
        "INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,created_at,updated_at)
         VALUES(:key,:value,:type,'notification',0,'隔离 non-AV QA',UTC_TIMESTAMP(),UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type=VALUES(value_type),updated_at=UTC_TIMESTAMP()",
        ['key'=>$key,'value'=>$value,'type'=>$type]
    );
}
fwrite(STDOUT,"QA_NON_AV_REMINDER_TEMPLATE_READY\n");
