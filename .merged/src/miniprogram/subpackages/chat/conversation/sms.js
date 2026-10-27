// Paid SMS is always an explicit, account-scoped confirmation. No automatic sends.
const money=n=>'¥'+(Math.max(0,Number(n)||0)/100).toFixed(2)
const labels={queued:'已提交，等待发送',dispatch_claimed:'正在准备发送',sending:'正在核对发送结果',provider_accepted:'已受理，等待运营商回执',uncertain:'结果待核对，请勿重复发送',delivered:'已送达，费用已结算',rejected:'未发送，预占已退回',delivery_failed:'发送失败，预占已退回',cancelled_before_send:'对方已读或状态变化，未发送',unknown:'未确认送达，预占已退回，不重复发送'}
module.exports={
 startSMS(){
  if(!this.manager.io?.sms||!this.visible||!this.manager.enabled)return
  if(this.smsPending?.epoch!==this.manager.epoch)this.smsPending=null
  this.refreshSMS(true)
  if(!this.smsTimer)this.smsTimer=setTimeout(()=>{this.smsTimer=null;this.startSMS()},60000)
 },
 stopSMS(reset=false){
  if(this.smsTimer)clearTimeout(this.smsTimer);this.smsTimer=null;this.smsSerial=(this.smsSerial||0)+1;this.smsQuote=null;this.smsLoading=false
  if(reset){this.smsPending=null;this.smsRechargeIntent=false}
  this.setData({smsAnchor:'',smsFirstHint:false,smsDialog:null,smsBusy:false})
 },
 async refreshSMS(force=false){
  if(!this.visible||!this.data.canSend||!this.manager.io?.sms||this.smsLoading)return
  const now=this.manager.io.now();if(!force&&this.smsLast>now-10000)return
  this.smsLast=now;this.smsLoading=true
  const life=this.life,epoch=this.manager.epoch,serial=(this.smsSerial||0)+1;this.smsSerial=serial
  try{
   const quote=await this.manager.io.sms('quote',{conversation_id:this.id})
   if(!this.current(life,epoch)||serial!==this.smsSerial)return
   this.smsQuote=quote
   const own=this.data.messages.filter(m=>m.isMine&&!m.isPlaceholder),anchor=(this.smsPending?.epoch===epoch||quote.enabled&&quote.items?.length)?own[own.length-1]?.messageId:''
   let hint=false
   if(anchor){const env=require('../../../utils/ApiEnvironment.js'),key=env.storageKey('chat-sms-hint:'+(quote.payer_id||String(epoch)))
    try{hint=!wx.getStorageSync(key);if(hint)wx.setStorageSync(key,true)}catch(_){hint=!this.smsHintSeen}this.smsHintSeen=true}
   this.setData({smsAnchor:anchor||'',smsFirstHint:hint})
   if(this.smsRechargeIntent){this.smsRechargeIntent=false;if(anchor)this.showSMSDialog(quote)}
  }catch(_){if(this.current(life,epoch)&&serial===this.smsSerial){this.smsQuote=null;this.setData({smsAnchor:'',smsFirstHint:false})}}
  finally{if(serial===this.smsSerial)this.smsLoading=false}
 },
 showSMSDialog(quote,recipient){
  if(!quote?.items?.length)return
  const index=Math.max(0,quote.items.findIndex(r=>r.recipient_id===recipient)),item=quote.items[index]
  this.setData({smsDialog:{items:quote.items.map(r=>({id:r.recipient_id,name:r.display_name})),selected:item.recipient_id,fee:money(item.fee_cents),balance:money(quote.available_cents),insufficient:quote.available_cents<item.fee_cents,notice:'确认后先预占余额，运营商确认送达才扣费；失败或超时未确认会退回。',state:'confirm'},smsBusy:false})
 },
 async openSMS(){
  if(!this.visible||!this.data.canSend||this.data.smsBusy)return
  if(typeof wx.hideKeyboard==='function')wx.hideKeyboard()
  await this.refreshSMS(true)
  if(!this.visible)return
  if(this.smsPending&&this.smsPending.epoch===this.manager.epoch){
   this.setData({smsDialog:{state:'pending',notice:'上次提交结果尚未确认，先核对原短信，避免重复扣费。'},smsBusy:false});return
  }
  if(this.smsQuote?.items?.length)this.showSMSDialog(this.smsQuote)
  else wx.showToast({title:'对方可能已读，或暂不满足短信提醒条件',icon:'none'})
 },
 selectSMSRecipient(event){if(this.data.smsBusy||this.data.smsDialog?.state!=='confirm')return;this.showSMSDialog(this.smsQuote,String(event.currentTarget.dataset.id||''))},
 closeSMS(){if(!this.data.smsBusy)this.setData({smsDialog:null})},
 async confirmSMS(){
  const dialog=this.data.smsDialog;if(!dialog||this.data.smsBusy||!this.visible)return
  if(dialog.state==='pending'){await this.checkSMS();return}
  if(dialog.state!=='confirm'){this.closeSMS();return}
  if(dialog.insufficient){this.smsRechargeIntent=true;this.setData({smsDialog:null});wx.navigateTo({url:'/pages/recharge/recharge'});return}
  const life=this.life,epoch=this.manager.epoch,selected=dialog.selected
  this.setData({smsBusy:true})
  try{
   // Refresh both unread authority and balance immediately before requesting a hold.
   const quote=await this.manager.io.sms('quote',{conversation_id:this.id})
   if(!this.current(life,epoch))return
   const item=quote.items?.find(r=>r.recipient_id===selected)
   this.smsQuote=quote
   if(!item){this.setData({smsDialog:null});wx.showToast({title:'对方已读或提醒状态已变化，未扣费',icon:'none'});return}
   if(money(item.fee_cents)!==dialog.fee||quote.available_cents<item.fee_cents){this.showSMSDialog(quote,selected);return}
   const request=await this.manager.io.randomBytes(16)
   if(!this.current(life,epoch))return
   const payload={conversation_id:this.id,recipient_id:item.recipient_id,message_id:item.message_id,wave:item.wave,fee_cents:item.fee_cents,quote_token:item.quote_token,client_request_id:request}
   this.smsPending={epoch,request,payload}
   const result=await this.manager.io.sms('create',payload)
   if(!this.current(life,epoch))return
   this.smsPending.request=result.client_request_id||request
   this.showSMSResult(result)
  }catch(error){
   if(!this.current(life,epoch))return
   if(this.smsPending){
    // A definite refusal did not create a task; network/5xx uncertainty retains the original ID.
    if(error.statusCode&&error.statusCode<500){this.smsPending=null;await this.refreshSMS(true);if(this.smsQuote?.items?.length)this.showSMSDialog(this.smsQuote,selected);wx.showToast({title:error.message||'暂时无法提醒',icon:'none'})}
    else this.setData({smsDialog:{state:'pending',notice:'提交结果待核对，请核对原短信，勿重复发送。'}})
   }else wx.showToast({title:error.message||'暂时无法提醒',icon:'none'})
  }finally{if(this.current(life,epoch))this.setData({smsBusy:false})}
 },
 showSMSResult(result){
  const pending=['queued','dispatch_claimed','sending','provider_accepted','uncertain'].includes(result.state)
  if(!pending)this.smsPending=null
  this.setData({smsDialog:{state:pending?'pending':'result',notice:labels[result.state]||'正在核对短信状态',fee:money(result.fee_cents)},smsBusy:false,smsAnchor:pending?this.data.messages.filter(m=>m.isMine&&!m.isPlaceholder).slice(-1)[0]?.messageId||'':'',smsFirstHint:false})
 },
 async checkSMS(){
  if(!this.smsPending||this.data.smsBusy)return
  const life=this.life,epoch=this.manager.epoch,pending=this.smsPending;let missing=false;this.setData({smsBusy:true})
  try{
   const result=await this.manager.io.sms('status',{client_request_id:pending.request})
   if(!this.current(life,epoch)||this.smsPending!==pending)return
   if(result.state==='not_found'){
    missing=true
    // Replay only the original user-confirmed operation; this cannot mint a second charge.
    const replay=await this.manager.io.sms('create',pending.payload)
    if(this.current(life,epoch)&&this.smsPending===pending)this.showSMSResult(replay)
   }else this.showSMSResult(result)
  }catch(error){if(this.current(life,epoch)){
   if(missing&&['CHAT_SMS_PRICE_CHANGED','CHAT_SMS_INELIGIBLE','CHAT_SMS_BALANCE_INSUFFICIENT','CHAT_SMS_RATE_LIMIT'].includes(error.code)){this.smsPending=null;await this.refreshSMS(true);if(this.smsQuote?.items?.length)this.showSMSDialog(this.smsQuote);else this.setData({smsDialog:{state:'result',notice:'当前确认已失效，请重新查看提醒条件后再确认。'}})}
   else this.setData({smsDialog:{state:'pending',notice:error.message||'暂时无法核对，请稍后再试；不会新建另一条短信。'}})
  }}
  finally{if(this.current(life,epoch))this.setData({smsBusy:false})}
 }
}
