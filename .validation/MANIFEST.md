# Isolated validation manifest

- Source repository: `franz-9527/workers`
- Candidate SHA: `cdb107c327cb1c3781f09786cd47f02b64b6d989`
- Baseline main SHA: `993a78d18e429b7d05057d9eed2fb47be21ef7ed`
- Test runtime: PHP 8.3.36, Node 24 (runner), MySQL 5.7.44
- No production credentials, database, SMS, WeChat, or WeCom endpoints are used.
- The mini-program review test loads the real `WorkerProfileTime.js` utility; OrderApi remains stubbed.
- Test labels are aligned with the current product mappings for unrecorded and pending review notices.
- The snapshot is temporary and must be deleted after validation.
