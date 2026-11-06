<?php
declare(strict_types=1);

namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\External\WeChatAccessTokenProvider;

/**
 * Managed Go runtime. Test inherits PHP/site/provider configuration and opens
 * only capabilities whose real prerequisites are present.
 */
final class ChatGoRuntimeConfig
{
    private const REDIS_ENABLED_KEY='chat.go_redis_enabled';
    private const REDIS_ADDRESS_KEY='chat.go_redis_address';
    private const REDIS_PASSWORD_KEY='chat.go_redis_password_ciphertext';
    private const REDIS_PASSWORD_CONTEXT='chat-go-redis-password-v1';
    private const PYTHON_KEY='chat.go_python_binary';
    private const FFMPEG_KEY='chat.go_ffmpeg_binary';
    private const FFPROBE_KEY='chat.go_ffprobe_binary';
    private const ASR_PROFILE_KEY='chat.go_asr_profile';
    private const ASR_API_KEY='chat.go_asr_api_name';
    private const ASR_ENCODING_KEY='chat.go_asr_data_encoding';
    private const CALLS_ENDPOINT_KEY='chat.go_calls_php_endpoint';

    public function __construct(private Database $db,private PrivateValueCipher $cipher){}

    public function status(array $signing):array
    {
        $database=$this->database();
        $redis=$this->redisSettings();
        $environment=$this->environment();
        $chat=(array)\app_config('chat',[]);
        $schemaReady=$this->messageSchemaReady();
        $chatEnabled=$this->chatEnabled();
        $operationsConfigured=$this->operationsConfigured($chat);
        $appId=trim((string)\setting('wechat.miniapp_appid',''));
        $messageReady=$environment==='test'&&!empty($signing['configured'])&&$redis['enabled']&&$chatEnabled&&$schemaReady&&$operationsConfigured&&$this->validAppId($appId);
        $caps=$this->capabilities($environment,$messageReady,false);
        $messageMissing=[];
        foreach(['聊天总开关未开放'=>$chatEnabled,'PHP 签名未就绪，请生成签名或检查 sodium/加密配置'=>!empty($signing['configured']),'Redis 尚未启用'=>$redis['enabled'],'聊天数据表缺失，请完成后台升级'=>$schemaReady,'恢复门禁配置不完整'=>$operationsConfigured,'小程序 AppID 尚未配置'=>$this->validAppId($appId)] as $label=>$ready)if(!$ready)$messageMissing[]=$label;
        unset($caps['qiniu'],$caps['wechat_credential_file'],$caps['wechat_rejection_directory'],$caps['callback_token'],$caps['callback_aes_key'],$caps['internal_key']);
        return array_merge([
            'managed'=>true,
            'environment'=>$environment,
            'database_address'=>$this->databaseAddress($database),
            'database_name'=>(string)($database['database']??$database['name']??''),
            'database_user'=>(string)($database['username']??''),
            'database_source'=>'PHP 站点已安装配置',
            'redis_enabled'=>$redis['enabled'],
            'redis_address'=>$redis['address'],
            'redis_password_configured'=>$redis['password_ciphertext']!=='',
            'auth_managed'=>!empty($signing['configured']),
            'core_managed'=>!empty($signing['configured']),
            'manual_mode_env'=>'CHAT_CONFIG_MANUAL',
            'manual_file_ignored'=>true,
            'chat_enabled'=>$chatEnabled,
            'message_schema_ready'=>$schemaReady,
            'message_operations_configured'=>$operationsConfigured,
            'message_app_id_configured'=>$this->validAppId($appId),
            'messages_enabled'=>$messageReady,'message_missing'=>$messageMissing,'message_recovery_default'=>$environment==='test'&&empty($chat['recovery_gate_file'])&&empty($chat['recovery_epoch']),
            'safety_mode'=>$messageReady?'local':($environment==='production'?'release_required':'disabled'),
        ],$caps);
    }

    /** Values for the authenticated super-administrator settings form only; keep status() secret-free. */
    public function adminFormValues():array
    {
        $redis=$this->redisSettings();$password='';$errors=[];
        if($redis['password_ciphertext']!==''){
            try{$password=$this->cipher->decrypt($redis['password_ciphertext'],self::REDIS_PASSWORD_CONTEXT);}
            catch(\Throwable $e){$errors[]='Redis 密码读取失败，请重新填写后保存。';}
        }
        $callback=(new ChatMediaCallbackSettings($this->db,$this->cipher))->runtime(
            trim((string)\setting('wechat.miniapp_appid','')),$this->siteUrl());
        $asrDiagnostic=$this->recentAsrDiagnostic();$openApiProbe=$this->asrOpenApiProbe();
        if($openApiProbe!==[])$asrDiagnostic['openapi_probe']=$openApiProbe;
        return ['redis_password'=>$password,'wechat_callback_token'=>$callback['token'],
            'wechat_callback_aes_key'=>$callback['aes_key'],'callback_verified'=>$callback['verified'],
            'callback_configured'=>$callback['configured'],'callback_url'=>$callback['url'],
            'asr_diagnostic'=>$asrDiagnostic,'errors'=>$errors];
    }

    private function asrOpenApiProbe():array
    {
        if($this->environment()!=='test')return[];
        $path=dirname(\base_path()).'/chat/bin/state/asr-openapi-diagnostic.json';
        try{
            clearstatcache(true,$path);
            if(@is_link($path)||!@is_file($path)||($size=@filesize($path))===false||$size<2||$size>16384)return[];
            $raw=@file_get_contents($path);
            $data=is_string($raw)?json_decode($raw,true,32):null;
            if(!is_array($data)||($data['schema_version']??null)!==1)return[];
            $status=(string)($data['status']??'');
            if(!in_array($status,['completed','failed','skipped'],true))return[];
            $out=['status'=>$status,'revision'=>mb_substr((string)($data['revision']??''),0,64),'tested_at'=>mb_substr((string)($data['tested_at']??''),0,64)];
            if(preg_match('/^[1-9][0-9]{0,19}$/D',(string)($data['intent_id']??''))===1)$out['intent_id']=(string)$data['intent_id'];
            if(preg_match('/^[A-Za-z0-9.-]{1,253}$/D',(string)($data['source_host']??''))===1)$out['source_host']=(string)$data['source_host'];
            if(is_int($data['source_bytes']??null)&&$data['source_bytes']>=0&&$data['source_bytes']<=3*1024*1024)$out['source_bytes']=$data['source_bytes'];
            $recommended=(string)($data['recommended']??'none');
            if(in_array($recommended,['none','service_raw_url_object','tencent_current_url_object','tencent_current_data_object'],true))$out['recommended']=$recommended;
            if(isset($data['code'])&&preg_match('/^[A-Z0-9_]{1,64}$/D',(string)$data['code'])===1)$out['code']=(string)$data['code'];
            $out['variants']=[];
            foreach(array_slice(is_array($data['variants']??null)?$data['variants']:[],0,3) as $row){
                if(!is_array($row))continue;$name=(string)($row['name']??'');
                if(!in_array($name,['service_raw_url_object','tencent_current_url_object','tencent_current_data_object'],true))continue;
                $item=['name'=>$name,'success'=>($row['success']??false)===true,'request_id_present'=>($row['request_id_present']??false)===true];
                $http=(int)($row['http_status']??0);if($http>=0&&$http<=599)$item['http_status']=$http;
                $err=(string)($row['errcode']??'');if($err===''||preg_match('/^-?[0-9]{1,12}$/D',$err)===1)$item['errcode']=$err;
                $inner=(string)($row['inner_code']??'');if($inner===''||preg_match('/^[A-Za-z0-9_.:-]{1,96}$/D',$inner)===1)$item['inner_code']=$inner;
                $type=(string)($row['data_type']??'');if(in_array($type,['string','object','null','integer','double','boolean'],true))$item['data_type']=$type;
                if(isset($row['errmsg'])&&is_string($row['errmsg']))$item['errmsg']=mb_substr($row['errmsg'],0,240);
                $length=(int)($row['result_length']??0);if($length>=0&&$length<=4000)$item['result_length']=$length;
                $out['variants'][]=$item;
            }
            return$out;
        }catch(\Throwable $e){return[];}
    }

