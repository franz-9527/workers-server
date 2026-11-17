# Isolated validation manifest

- Source repository: `franz-9527/workers`
- Candidate SHA: `04425869258d80f58ae3a47f45a205e45bcd15f4`
- Baseline main SHA: `993a78d18e429b7d05057d9eed2fb47be21ef7ed`
- Test runtime: PHP 8.3.36, Node 24 (runner), MySQL 5.7.44
- No production credentials, database, SMS, WeChat, or WeCom endpoints are used.
- All 35 snapshotted source files were SHA-verified against the candidate commit before this validation record was finalized.
- The mini-program review test loads the real `WorkerProfileTime.js` utility; OrderApi remains stubbed.
- The MySQL regression covers missing local user identity and confirms an in-app review result plus a skipped outbox row are retained.
- This snapshot and its workflow are temporary and will be removed after validation.
