// Display only: all permissions, amounts and payment transitions come from the order API.
function offlineCollection(detail,worker) {
 const order=detail.order||{},actions=detail.actions||{},platform=detail.platform_order||{}
 if(detail.viewer_mode==='public_preview'||actions.is_admin||order.workflow_profile==='measurement'||order.lifecycle_status!=='active'||order.fulfillment_status==='accepted'||!['','none','failed'].includes(String(order.refund_status||'none')))return null
 if(order.assignment_status!=='accepted'&&(detail.assignment||{}).status!=='accepted')return null
 let amount
 if(order.is_platform_order){
  // Public customer views intentionally do not expose worker settlement routing.
  if(platform.worker_settlement_mode!=='worker_collect')return null
  amount=platform.customer_tail_cents
 }else{
  const total=Number(order.final_amount_cents),booking=Number(order.booking_fee_cents)
  if(!Number.isSafeInteger(total)||!Number.isSafeInteger(booking)||booking<0)return null
  amount=total-booking
  const adjustment=detail.collection_adjustment||{}
  if(['approved','auto_approved'].includes(adjustment.status))amount=Number(adjustment.requested_tail_cents)
 }
 amount=Number(amount)
 if(!Number.isSafeInteger(amount)||amount<=0)return null
 const receipt=(detail.media||[]).some(item=>item.purpose==='collection_receipt')||Number(actions.collection_receipt_count||0)>0
 return {amountText:'¥'+(amount/100).toFixed(2),title:'本单需线下结算',
  description:worker?(receipt?'已有收款凭证，请核对实际收款；勿重复收取。':'完工时与客户核对尾款，收款后上传凭证。'):(receipt?'请结合师傅收款凭证核对；已付款无需重复支付。':'完工后与师傅核对尾款；已付款无需重复支付。')}
}
function workerCards(detail) {
 if(detail.viewer_mode==='public_preview')return []
 const a=detail.actions||{},o=detail.order||{},items=[]
 if(a.can_work&&!['submitted','accepted'].includes(o.fulfillment_status)){
  items.push({key:'cannot_contact',title:'无法联系',description:'联系不上客户时，请记录联系情况并上传带时间、位置的现场取证照片，提交后联系客服协商处理。',actionText:'提交无法联系情况',icon:'cuIcon-phone',tone:'orange'})
  items.push({key:'cannot_work',title:o.workflow_profile==='measurement'?'无法测量':'无法施工',description:'现场条件不满足服务要求时，请上传取证照片、说明原因，再与客户及平台协商处理。',actionText:'说明现场情况',icon:'cuIcon-warn',tone:'orange'})
 }
 if(a.can_worker_cancel)items.push({key:'cancel_service',title:'因自身原因取消',description:'如因你自身原因无法履约，请主动取消；客户不承担上门或时间补偿，可退线上实收全额原路退款。',actionText:'因自身原因取消',icon:'cuIcon-roundclose',tone:'red'})
 if(items.length)items.push({key:'service',title:'联系客服',description:'需要协助时，可联系客服说明本单情况，由平台协助沟通处理。',actionText:'联系客服',icon:'cuIcon-service',tone:'cyan'})
 return items
}
function customerCards(hasProblemEntry,label) {
 if(!hasProblemEntry)return []
 return [{key:'problem',title:label||'遇到问题',description:label==='查看问题处理'?'已有退款、争议或售后记录，可进入查看当前处理进度。':'需要申请退款、对服务成果有异议、申请维修或需要平台介入时，从这里办理。',actionText:label||'遇到问题',icon:'cuIcon-warn',tone:'orange'},
  {key:'service',title:'联系客服',description:'需要协助时，可联系客服说明本单情况，由平台协助沟通处理。',actionText:'联系客服',icon:'cuIcon-service',tone:'cyan'}]
}
module.exports={offlineCollection,workerCards,customerCards}