    private function recentAsrDiagnostic():array
    {
        try{
            if(!$this->tablesReady(['chat_send_intents','chat_av_jobs','chat_av_asr_requests']))return['status'=>'table_missing'];

            $av=$this->db->fetch(
                "SELECT CAST(i.id AS CHAR) intent_id,i.state intent_state,COALESCE(i.error_code,'') intent_error,"
                ."i.created_at intent_created_at,i.updated_at intent_updated_at,j.phase,COALESCE(j.error_code,'') job_error,"
                ."j.submitted_at,j.next_attempt_at "
                ."FROM chat_av_jobs j JOIN chat_send_intents i ON i.id=j.intent_id ORDER BY i.id DESC LIMIT 1"
            );
            $asr=$this->db->fetch(
                "SELECT CAST(intent_id AS CHAR) intent_id,ordinal,state,metadata_json,created_at "
                ."FROM chat_av_asr_requests ORDER BY created_at DESC LIMIT 1"
            );
            if(!$av&&!$asr)return['status'=>'empty'];

            $result=['status'=>'request_seen'];
            if($av){
                $result['latest_intent_id']=(string)$av['intent_id'];
                $result['intent_state']=(string)($av['intent_state']??'');
                $result['intent_error']=(string)($av['intent_error']??'');
                $result['intent_created_at']=(string)($av['intent_created_at']??'');
                $result['intent_updated_at']=(string)($av['intent_updated_at']??'');
                $result['job_phase']=(string)($av['phase']??'');
                $result['job_error']=(string)($av['job_error']??'');
                $result['job_submitted_at']=(string)($av['submitted_at']??'');
                $result['job_next_attempt_at']=(string)($av['next_attempt_at']??'');
            }
            if($asr){
                $result['asr_intent_id']=(string)$asr['intent_id'];
                $result['asr_ordinal']=(int)$asr['ordinal'];
                $result['request_state']=(string)($asr['state']??'');
                $result['asr_created_at']=(string)($asr['created_at']??'');
                $result['has_metadata']=false;
                $raw=(string)($asr['metadata_json']??'');
                if($raw!==''){
                    $meta=json_decode($raw,true);
                    if(!is_array($meta)){
                        $result['status']='metadata_invalid';
                    }else{
                        $result['has_metadata']=true;
                        $allowed=['service','api','engine','input_mode','provider_code','provider_message','provider_inner_code','url_host','url_length','url_encoded_length','request_key_length','data_encoding','source_preflight'];
                        foreach($allowed as $key){
                            $value=$meta[$key]??null;
                            if(is_string($value))$result[$key]=mb_substr($value,0,240);
                            elseif(is_int($value)||is_float($value))$result[$key]=(int)$value;
                        }
                    }
                }
            }

            if($av&&(!$asr||(string)$av['intent_id']!==(string)$asr['intent_id'])){
                $result['status']='asr_not_reached';
            }elseif($asr){
                $state=(string)($asr['state']??'');
                if(!empty($result['has_metadata']))$result['status']='diagnostic_ready';
                elseif($state==='unknown')$result['status']='result_unknown';
                elseif($state==='started')$result['status']='request_started';
                elseif($state==='completed')$result['status']='completed_without_metadata';
            }
            return$result;
        }catch(\Throwable $e){return['status'=>'read_failed'];}
    }



    /** Saved form values are independent of optional capability diagnostics. */
    public function configuration():array
    {
        $tools=$this->goSettings();$redis=$this->redisSettings();
        $api=trim((string)($tools[self::ASR_API_KEY]??''));
        $profile=$tools[self::ASR_PROFILE_KEY]??($api===''||$api==='SentenceASR'?'sentence_asr':'custom');
        if(!in_array($profile,['sentence_asr','custom','disabled'],true))$profile='custom';
        if($profile==='sentence_asr')$api='SentenceASR';
        if($profile==='disabled')$api='';
        $encoding=$tools[self::ASR_ENCODING_KEY]??'object';
        if(!in_array($encoding,['object','json_string'],true))$encoding='object';
        if($profile==='sentence_asr')$encoding='object';
        return ['redis_enabled'=>$redis['enabled'],'redis_address'=>$redis['address'],'redis_password_configured'=>$redis['password_ciphertext']!=='',
            'python_binary'=>$tools[self::PYTHON_KEY]??'/usr/bin/python3',
            'ffmpeg_binary'=>$tools[self::FFMPEG_KEY]??'/usr/bin/ffmpeg','ffprobe_binary'=>$tools[self::FFPROBE_KEY]??'/usr/bin/ffprobe',
            'asr_profile'=>$profile,'asr_api_name'=>$api,'asr_data_encoding'=>$encoding,
            'calls_php_endpoint'=>$tools[self::CALLS_ENDPOINT_KEY]??''];
    }

    public function prepareTesting(int $admin,string $role):void
    {
        if($this->environment()!=='test')throw new RuntimeException('仅测试站可初始化测试聊天。');
        $this->db->transaction(function()use($admin,$role):void{
            $actor=$this->db->fetch("SELECT role FROM admin_users WHERE id=:id AND status IN ('active','1') AND must_change_password=0 FOR UPDATE",['id'=>$admin]);
            if(!$actor||$actor['role']!==$role||$role!=='super_admin')throw new RuntimeException('仅超级管理员可初始化测试聊天。');
            $this->upsert('chat.enabled','1','聊天总开关',$admin);
            $this->upsert(self::REDIS_ENABLED_KEY,'1','Go 聊天 Redis 开关',$admin);
        });
    }

