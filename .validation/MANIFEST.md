# Isolated validation manifest

- Source repository: `franz-9527/workers`
- Candidate SHA: `c05c5e77ee77855e80fea286ca5e8a282663c6fa`
- Baseline main SHA: `975f46bac6963f59f55db889a8c283b584a55f86`
- Test runtime: PHP 8.3.36, Node 20 (runner default), MySQL 5.7.44
- Snapshot covers exact review-event reconciliation, repeated-review idempotency, transactional WeChat acceptance/quota accounting, terminal unknown outcomes, and admin outbox presentation.
- No production credentials, database, SMS, WeChat, or WeCom endpoints are used.
- Tests run only in this isolated repository; the temporary snapshot and workflow are removed after validation.
