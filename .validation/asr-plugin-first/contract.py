from pathlib import Path
root=Path(__file__).parent/'candidate'
worker=(root/'av_worker.go').read_text()
publish=(root/'av_publish.go').read_text()
background=(root/'av_background_asr.go').read_text()
messaging=(root/'messaging.go').read_text()
chatav=(root/'ChatAV.js').read_text()
uiav=(root/'av.js').read_text()
wxml=(root/'index.wxml').read_text()
docs=(root/'asr-doc.md').read_text()
settings=(root/'chat-settings.php').read_text()

checks=[
 ("nextPhase = \"ready\"",worker),
 ("transcript = strings.TrimSpace(v.Request.ClientTranscript)",worker),
 ("_, e = a.Publish(ctx, id)",worker),
 ("j.phase IN ('asr_ready','asr_started','raw_ready','raw_started','raw_wait')",worker),
 ("INSERT IGNORE INTO chat_av_asr_requests",publish),
 ("'queued'",publish),
 ("backgroundQueued",publish),
 ("ProcessBackgroundASROne",background),
 ("r.state='queued'",background),
 ("j.phase='published'",background),
 ("u.kind='audio'",background),
 ("server_asr",background),
 ("strings.TrimSpace(v.Request.ClientTranscript) == \"\"",background),
 ("UPDATE chat_av_jobs SET principal_json='{}'",background),
 ("ProcessBackgroundASROne(pass)",messaging),
 ("[120,220,360,600,900,1400]",chatav),
 ("row.phase='publish'",chatav),
 ("transcript:String(r.clientTranscript||'')",uiav),
 ("r.kind==='audio'&&r.state==='checking'?'发送中'",uiav),
 ("av.kind === 'audio' && av.transcript",wxml),
 ("插件优先、ASR 后台增强",docs),
 ("SentenceASR 在消息发布后后台执行",settings),
]
for needle,body in checks:
    if needle not in body:
        raise SystemExit('missing invariant: '+needle)

if "UPDATE chat_send_intents" in background:
    raise SystemExit("background ASR must not mutate accepted send intent")
if "a.recognize(ctx, v)" not in worker:
    raise SystemExit("video foreground ASR path unexpectedly removed")
if 'nextPhase, transcript, asrMeta := "asr_ready"' not in worker or 'case "asr_ready":' not in worker or 'e = a.recognize(ctx, v)' not in worker:
    raise SystemExit("video ASR path unexpectedly removed")
print("PLUGIN_FIRST_ASR_CONTRACT_OK")
