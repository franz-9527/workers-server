const Runtime=require('../../../utils/ChatRuntime.js')
const Api=require('../../../utils/OrderApi.js')
const Inbox=require('../../../utils/ChatInboxModel.js')
const Presentation=require('../../../utils/ChatPresentation.js')

Page({
 data:{ready:false,error:'',filter:'all',items:[],next:'',pendingCount:0,attentionCount:0,statsPartial:false,showMenu:false,loading:false},
 onLoad(){this.manager=Runtime.get();this.serial=0},
 onShow(){this.visible=true;this.start()},
 onHide(){this.visible=false;this.serial++;this.loading=false;this.setData({showMenu:false})},
 onUnload(){this.visible=false;this.serial++},
 onPullDownRefresh(){this.start().finally(()=>wx.stopPullDownRefresh())},
 active(serial){return this.visible&&serial===this.serial&&this.identity===this.manager.identityEpoch},
 async start(){
  const serial=++this.serial;this.identity=this.manager.identityEpoch
  // A refresh supersedes the previous generation, including its loading lock.
  this.loading=false
  this.setData({ready:false,error:'',items:[],next:'',pendingCount:0,attentionCount:0,statsPartial:false,loading:false})
  if(!this.manager.enabled){this.setData({error:'沟通服务暂不可用，请返回消息页重试。'});return}
  if(this.manager.simulation||this.manager.testSandbox){this.setData({error:'当前测试身份不进入真实用户反馈。'});return}
  try{
   const result=await Api.request('chat.support.entry','POST',{})
   if(!this.active(serial))return
   const entry=result.data
   if(!entry?.enabled){this.setData({error:'平台反馈助手已暂停。'});return}
   if(!entry.staff){this.setData({error:'当前账号没有反馈处理权限。'});return}
   this.setData({ready:true})
   await this.refresh(false,serial)
  }catch(_){if(this.active(serial))this.setData({error:'暂时无法读取用户反馈，请下拉重试。'})}
 },
 async http(serial,path,data){
  const epoch=this.manager.epoch,token=await this.manager.credential(epoch)
  if(!this.active(serial)||!this.manager.live(epoch))throw new Error('context changed')
  const result=await this.manager.io.http(path,data,token,'support')
  if(!this.active(serial)||!this.manager.live(epoch))throw new Error('context changed')
  return result
 },
 async refresh(append=false,serial=this.serial){
  if(this.loading||!this.active(serial))return
  this.loading=true;this.setData({loading:true})
  try{
   const page=await this.http(serial,'support/inbox',{limit:20,cursor:append?this.data.next:'',filter:this.data.filter})
   if(!this.active(serial))return
   const rows=new Map((append?this.data.items:[]).map(row=>[String(row.conversation_id),row]))
   const unreadComplete=page.unread_complete!==false
   for(const row of page.items||[]){
    const raw=String(row.owner_name||'').trim()
    const ownerName=raw&&!/[\u0000-\u001f\u007f<>]/.test(raw)?Array.from(raw).slice(0,40).join(''):('反馈用户 · '+String(row.owner_suffix||''))
    const prior=rows.get(String(row.conversation_id))
    const merged={...row,ownerName,avatarPath:require('../../../utils/ChatIdentity.js').avatarPath(row.owner_avatar_ref,row.owner_role==='worker'?'worker':'user'),preview:row.last_user_message?Inbox.previewText(row.last_user_message):(row.handled?'已处理，点击查看反馈记录':'等待处理，点击查看反馈'),activityLabel:Presentation.timeLabel(row.last_user_message_at)}
    if(!unreadComplete&&prior){
      merged.unread_count=prior.unread_count
      merged.unread_count_capped=prior.unread_count_capped
    }
    rows.set(String(row.conversation_id),merged)
   }
   if(page.identity_complete===false)console.warn('[反馈] 用户身份展示已降级',String(page.identity_warning||'CHAT_SUPPORT_INBOX_IDENTITY_DISPLAY_UNAVAILABLE'))
   if(page.preview_complete===false)console.warn('[反馈] 摘要展示已降级',String(page.preview_warning||'CHAT_SUPPORT_INBOX_PREVIEW_UNAVAILABLE'))
   if(!unreadComplete)console.warn('[反馈] 未读数暂未完整同步',String(page.unread_warning||'CHAT_SUPPORT_INBOX_UNREAD_UNAVAILABLE'))
   const patch={items:[...rows.values()],next:page.next_cursor||'',error:''}
   if(!append){
    patch.pendingCount=Number(page.pending_count)||0
    const complete=page.attention_count_complete!==false
    patch.statsPartial=!complete
    if(complete)patch.attentionCount=Number(page.attention_count)||0
    const warning=complete?'':String(page.attention_warning_detail||page.attention_warning||'CHAT_SUPPORT_INBOX_ATTENTION_PARTIAL')
    if(warning&&warning!==this.attentionWarning)console.warn('[反馈] 注意项统计暂未完整同步',warning)
    this.attentionWarning=warning
   }
   this.setData(patch)
  }catch(_){if(this.active(serial))this.setData({error:'反馈列表暂未更新，请下拉重试。'})}
  finally{if(this.active(serial)){this.loading=false;this.setData({loading:false})}}
 },
 toggleMenu(){this.setData({showMenu:!this.data.showMenu})},
 closeMenu(){if(this.data.showMenu)this.setData({showMenu:false})},
 stopMenu(){},
 filterList(event){
  if(this.loading)return
  const filter=String(event.currentTarget.dataset.filter||'')
  if(!['all','unread','pending','archived','hidden'].includes(filter))return
  this.setData({filter,items:[],next:'',showMenu:false});this.refresh()
 },
 more(){this.refresh(true)},
 openItem(event){
  const row=this.data.items.find(item=>String(item.conversation_id)===String(event.currentTarget.dataset.id||''))
  if(!row)return
  wx.navigateTo({url:'/subpackages/chat/conversation/index?conversation_id='+encodeURIComponent(row.conversation_id)+'&scene=support&title='+encodeURIComponent(String(row.ownerName||'用户反馈').slice(0,24))})
 }
})
