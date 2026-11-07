from pathlib import Path
import json,re
root=Path('.validation/tencent-asr-direct/candidate')
go=(root/'chat/internal/moderation/tencent_asr.go').read_text()
av=(root/'chat/internal/moderation/av.go').read_text()
cfg=(root/'chat/internal/avpipeline/config.go').read_text()
php=(root/'server/app/Application/Chat/ChatGoRuntimeConfig.php').read_text()
view=(root/'server/views/admin/chat-settings.php').read_text()
diag=(root/'server/bin/chat-asr-diagnostic.php').read_text()
app=json.loads((root/'miniprogram/app.json').read_text())
docs=(root/'docs/订单聊天/官方语音识别插件与音视频通话.md').read_text()

must=[
 ('tencent host','asr.tencentcloudapi.com' in go),
 ('direct action','SentenceRecognition' in go),
 ('TC3','TC3-HMAC-SHA256' in go),
 ('raw URL',re.search(r'"Url"\s*:\s*q\.URL',go) is not None),
 ('dialect engine','16k_zh_dialect' in go and '16k_zh_dialect' in php),
 ('direct provider',"Provider == \"tencent_cloud\"" in av),
 ('secret env config','ASRSecretIDEnv' in cfg and 'ASRSecretKeyEnv' in cfg),
 ('encrypted id','TENCENT_ASR_SECRET_ID_CONTEXT' in php and 'cipher->encrypt($tencentSecretId' in php),
 ('encrypted key','TENCENT_ASR_SECRET_KEY_CONTEXT' in php and 'cipher->encrypt($tencentSecretKey' in php),
 ('runtime env',"CHAT_TENCENT_ASR_SECRET_ID" in php and "CHAT_TENCENT_ASR_SECRET_KEY" in php),
 ('usage','estimated_free_remaining' in php and 'free_monthly_success_calls' in php),
 ('no fake official remaining',"official_remaining_available'=>false" in php),
 ('admin fields','name="tencent_asr_secret_id"' in view and 'name="tencent_asr_secret_key"' in view),
 ('password fields','type="password"' in view),
 ('fallback','wx069ba97219f66d99' in view),
 ('old probe disabled',"$profile!=='sentence_asr'" in diag),
 ('docs','腾讯云一句话识别直连主方案' in docs),
]
for name,ok in must:
    if not ok: raise SystemExit('missing contract: '+name)
if 'url.QueryEscape' in go or 'urlencode' in go:
    raise SystemExit('direct Tencent URL must not inherit WeChat service-market URL encoding')
if app.get('plugins',{}).get('WechatSI',{}).get('provider')!='wx069ba97219f66d99':
    raise SystemExit('WechatSI fallback provider lost')
if app.get('plugins',{}).get('WechatSI',{}).get('version')!='0.3.10':
    raise SystemExit('WechatSI fallback not updated')
if 'QCloudAIVoice' in app.get('plugins',{}):
    raise SystemExit('long-lived Tencent credential plugin must not be enabled')
if re.search(r'value="<\?=\s*e\([^)]*tencent_asr_secret',view):
    raise SystemExit('Tencent secrets must never be prefilled')
print('TENCENT_ASR_DIRECT_CONTRACT_OK')
