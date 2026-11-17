# Isolated validation manifest

- Source repository: `franz-9527/workers`
- Candidate SHA: `9d77d92fd972aa5bb8f233e0e83e0d2b6dc096ed`
- Baseline main SHA: `993a78d18e429b7d05057d9eed2fb47be21ef7ed`
- Test runtime: PHP 8.3.36, Node 24 (runner), MySQL 5.7.44
- No production credentials, database, SMS, WeChat, or WeCom endpoints are used.
- The mini-program review test loads the real `WorkerProfileTime.js` utility; OrderApi remains stubbed.
- The expected unrecorded notice label is aligned with the product's `NOTICE_STATUS_MEANING` map.
- The snapshot is temporary and must be deleted after validation.
