// Presentation only. Permissions, prices and transitions remain server-owned.
function layout(detail) {
  const order = detail.order || {}, actions = detail.actions || {}, assignment = detail.assignment || null
  const media = detail.media || [], afterSales = detail.after_sales || [], changes = detail.changes || []
  const archived = (!!order.lifecycle_status && order.lifecycle_status !== 'active') || order.fulfillment_status === 'accepted'
  const submitted = order.fulfillment_status === 'submitted'
  const repair = ['open', 'processing'].includes(order.repair_status) || afterSales.some(item => item.type === 'warranty_repair' && ['open', 'processing'].includes(item.status))
  const dispute = ['open', 'processing'].includes(order.dispute_status)
  const pendingChange = changes.some(item => item.status === 'pending')
  const negotiation = (detail.negotiations || []).some(item => item.can_respond || item.can_reject_only || item.can_propose || item.can_escalate || ['pending', 'accepted_processing', 'escalated'].includes(item.status))
  const receipts = media.filter(item => item.purpose === 'collection_receipt')
  const settlementVisible = order.workflow_profile !== 'measurement' && !!assignment && (submitted || order.fulfillment_status === 'accepted')
  const settlementDue = settlementVisible && (!!actions.can_pay_commission || Number((detail.commission_receivable || {}).unpaid_cents || 0) > 0 || !receipts.length)
  let stage = 'overview'
  if (detail.viewer_mode === 'public_preview') stage = 'preview'
  else if (repair) stage = 'repair'
  else if (actions.can_accept || (assignment && assignment.status === 'offered' && !actions.can_quote)) stage = 'assignment'
  else if (actions.can_quote || actions.can_grab) stage = 'quote'
  else if (settlementDue) stage = 'settlement'
  else if (submitted) stage = 'confirmation'
  else if (!archived && assignment && assignment.status === 'accepted') stage = 'work'
  else if (archived) stage = 'archived'
  else if (detail.own_quote) stage = 'waiting_quote'
  const phase = [order.id || order.public_no || '', stage, order.lifecycle_status || '', order.fulfillment_status || '', assignment && assignment.status || '', !!pendingChange, !!dispute, !!negotiation].join(':')
  return {
    phase, stage, serviceArchived: archived,
    sections: {
      information: !assignment && !detail.own_quote,
      work: stage === 'work',
      settlement: settlementDue || submitted,
      changes: pendingChange || dispute,
      afterSales: repair
    },
    sectionOrder: {
      grab: 2, quote: 2,
      navigation: ['quote', 'assignment', 'work'].includes(stage) ? 5 : 35,
      information: ['quote', 'preview', 'overview'].includes(stage) && !assignment ? 20 : 60,
      assignment: stage === 'assignment' ? 3 : 22,
      work: stage === 'work' ? 3 : 55,
      settlement: settlementDue || submitted ? 3 : 28,
      changes: pendingChange || dispute ? 4 : 65,
      afterSales: repair ? 3 : 75,
      negotiations: negotiation ? 4 : 70,
      platform: detail.platform_order && assignment && actions.can_work === false && !archived ? 2 : 12,
      measurement: archived && !repair ? 35 : 6,
      materials: 50, collection: stage === 'settlement' ? 14 : 65, review: 80, problem: 1000
    }
  }
}
module.exports = { layout }
