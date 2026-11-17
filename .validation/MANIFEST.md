# Isolated validation manifest

- Source repository: `franz-9527/workers`
- Candidate SHA: `97f64709b9029b0a2a320b463b1420cfb9410486`
- Baseline main SHA: `993a78d18e429b7d05057d9eed2fb47be21ef7ed`
- Test runtime: PHP 8.3.36, Node 24 (runner), MySQL 5.7.44
- No production credentials, database, SMS, WeChat, or WeCom endpoints are used.
- The mini-program review test loads the real `WorkerProfileTime.js` utility; OrderApi remains stubbed.
- The snapshot is temporary and must be deleted after validation.
