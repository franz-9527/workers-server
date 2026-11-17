Scheduler task-level failure backoff regression snapshot

Source repository: franz-9527/workers
Source commit: 6789c743b27d5730b2a9a9636160b09676e3a87f

The snapshot is intentionally limited to the scheduler service, its database adapter,
the task-level backoff helper, the legacy gateway wiring, and the MySQL regression test.

The temporary workflow uses an isolated MySQL 5.7.44 service database named worker_test.
The regression test creates and drops its own separate database. It does not connect to
any production or test-site database.

Expected command:
php .validation/scheduler-task-backoff/source/server/tests/v260_scheduler_task_backoff_mysql.php

This directory and the temporary workflow must be removed after the run.
