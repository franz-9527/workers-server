'use strict'

function primaryActionFor(actions, isMeasurementOrder) {
  if (actions.is_admin) return null
  const candidates = [
    { key: 'supplement', allowed: actions.can_supplement, label: '补充资料', style: '' },
    { key: 'payOrder', allowed: actions.can_pay_order, label: '支付预约费', style: '' },
    { key: 'payExisting', allowed: actions.can_pay, label: isMeasurementOrder ? '继续支付测量款' : '继续支付', style: '' },
    { key: 'quotes', allowed: actions.can_select_quote, label: isMeasurementOrder ? '比较测量报价' : '比较报价', style: '' },
    { key: actions.accept_waiting_receipt ? 'acceptWaiting' : 'accept', allowed: actions.can_accept_completion, label: actions.accept_waiting_receipt ? '等待师傅上传凭证' : (isMeasurementOrder ? '确认测量成果' : '确认验收'), style: actions.accept_waiting_receipt ? 'is-waiting' : '' },
    { key: 'review', allowed: actions.can_review, label: '评价师傅', style: '' }
  ]
  return candidates.find(item => !!item.allowed) || null
}

function problemOptions(actions, isMeasurementOrder) {
  const items = []
  if (actions.can_open_dispute) items.push({ key: 'dispute', label: isMeasurementOrder ? '对测量成果有异议' : '对完工结果有异议' })
  if (actions.can_request_repair) items.push({ key: 'repair', label: isMeasurementOrder ? '申请测量售后' : '申请维修保障' })
  if (actions.can_request_repair_intervention) items.push({ key: 'repairIntervention', label: '申请平台介入' })
  if (actions.can_cancel) items.push({ key: 'cancel', label: '申请退款并取消订单' })
  return items
}

function problemTarget(state) {
  if (state.dispute) return 'order-dispute-panel'
  const activeRepair = (state.afterSales || []).some(item => item && item.type === 'warranty_repair' && (item.status === 'open' || item.status === 'processing'))
  if (activeRepair) return '.after-sales-card'
  if (state.refundInfo) return '.refund-info-card'
  if (state.cancelRequest) return '.cancel-progress-card'
  return ''
}

module.exports = { primaryActionFor, problemOptions, problemTarget }
