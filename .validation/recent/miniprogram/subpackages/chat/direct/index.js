const Api = require('../../../utils/OrderApi.js')
const Runtime = require('../../../utils/ChatRuntime.js')
const { validText,compare } = require('../../../utils/ChatManager.js')
const { createWxPendingLedger } = require('../../../utils/ChatPendingLedger.js')
const { directImages } = require('./images.js')
const OrderCard = require('../../../utils/ChatOrderCard.js')
const Inbox = require('../../../utils/ChatInboxModel.js')
const Presentation = require('../../../utils/ChatPresentation.js')
const {quoteText, sceneReferences} = require('../references.js')
const chatAvatars={user:'/images/chat-user.svg',worker:'/images/chat-worker.svg',platform:'/images/chat-platform.svg'}
const actorID=value=>typeof value==='string'&&/^[1-9][0-9]{0,19}$/.test(value)
const avatarFor=roles=>roles.includes('worker')?chatAvatars.worker:roles.includes('admin')?chatAvatars.platform:chatAvatars.user
const directInboxSummary = unread => {
  const summary=Inbox.directSummary(unread)
  return summary?{...summary,activityLabel:Presentation.timeLabel(summary.activityAt)}:null
}
const directSceneMeta = entry => {
  if (!entry) return ''
  const roles=(entry.peer?.roles||[]).map(role=>role==='worker'?'师傅':role==='admin'?'管理员':'').filter(Boolean)
  const status=entry.blocked?'暂不可发送':'普通私聊'
  return [...roles,status].filter(Boolean).join(' · ')
}
const empty = {items:[],messages:[],conversation:'',entry:null,imagesEnabled:false,imageItems:[],imageState:'',imageNotice:'',
  next:'',earlier:'',cursor:'',changeCursor:'',draft:'',notice:'',error:'',busy:false,pending:false,canForget:false,canSend:false,ready:false,preferences:null,directState:null,
  filter:'active',replyTo:'',replyPreview:'',messageMenu:null,showTools:false,showSettings:false,showInboxMenu:false,sceneMeta:'',inboxSummaryStale:false}
const api = async (route,body) => (await Api.request('chat.direct.'+route,'POST',body)).data

