from pathlib import Path
root=Path(__file__).parent/'candidate'
config=(root/'chat/internal/avpipeline/config.go').read_text()
mirror=(root/'chat/internal/imagepipeline/local_asr_mirror.go').read_text()
cleanup=(root/'chat/internal/message/av_cleanup.go').read_text()
php=(root/'server/app/Application/Chat/ChatGoRuntimeConfig.php').read_text()
probe=(root/'server/app/Application/Chat/ChatAsrOpenApiProbe.php').read_text()
diag=(root/'server/bin/chat-asr-diagnostic.php').read_text()
api=(root/'server/api/index.php').read_text()
nginx=(root/'server/nginx.worker.conf.example').read_text()
gitignore=(root/'.gitignore').read_text()
docs=(root/'docs/订单聊天/官方语音识别插件与音视频通话.md').read_text()

checks=[
 ('ASRPublicRoot',config),('asr_public_root',config),('validASRPublicOrigin',config),('env != "test"',config),
 ('NewASRMirrorStorage',config),('ttl < 5*time.Minute || ttl > 30*time.Minute',mirror),('sourceprobe.VerifyMP3',mirror),
 ('sha256.Sum256(body)',mirror),('asrMirrorName',mirror),('CleanInspection',mirror),
 ('CleanInspection(context.Context)',cleanup),
 ("$root.'/server/uploads/chat-asr'",php),("'asr_public_ttl_seconds'=>!empty($caps['asr_public_root'])?600:0",php),
 ("sentence-asr-site-https-object-v8",probe),("$body=['service'=>self::SERVICE,'api'=>self::API,'client_msg_id'=>$clientMsgId,'data'=>$data]",probe),
 ("site_https_mirror",diag),("mirror_verify",diag),("'/uploads/chat-asr/'",diag),
 ("sentence_asr_object_site_https",api),("source_mode",api),
 ("^/uploads/chat-asr/[a-f0-9]{64}\\.mp3$",nginx),("location ~ ^/uploads/chat-asr/ {",nginx),
 ("/server/uploads/chat-asr/",gitignore),("网站 HTTPS 临时音频源",docs),
]
for needle,body in checks:
    if needle not in body: raise SystemExit('missing invariant: '+needle)
if "json_encode($data" in probe:
    raise SystemExit('probe must not stringify data')
if "ASRPublicRoot" not in config or "ASRPublicOrigin" not in config:
    raise SystemExit('managed config missing mirror fields')
print("ASR_SITE_SOURCE_CONTRACT_OK")
