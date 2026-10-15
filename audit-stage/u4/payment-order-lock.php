<?php
// Exact current dev/2.5.0 prepareOrderBooking lock/guard excerpt.
$order = $this->db->fetch(
    'SELECT * FROM orders WHERE public_no=:public_no AND user_id=:user_id LIMIT 1 FOR UPDATE',
    ['public_no'=>$orderNo,'user_id'=>$userId]
);
if (!$order || (string)$order['lifecycle_status'] !== 'active') throw new RuntimeException('订单不存在或已经结束');
if ((string)$order['audit_status'] !== 'draft' || (string)$order['payment_status'] !== 'awaiting_payment') {
    throw new RuntimeException('请先完整提交订单资料和必传图片');
}
$existing = $this->activeOrderPayment((int)$order['id'], 'order_booking');