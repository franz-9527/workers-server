from pathlib import Path
root=Path(__file__).parent/'candidate'
policy=(root/'policy.go').read_text()
inspection=(root/'av_inspection.go').read_text()
worker=(root/'av_worker.go').read_text()
avjs=(root/'av.js').read_text()
docs=(root/'asr-doc.md').read_text()
checks=[
 ("return !candidate || rawDecision == \"pass\"",policy),
 ("providerFallbackRawCheck(ctx, v, string(meta))",inspection),
 ("phase='raw_ready'",inspection),
 ("state='checking'",inspection),
 ("CHAT_AV_WECHAT_MEDIA_REVIEW_REQUIRED",inspection),
 ("recoverProviderFallbackReviews",worker),
 ("j.error_code=?",worker),
 ("avfallback.ReviewCode",worker),
 ("i.state='review_pending'",worker),
 ("语音安全检测无法自动确认",avjs),
 ("不再进入管理员人工预审",docs),
 ("自动把旧版",docs),
]
for needle,body in checks:
    if needle not in body:
        raise SystemExit("missing invariant: "+needle)
for forbidden,body in [
 ("providerFallbackReview(ctx, v",inspection),
 ("approved && (rawDecision == \"pass\" || rawDecision == \"review\")",policy),
 ("管理员必须实际播放原音",docs),
]:
    if forbidden in body:
        raise SystemExit("forbidden legacy invariant: "+forbidden)
print("ASR_AUTO_FALLBACK_CONTRACT_OK")
