'use strict'
module.exports = function primaryActionFor(actions, isMeasurementOrder) {
  if (actions.is_admin) return null
  const candidates = [
    { key: 'supplement', allowed: actions.can_supplement, label: '补充资料', style: '' },
    { key: 'payOrder', allowed: actions.can_pay_order, label: '支付预约费', style: '' },
    { key: 'payExisting', allowed: actions.can_pay, label: isMeasurementOrder ? '继续支付测量款' : '继续支付', style: '' },
    { key: 'quotes', allowed: actions.can_select_quote, label: isMeasurementOrder ? '比较测量报价' : '比较报价', style: '' },
    { key: actions.accept_waiting_receipt ? 'acceptWaiting' : 'accept', allowed: actions.can_accept_completion, label: actions.accept_waiting_receipt ? '等待师傅上传凭证' : (isMeasurementOrder ? '确认测量成果' : '确认验收'), style: actions.accept_waiting_receipt ? 'is-waiting' : '' },
    { key: 'dispute', allowed: actions.can_open_dispute, label: '提交争议', style: 'is-attention' },
    { key: 'review', allowed: actions.can_review, label: '评价师傅', style: '' },
    { key: 'repair', allowed: actions.can_request_repair, label: isMeasurementOrder ? '申请测量售后' : '申请维修', style: '' },
    { key: 'repairIntervention', allowed: actions.can_request_repair_intervention, label: '申请平台介入', style: 'is-attention' }
  ]
  return candidates.find(item => !!item.allowed) || null
}