    public function save(array $input,int $admin,string $role):void
    {
        $saved=$this->configuration();$redis=$this->redisSettings();
        $enabled=$input['redis_enabled']??($redis['enabled']?'1':'0');
        $address=trim((string)($input['redis_address']??$redis['address']));
        $password=(string)($input['redis_password']??'');
        $clear=($input['clear_redis_password']??'0')==='1';
        $python=trim((string)($input['python_binary']??$saved['python_binary']));
        $ffmpeg=trim((string)($input['ffmpeg_binary']??$saved['ffmpeg_binary']));
        $ffprobe=trim((string)($input['ffprobe_binary']??$saved['ffprobe_binary']));
        $asrApi=trim((string)($input['asr_api_name']??$saved['asr_api_name']));
        $asrProfile=$input['asr_profile']??($asrApi!==''&&$asrApi!==$saved['asr_api_name']?($asrApi==='SentenceASR'?'sentence_asr':'custom'):$saved['asr_profile']);
        if(!is_string($asrProfile)||!in_array($asrProfile,['sentence_asr','custom','disabled'],true))throw new RuntimeException('请选择语音识别服务。');
        if($asrProfile==='sentence_asr')$asrApi='SentenceASR';
        elseif($asrProfile==='disabled')$asrApi='';
        elseif($asrApi==='')throw new RuntimeException('请填写自定义语音识别 API Name；如需关闭，请选择暂不使用。');
        $asrEncoding=$asrProfile==='sentence_asr'?'object':(string)($input['asr_data_encoding']??$saved['asr_data_encoding']);
        $callsEndpoint=trim((string)($input['calls_php_endpoint']??$saved['calls_php_endpoint']));

        if(!is_string($enabled)||!in_array($enabled,['0','1'],true))throw new RuntimeException('请选择 Redis 状态。');
        if($enabled==='1'&&!$this->validLoopbackAddress($address))throw new RuntimeException('Redis 仅允许本机回环地址，例如 127.0.0.1:6379。');
        if(strlen($password)>256||strpos($password,"\0")!==false)throw new RuntimeException('Redis 密码格式不正确。');
        foreach([$python,$ffmpeg,$ffprobe] as $path){
            if($path===''||$path[0]!=='/'||strlen($path)>512||strpos($path,"\0")!==false)throw new RuntimeException('媒体工具必须填写服务器绝对路径。');
        }
        if($asrApi!==''&&preg_match('/^[A-Za-z0-9_]{1,128}$/D',$asrApi)!==1)throw new RuntimeException('语音识别 API Name 格式不正确。');
        if(!in_array($asrEncoding,['object','json_string'],true))throw new RuntimeException('语音识别 data 编码不正确。');
        if($callsEndpoint!==''&&!$this->validCallsEndpoint($callsEndpoint))throw new RuntimeException('通话 PHP 私有端点必须是 HTTPS 且路径为 /chat-calls.php。');

        $ciphertext=null;
        if(!$clear&&$password!==''){
            $ciphertext=$this->cipher->encrypt($password,self::REDIS_PASSWORD_CONTEXT);
            if(!is_string($ciphertext)||$ciphertext==='')throw new RuntimeException('Redis 密码加密失败，未保存。');
        }
        $this->db->transaction(function()use($input,$enabled,$address,$password,$clear,$ciphertext,$python,$ffmpeg,$ffprobe,$asrApi,$asrProfile,$asrEncoding,$callsEndpoint,$admin,$role):void{
            $actor=$this->db->fetch("SELECT role FROM admin_users WHERE id=:id AND status IN ('active','1') AND must_change_password=0 FOR UPDATE",['id'=>$admin]);
            if(!$actor||(string)$actor['role']!==$role||$role!=='super_admin')throw new RuntimeException('仅超级管理员可修改 Go 服务配置。');
            $this->upsert(self::REDIS_ENABLED_KEY,$enabled,'Go 聊天 Redis 开关',$admin);
            if($address!=='')$this->upsert(self::REDIS_ADDRESS_KEY,$address,'Go 聊天 Redis 本机地址',$admin);
            if($clear)$this->upsert(self::REDIS_PASSWORD_KEY,'','Go 聊天 Redis 密码密文',$admin);
            elseif($password!=='')$this->upsert(self::REDIS_PASSWORD_KEY,(string)$ciphertext,'Go 聊天 Redis 密码密文',$admin);
            $this->upsert(self::PYTHON_KEY,$python,'聊天媒体 Python 路径',$admin);
            $this->upsert(self::FFMPEG_KEY,$ffmpeg,'聊天媒体 FFmpeg 路径',$admin);
            $this->upsert(self::FFPROBE_KEY,$ffprobe,'聊天媒体 FFprobe 路径',$admin);
            $this->upsert(self::ASR_PROFILE_KEY,$asrProfile,'微信语音识别服务预设',$admin);
            $this->upsert(self::ASR_API_KEY,$asrApi,'微信服务市场语音识别 API Name',$admin);
            $this->upsert(self::ASR_ENCODING_KEY,$asrEncoding,'微信服务市场语音识别 data 编码',$admin);
            $this->upsert(self::CALLS_ENDPOINT_KEY,$callsEndpoint,'Go 通话 PHP 私有端点',$admin);
            (new ChatMediaCallbackSettings($this->db,$this->cipher))->saveLocked($input,$admin,trim((string)\setting('wechat.miniapp_appid','')),$this->siteUrl());
        });
    }

