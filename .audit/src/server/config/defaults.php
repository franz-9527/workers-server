<?php

// 2026-08-14：提供无需 Composer 的默认配置，生产密钥由网页安装器写入 installed.php。
return [
    'installed' => false,
    // V2.4.0：默认版本跟随当前代码包；全新安装基线已包含帮助中心结构和草稿问答。
    'version' => '2.4.0',
    'app' => [
        'name' => '壁选师傅订单管理系统',
        'url' => '',
        'env' => 'production',
        'debug' => false,
        'timezone' => 'Asia/Shanghai',
        'key' => '',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => '',
        'username' => '',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    // Independent PHP Ed25519 seed; Go receives public keys only. Disabled until chat acceptance.
    'chat' => ['enabled'=>false,'calls_enabled'=>false,'collaboration_enabled'=>false,'key_id'=>'chat-v1','seed_env'=>'CHAT_ACCESS_SEED','order_policy_enabled'=>false,'safety_credential_export_enabled'=>false,'safety_rejection_directory'=>'','reminders_enabled'=>false,'internal_key_id'=>'chat-internal-v1','internal_key_env'=>'CHAT_INTERNAL_KEY','internal_go_origin'=>'','recovery_directory'=>'','recovery_gate_file'=>'','recovery_epoch'=>'','recovery_key_env'=>'CHAT_RECOVERY_KEY','test_report_key_env'=>'CHAT_TEST_REPORT_KEY','test_report_revision'=>''],
    'security' => [
        'session_name' => 'worker_admin_session',
        'session_lifetime' => 7200,
        'login_max_attempts' => 5,
        'login_lock_minutes' => 15,
        'force_https' => true,
    ],
    'media' => [
        'max_width' => 1600,
        'jpeg_quality' => 90,
        'max_file_bytes' => 1048576,
        'storage_path' => 'storage/private/uploads',
    ],
];

