from pathlib import Path

av = Path(".validation/asr-openapi/snapshots/av.go").read_text()
cfg = Path(".validation/asr-openapi/snapshots/ChatGoRuntimeConfig.php").read_text()
view = Path(".validation/asr-openapi/snapshots/chat-settings.php").read_text()

assert 'asrcontract.BuildData(a.c.APIName, a.c.DataEncoding, q.URL, q.UserAudioKey)' in av
assert 'req.Header.Set("Content-Type", "application/json; charset=utf-8")' in av
assert 'dataEncoding = "json_string"' not in av
assert "if($profile==='sentence_asr')$encoding='object';" in cfg
assert "$asrEncoding=$asrProfile==='sentence_asr'?'object':" in cfg
assert "SentenceASR Open API HTTPS" in view
assert "不再二次序列化为字符串" in view
print("ASR_OPENAPI_SNAPSHOT_CHECK_PASSED")
