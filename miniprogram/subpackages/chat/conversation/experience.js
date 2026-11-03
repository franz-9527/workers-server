// Transient UI only. All reads/writes still pass the shared authenticated manager.
const Identity=require('../../../utils/ChatIdentity.js')
module.exports={
 closeMessageMenu(){this.menuActions=null;this.setData({messageMenu:null})},
 messageActions(event){
  const id=String(event.currentTarget.dataset.id||event.currentTarget.dataset.message||''),row=this.views[id]
  if(!this.visible||!row||this.data.status==='denied')return
  const life=this.life,epoch=this.manager.epoch,version=row.stateVersion,actions=[]
  const add=(key,label,run)=>actions.push({key,label,run})
  if(row.canCopy)add('copy','复制',()=>wx.setClipboardData({data:row.body}))
  if((row.canCopy||row.isImage||row.isAV)&&this.data.canSend)add('reply','回复',()=>{
   const item=String(event.currentTarget.dataset.item||'')
   if(row.isImage&&item){const draft=this.manager.draft(this.id);this.manager.saveDraft(this.id,draft.text,draft.revision,{...(draft.source||{}),reply_to_message_id:id,reply_to_item_id:item});this.refreshComposer()}
   else this.replyTo(id)
  })
  const age=this.manager.io.now()-Date.parse(row.createdAt)
  if(row.isMine&&(row.canCopy||row.isImage||row.isAV)&&age>=0&&age<=120000)add('recall','撤回',()=>this.recallMessage(id))
  if(row.canReedit&&this.data.canSend)add('edit','重新编辑',()=>this.reedit(id))
  if(!this.manager.simulation)add('report','举报',()=>row.isImage&&event.currentTarget.dataset.item?this.reportImage(id,String(event.currentTarget.dataset.item)):this.openReport(id,''))
  add('time','时间',()=>wx.showModal({title:'消息时间',content:row.createdAt||'时间暂不可用',showCancel:false}))
  this.presentMessageMenu(event,actions,()=>this.current(life,epoch)&&this.views[id]?.stateVersion===version,'#message-'+id+' .message-bubble')
 },
 presentMessageMenu(event,actions,guard,selector){
  const life=this.life,epoch=this.manager.epoch
  const show=rect=>{
   if(!guard())return
   const window=typeof wx.getWindowInfo==='function'?wx.getWindowInfo():wx.getSystemInfoSync()
   const width=Math.min(300,window.windowWidth-24),height=actions.length>4?100:54,viewportHeight=window.windowHeight-(this.data.keyboardHeight||0)
   const point=event.touches?.[0]||event.changedTouches?.[0]||{}
   const y=rect?.top??point.clientY??120,x=rect?.left??point.clientX??12
   this.menuActions={guard,life,epoch,actions}
   this.setData({messageMenu:{top:Math.max(8,Math.min(y>=height+12?y-height-8:(rect?.bottom??y)+8,viewportHeight-height-8)),left:Math.max(12,Math.min(x,window.windowWidth-width-12)),actions:actions.map(({key,label})=>({key,label}))}})
  }
  if(selector&&typeof wx.createSelectorQuery==='function')wx.createSelectorQuery().in(this).select(selector).boundingClientRect().exec(r=>show(r?.[0]))
  else show(null)
 },
 runMessageAction(event){
  const menu=this.menuActions,key=event.currentTarget.dataset.key;this.closeMessageMenu()
  if(!menu||!this.current(menu.life,menu.epoch)||!menu.guard())return
  menu.actions.find(a=>a.key===key)?.run()
 },
 stopReceiptPoll(){if(this.receiptTimer)clearTimeout(this.receiptTimer);this.receiptTimer=null;this.receiptGeneration=(this.receiptGeneration||0)+1;this.receiptRunning=false},
 startReceiptPoll(){
  if(this.receiptTimer||this.receiptRunning||!this.visible||this.manager.simulation)return
  const life=this.life,epoch=this.manager.epoch,generation=this.receiptGeneration||0
  const tick=async()=>{
   this.receiptTimer=null
   if(!this.current(life,epoch)||generation!==(this.receiptGeneration||0))return
   this.receiptRunning=true
   try{
    // Only acknowledge a completely synchronized, actually visible latest window.
    const target=this.manager.readTarget(this.id)
    if(this.atBottom&&this.mode==='latest'&&!this.data.windowBusy&&this.initialized&&target&&target.seq!==this.lastVisibleRead){
     await this.manager.acknowledge(this.id,target)
     if(!this.current(life,epoch))return
     this.lastVisibleRead=target.seq;this.refreshUnread()
    }
    const rows=this.data.messages.filter(r=>r.isMine&&r.state==='published').slice(-3),labels={...this.data.receiptLabels}
    for(const row of rows){
     const counts=await this.manager.http('conversation.readers',{conversation_id:this.id,message_id:row.messageId},epoch)
     if(!this.current(life,epoch)||generation!==(this.receiptGeneration||0))return
     labels[row.messageId]=this.data.isGroup?counts.read_count+'人已读 · '+counts.unread_count+'人未读':counts.read_count>0?'已读':counts.unread_count>0?'未读':'暂无可查看成员'
    }
    if(this.current(life,epoch))this.setData({receiptLabels:labels})
   }catch(_){/* No inferred read receipts when the server cannot confirm. */}
   finally{
    this.receiptRunning=false
    if(this.current(life,epoch)&&generation===(this.receiptGeneration||0))this.receiptTimer=setTimeout(tick,10000)
   }
  }
  this.receiptTimer=setTimeout(tick,500)
 },
 async shareOrder(){
  if(!this.data.canSend||this.sharingOrder)return
  const life=this.life,epoch=this.manager.epoch;this.sharingOrder=true
  try{
   const context=this.data.orderContext
   const candidates=context&&!context.simulation?[context]:[]
   const api=require('../../../utils/OrderApi.js')
   const kind=context?.role==='worker'?'worker':'mine'
   let pageNumber=1
   let result
   try{result=await api.request('orders.center','GET',{kind,status:'all',page:pageNumber})}catch(error){if(!candidates.length)throw error;result={data:{items:[],has_more:false}}}
   if(!this.current(life,epoch))return
   for(const value of result.data?.items||[])if(value.public_no&&!candidates.some(v=>(v.publicNo||v.public_no)===value.public_no))candidates.push(value)
   if(!candidates.length){this.setData({notice:'当前身份暂无可分享的订单，请先创建测试订单'});return}
   let offset=0,card
   while(!card){
    const page=candidates.slice(offset,offset+5),more=offset+5<candidates.length||result.data?.has_more===true
    const selection=await new Promise((resolve,reject)=>wx.showActionSheet({itemList:page.map(v=>Array.from(v.title||v.publicNo||v.public_no).slice(0,24).join('')).concat(more?['更多订单']:[]),success:resolve,fail:reject}))
    if(!this.current(life,epoch))return
    if(selection.tapIndex===page.length&&more){
     offset+=page.length
     if(offset>=candidates.length&&result.data?.has_more===true){
      result=await api.request('orders.center','GET',{kind,status:'all',page:++pageNumber})
      if(!this.current(life,epoch))return
      for(const value of result.data?.items||[])if(value.public_no&&!candidates.some(v=>(v.publicNo||v.public_no)===value.public_no))candidates.push(value)
      if(offset>=candidates.length){this.setData({notice:'暂无更多可分享订单，请重新选择'});return}
     }
     continue
    }
    card=page[selection.tapIndex];if(!card)return
   }
   const confirmation=await new Promise((resolve,reject)=>wx.showModal({title:'发送订单',content:'发送“'+(card.title||card.publicNo||card.public_no)+'”到当前会话？',success:resolve,fail:reject}))
   if(!confirmation.confirm||!this.current(life,epoch))return
   const text=require('../../../utils/ChatOrderCard.js').encode(card)
   // Separate share action leaves the user's typed draft and reply untouched.
   const draft=this.manager.draft(this.id)
   await this.manager.sendText(this.id,text,draft.revision)
   if(this.current(life,epoch))this.refreshComposer()
  }catch(error){if(this.current(life,epoch)&&!String(error.errMsg||'').includes('cancel'))this.showError(error)}
  finally{this.sharingOrder=false}
 },
 openSharedOrder(event){
  const row=this.views[String(event.currentTarget.dataset.id||'')]
  const card=row?.orderCard
  if(!card)return
  const role=this.data.orderContext?.role||'customer'
  const url=require('../../../utils/ChatInboxModel.js').orderURL({...card,role,simulation:false})
  if(url)wx.navigateTo({url})
 }
}
