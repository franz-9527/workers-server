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
  items.push({key:'cannot_contact',title:'无法联系',description:'记录现场联系情况',icon:'cuIcon-phone',tone:'orange'})
  items.push({key:'cannot_work',title:o.workflow_profile==='measurement'?'无法测量':'无法施工',description:'上传取证并说明原因',icon:'cuIcon-warn',tone:'orange'})
 }
 if(a.can_worker_cancel)items.push({key:'cancel_service',title:'因自身原因取消',description:'无法履约时主动办理',icon:'cuIcon-roundclose',tone:'red'})
 if(items.length)items.push({key:'service',title:'联系客服',description:'沟通处理订单问题',icon:'cuIcon-service',tone:'cyan'})
 return items
}
function customerCards(hasProblemEntry,label) {
 if(!hasProblemEntry)return []
 return [{key:'problem',title:label||'遇到问题',description:label==='查看问题处理'?'查看退款、争议或售后':'退款、异议与维修',icon:'cuIcon-warn',tone:'orange'},
  {key:'service',title:'联系客服',description:'沟通处理订单问题',icon:'cuIcon-service',tone:'cyan'}]
}
module.exports={offlineCollection,workerCards,customerCards}