Page({
  ...directImages,
  ...sceneReferences,
  data: {...empty,inbox:true},
  onLoad(options) {
    this.manager=Runtime.get()
    this.imageUploads=new Set()
    this.selectionSerial=0
    this.serial=0
    this.target=options.id?{relationship:String(options.id)}:options.target?{target:String(options.target),source:options.source==='worker'?'worker':'public_user'}:null
    this.textLedger=createWxPendingLedger(wx,'chat-direct-text-v1')
    this.imageLedger=createWxPendingLedger(wx,'chat-direct-images-v1')
    this.leave=this.manager.subscribe((type,payload)=>{
      if(type==='reset'||type==='availability'&&!payload.enabled)this.closeView()
    })
    this.setData({inbox:!this.target})
  },
  onShow() {
    this.visible=true
    this.identity=this.manager.identityEpoch
    this.deep=false
    this.sceneLatestMounted=false
    this.sceneAtBottom=false
    this.sceneReadCursor=''
    this.sceneReadJob=null
    this.sceneReadAgain=false
    this.sceneScrollTop=0
    this.resetSceneImages()
    ++this.serial
    this.refresh().finally(()=>this.schedule(this.serial))
  },
  onHide() {
    this.visible=false
    ++this.serial
    this.loading=false
    clearTimeout(this.timer)
    this.stopImageUploads()
    this.closeMessageMenu()
    this.resetSceneImages()
    this.sceneAtBottom=false
    this.sceneReadJob=null
    this.setData({...empty,inbox:!this.target})
  },
  onUnload() {
    this.unloaded=true
    this.onHide()
    this.leave?.()
  },
  onPullDownRefresh() { this.refresh().finally(()=>wx.stopPullDownRefresh()) },
  active(serial) { return this.visible && serial===this.serial && this.manager.identityEpoch===this.identity },
  closeView() {
    ++this.serial
    this.loading=false
    this.selectionSerial++
    this.imageSelection=null
    clearTimeout(this.timer)
    this.stopImageUploads()
    this.resetSceneImages()
    this.textLedger?.unbind()
    this.imageLedger?.unbind()
    if(this.key)this.manager.directImageAttempts?.delete(this.key)
    this.setData({...empty,inbox:!this.target,error:'当前私聊暂不可用，请返回消息页重新进入。'})
  },
  schedule(serial) {
    clearTimeout(this.timer)
    if(!this.active(serial))return
    this.timer=setTimeout(async ()=>{
      if(!this.active(serial))return
      if(!this.data.busy){
        if(this.deep&&this.data.conversation)await this.checkVisibleAccess(serial)
        else await this.refresh()
      }
      if(this.active(serial)&&this.data.conversation)await this.refreshImageState(serial)
      this.schedule(serial)
    },9000)
  },
  failure(serial,err) {
    if(!this.active(serial))return
    if(['CHAT_DIRECT_DISABLED','CHAT_UNAUTHORIZED','CHAT_PERMISSION_DENIED','CHAT_FORBIDDEN'].includes(err.code)) {
      this.closeView()
      return
    }
    this.setData({error:err.code==='CHAT_DIRECT_DAILY_LIMIT'?'今日主动联系新联系人次数已用完，明日恢复。':
      err.code==='CHAT_DIRECT_STRANGER_LIMIT'?'已发送 3 条消息，等待对方回复后可继续发送。':'操作暂未完成，请刷新后重试。'})
  },
  async http(serial,path,body) {
    const epoch=this.manager.epoch
    const token=await this.manager.credential(epoch)
    if(!this.active(serial)||!this.manager.live(epoch)||this.manager.simulation||this.manager.testSandbox)throw new Error('context changed')
    const result=await this.manager.io.http(path,body,token,'direct')
    if(!this.active(serial)||!this.manager.live(epoch))throw new Error('context changed')
    return result
  },
  async checkVisibleAccess(serial) {
    try{
      if(this.data.changeCursor){
        const page=await this.http(serial,'messages/changes',{conversation_id:this.data.conversation,cursor:this.data.changeCursor,limit:50})
        if(!this.active(serial))return
        const changes=new Map((page.changes||[]).map(change=>[String(change.message?.message_id||''),change.message]))
        const messages=this.data.messages.map(row=>{
          const update=changes.get(String(row.message_id))
          if(!update||update.state==='published')return row
          return {...row,state:update.state,version:String(update.state_version||row.version||''),canRecall:false,text:update.state==='recalled'?'消息已撤回':'此消息已不可查看',images:[],order:null}
        })
        this.setData({messages,changeCursor:String(page.change_cursor||this.data.changeCursor)})
        this.refreshSceneImageObserver()
      }else await this.http(serial,'conversations/unread',{conversation_id:this.data.conversation})
    }catch(err){
      if(!this.active(serial))return
      if(err.code==='CHAT_RESYNC_REQUIRED'){this.deep=false;await this.refresh()}
      else if(['CHAT_UNAUTHORIZED','CHAT_FORBIDDEN','CHAT_PERMISSION_DENIED','CHAT_DIRECT_DISABLED'].includes(err.code))this.failure(serial,err)
    }
  },
  async refresh(append=false) {
    if(!this.visible||this.loading)return
    const serial=this.serial
    this.loading=true
    try {
      if(!this.target) {
        const page=await api('inbox',{after:append?this.data.next:'0',filter:this.data.filter})
        if(!this.active(serial))return
        const prior=new Map(this.data.items.map(row=>[String(row.relationship||''),row]))
        const ids=(page.items||[]).map(row=>String(row.relationship||'')).filter(id=>/^[1-9][0-9]{0,19}$/.test(id))
        const summaries=new Map()
        let summaryStale=false,summaryFetched=ids.length===0
        if(ids.length){
          try{
            const result=await this.http(serial,'direct/summaries',{conversation_ids:ids})
            summaryFetched=true
            for(const item of (result?.items||[])){
              const id=String(item?.conversation_id||''),summary=directInboxSummary(item?.unread)
              if(summary&&ids.includes(id))summaries.set(id,summary)
            }
          }catch(err){
            if(!this.active(serial))return
            if(['CHAT_UNAUTHORIZED','CHAT_FORBIDDEN','CHAT_PERMISSION_DENIED','CHAT_DIRECT_DISABLED'].includes(err.code))throw err
            summaryStale=true
          }
        }
        const rows=new Map((append?this.data.items:[]).map(row=>[row.relationship,row]))
        for(const row of (page.items||[])){
          if(!row.relationship)continue
          const id=String(row.relationship)
          const summary=summaryFetched?(summaries.get(id)||null):(prior.get(id)?.summary||null)
          rows.set(row.relationship,{...row,summary})
        }
        this.setData({items:[...rows.values()],next:page.next,ready:true,error:'',inboxSummaryStale:summaryStale})
      } else {
        const entry=await api('entry',this.target)
        if(!this.active(serial))return
        this.setData({entry,ready:true,conversation:entry.relationship||'',sceneMeta:directSceneMeta(entry),error:''})
        wx.setNavigationBarTitle({title:entry.peer?.display_name||'普通私聊'})
        if(!entry.ready) {
          this.setData({messages:[],imagesEnabled:false,canSend:false,directState:null,notice:'正在建立私聊会话，请稍后。'})
          return
        }
        const id=entry.relationship
        const request={conversation_id:id,limit:30}
        if(append)request.cursor=this.data.earlier
        const page=await this.http(serial,append?'messages/history':'conversations/bootstrap',request)
        if(page.conversation_type!=='direct'){this.closeView();return}
        this.key='direct:'+id
        this.textLedger?.bind(this.manager.io.environment(),this.manager.calls.actorID)
        this.imageLedger?.bind(this.manager.io.environment(),this.manager.calls.actorID)
        if(!this.manager.directAttempts)this.manager.directAttempts=new Map()
        let row=this.manager.directAttempts.get(this.key)
        if(!row){
          const record=this.textLedger?.list().find(value=>value.conversation_id===id)
          if(record){row={client:record.client_message_id,text:'',submitted:true};this.manager.directAttempts.set(this.key,row)}
        }
        const messages=new Map((append?this.data.messages:[]).map(msg=>[msg.message_id,msg]))
        for(const msg of page.messages) {
          const own=msg.sender_id===page.viewer_actor_id
          const roles=Array.isArray(msg.display?.roles)?msg.display.roles:(!own&&Array.isArray(entry.peer?.roles)?entry.peer.roles:[])
          messages.set(msg.message_id,{
            message_id:msg.message_id,sender_id:String(msg.sender_id||''),seq:msg.seq,version:String(msg.state_version||''),own,author:own?'我':msg.display?.name||entry.peer.display_name,
            roles,avatarPath:avatarFor(roles),state:msg.state,canRecall:own&&msg.state==='published'&&Date.now()<Date.parse(msg.created_at)+120000,
            text:msg.state==='published'?msg.text||'':'消息已不可查看',images:msg.state==='published'?msg.images||[]:[],
            order:msg.state==='published'?OrderCard.parse(msg.text):null,reply:msg.reply,
            quote:msg.reply?quoteText(msg.reply):''
          })
        }
        const visibleMessages=[...messages.values()].sort((a,b)=>compare(a.seq,b.seq))
        this.setData({messages:visibleMessages,imagesEnabled:page.capabilities?.images===true,
          ...(!this.data.pending&&row?{pending:true,draft:row.text}:{}),
          canSend:page.can_send&&!entry.blocked,directState:page.direct_state||null,preferences:page.preferences||null,
          earlier:page.history_cursor||'',cursor:append?this.data.cursor:page.sync_cursor||'',
          changeCursor:append?this.data.changeCursor:String(page.change_cursor||'')})
        this.consumeImageSelection()
        const imageRow=this.imageRows().get(this.key)
        if(!imageRow||!this.data.imageState)await this.refreshImageState(serial,true)
        this.afterSceneRefresh(visibleMessages,append)
        this.refreshSceneImageObserver()
      }
      this.deep=append
    } catch(err){this.failure(serial,err)}
    finally {if(this.active(serial))this.loading=false}
  },
  more(){this.sceneAtBottom=false;this.refresh(true)},
  filter(event){
    if(this.loading)return
    const filter=String(event.currentTarget.dataset.filter||'')
    if(!['active','archived','hidden'].includes(filter))return
    this.setData({items:[],next:'',filter,showInboxMenu:false})
    this.refresh()
  },
  async preference(event){
    const prefs=this.data.preferences,field=String(event.currentTarget.dataset.field||''),serial=this.serial
    if(this.data.busy||!prefs||!['pinned','later','hidden','archived','muted'].includes(field))return
    const expected=['pinned','later'].includes(field)?prefs[field+'_version']:prefs.option_version
    this.setData({busy:true})
    try{
      const result=await this.http(serial,'conversations/preference',{conversation_id:this.data.conversation,member_version:prefs.member_version,
        field,value:!prefs[field],expected_version:expected})
      this.setData({preferences:result})
      this.manager.refreshAccount()
    }catch(err){this.failure(serial,err)}
    finally{if(this.active(serial))this.setData({busy:false})}
  },
  open(event){
    const id=event.currentTarget.dataset.id
    if(!id)return
    this.closeSceneInboxMenu()
    wx.navigateTo({url:'/subpackages/chat/direct/index?id='+id})
  },
  openUserProfile(event){
    const id=String(event.currentTarget.dataset.message||''),row=this.data.messages.find(item=>item.message_id===id)
    const conversation=String(this.data.conversation||'')
    if(!row||!actorID(row.sender_id)||!actorID(conversation))return
    this.closeMessageMenu()
    wx.navigateTo({url:'/subpackages/chat/participant/index?conversation_id='+encodeURIComponent(conversation)+'&actor_id='+encodeURIComponent(row.sender_id)})
  },
  async transition(event) {
    const command=String(event.currentTarget.dataset.cmd||'')
    if(this.data.busy||!this.data.entry||!['block','unblock'].includes(command))return
    const serial=this.serial
    if(command==='block'){
      const confirmed=await new Promise(resolve=>wx.showModal({
        title:'拉黑此用户？',
        content:'拉黑后双方暂不可继续发送消息，你仍可在会话设置中解除拉黑。',
        confirmText:'确认拉黑',
        confirmColor:'#b34d45',
        success:result=>resolve(result.confirm===true),
        fail:()=>resolve(false)
      }))
      if(!confirmed||!this.active(serial))return
    }
    this.setData({busy:true})
    try {
      const entry=await api('transition',{...this.target,transition:command,expected_version:this.data.entry.version})
      if(!this.active(serial))return
      this.setData({entry,sceneMeta:directSceneMeta(entry)})
      await this.refresh()
    } catch(err) {
      if(err.code==='CHAT_VERSION_CONFLICT')await this.refresh()
      else this.failure(serial,err)
    } finally {if(this.active(serial))this.setData({busy:false})}
  },
  input(event){const draft=event.detail.value;this.setData({draft,...(draft?{showTools:false}:{})})},
  async ensureConversation() {return this.data.conversation},
  async send() {
    if(this.data.busy||!this.key||(!this.data.canSend&&!this.data.pending))return
    const serial=this.serial,id=this.data.conversation
    let row=this.manager.directAttempts.get(this.key)
    if(!row&&!validText(this.data.draft)){this.setData({notice:'请填写消息内容。'});return}
    this.setData({busy:true})
    try {
      if(!row) {
        const text=this.data.draft,client=await this.manager.clientID()
        if(!this.active(serial))return
        row={client,text,reply:this.data.replyTo||'',submitted:false};this.manager.directAttempts.set(this.key,row);this.setData({pending:true})
      }
      const request={conversation_id:id,client_message_id:row.client}
      let result
      if(row.submitted) {
        try {result=await this.http(serial,'messages/result',request)}
        catch(err){if(err.code!=='CHAT_SEND_RESULT_UNKNOWN')throw err}
      }
      if(!result||result.state==='retryable'||result.state==='checking'&&Date.now()-(row.sentAt||0)>=10000&&row.text) {
        if(!row.text||!this.data.canSend){this.setData({notice:'暂无已发送记录。',canForget:true});return}
        row.submitted=true
        row.sentAt=Date.now()
        this.textLedger?.put(request)
        result=await this.http(serial,'messages/send',{...request,message_type:'text',text:row.text,...(row.reply?{reply_to_message_id:row.reply}:{})})
      }
      if(result.client_message_id!==row.client)throw new Error('unexpected result')
      if(['accepted','rejected'].includes(result.state)){
        this.textLedger?.remove(row.client)
        this.manager.directAttempts.delete(this.key)
        const notice=result.state==='accepted'?'已发送。':
          result.code==='CHAT_REFERENCE_UNAVAILABLE'?'引用消息已不可用，已保留输入文字。':
          result.code==='CHAT_DIRECT_STRANGER_LIMIT'?'已发送 3 条消息，等待对方回复后可继续发送。':
          result.code==='CHAT_DIRECT_DAILY_LIMIT'?'今日主动联系新联系人次数已用完，明日恢复。':'消息未通过审核。'
        this.setData({pending:false,draft:result.state==='accepted'?'':row.text,canForget:false,replyTo:'',replyPreview:'',notice})
        if(result.state==='accepted'){await this.refresh();this.manager.refreshAccount()}
      } else this.setData({notice:'发送结果待确认，请稍后查询。'})
    } catch(err){
      if(this.active(serial)&&['CHAT_SEND_RESTRICTED','CHAT_DIRECT_STRANGER_LIMIT','CHAT_DIRECT_DAILY_LIMIT','CHAT_ACTION_EXPIRED','CHAT_REFERENCE_UNAVAILABLE'].includes(err.code)){
        if(row)this.textLedger?.remove(row.client)
        this.manager.directAttempts.delete(this.key)
        this.setData({pending:false,canForget:false,replyTo:'',replyPreview:'',
          notice:err.code==='CHAT_REFERENCE_UNAVAILABLE'?'引用消息已不可用，已保留输入文字。':
            err.code==='CHAT_DIRECT_STRANGER_LIMIT'?'已发送 3 条消息，等待对方回复后可继续发送。':
            err.code==='CHAT_DIRECT_DAILY_LIMIT'?'今日主动联系新联系人次数已用完，明日恢复。':'当前暂不可继续发送。'})
      } else this.failure(serial,err)
    } finally {if(this.active(serial))this.setData({busy:false})}
  },
  async report(event){
    const serial=this.serial,id=event.currentTarget.dataset.message
    const choice=await new Promise(resolve=>wx.showActionSheet({itemList:['骚扰或辱骂','垃圾信息','不安全内容'],success:r=>resolve(r.tapIndex),fail:()=>resolve(-1)}))
    if(!this.active(serial)||choice<0)return
    try{
      await this.http(serial,'direct/report',{conversation_id:this.data.conversation,message_id:id,reason:['abuse','spam','unsafe'][choice]})
      wx.showToast({title:'已提交举报',icon:'none'})
    }catch(err){this.failure(serial,err)}
  },
  async shareOrder(){
    if(this.data.directState?.established!==true||!this.data.canSend||this.data.busy||this.data.pending)return
    const serial=this.serial
    try{
      const result=await Api.request('orders.center','GET',{kind:'mine',status:'all',page:1})
      if(!this.active(serial))return
      const cards=[]
      for(const item of (result.data?.items||[]).slice(0,8)){
        try{
          const card=await api('card',{relationship:this.data.conversation,order:item.public_no})
          if(!this.active(serial))return
          cards.push(card)
        }catch(err){if(!this.active(serial))return;if(err.code!=='CHAT_FORBIDDEN')throw err}
      }
      if(!cards.length){this.setData({notice:'暂无双方可查看的近期订单。'});return}
      const index=await new Promise(resolve=>wx.showActionSheet({itemList:cards.map(card=>'订单 '+card.public_no),success:r=>resolve(r.tapIndex),fail:()=>resolve(-1)}))
      if(index>=0&&this.active(serial))this.setData({draft:OrderCard.encode(cards[index]),notice:'已添加订单卡片，点击发送确认。',showTools:false})
    }catch(err){this.failure(serial,err)}
  },
  async openOrder(event){
    const serial=this.serial,id=event.currentTarget.dataset.message
    const order=this.data.messages.find(msg=>msg.message_id===id)?.order
    if(!order)return
    try{
      const card=await api('card',{relationship:this.data.conversation,order:order.publicNo})
      if(!this.active(serial))return
      const url=Inbox.orderURL({publicNo:card.public_no,role:card.role})
      if(url)wx.navigateTo({url})
    }catch(err){if(this.active(serial))this.setData({notice:'此订单当前不可查看。'})}
  },
  forget() {
    if(this.data.busy||!this.data.canForget)return
    const row=this.manager.directAttempts.get(this.key)
    if(row)this.textLedger?.remove(row.client)
    this.manager.directAttempts.delete(this.key)
    this.setData({pending:false,canForget:false,notice:'待确认记录已清除。'})
  },
})