from pathlib import Path
root=Path(__file__).parent/'candidate'
cls=(root/'server/app/Application/Chat/ChatAsrSelfDiagnostic.php').read_text()
module=(root/'server/app/Http/Admin/Modules/ChatSettingsAdminModule.php').read_text()
view=(root/'server/views/admin/chat-settings.php').read_text()
css=(root/'server/assets/admin.css').read_text()
docs=(root/'docs/订单聊天/官方语音识别插件与音视频通话.md').read_text()

for needle,body in [
 ('final class ChatAsrSelfDiagnostic',cls),
 ("'provider_binding_high'",cls),
 ("'service_binding'",cls),
 ('当前公开 Open API 无法直接读取这些绑定事实',cls),
 ('ChatAsrSelfDiagnostic',module),
 ("'asr_self_diagnostic'",module),
 ('ASR 自诊断',view),
 ('自动汇总现有证据，不会因刷新本页再次调用 SentenceASR',view),
 ('sentence_asr_object_site_https',view),
 ('本站 HTTPS MP3 + Data object',view),
 ('.chat-asr-self-check__grid',css),
 ('后台 ASR 自诊断',docs),
]:
    if needle not in body: raise SystemExit('missing: '+needle)

for forbidden in ['WeChatAccessTokenProvider','->run(','curl_init(','api.weixin.qq.com','qiniu']:
    if forbidden in cls:
        raise SystemExit('self diagnostic must be passive: '+forbidden)

if '已绑定失败' in view or '确认未绑定' in view or '服务未绑定' in view:
    raise SystemExit('view overstates inferred binding')
if 'v7 受控探针未成功' in view:
    raise SystemExit('stale v7 result copy remains')
print('ASR_SELF_DIAGNOSTIC_CONTRACT_OK')