    /**
     * @return array{config:array<string,mixed>,environment:array<string,string>,summary:array<string,mixed>}
     */
    public function render(array $signing):array
    {
        $root=dirname(\base_path());
        $template=$root.'/chat/config.example.json';
        $base=$this->readJson($template);
        if(!$base)throw new RuntimeException('Go 配置模板不可用。');

        $environment=$this->environment();
        $database=$this->database();
        $address=$this->databaseAddress($database);
        $dbName=(string)($database['database']??$database['name']??'');
        $dbUser=(string)($database['username']??'');
        $dbPassword=(string)($database['password']??'');
        if($dbName===''||$dbUser===''||$dbPassword==='')throw new RuntimeException('PHP 数据库配置不完整，无法生成 Go 配置。');

        $redis=$this->redisSettings();
        $auth=!empty($signing['configured'])&&!empty($signing['public_keys'])&&is_array($signing['public_keys']);
        $ports=$environment==='production'?['127.0.0.1:9501','127.0.0.1:9502']:['127.0.0.1:19501','127.0.0.1:19502'];

        $base['env']=$environment;
        $base['provider_mode']='disabled';
        $base['http']=['listen'=>$ports[0],'internal_listen'=>$ports[1]];
        $base['dependencies']['mysql']=array_replace((array)($base['dependencies']['mysql']??[]),[
            'enabled'=>true,'address'=>$address,'database'=>$dbName,'username'=>$dbUser,
            'password_env'=>'CHAT_MYSQL_PASSWORD','shared_app_credentials'=>true,
            'max_open_serve'=>4,'max_open_worker'=>2,'max_idle'=>1,
        ]);
        $base['dependencies']['redis']=array_replace((array)($base['dependencies']['redis']??[]),[
            'enabled'=>$redis['enabled'],'address'=>$redis['address'],'key_prefix'=>'bxchat:'.$environment.':',
            'password_env'=>$redis['password_ciphertext']!==''?'CHAT_REDIS_PASSWORD':'','pool_serve'=>2,'pool_worker'=>1,
        ]);
        $base['auth']=['enabled'=>$auth,'public_keys'=>$auth?$signing['public_keys']:[]];
        $base['conversations']=['enabled'=>$auth];
        $base['history']=['enabled'=>$auth,'cursor_key_env'=>$auth?'CHAT_HISTORY_CURSOR_KEY':''];
        $base['socket']=['enabled'=>$auth&&$redis['enabled']];

        $chat=(array)\app_config('chat',[]);
        $chatEnabled=$this->chatEnabled();
        $schemaReady=$this->messageSchemaReady();
        $operationsConfigured=$this->operationsConfigured($chat);
        $appId=trim((string)\setting('wechat.miniapp_appid',''));
        $messages=$environment==='test'&&$auth&&$redis['enabled']&&$chatEnabled&&$schemaReady&&$operationsConfigured&&$this->validAppId($appId);
        $caps=$this->capabilities($environment,$messages,true);

        $credentialFile=(string)($caps['wechat_credential_file']??'');
        $rejectionDirectory=(string)($caps['wechat_rejection_directory']??'');
        $base['safety']=[
            'enabled'=>$messages,
            'mode'=>$messages?'local':'',
            'app_id'=>$messages?$appId:'',
            'credential_file'=>$credentialFile,
            'rejection_directory'=>$credentialFile!==''?$rejectionDirectory:'',
        ];
        $base['messages']=['enabled'=>$messages];

        $q=$caps['qiniu']??[];
        $base['images']=[
            'enabled'=>!empty($caps['images_enabled']),'mode'=>'manual_precheck','private_bucket'=>!empty($q['private']),
            'namespace'=>(string)($q['namespace']??''),'proxy_origin'=>(string)($q['proxy_origin']??''),'proxy_key_env'=>'CHAT_MEDIA_PROXY_KEY',
            'bucket'=>(string)($q['bucket']??''),'access_key_env'=>'CHAT_QINIU_ACCESS_KEY','secret_key_env'=>'CHAT_QINIU_SECRET_KEY',
            'download_origin'=>(string)($q['download_origin']??''),'upload_origin'=>(string)($q['upload_origin']??''),
            'php_binary'=>(string)($caps['php_binary']??''),'python_binary'=>(string)($caps['python_binary']??''),
            'php_script'=>$root.'/server/bin/chat-images.php','python_script'=>$root.'/chat/helpers/images/inspect.py',
            'temp_root'=>(string)($caps['image_temp_root']??''),'max_file_bytes'=>1048576,'max_long_edge_px'=>1600,
        ];

        $base['retention']=array_replace((array)($base['retention']??[]),[
            'enabled'=>!empty($caps['av_enabled']),'body_days'=>365,'evidence_days'=>30,'change_days'=>90,'batch'=>20,
        ]);
        $base['av']=[
            'callback_verification'=>!empty($caps['callback_verification_enabled']),
            'enabled'=>!empty($caps['av_enabled']),'audio'=>!empty($caps['av_enabled']),'video'=>!empty($caps['av_enabled']),
            'python_binary'=>(string)($caps['python_binary']??''),'python_script'=>$root.'/chat/helpers/media/inspect.py',
            'ffmpeg_binary'=>(string)($caps['ffmpeg_binary']??''),'ffprobe_binary'=>(string)($caps['ffprobe_binary']??''),
            'temp_root'=>(string)($caps['av_temp_root']??''),'bucket'=>(string)($q['bucket']??''),'private_bucket'=>!empty($q['private']),
            'namespace'=>(string)($q['namespace']??''),'proxy_origin'=>(string)($q['proxy_origin']??''),'proxy_key_env'=>'CHAT_MEDIA_PROXY_KEY',
            'download_origin'=>(string)($q['download_origin']??''),'upload_origin'=>(string)($q['upload_origin']??''),
            'access_key_env'=>'CHAT_QINIU_ACCESS_KEY','secret_key_env'=>'CHAT_QINIU_SECRET_KEY',
            'asr'=>['api_name'=>(string)($caps['asr_api_name']??''),'data_encoding'=>(string)($caps['asr_data_encoding']??'object')],
            'daily_asr_budget'=>200,'callback_token_env'=>'CHAT_WECHAT_CALLBACK_TOKEN','callback_key_env'=>'CHAT_WECHAT_CALLBACK_AES_KEY',
        ];

        $siteUrl=$this->siteUrl();
        $callsEndpoint=(string)($caps['calls_php_endpoint']??'');
        $internalKeyId=(string)($chat['internal_key_id']??'chat-internal-v1');
        $base['reminders']=[
            'enabled'=>false,
            'php_endpoint'=>$siteUrl!==''?$siteUrl.'/chat-internal.php':'',
            'key_id'=>$internalKeyId,'key_env'=>'CHAT_INTERNAL_KEY',
            'paid_sms_enabled'=>!empty($caps['sms_bridge_enabled']),
        ];
        $base['calls']=[
            'enabled'=>!empty($caps['calls_bridge_enabled']),
            'php_endpoint'=>$callsEndpoint,
            'key_id'=>$internalKeyId,'key_env'=>'CHAT_INTERNAL_KEY',
            'shengwang_app_id'=>'','certificate_env'=>'CHAT_SHENGWANG_CERTIFICATE',
            'customer_id_env'=>'CHAT_SHENGWANG_CUSTOMER_ID','customer_secret_env'=>'CHAT_SHENGWANG_CUSTOMER_SECRET',
            'publish_privileges_verified'=>false,
        ];

        if($environment==='test'){
            $base['release']=array_replace((array)($base['release']??[]),['staging'=>$messages,'file'=>'','key_env'=>'CHAT_RELEASE_KEY']);
        }
        $base['operations']=['gate_file'=>(string)($chat['recovery_gate_file']??''),'epoch'=>(string)($chat['recovery_epoch']??'')];

        $appKey=(string)\app_config('app.key','');
        if($auth&&$appKey==='')throw new RuntimeException('PHP app.key 缺失，无法派生聊天运行密钥。');
        $env=['CHAT_MYSQL_PASSWORD'=>$dbPassword];
        if($auth)$env['CHAT_HISTORY_CURSOR_KEY']=hash_hmac('sha256','bixuan-chat-history-cursor-v1:'.$environment,$appKey);
        if($messages&&!empty($caps['internal_key']))$env['CHAT_INTERNAL_KEY']=$caps['internal_key'];
        if(!empty($caps['images_enabled'])||!empty($caps['av_enabled'])){
            $env['CHAT_QINIU_ACCESS_KEY']=(string)($q['access_key']??'');
            $env['CHAT_QINIU_SECRET_KEY']=(string)($q['secret_key']??'');
            if(($q['proxy_origin']??'')!=='')$env['CHAT_MEDIA_PROXY_KEY']=base64_encode(hash_hmac('sha256','bixuan-chat-media-proxy-v1:'.$environment,$appKey,true));
        }
        if(!empty($caps['av_enabled'])||!empty($caps['callback_verification_enabled'])){
            $env['CHAT_WECHAT_CALLBACK_TOKEN']=$caps['callback_token'];
            $env['CHAT_WECHAT_CALLBACK_AES_KEY']=$caps['callback_aes_key'];
        }
        if($redis['password_ciphertext']!==''){
            try{$redisPassword=$this->cipher->decrypt($redis['password_ciphertext'],self::REDIS_PASSWORD_CONTEXT);}
            catch(\Throwable $e){throw new RuntimeException('Redis 私密配置不可用。');}
            if($redisPassword==='')throw new RuntimeException('Redis 私密配置不可用。');
            $env['CHAT_REDIS_PASSWORD']=$redisPassword;
        }

        return ['config'=>$base,'environment'=>$env,'summary'=>[
            'environment'=>$environment,'auth'=>$auth,'mysql'=>'php_shared','redis'=>$redis['enabled'],
            'conversations'=>$auth,'history'=>$auth,'socket'=>$auth&&$redis['enabled'],'messages'=>$messages,
            'images'=>!empty($caps['images_enabled']),'audio'=>!empty($caps['av_enabled']),'video'=>!empty($caps['av_enabled']),
            'paid_sms'=>!empty($caps['sms_bridge_enabled']),'calls'=>!empty($caps['calls_enabled']),'calls_recovery_only'=>!empty($caps['calls_recovery_only']),
            'safety_mode'=>$messages?'local':'disabled','chat_enabled'=>$chatEnabled,
            'message_schema_ready'=>$schemaReady,'operations_configured'=>$operationsConfigured,
        ]];
    }

    /** @param array<string,string> $values */
    public static function environmentFile(array $values):string
    {
        $lines=["# Generated by server/bin/chat-runtime-config.php; do not edit."];
        foreach($values as $name=>$value){
            if(preg_match('/^CHAT_[A-Z0-9_]{1,60}$/D',$name)!==1||strpos($value,"\0")!==false)throw new RuntimeException('运行环境变量格式无效。');
            $lines[]='export '.$name."='".str_replace("'","'\"'\"'",$value)."'";
        }
        return implode("\n",$lines)."\n";
    }

