const Runtime=require('../../../utils/ChatRuntime.js')
const Api=require('../../../utils/OrderApi.js')
const Inbox=require('../../../utils/ChatInboxModel.js')
const Presentation=require('../../../utils/ChatPresentation.js')

Page({
 data:{ready:false,error:'',filter:'all',items:[],next:'',pendingCount:0,attentionCount:0,showMenu:false,loading:false},
 onLoad(){this.manager=Runtime.get();this.serial=0},
 onShow(){this.visible=true;this.start()},
 onHide(){this.visible=false;this.serial++;this.loading=false;this.setData({showMenu:false})},
 onUnload(){this.visible=false;this.serial++},
 onPullDownRefresh(){this.start().finally(()=>wx.stopPullDownRefresh())},
 active(serial){return this.visible&&serial===this.serial&&this.identity===this.manager.identityEpoch},
 async start(){
  const serial=++this.serial;this.identity=this.manager.identityEpoch
  this.setData({ready:false,error:'',items:[],next:'',pendingCount:0,attentionCount:0})
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
   for(const row of page.items||[]){
    const raw=String(row.owner_name||'').trim()
    const ownerName=raw&&!/[\u0000-\u001f\u007f<>]/.test(raw)?Array.from(raw).slice(0,40).join(''):('反馈用户 · '+String(row.owner_suffix||''))
    rows.set(String(row.conversation_id),{...row,ownerName,preview:row.last_user_message?Inbox.previewText(row.last_user_message):(row.handled?'已处理，点击查看反馈记录':'等待处理，点击查看反馈'),activityLabel:Presentation.timeLabel(row.last_user_message_at)})
   }
   this.setData({items:[...rows.values()],next:page.next_cursor||'',pendingCount:page.pending_count||0,attentionCount:page.attention_count||0,error:''})
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
