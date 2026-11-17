# Isolated validation manifest

- Source repository: `franz-9527/workers`
- Candidate SHA: `0d7f74681b74f8b389cf1998543cde1e1e7e56b9`
- Baseline main SHA: `993a78d18e429b7d05057d9eed2fb47be21ef7ed`
- Test runtime: PHP 8.3.36, Node 24 (runner), MySQL 5.7.44
- No production credentials, database, SMS, WeChat, or WeCom endpoints are used.
- The mini-program review test loads the real `WorkerProfileTime.js` utility; OrderApi remains stubbed.
- The MySQL regression also covers the missing local-user row path, which must not silently drop the in-app review result.
- The snapshot is temporary and must be deleted after validation.