    private function capabilities(string $environment,bool $messages,bool $prepare):array
    {
        $root=dirname(\base_path());$tools=$this->goSettings();
        $requested=$environment==='test';
        $python=$tools[self::PYTHON_KEY]??'/usr/bin/python3';
        $ffmpeg=$tools[self::FFMPEG_KEY]??'/usr/bin/ffmpeg';
        $ffprobe=$tools[self::FFPROBE_KEY]??'/usr/bin/ffprobe';
        $phpBinary=ChatMediaPreflight::phpBinary();
        $asr=$this->configuration();$asrApi=$asr['asr_api_name'];
        $asrEncoding=$asr['asr_data_encoding'];
        if(!in_array($asrEncoding,['object','json_string'],true))$asrEncoding=$asr['asr_profile']==='sentence_asr'?'json_string':'object';
        $checks=(new ChatMediaPreflight())->inspect($root,$phpBinary,$python,$ffmpeg,$ffprobe,$prepare&&$requested);
        $phpBinary=(string)($checks['php_binary']??$phpBinary);
        $shared=[];
        if(!$messages)$shared[]='文字消息链路未就绪，请先检查签名、Redis、聊天总开关、数据表和恢复门禁';
        $imageMissing=$shared;$avMissing=$shared;
        $qiniu=$this->qiniu();
        $imageMissing=array_merge($imageMissing,$qiniu['missing']);$avMissing=array_merge($avMissing,$qiniu['missing']);
        if(!empty($checks['checked_at'])){
            foreach(['pillow'=>'Python Pillow 图片编解码依赖','zxing'=>'Python zxing-cpp 二维码依赖'] as $key=>$label){
                if(empty($checks[$key])){$imageMissing[]=$label.'未通过启动检查';$avMissing[]=$label.'未通过启动检查';}
            }
            if(empty($checks['php_cli']))$imageMissing[]='PHP CLI 未通过启动检查（不能使用 php-fpm）';
            if(empty($checks['php_gd']))$imageMissing[]='PHP CLI GD 扩展或 JPEG/PNG/WebP 支持未通过启动检查';
            foreach(['ffmpeg'=>'FFmpeg','ffprobe'=>'FFprobe'] as $key=>$label)if(empty($checks[$key]))$avMissing[]=$label.' 未通过启动检查';
            if(empty($checks['voice_pipeline'])){
                $voiceCodes=[
                    'CHAT_AV_TRANSCODE_FAILED'=>'FFmpeg 无法完成微信录音的 MP3 处理，请检查编码器/解码器',
                    'CHAT_AV_RESOURCE_LIMIT_FAILED'=>'媒体进程系统资源限制不兼容，请重新应用新版运行配置',
                    'CHAT_AV_TOOL_PERMISSION_DENIED'=>'媒体工具或临时目录权限不足',
                    'CHAT_AV_TOOL_NOT_FOUND'=>'媒体处理工具或脚本路径不存在',
                    'CHAT_AV_PROCESS_TIMEOUT'=>'本机语音预处理自检超时',
                    'CHAT_AV_AUDIO_FORMAT_UNSUPPORTED'=>'FFmpeg/FFprobe 无法处理标准 MP3 录音',
                    'CHAT_AV_AUDIO_PROFILE_UNSUPPORTED'=>'标准 16k 单声道录音参数检查失败',
                    'CHAT_AV_OUTPUT_INVALID'=>'媒体辅助程序输出校验失败',
                    'CHAT_AV_HELPER_RUNTIME_FAILED'=>'媒体辅助程序启动失败',
                ];
                $voiceCode=(string)($checks['voice_error']??'');
                $avMissing[]='真实语音预处理自检未通过：'.($voiceCodes[$voiceCode]??($voiceCode!==''?$voiceCode:'未返回具体原因'));
            }
        }else{
            $pending=($checks['probe_status']??'')==='stale'?'工具路径或脚本已更新，等待启动器重新检查':'等待启动器执行媒体依赖检查；网页不检查系统二进制，不代表工具未安装';
            $imageMissing[]=$pending;$avMissing[]=$pending;
        }
        foreach(['server/bin/chat-images.php','chat/helpers/images/inspect.py'] as $path)if(!is_file($root.'/'.$path)||!is_readable($root.'/'.$path))$imageMissing[]='图片辅助脚本缺失或不可读：'.$path;
        if(!is_file($root.'/chat/helpers/media/inspect.py')||!is_readable($root.'/chat/helpers/media/inspect.py'))$avMissing[]='音视频辅助脚本缺失或不可读';
        $imageTools=!empty($checks['php_cli'])&&!empty($checks['php_gd'])&&!empty($checks['pillow'])&&!empty($checks['zxing']);
        $avTools=!empty($checks['pillow'])&&!empty($checks['zxing'])&&!empty($checks['ffmpeg'])&&!empty($checks['ffprobe'])&&!empty($checks['voice_pipeline']);
        $imageSchema=$this->tablesReady(['chat_image_uploads','chat_image_bundles','chat_image_items','chat_image_audit','chat_image_objects','chat_image_reports']);
        $avSchema=$this->tablesReady(['chat_av_uploads','chat_av_parts','chat_av_jobs','chat_av_objects','chat_av_items','chat_av_listens','chat_av_raw_receipts','chat_av_asr_requests','chat_av_budgets']);
        $smsSchema=$this->tablesReady(['chat_sms_guards','chat_sms_nudges','chat_internal_nonces']);
        $callSchema=$this->tablesReady(['chat_call_settings','chat_call_resource_pools','chat_call_resource_calls','chat_call_resource_operations','chat_call_resource_ledger','chat_call_accounts','chat_call_sessions','chat_call_legs','chat_call_operations','chat_call_events','chat_internal_nonces']);
        if(!$imageSchema)$imageMissing[]='图片数据表缺失，请先完成后台版本升级';
        if(!$avSchema)$avMissing[]='音视频数据表缺失，请先完成后台版本升级';
        $imageTemp=$root.'/chat/bin/state/tmp/images';$avTemp=$root.'/chat/bin/state/tmp/media';
        if($requested&&$prepare){$imageTempReady=$this->ensurePrivateDirectory($imageTemp);$avTempReady=$this->ensurePrivateDirectory($avTemp);}
        else{$imageTempReady=$this->privateDirectory($imageTemp);$avTempReady=$this->privateDirectory($avTemp);}
        if(!$imageTempReady)$imageMissing[]='图片临时目录不可写或权限不是0700';
        if(!$avTempReady)$avMissing[]='音视频临时目录不可写或权限不是0700';

        $appId=trim((string)\setting('wechat.miniapp_appid',''));$secret=trim((string)\setting('wechat.miniapp_secret',''));
        $asrReady=preg_match('/^[A-Za-z0-9_]{1,128}$/D',$asrApi)===1;
        if(!$asrReady)$avMissing[]=$asr['asr_profile']==='disabled'?'语音识别服务已停用，请选择已授权的服务':'自定义语音识别 API Name 未填写，请选择一句话识别预设或补充接口名称';
        if($secret==='')$avMissing[]='后台小程序 AppSecret 未配置';
        $credentialFile='';$rejectionDirectory='';
        if($requested&&$this->validAppId($appId)&&$secret!=='')[$credentialFile,$rejectionDirectory]=$this->prepareWechatCredential($environment,$appId,$secret);
        if($credentialFile==='')$avMissing[]='微信统一 access token 导出未就绪、已过期或权限不正确；请检查启动器凭证维护日志';
        $callback=(new ChatMediaCallbackSettings($this->db,$this->cipher))->runtime($appId,$this->siteUrl());
        $avMissing=array_merge($avMissing,$callback['missing']);

        $internal=$this->internalKey($environment);
        $smsProvider=ChatSmsProviderReadiness::inspect(static fn(string $key,$default)=>\setting($key,$default),$requested);
        $smsMissing=array_merge($shared,$smsProvider['missing']);
        if(!$smsSchema)$smsMissing[]='短信桥接数据表缺失，请先完成后台版本升级';
        if($internal==='')$smsMissing[]='聊天内部签名密钥或密钥编号未配置、格式不正确';

        $callsEndpoint=trim((string)($tools[self::CALLS_ENDPOINT_KEY]??''));
        if($callsEndpoint===''&&$requested&&$this->siteUrl()!=='')$callsEndpoint=$this->siteUrl().'/chat-call-bridge.php';
        if(!$this->validCallsEndpoint($callsEndpoint))$callsEndpoint='';
        $callsMissing=$shared;$callAdmission=['ready'=>false,'missing'=>['通话数据表缺失'],'providers'=>[]];
        if($callSchema)$callAdmission=$this->callAdmission();
        $callsMissing=array_merge($callsMissing,$callAdmission['missing']);
        if(!$callSchema)$callsMissing[]='通话数据表缺失，请先完成后台版本升级';
        if($callsEndpoint==='')$callsMissing[]='通话 PHP 私有 HTTPS 端点未配置；普通站点入口被 Nginx 拦截，不能替代私有端点';
        if($internal==='')$callsMissing[]='聊天内部签名密钥或密钥编号未配置、格式不正确';
        // Keep the authenticated bridge alive for stop/query of existing supplier exposure.
        $activeCalls=false;
        if($callSchema){try{$activeCalls=(bool)$this->db->fetchValue("SELECT id FROM chat_call_sessions WHERE state IN ('ringing','preparing','connecting','active','stopping') LIMIT 1");}catch(\Throwable $e){$callsMissing[]='活动通话状态不可读取';}}
        $states=[
            'images'=>$this->feature($requested,$imageMissing),
            'audio'=>$this->feature($requested,$avMissing),
            'video'=>$this->feature($requested,$avMissing),
            'paid_sms'=>$this->feature($requested,$smsMissing),
            'calls'=>$this->feature($requested,$callsMissing),
        ];
        $callsBridge=$requested&&$messages&&$callSchema&&$callsEndpoint!==''&&$internal!==''&&($states['calls']['enabled']||$activeCalls);
        return [
            'capabilities'=>$states,'full_capabilities_requested'=>$requested,
            'qiniu_ready'=>$qiniu['ready'],'qiniu_bucket'=>$qiniu['bucket'],'qiniu'=>$qiniu,'qiniu_private'=>!empty($qiniu['private']),'qiniu_namespace'=>(string)($qiniu['namespace']??''),
            'python_binary'=>$python,'ffmpeg_binary'=>$ffmpeg,'ffprobe_binary'=>$ffprobe,'php_binary'=>$phpBinary,'media_checks'=>$checks,
            'image_tools_ready'=>$imageTools,'image_schema_ready'=>$imageSchema,'images_enabled'=>$states['images']['enabled'],'image_temp_root'=>$states['images']['enabled']?$imageTemp:'',
            'av_tools_ready'=>$avTools,'av_schema_ready'=>$avSchema,'asr_api_name'=>$asrApi,'asr_data_encoding'=>$asrEncoding,
            'asr_profile'=>$asr['asr_profile'],'asr_configured'=>$asrReady,'wechat_secret_configured'=>$secret!=='','av_prerequisites_ready'=>!$avMissing,
            'wechat_credential_ready'=>$credentialFile!=='','av_enabled'=>$states['audio']['enabled'],'av_temp_root'=>$states['audio']['enabled']?$avTemp:'',
            'wechat_credential_file'=>$states['audio']['enabled']?$credentialFile:'','wechat_rejection_directory'=>$states['audio']['enabled']?$rejectionDirectory:'',
            'callback_verification_enabled'=>$requested&&$messages&&$callback['configured'],
            'callback_configured'=>$callback['configured'],'callback_verified'=>$callback['verified'],'callback_url'=>$callback['url'],
            'callback_token'=>$requested&&$messages?$callback['token']:'','callback_aes_key'=>$requested&&$messages?$callback['aes_key']:'',
            'internal_key'=>$internal,
            'sms_bridge_enabled'=>$states['paid_sms']['enabled'],'sms_provider_ready'=>$smsProvider['ready'],'sms_schema_ready'=>$smsSchema,
            'calls_enabled'=>$states['calls']['enabled'],'calls_bridge_enabled'=>$callsBridge,'calls_recovery_only'=>$callsBridge&&!$states['calls']['enabled'],
            'calls_schema_ready'=>$callSchema,'calls_endpoint_ready'=>$callsEndpoint!=='','calls_php_endpoint'=>$callsEndpoint,
            'calls_providers'=>$callAdmission['providers'],
        ];
    }

