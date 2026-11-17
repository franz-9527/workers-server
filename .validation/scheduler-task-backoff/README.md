Scheduler task-level failure backoff regression snapshot

Source repository: franz-9527/workers
Source commit: bfc0eb3c33a2c6000e9e286527fc2392cb281c02

The snapshot contains only the scheduler code, its contracts/catalog, admin status
projection, and the focused MySQL regression test. Every PHP source blob is checked
against the exact source commit before validation.

Runtime: PHP 7.4.33 and isolated MySQL 5.7.44 service container.
The regression test creates and drops its own separate database. It does not connect
to any production or test-site database.

Expected command:
php .validation/scheduler-task-backoff/source/server/tests/v260_scheduler_task_backoff_mysql.php

This directory and the temporary workflow must be removed after the run.
