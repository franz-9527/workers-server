from pathlib import Path
root=Path(__file__).parent/'candidate'
inspection=(root/'av_inspection.go').read_text()
worker=(root/'av_worker.go').read_text()
publish=(root/'av_publish.go').read_text()
policy=(root/'policy.go').read_text()
recorder=(root/'ChatVoiceRecorder.js').read_text()
docs=(root/'asr-doc.md').read_text()
checks=[
 ("audioRecognitionFallback",inspection),
 ("phase='text_ready'",inspection),
 ('"source": source',inspection),
 ("server_asr",inspection),
 ("recoverNonBlockingAudio",worker),
 ("j.phase IN ('raw_ready','raw_started','raw_wait')",worker),
 ('v.Request.MessageType == "audio" && v.Proof == ""',worker),
 ('return a.advance(ctx, v, "ready")',worker),
 ('q.MessageType == "audio" && raw != ""',publish),
 ('rawDecision == "" || rawDecision == "pass"',policy),
 ('sourceProof == "" && textProof == ""',policy),
 ("partialTranscript",recorder),
 ("recognitionFailed",recorder),
 ("语音优先、转写增强",docs),
]
for needle,body in checks:
    if needle not in body:
        raise SystemExit('missing invariant: '+needle)
for forbidden,body in [
 ("CHAT_AUDIO_NO_RECOGNIZABLE_TEXT",worker),
 ("providerFallbackRawCheck",inspection),
 ('q.MessageType == "audio" && (raw != "pass"',publish),
 ("manager.onRecognize=()=>{}",recorder),
]:
    if forbidden in body:
        raise SystemExit('legacy blocking invariant: '+forbidden)
print('VOICE_NONBLOCKING_CONTRACT_OK')