    private function feature(bool $requested,array $missing):array
    {
        if(!$requested)return ['requested'=>false,'enabled'=>false,'missing'=>['正式站需独立发布验收，不随测试站自动开放']];
        return ['requested'=>true,'enabled'=>!$missing,'missing'=>array_values(array_unique($missing))];
    }

    private function privateDirectory(string $path):bool
    {
        clearstatcache(true,$path);$mode=@fileperms($path);
        return !is_link($path)&&is_dir($path)&&is_writable($path)&&is_int($mode)&&($mode&0077)===0;
    }

    private function internalKey(string $environment):string
    {
        $chat=(array)\app_config('chat',[]);$name=(string)($chat['internal_key_env']??'');
        if(preg_match('/^CHAT_[A-Z0-9_]{1,60}$/D',$name)!==1||preg_match('/^[a-z0-9_-]{1,32}$/D',(string)($chat['internal_key_id']??''))!==1)return '';
        $encoded=getenv($name);$raw=is_string($encoded)?base64_decode($encoded,true):false;
        if(is_string($raw)&&strlen($raw)===32&&base64_encode($raw)===$encoded)return $encoded;
        $appKey=(string)\app_config('app.key','');
        return $environment!=='production'&&$appKey!==''?base64_encode(hash_hmac('sha256','bixuan-chat-internal-v1:'.$environment,$appKey,true)):'';
    }

