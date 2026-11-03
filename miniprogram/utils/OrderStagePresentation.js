// 跨页面统一显示平台测量核价阶段；退款/争议状态仍优先。
function platformPricingStatus(detail, worker) {
  const order = detail.order || {}
  const review = detail.platform_order && detail.platform_order.pricing_review
  if (!order.is_platform_order || !review || order.lifecycle_status !== 'active') return ''
  if (['', 'none', 'failed'].indexOf(String(order.refund_status || 'none')) < 0) return ''
  if (['', 'none', 'resolved'].indexOf(String(order.dispute_status || 'none')) < 0) return ''
  if (review.status === 'pending') return '等待平台核价'
  if (review.status === 'awaiting_customer_confirmation') return worker ? '等待客户确认价格' : '待确认施工价格'
  return ''
}

function changeStatusText(change) {
  if (change.status === 'accepted' && change.payment_status === 'awaiting_payment') return '等待在线补款'
  if (change.status === 'accepted' && change.payment_status === 'refund_pending') return '退款处理中'
  if (change.status === 'accepted' && change.payment_status === 'refunded') return '加费未生效·已退款'
  const platform = change.decision_by === 'platform'
  return { pending: platform ? '等待平台审核' : '等待客户处理', accepted: platform ? '平台已批准' : '客户已同意', rejected: platform ? '平台已拒绝' : '客户已拒绝' }[change.status] || change.status
}

module.exports = { platformPricingStatus, changeStatusText }
