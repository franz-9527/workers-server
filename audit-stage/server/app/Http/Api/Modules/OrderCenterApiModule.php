<?php
// Current dev/2.5.0 API keyword excerpt used by regression evidence.
$kind = trim((string)($_GET['kind'] ?? 'mine'));
$status = trim((string)($_GET['status'] ?? 'all'));
$page = max(1, (int)($_GET['page'] ?? 1));
$keyword = trim((string)($_GET['keyword'] ?? ''));