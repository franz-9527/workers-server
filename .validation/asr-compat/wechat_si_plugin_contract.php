<?php
declare(strict_types=1);
require __DIR__.'/ChatWechatSIPluginProbe.php';

use WorkerAdmin\Application\Chat\ChatWechatSIPluginProbe;

$approved=(new ChatWechatSIPluginProbe(static fn(string $token):array=>['status'=>200,'body'=>'{"errcode":0,"errmsg":"ok","plugin_list":[{"appid":"wx069ba97219f66d99","status":2,"nickname":"WechatSI"}]}']))->run(str_repeat('a',32));
if(($approved['plugin_state']??'')!=='approved'||empty($approved['approved']))throw new RuntimeException('approved');

$pending=(new ChatWechatSIPluginProbe(static fn(string $token):array=>['status'=>200,'body'=>'{"errcode":0,"plugin_list":[{"appid":"wx069ba97219f66d99","status":1}]}']))->run(str_repeat('a',32));
if(($pending['plugin_state']??'')!=='pending'||!empty($pending['approved']))throw new RuntimeException('pending');

$missing=(new ChatWechatSIPluginProbe(static fn(string $token):array=>['status'=>200,'body'=>'{"errcode":0,"plugin_list":[{"appid":"other","status":2,"nickname":"secret"}]}']))->run(str_repeat('a',32));
if(($missing['plugin_state']??'')!=='not_added'||isset($missing['nickname']))throw new RuntimeException('missing');

$failed=(new ChatWechatSIPluginProbe(static fn(string $token):array=>['status'=>200,'body'=>'{"errcode":40001,"errmsg":"secret token details"}']))->run(str_repeat('a',32));
if(($failed['status']??'')!=='failed'||($failed['errcode']??'')!=='40001'||isset($failed['errmsg']))throw new RuntimeException('failed');
echo "WECHATSI_PLUGIN_PROBE_CONTRACT_PASSED\n";