    /** Pure reads: do not use availability(), which writes resource alerts. */
    private function callAdmission():array
    {
        $providers=[];$missing=[];$valid=false;$poolMissing=[];
        try{
            $credentials=(new ChatCallCredentials($this->db,$this->cipher))->runtime();
            $shengwang=($credentials['managed']??false)===true&&preg_match('/^[a-f0-9]{32}$/D',(string)($credentials['app_id']??''))===1
                &&preg_match('/^[a-f0-9]{32}$/D',(string)($credentials['certificate']??''))===1
                &&trim((string)($credentials['customer_id']??''))!==''&&trim((string)($credentials['customer_secret']??''))!==''
                &&($credentials['publish_privileges_verified']??false)===true;
            $providers['shengwang']=['configured'=>$shengwang,'pool_ready'=>false];
            if(!$shengwang)$missing[]='声网 AppID、App Certificate、REST Customer ID/Secret 或发布权限确认未就绪';
            $resources=new ChatCallResources($this->db,null,$this->environment()==='test');$settings=$resources->settings()['config'];
            if(empty($settings['enabled']))$missing[]='通话总开关尚未开放，请在音视频通话的功能与密钥中保存设置';
            if(empty($settings['audio_enabled'])&&empty($settings['video_enabled']))$missing[]='语音和视频通话均已关闭';
            foreach($this->db->fetchAll('SELECT * FROM chat_call_resource_pools ORDER BY id DESC') as $row){
                $provider=(string)$row['provider'];if(!in_array($provider,['wechat','shengwang'],true))continue;
                $profile=json_decode((string)$row['meter_json'],true,32,JSON_THROW_ON_ERROR);
                $configured=$provider==='wechat'?($profile['app_id']??'')===trim((string)\setting('wechat.miniapp_appid','')):$shengwang&&($profile['app_id']??'')===($credentials['app_id']??'');
                $poolLabel=($provider==='shengwang'?'声网':'微信').'资源 #'.(string)$row['id'].'：';
                if(!$configured){$poolMissing[]=$poolLabel.'应用 AppID 与当前通道配置不匹配，或通道密钥尚未就绪';continue;}
                $decision=$resources->decision($row,$settings);
                if($decision['status']!=='available'){
                    foreach(\WorkerAdmin\Domain\Chat\CallQuotaDisplay::blockers($row,$decision) as $blocker)$poolMissing[]=$poolLabel.$blocker;
                    continue;
                }
                try{
                    $seconds=(int)($settings['window_seconds']??60)+(int)($settings['stop_margin_seconds']??30);
                    if($seconds<1||$seconds>86400)continue;
                    $legs=array_fill(0,2,['previous_seconds'=>0,'current_seconds'=>$seconds]);
                    $policy=new \WorkerAdmin\Domain\Chat\CallResourcePolicy();
                    $costs=['audio'=>$policy->incrementalCost($profile['audio'],$legs),'video'=>$policy->incrementalCost($profile['video'],$legs)];
                    if($provider==='wechat')$costs=['audio'=>max($costs),'video'=>max($costs)];
                    $mediaReady=[];
                    foreach(['audio','video'] as $media){
                        $priority=(array)($settings[$media.'_priority']??[]);
                        if(empty($settings['allow_fallback']))$priority=array_slice($priority,0,1);
                        $mediaReady[$media]=!empty($settings[$media.'_enabled'])&&in_array($provider,$priority,true)
                            &&$costs[$media]>0&&(int)$decision['available_units']>=$costs[$media];
                    }
                    if(!$mediaReady['audio']&&!$mediaReady['video'])$poolMissing[]=$poolLabel.'当前路由未选择此通道，或额度不足以覆盖双端安全通话窗口';
                    if(!empty($settings['enabled'])&&($mediaReady['audio']||$mediaReady['video'])){
                        $providers[$provider]=['configured'=>true,'pool_ready'=>true]+$mediaReady;$valid=true;
                    }
                }catch(\Throwable $e){$missing[]='通话资源池的音视频计量参数不完整或无效';}
            }
            if($valid)$missing=[];
            else $missing=array_merge($missing,array_slice(array_values(array_unique($poolMissing)),0,12),$poolMissing?[]:['尚未登记当前应用可用的资源池，请到音视频通话的资源与用量中登记或复核']);
        }catch(\Throwable $e){$missing[]='通话凭据或资源池配置不可读取、解密或校验失败';}
        return ['ready'=>$valid,'missing'=>$missing,'providers'=>$providers];
    }

    /** @return array{0:string,1:string} */
    private function prepareWechatCredential(string $environment,string $appId,string $secret):array
    {
        $export=\base_path('storage/private/chat-credentials');
        $reject=(string)\app_config('chat.safety_rejection_directory','');
        if($reject==='')$reject=\base_path('storage/private/chat-credential-rejections');
        clearstatcache(true,$reject);$permissions=@fileperms($reject);
        if(!is_dir($reject)||!is_int($permissions)||($permissions&0007)!==0)return ['',''];
        try{
            $provider=new WeChatAccessTokenProvider($environment,$appId,$secret,\base_path('storage/private/wechat-tokens'),$export,null,$reject);
            $meta=$provider->metadata();
            $file=(string)($meta['credential_file']??'');
            return $file!==''&&is_file($file)?[$file,$reject]:['',''];
        }catch(\Throwable $e){
            return ['',''];
        }
    }

