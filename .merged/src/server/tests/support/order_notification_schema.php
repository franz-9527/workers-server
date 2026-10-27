<?php
// 给隔离的最小 MySQL 用例补齐真实通知写入依赖；演示订单禁止外部发送。
function installOrderNotificationTestSchema(\WorkerAdmin\Core\Database $db): void
{
    foreach (['user_id'=>'BIGINT UNSIGNED NOT NULL DEFAULT 88','booking_fee_cents'=>'INT UNSIGNED NOT NULL DEFAULT 0','final_amount_cents'=>'INT UNSIGNED NOT NULL DEFAULT 0','is_demo'=>'TINYINT NOT NULL DEFAULT 1'] as $column=>$type) {
        if (!$db->columnExists('orders',$column)) $db->execute('ALTER TABLE orders ADD `'.$column.'` '.$type);
    }
    $db->execute('CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,mobile_ciphertext TEXT NULL) ENGINE=InnoDB');
    $db->execute("INSERT INTO users(id,mobile_ciphertext) VALUES(88,''),(999,'')");
    $db->execute('CREATE TABLE notifications LIKE worker_test.notifications');
}
