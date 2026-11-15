// User-order-only billing presentation. No worker receivable or collection actions belong here.
// A single customer-facing amount source for the bottom bar and its bill drawer.
// Never infer actual offline collection from a media receipt or from a fulfilled order.
function customerBill(detail) {
 const order=detail.order||{},platform=detail.platform_order||{},actions=detail.actions||{}
 if(!order.id)return null
 const money=cents=>'¥'+(cents/100).toFixed(2)
 const amount=value=>{const n=Number(value);return Number.isSafeInteger(n)&&n>=0?n:0}
 const platformOrder=!!order.is_platform_order,measurement=order.workflow_profile==='measurement'
 const managed=platformOrder&&platform.customer_flow_mode==='platform_managed'
 const rawTotal=platformOrder&&amount(platform.customer_contract_total_cents)>0
  ?amount(platform.customer_contract_total_cents):amount(order.final_amount_cents)
 const totalKnown=rawTotal>0,booking=amount(order.booking_fee_cents)
 const bookingPaid=platformOrder?Math.min(booking,amount(platform.booking_paid_cents))
  :(order.payment_status==='paid'?booking:0)
 const servicePaid=measurement&&detail.measurement&&detail.measurement.service_payment_status==='paid'
 const refundStatus=String(order.refund_status||'none')
 const interrupted=order.lifecycle_status!=='active'||!['','none','failed'].includes(refundStatus)
 const completed=order.fulfillment_status==='accepted'||order.lifecycle_status==='completed'
 const assigned=order.assignment_status==='accepted'||detail.assignment?.status==='accepted'
 const receipt=(detail.media||[]).some(item=>item.purpose==='collection_receipt')||amount(actions.collection_receipt_count)>0
 const adjustment=detail.collection_adjustment||{}
 const approvedAdjustment=!measurement&&!platformOrder&&['approved','auto_approved'].includes(adjustment.status)
 const ordinaryTail=totalKnown?Math.max(0,rawTotal-booking):0
 const tail=platformOrder?amount(platform.customer_tail_cents)
  :(approvedAdjustment?amount(adjustment.requested_tail_cents):ordinaryTail)
 const knownTail=totalKnown&&!measurement&&(!managed||tail>0)
 const canSayOffline=!platformOrder&&!measurement&&knownTail&&tail>0&&bookingPaid===booking&&assigned&&!interrupted&&!completed&&!receipt
 const rows=[]
 if(!totalKnown)rows.push({label:managed?'客户价格':'成交金额',value:'待确认',detail:managed?'由平台确定后更新':'报价选定后显示最终成交金额',style:'total'})
 if(!managed&&booking>0)rows.push({label:measurement?'测量预约费':'线上预约费',value:money(booking),detail:bookingPaid>=booking?'已支付 · 计入成交金额':'待支付 · 不计为已付',style:bookingPaid>=booking?'paid':'pending'})
 if(managed&&bookingPaid>0)rows.push({label:'平台确认的预付款',value:money(bookingPaid),detail:'以平台收款记录为准',style:'paid'})
 if(measurement&&totalKnown){
  const remainder=Math.max(0,rawTotal-booking)
  if(remainder>0)rows.push({label:'测量服务补款（线上）',value:money(remainder),detail:servicePaid?'已完成线上支付':'支付状态以本单微信支付记录为准',style:servicePaid?'paid':'pending'})
 }else if(knownTail&&tail>0){
  rows.push({label:approvedAdjustment?'已核准线下尾款':(platformOrder?'客户约定剩余款':'约定线下尾款'),value:money(tail),detail:approvedAdjustment?'以批准的调整金额为准':(platformOrder?'支付渠道以平台通知为准':'不包含已支付的预约费'),style:'tail'})
 }
 if(!rows.length)rows.push({label:'结算方式',value:managed?'平台托管':'按订单约定',detail:managed?'由平台安排收款，不需要微信预约费':'以订单实际支付记录为准',style:'total'})
 let summaryLabel=totalKnown?'成交金额':(managed?'平台托管':'预约费')
 let summaryAmount=totalKnown?money(rawTotal):(managed?'价格待确认':booking>0?money(booking):'待确认')
 let summaryHint=totalKnown?'查看费用明细':'查看预约费说明'
 let balanceLabel='本单费用'
 let balanceAmount=summaryAmount
 let balanceNote=totalKnown?'本单账单按已确认的价格展示，实际支付记录以订单付款状态为准。':'尚未确定成交价，具体费用以报价和订单支付结果为准。'
 let tip=''
 if(interrupted){
  balanceLabel='原订单金额';balanceAmount=summaryAmount
  balanceNote='订单已结束或处于退款流程，以上是原约定金额，不代表仍需付款；以退款记录及处理结果为准。'
 }else if(managed){
  summaryLabel='平台托管';summaryHint='平台结算明细'
  balanceLabel='平台负责收款安排';balanceAmount=totalKnown?money(rawTotal):'待平台确认'
  balanceNote='本单不需微信预约费。具体收款进度及线下支付方式以平台确认记录为准。'
 }else if(measurement){
  if(servicePaid){
   balanceLabel='测量服务款';balanceAmount=totalKnown?money(rawTotal):'已结算'
   balanceNote='测量服务款已按线上流程支付，冻结或师傅结算不代表您还需要付款。'
  }else if(totalKnown&&bookingPaid>=booking&&rawTotal>booking){
   balanceLabel='测量补款参考';balanceAmount=money(rawTotal-booking)
   balanceNote='测量尾款通过微信线上支付，不是线下交给师傅；请以待支付订单及支付结果为准。'
  }else{
   balanceLabel='线上测量费用';balanceAmount=summaryAmount
   balanceNote='测量费用在线上支付；请在订单要求支付时操作，不需线下结算。'
  }
 }else if(canSayOffline){
  summaryLabel='线下待付参考';summaryAmount=money(tail);summaryHint='上拉核对账单'
  balanceLabel='剩余约定尾款';balanceAmount=money(tail)
  balanceNote='预约费已从成交价中扣除。完工后与师傅确认实际应付金额，线下支付后保留收款凭证。'
 }else if(!platformOrder&&booking>0&&bookingPaid<booking){
  summaryLabel='待付预约费';summaryAmount=money(booking);summaryHint='上拉核对费用'
  balanceLabel='当前待付预约费';balanceAmount=money(booking)
  balanceNote='预约费在线上支付；成交价和后续尾款以订单最终确认内容为准。'
 }else if(knownTail&&tail>0){
  balanceLabel=receipt?'线下尾款待核对':(completed?'约定线下尾款':'预计线下尾款')
  balanceAmount=money(tail)
  balanceNote=receipt?'已上传收款凭证，请双方核对实际到账；凭证不等于系统已确认结清，勿重复付款。'
    :completed?'订单已验收，尾款是否付清需按实际收款凭证及双方核对结果确认。'
    :bookingPaid<booking?'先支付预约费，尾款待成交、接单及履约后按双方约定线下结算。'
    :'该金额为约定尾款，不代表当前已到付款时点；实际收款按履约和双方核对结果确定。'
 }
 if(approvedAdjustment&&knownTail){
  tip='师傅申请的实际尾款调整已获批准，明细采用批准金额；如与原成交金额存在差异，请以调整记录为准。'
 }else if(adjustment.status==='pending'){
  tip='存在待审核的尾款调整申请；本账单仍显示当前生效的约定金额。'
 }else if(receipt&&knownTail){
  tip='已上传收款凭证，实际支付状态需核对，切勿重复支付。'
 }
 return {summaryLabel,summaryAmount,summaryHint,totalLabel:totalKnown?'本单成交金额':'待确认成交价',
  totalAmount:totalKnown?money(rawTotal):'待确认',rows,balanceLabel,balanceAmount,balanceNote,
  tip,hasAmount:totalKnown||booking>0,hasTail:knownTail&&tail>0,orderNo:String(order.id),managed}
}
module.exports={customerBill}