    private function qiniu():array
    {
        $missing=[];$testing=$this->environment()==='test';
        if((string)\setting('media.storage_disk','private_local')!=='qiniu'||!filter_var(\setting('qiniu.enabled',false),FILTER_VALIDATE_BOOLEAN))$missing[]='后台七牛存储未开启或未选为媒体存储';
        $private=filter_var(\setting('qiniu.private_bucket',false),FILTER_VALIDATE_BOOLEAN);
        $ak=trim((string)\setting('qiniu.access_key',''));$sk=trim((string)\setting('qiniu.secret_key',''));
        if($ak==='')$missing[]='七牛 AccessKey 未配置';if($sk==='')$missing[]='七牛 SecretKey 未配置';
        $bucket=trim((string)\setting('qiniu.bucket',''));
        if(preg_match('/^[A-Za-z0-9_-]{1,63}$/D',$bucket)!==1)$missing[]='七牛 Bucket 未配置或格式不正确';
        $raw=trim((string)\setting('qiniu.domain',''));
        if($raw!==''&&!preg_match('#^https?://#i',$raw))$raw=($private?'https':'http').'://'.$raw;
        $download=$private?$this->httpsOrigin($raw):rtrim($raw,'/');
        $du=parse_url($download);$host=(string)($du['host']??'');$scheme=(string)($du['scheme']??'');
        if(!$du||!in_array($scheme,$private?['https']:['http','https'],true)||!str_contains($host,'.')
            ||filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)===false||filter_var($host,FILTER_VALIDATE_IP)!==false
            ||isset($du['user'])||isset($du['pass'])||isset($du['query'])||isset($du['fragment'])||!empty($du['path'])
            ||isset($du['port'])&&(int)$du['port']!==($scheme==='http'?80:443))$missing[]='七牛回源域名需为正确的公网 HTTP/HTTPS 域名，不含路径';
        $upload=trim((string)\setting('qiniu.upload_host',''));
        if($upload===''){
            $region=(string)\setting('qiniu.region','z2');
            $hosts=['z0'=>'up-z0.qiniup.com','cn-east-2'=>'up-cn-east-2.qiniup.com','z1'=>'up-z1.qiniup.com','z2'=>'up-z2.qiniup.com','na0'=>'up-na0.qiniup.com','as0'=>'up-as0.qiniup.com','ap-northeast-1'=>'up-ap-northeast-1.qiniup.com'];
            $upload=isset($hosts[$region])?'https://'.$hosts[$region]:'';
        }
        $upload=$this->httpsOrigin($upload);$uu=parse_url($upload);$uh=(string)($uu['host']??'');
        if($upload===''||isset($uu['port'])&&(int)$uu['port']!==443||(!str_ends_with($uh,'.qiniup.com')&&!str_ends_with($uh,'.qiniu.com')))$missing[]='七牛上传需使用当前区域官方 HTTPS qiniup.com/qiniu.com 端点';
        $root=trim((string)\setting($testing?'testing.qiniu_path_prefix':'qiniu.path_prefix',$testing?'test':'worker'),'/');
        $folder=trim((string)\setting('qiniu.prefix.chat','chat'),'/');$namespace=$root.'/'.$folder;
        if(strlen($namespace)>60||preg_match('#^[A-Za-z0-9_-]{1,40}(/[A-Za-z0-9_-]{1,40}){0,3}$#D',$namespace)!==1)$missing[]='聊天分类目录需为字母数字、短横线或下划线组成的相对目录，总长度不超过60位';
        $proxy=!$private?$this->siteUrl():'';
        if(!$private&&$testing&&$proxy==='')$missing[]='公开七牛回源需配置本站 HTTPS 地址，供小程序安全访问媒体';
        $ready=!$missing;
        return ['ready'=>$ready,'private'=>$private,'namespace'=>$namespace,'proxy_origin'=>$proxy,'missing'=>$missing,
            'access_key'=>$ready?$ak:'','secret_key'=>$ready?$sk:'','bucket'=>$bucket,'download_origin'=>$ready?$download:'','upload_origin'=>$ready?$upload:''];
    }

    private function httpsOrigin(string $raw):string
    {
        $raw=trim($raw);if($raw==='')return '';
        if(!preg_match('#^https?://#i',$raw))$raw='https://'.$raw;
        $url=parse_url($raw);if(!$url||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment']))return '';
        $path=(string)($url['path']??'');if($path!==''&&$path!=='/')return '';
        $port=isset($url['port'])?':'.(int)$url['port']:'';
        return 'https://'.$url['host'].$port;
    }

    private function siteUrl():string
    {
        $raw=rtrim(trim((string)\app_config('app.url','')),'/');$url=parse_url($raw);
        if(!$url||($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment']))return '';
        $path=(string)($url['path']??'');if($path!==''&&$path!=='/')return '';
        return $raw;
    }

    private function goSettings():array
    {
        $keys=[self::PYTHON_KEY,self::FFMPEG_KEY,self::FFPROBE_KEY,self::ASR_API_KEY,self::ASR_PROFILE_KEY,self::ASR_ENCODING_KEY,self::CALLS_ENDPOINT_KEY];
        $quoted="'".implode("','",$keys)."'";$out=[];
        try{foreach($this->db->fetchAll("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN (".$quoted.")") as $row)$out[(string)$row['setting_key']]=(string)$row['setting_value'];}
        catch(\Throwable $e){}
        return $out;
    }

    private function ensurePrivateDirectory(string $path):bool
    {
        if(is_link($path))return false;
        if(!is_dir($path)&&!@mkdir($path,0700,true)&&!is_dir($path))return false;
        @chmod($path,0700);clearstatcache(true,$path);$perm=@fileperms($path);
        return is_writable($path)&&is_int($perm)&&($perm&0077)===0;
    }

    private function chatEnabled():bool
    {
        $value=$this->db->fetchValue("SELECT setting_value FROM system_settings WHERE setting_key='chat.enabled' LIMIT 1");
        return $value===null||$value===false||in_array(strtolower((string)$value),['1','true','yes','on'],true);
    }
    private function messageSchemaReady():bool{return $this->tablesReady(['chat_conversations','chat_members','chat_member_intervals','chat_member_reads','chat_user_preferences','chat_preference_accounts','chat_send_intents','chat_messages','chat_message_changes','chat_event_receipts','chat_text_checks','chat_risk_events','chat_restrictions','chat_image_items','chat_governance_audit','chat_cases','chat_audit_accounts','chat_audit_snapshots','chat_audit_snapshot_items']);}
    private function tablesReady(array $required):bool
    {
        if(!$required)return true;$quoted=array_map(static fn(string $name):string=>"'".$name."'",$required);
        try{$count=(int)$this->db->fetchValue("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (".implode(',',$quoted).")");return $count===count($required);}
        catch(\Throwable $e){return false;}
    }
    private function operationsConfigured(array $chat):bool
    {
        $gate=(string)($chat['recovery_gate_file']??'');$epoch=(string)($chat['recovery_epoch']??'');
        if($gate===''&&$epoch==='')return $this->environment()==='test';
        return $gate!==''&&$gate[0]==='/'&&preg_match('/^[0-9a-f]{64}$/D',$epoch)===1;
    }
    private function validAppId(string $value):bool{return preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$value)===1;}

    private function validCallsEndpoint(string $endpoint):bool
    {
        if($endpoint==='')return false;$url=parse_url($endpoint);
        return is_array($url)&&($url['scheme']??'')==='https'&&!empty($url['host'])&&!isset($url['user'])&&!isset($url['pass'])&&!isset($url['query'])&&!isset($url['fragment'])&&in_array(($url['path']??''),$this->environment()==='test'?['/chat-calls.php','/chat-call-bridge.php']:['/chat-calls.php'],true);
    }

    private function database():array
    {
        $database=(array)\app_config('database',[]);if(empty($database['database'])&&!empty($database['name']))$database['database']=$database['name'];return $database;
    }
    private function environment():string
    {
        $value=(string)\app_config('app.env','production');$mapped=['testing'=>'test','development'=>'development','production'=>'production'][$value]??'';
        if($mapped==='')throw new RuntimeException('PHP 环境无法映射到 Go。');return $mapped;
    }
    private function databaseAddress(array $database):string
    {
        $host=strtolower(trim((string)($database['host']??'127.0.0.1')));if($host==='localhost')$host='127.0.0.1';
        if(!in_array($host,['127.0.0.1','::1'],true))throw new RuntimeException('自动模式仅复用本机 PHP 数据库。');
        $port=(int)($database['port']??3306);if($port<1||$port>65535)throw new RuntimeException('PHP 数据库端口无效。');
        return $host==='::1'?'[::1]:'.$port:$host.':'.$port;
    }
    private function redisSettings():array
    {
        $rows=$this->db->fetchAll("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('".self::REDIS_ENABLED_KEY."','".self::REDIS_ADDRESS_KEY."','".self::REDIS_PASSWORD_KEY."')");
        $values=[];foreach($rows as $row)$values[(string)$row['setting_key']]=(string)$row['setting_value'];
        $enabled=($values[self::REDIS_ENABLED_KEY]??'1')!=='0';$address=(string)($values[self::REDIS_ADDRESS_KEY]??'127.0.0.1:6379');
        if(!$this->validLoopbackAddress($address))$address='127.0.0.1:6379';
        return ['enabled'=>$enabled,'address'=>$address,'password_ciphertext'=>(string)($values[self::REDIS_PASSWORD_KEY]??'')];
    }
    private function validLoopbackAddress(string $address):bool
    {
        if(preg_match('/^127\.0\.0\.1:([0-9]{1,5})$/D',$address,$m)===1)return (int)$m[1]>=1&&(int)$m[1]<=65535;
        if(preg_match('/^\[::1\]:([0-9]{1,5})$/D',$address,$m)===1)return (int)$m[1]>=1&&(int)$m[1]<=65535;return false;
    }
    private function readJson(string $path):array
    {
        $raw=@file_get_contents($path);if(!is_string($raw)||strlen($raw)>65536)return [];$decoded=json_decode($raw,true);return is_array($decoded)?$decoded:[];
    }
    private function upsert(string $key,string $value,string $description,int $admin):void
    {
        $this->db->execute("INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,updated_by,created_at,updated_at)
             VALUES(:key,:value,'string','chat',0,:description,:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type='string',group_name='chat',is_public=0,description=VALUES(description),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()",
            ['key'=>$key,'value'=>$value,'description'=>$description,'admin'=>$admin]);
    }
}
