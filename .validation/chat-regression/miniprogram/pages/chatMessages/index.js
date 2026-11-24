const NotificationState = require('../../utils/NotificationState.js')
const ChatRuntime = require('../../utils/ChatRuntime.js')
const InboxModel = require('../../utils/ChatInboxModel.js')
const Presentation = require('../../utils/ChatPresentation.js')
const ApiEnvironment = require('../../utils/ApiEnvironment.js')
const OrderApi = require('../../utils/OrderApi.js')
const SUPPORT_RETRY_BASE_MS = 60000
const SUPPORT_RETRY_MAX_MS = 300000

function identity(user) {
  const origin = ApiEnvironment.current() || {}
  return String(origin.baseURL || '') + '|' + String(user && user.openid || '') + '|' + String(user && user.mobile || '')
}
function supportBase(service,current=null) {
  if(!service || service.enabled!==true || service.available===false)return null
  const name=String(service.name||'平台反馈助手').trim().slice(0,40)||'平台反馈助手'
  return {...(current||{}),enabled:true,name}
}

Page({
  data: {
    checkingLogin: true,
    pageVisible: false,
    isLoggedIn: false,
    noticeUnreadCount: 0,
    todoCount: 0,
    importantCount: 0,
    topTodoText: '',
    topTodoRole: '',
    businessTone: 'normal',
    latestNotification: null,
    summaryReady: false,
    summaryError: false,
    auditAllowed:false,
    chatEnabled: false,
    supportEntry: null,
    directEnabled: false,
    directSummaryStale: false,
    directHasMore: false,
    onlineRows: {},
    chatSimulation: false,
    canSwitchChatScope:false,
    ordersOnly:false,
    chatStatus: 'disabled',
    chatAvailabilityReason:'',
    showInboxMenu:false,hasPlatformChats:false,chatFilter: 'all', chatRole:'all',listMore:false,listBefore:false,preferenceError:'',preferenceBusy:false,
    adminInboxMode:false,adminUserGroups:[],adminConversationCount:0,adminInboxError:'',supportInboxHasMore:false,supportInboxLoading:false,
    chatRows: [],
    visibleChatRows: [],
    chatHasMore: false,
    chatLoading: false
  },

  onLoad() {
    this.memberIdentities={};this.identitySerial=0;this.identityPending=false
    this.activeIdentity = ''
    this.digestMap = {}
    this.directMap = {}
    this.supportMap = {};this.supportNext='';this.adminExpandedUsers={};this.supportRetryAt=0;this.supportRetryFailures=0;this.supportLoadJob=null;this.supportLoadSerial=0;this.supportRefreshToken=null;this.supportRefreshQueued=false
    this.pendingDigests = {};this.listBoundary=null;this.candidateCount=0
    this._visible = false
    this._showSerial = 0
    this.chatManager = ChatRuntime.get()
    this.managerEpoch = this.chatManager.epoch
    this.stopNotifications = NotificationState.subscribe(summary => {
      if (this._visible && !this.data.checkingLogin && this.data.isLoggedIn && this.data.summaryReady) this.applySummary(summary)
    }, false)
    this.stopChat = this.chatManager.subscribe((type, payload) => this.onChatEvent(type, payload))
  },

  onShow() {
    ChatRuntime.refreshAvailability()
    this.stopSummaryPolling()
    this._visible = true
    const serial = ++this._showSerial
    if (this.managerEpoch !== this.chatManager.epoch) {
      this.managerEpoch = this.chatManager.epoch
      // 锁屏/切后台后的聊天传输重建不应清空已经授权展示的会话摘要。
      // 摘要只保留在当前小程序进程内；真正换账号/换站点时由下方 identity 比较立即清理。
      this.identitySerial++;this.identityPending=false
      if(this.auditClient)this.auditClient.clear()
    }
    if (this.getTabBar && this.getTabBar()) this.getTabBar().setData({ selected: 3 })
    const chatEnabled = this.chatManager.enabled === true
    this.setData({ chatSimulation:this.chatManager.simulation===true,auditAllowed:chatEnabled&&this.chatManager.auditAllowed===true,pageVisible: true, chatEnabled, chatStatus: chatEnabled ? this.chatManager.state : 'disabled', chatAvailabilityReason:this.chatManager.availabilityReason||'',supportEntry:chatEnabled&&!this.chatManager.ordersOnly?supportBase(this.chatManager.supportService,this.data.supportEntry):null, checkingLogin: !this.data.isLoggedIn })
    const app = getApp()
    const known = app.globalData.userInfo || {}
    const knownIdentity = known.openid ? identity(known) : ''
    if (this.activeIdentity && knownIdentity && this.activeIdentity !== knownIdentity) {
      this.activeIdentity = ''
      this.clearSensitive()
    }
    const verifiedReturn = this.data.isLoggedIn && !!known.openid && this.activeIdentity === knownIdentity
    const check = typeof app.ensureUserReady === 'function' ? app.ensureUserReady(false) : Promise.resolve(known)
    // Cloud profile refresh must not hold up the independently authenticated API.
    const ready = verifiedReturn ? Promise.resolve(known) : check
    if (verifiedReturn) check.then(user => {
      if (this._visible && serial === this._showSerial && identity(user) !== this.activeIdentity) this.onShow()
    }).catch(() => {})
    ready.then(user => {
      if (!this._visible || serial !== this._showSerial) return
      if (!user || !user.openid) {
        this.activeIdentity = ''
        this.clearSensitive()
        this.setData({ checkingLogin: false })
        return
      }
      const key = identity(user)
      if (key !== this.activeIdentity) {
        this.activeIdentity = key
        this.clearSensitive()
      }
      this.setData({ isLoggedIn: true, checkingLogin: false,canSwitchChatScope:this.chatManager.canSwitchChatScope===true,ordersOnly:this.chatManager.ordersOnly===true })
      this.refreshSummary(serial)
      this.loadSupportEntry(serial)
      this.loadDirectInbox(serial)
      this.scheduleOnlineRows(serial)
      this.scheduleSummaryRefresh(serial)
      // The shared S11 transport performs its own bounded, authenticated
      // recovery. Opening an inbox NEVER acknowledges any message as read.
      if (this.chatManager.enabled) {
        this.setData({ chatLoading: !this.data.chatRows.length })
        this.chatManager.refreshAccount()
        ChatRuntime.discoverCollaborations(key).then(changed=>{
          if(changed&&this._visible&&serial===this._showSerial&&key===this.activeIdentity&&this.chatManager.enabled)this.chatManager.refreshAccount()
        })
      }
    }).catch(() => {
      if (!this._visible || serial !== this._showSerial) return
      this.clearSensitive()
      this.setData({ checkingLogin: false, summaryError: true })
    })
  },

  onHide() {
    this.clearOnlineRows()
    this._visible=false
    this.identitySerial++;this.identityPending=false
    this.stopSummaryPolling()
    if(this.auditClient)this.auditClient.clear()
    this._visible = false
    ++this._showSerial
    this.supportRefreshToken=null;this.supportRefreshQueued=false
    this.setData({ pageVisible: false,preferenceBusy:false,showInboxMenu:false,supportInboxLoading:false })
  },

  onUnload() {
    this.clearOnlineRows()
    this.identitySerial++;this.identityPending=false;this.memberIdentities={}
    this.directMap={};this.supportMap={};this.supportNext='';this.adminExpandedUsers={}
    this.setData({chatRows:[],visibleChatRows:[],adminUserGroups:[]})
    if(this.auditClient)this.auditClient.clear()
    this.stopSummaryPolling()
    this._visible = false
    ++this._showSerial
    if (this.stopNotifications) this.stopNotifications()
    if (this.stopChat) this.stopChat()
    this.stopNotifications = this.stopChat = null
    this.digestMap = {}
  },

  clearSensitive() {
    this.clearOnlineRows()
    this.identitySerial=(this.identitySerial||0)+1;this.identityPending=false;this.memberIdentities={};this.identityRetryAt=0
    if(this.auditClient)this.auditClient.clear()
    this.stopSummaryPolling()
    this.digestMap = {}
    this.directRequest=(this.directRequest||0)+1
    this.directMap = {}
    this.supportMap={};this.supportNext='';this.adminExpandedUsers={};this.supportRetryAt=0;this.supportRetryFailures=0;this.supportLoadJob=null;this.supportLoadSerial=0;this.supportRefreshToken=null;this.supportRefreshQueued=false
    this.pendingDigests = {};this.listBoundary=null;this.candidateCount=0
    this.hasPlatformCandidate=false
    this.setData({
      supportEntry:null,directEnabled:false,directSummaryStale:false,directHasMore:false,auditAllowed:false,canSwitchChatScope:false,ordersOnly:false,isLoggedIn: false, summaryReady: false, noticeUnreadCount: 0,
      importantCount: 0, todoCount: 0, topTodoText: '', topTodoRole: '', summaryError: false,
      businessTone: 'normal',
      latestNotification: null,
      chatRows: [], visibleChatRows: [], chatLoading: false,
      chatHasMore: false, hasPlatformChats:false,showInboxMenu:false,chatFilter: 'all',chatRole:'all',listMore:false,listBefore:false,preferenceError:'',preferenceBusy:false,
      adminInboxMode:false,adminUserGroups:[],adminConversationCount:0,adminInboxError:'',supportInboxHasMore:false,supportInboxLoading:false
    })
  },

  openAudit(){if(this.data.auditAllowed&&this.chatManager.enabled)wx.navigateTo({url:'/subpackages/chat/audit/index'})},
  clearSupportRetry(){
    this.supportRetryAt=0
    this.supportRetryFailures=0
  },
  deferSupportRetry(){
    this.supportRetryFailures=Math.min(4,(this.supportRetryFailures||0)+1)
    const delay=Math.min(SUPPORT_RETRY_MAX_MS,SUPPORT_RETRY_BASE_MS*Math.pow(2,this.supportRetryFailures-1))
    this.supportRetryAt=Date.now()+delay
    return delay
  },
  // Re-read every previously loaded page from one server-validated cursor chain.
  // Never retain older pages as trusted after a new first-page snapshot.
  async fetchSupportSnapshot(manager,token,current,desiredCount) {
    try{return await this.fetchSupportSnapshotOnce(manager,token,current,desiredCount)}
    catch(error){
      if(error?.code!=='CHAT_RESYNC_REQUIRED'||!current())throw error
      return this.fetchSupportSnapshotOnce(manager,token,current,desiredCount)
    }
  },

  async fetchSupportSnapshotOnce(manager,token,current,desiredCount) {
    const items=[],seenIDs=new Set(),seenCursors=new Set()
    let first=null,cursor=''
    let unreadComplete=true,identityComplete=true,previewComplete=true
    let unreadWarning='',identityWarning='',previewWarning=''
    do {
      if(!current())throw Object.assign(new Error('context changed'),{code:'CHAT_CONTEXT_CHANGED'})
      const page=await manager.io.http('support/inbox',{filter:'all',limit:20,...(cursor?{cursor}:{})},token,'support')
      if(!current())throw Object.assign(new Error('context changed'),{code:'CHAT_CONTEXT_CHANGED'})
      if(!first)first=page
      unreadComplete=unreadComplete&&page?.unread_complete!==false
      identityComplete=identityComplete&&page?.identity_complete!==false
      previewComplete=previewComplete&&page?.preview_complete!==false
      unreadWarning=unreadWarning||String(page?.unread_warning||'')
      identityWarning=identityWarning||String(page?.identity_warning||'')
      previewWarning=previewWarning||String(page?.preview_warning||'')
      for(const item of page?.items||[]){
        const id=String(item.conversation_id||'')
        if(!id||seenIDs.has(id))continue
        seenIDs.add(id);items.push(item)
      }
      const next=String(page?.next_cursor||'')
      if(next&&seenCursors.has(next))throw Object.assign(new Error('cursor repeated'),{code:'CHAT_RESYNC_REQUIRED'})
      if(next)seenCursors.add(next)
      cursor=next
    }while(cursor&&items.length<desiredCount)
    return {...first,items,next_cursor:cursor,unread_complete:unreadComplete,identity_complete:identityComplete,preview_complete:previewComplete,unread_warning:unreadWarning,identity_warning:identityWarning,preview_warning:previewWarning}
  },

  loadSupportEntry(serial) {
    if(this.supportLoadJob&&this.supportLoadSerial===serial)return this.supportLoadJob
    const job=this._loadSupportEntry(serial)
    this.supportLoadSerial=serial
    const wrapped=Promise.resolve(job).finally(()=>{if(this.supportLoadJob===wrapped)this.supportLoadJob=null})
    this.supportLoadJob=wrapped
    return wrapped
  },
  async _loadSupportEntry(serial) {
    const manager=this.chatManager
    if(!this.data.isLoggedIn||!manager.enabled||manager.ordersOnly){
      this.supportMap={};this.supportNext=''
      if(this._visible&&serial===this._showSerial)this.setData({supportInboxHasMore:false,supportInboxLoading:false,adminInboxError:''},()=>this.showChatRows())
      return
    }
    const identityEpoch=manager.identityEpoch,transport=manager.epoch
    const current=()=>this._visible&&serial===this._showSerial&&manager.identityEpoch===identityEpoch&&manager.epoch===transport&&manager.enabled&&!manager.ordersOnly
    let entry=supportBase(manager.supportService,this.data.supportEntry)
    if(!entry){
      this.supportMap={};this.supportNext=''
      if(current())this.setData({supportEntry:null,supportInboxHasMore:false,supportInboxLoading:false,adminInboxError:''},()=>this.showChatRows())
      return
    }
    try {
      const response=await OrderApi.request('chat.support.entry','POST',{})
      if(!current())return
      if(response.data&&response.data.enabled){
        const priorID=String(entry.conversation_id||''),nextID=String(response.data.conversation_id||'')
        entry={...entry,...response.data,detailReady:true}
        if(priorID!==nextID)entry={...entry,ownUnread:0,ownLastText:'',ownActivityAt:0,ownActivityLabel:'',ownLastMessageId:''}
      }else if(response.data&&response.data.enabled===false)entry=null
    } catch (error) {
      console.warn('[消息] 平台反馈助手状态暂未更新',String(error&&error.code||'unavailable'))
    }
    if(!current())return
    if(!entry){
      this.supportMap={};this.supportNext=''
      this.setData({supportEntry:null,supportInboxHasMore:false,supportInboxLoading:false,adminInboxError:''},()=>this.showChatRows())
      return
    }
    this.setData({supportEntry:entry})
    if(!entry.staff){this.supportMap={};this.supportNext='';this.clearSupportRetry()}
    if(!entry.conversation_id&&!entry.staff){
      this.setData({supportInboxHasMore:false,supportInboxLoading:false,adminInboxError:''},()=>this.showChatRows())
      return
    }
    if(manager.simulation)return
    if(entry.staff&&this.supportRetryAt>Date.now()){
      this.setData({adminInboxError:'部分反馈会话暂未同步，订单和私聊可正常使用'})
      return
    }
    let token
    try{
      token=await manager.credential(transport)
      if(!current()||!manager.live(transport))return
    }catch(error){
      if(!current())return
      if(['CHAT_PERMISSION_DENIED','CHAT_UNAUTHORIZED','CHAT_FORBIDDEN'].includes(error?.code)){
        this.supportMap={};this.supportNext='';this.clearSupportRetry()
        this.setData({supportEntry:{...entry,staff:false},supportInboxHasMore:false,supportInboxLoading:false,adminInboxError:''},()=>this.showChatRows())
      }else if(entry.staff){
        this.deferSupportRetry()
        console.warn('[消息] 反馈处理管理员聊天凭证暂未更新',String(error?.code||'CHAT_UNAVAILABLE'),Number(error?.statusCode||0),String(error?.requestId||''))
        this.setData({adminInboxError:'反馈会话权限暂未同步，下拉重试'})
      }
      return
    }
    if(entry.staff&&this.data.supportInboxLoading){
      this.supportRefreshQueued=true
      return
    }
    const refreshToken={}
    if(entry.staff){
      this.supportRefreshToken=refreshToken
      this.setData({supportInboxLoading:true})
    }
    const desiredCount=Math.max(20,Object.keys(this.supportMap||{}).length)
    const jobs=[
      entry.conversation_id?manager.io.http('conversations/unread',{conversation_id:entry.conversation_id},token,'support'):Promise.resolve(null),
      entry.staff?this.fetchSupportSnapshot(manager,token,current,desiredCount):Promise.resolve(null)
    ]
    let ownResult,groupResult
    try{[ownResult,groupResult]=await Promise.allSettled(jobs)}
    finally{
      if(this.supportRefreshToken===refreshToken){
        this.supportRefreshToken=null
        if(this._visible&&serial===this._showSerial)this.setData({supportInboxLoading:false})
      }
    }
    if(!current()||!manager.live(transport))return
    const denied=error=>['CHAT_PERMISSION_DENIED','CHAT_UNAUTHORIZED','CHAT_FORBIDDEN'].includes(error?.code)
    const patch={supportInboxLoading:false,adminInboxError:''}
    if(ownResult.status==='fulfilled'){
      const summary=InboxModel.supportSummary(ownResult.value)
      entry={...entry,
        ownUnread:summary?.unreadCount||0,
        ownLastText:summary?.lastText||'',
        ownLastMessageId:summary?.lastMessageId||'',
        ownActivityAt:summary?.activityAt||0,
        ownActivityLabel:summary?.activityAt?Presentation.timeLabel(summary.activityAt):''}
    }
    if(entry.staff&&groupResult.status==='fulfilled'){
      const next={},unreadComplete=groupResult.value?.unread_complete!==false
      for(const item of groupResult.value?.items||[]){
        const row=InboxModel.supportInboxRow(item)
        if(row){
          const prior=this.supportMap&&this.supportMap[row.conversationId]
          if(!unreadComplete&&prior){
            row.unreadCount=prior.unreadCount
            row.unreadLabel=prior.unreadLabel
          }
          next[row.conversationId]=row
        }
      }
      this.supportMap=next;this.supportNext=String(groupResult.value?.next_cursor||'');this.clearSupportRetry()
      const attentionComplete=groupResult.value?.attention_count_complete!==false
      if(!attentionComplete)console.warn('[消息] 反馈注意项统计暂未完整同步',String(groupResult.value?.attention_warning||'CHAT_SUPPORT_INBOX_ATTENTION_PARTIAL'))
      if(groupResult.value?.identity_complete===false)console.warn('[消息] 反馈用户身份展示已降级',String(groupResult.value?.identity_warning||'CHAT_SUPPORT_INBOX_IDENTITY_DISPLAY_UNAVAILABLE'))
      if(groupResult.value?.preview_complete===false)console.warn('[消息] 反馈摘要展示已降级',String(groupResult.value?.preview_warning||'CHAT_SUPPORT_INBOX_PREVIEW_UNAVAILABLE'))
      if(!unreadComplete)console.warn('[消息] 反馈未读数暂未完整同步',String(groupResult.value?.unread_warning||'CHAT_SUPPORT_INBOX_UNREAD_UNAVAILABLE'))
      entry={...entry,
        attention:attentionComplete?(Number(groupResult.value?.attention_count)||0):(Number(entry.attention)||0),
        attentionPartial:!attentionComplete,
        pendingCount:Number(groupResult.value?.pending_count)||0,
        summaryReady:true}
      patch.supportInboxHasMore=!!this.supportNext
    }else if(entry.staff&&groupResult.status==='rejected'){
      if(denied(groupResult.reason)){
        this.supportMap={};this.supportNext='';this.clearSupportRetry();entry={...entry,staff:false}
        patch.supportInboxHasMore=false
      }else{
        this.deferSupportRetry()
        console.warn('[消息] 管理员反馈会话列表暂未更新',String(groupResult.reason?.code||'CHAT_UNAVAILABLE'),Number(groupResult.reason?.statusCode||0),String(groupResult.reason?.requestId||''))
        patch.supportInboxHasMore=!!this.supportNext
        patch.adminInboxError='部分反馈会话暂未同步，订单和私聊可正常使用'
      }
    }else{
      this.supportMap={};this.supportNext='';patch.supportInboxHasMore=false
      entry={...entry,summaryReady:true}
    }
    patch.supportEntry=entry
    this.setData(patch,()=>this.showChatRows())
  },
  async loadMoreSupport() {
    if(!this._visible||!this.data.supportEntry?.staff||!this.data.supportInboxHasMore||this.data.supportInboxLoading||this.supportRefreshToken||!this.supportNext)return
    const manager=this.chatManager,serial=this._showSerial,identityEpoch=manager.identityEpoch,transport=manager.epoch,cursor=this.supportNext
    const current=()=>this._visible&&serial===this._showSerial&&manager.identityEpoch===identityEpoch&&manager.epoch===transport&&manager.enabled&&!manager.ordersOnly
    this.setData({supportInboxLoading:true,adminInboxError:''})
    try{
      const token=await manager.credential(transport)
      if(!current()||!manager.live(transport))return
      const page=await manager.io.http('support/inbox',{filter:'all',limit:20,cursor},token,'support')
      if(!current()||!manager.live(transport))return
      const next={...(this.supportMap||{})},unreadComplete=page?.unread_complete!==false
      for(const item of page?.items||[]){
        const row=InboxModel.supportInboxRow(item)
        if(row){
          const prior=next[row.conversationId]
          if(!unreadComplete&&prior){
            row.unreadCount=prior.unreadCount
            row.unreadLabel=prior.unreadLabel
          }
          next[row.conversationId]=row
        }
      }
      this.supportMap=next;this.supportNext=String(page?.next_cursor||'');this.clearSupportRetry()
      if(page?.identity_complete===false)console.warn('[消息] 更多反馈用户身份展示已降级',String(page?.identity_warning||'CHAT_SUPPORT_INBOX_IDENTITY_DISPLAY_UNAVAILABLE'))
      if(page?.preview_complete===false)console.warn('[消息] 更多反馈摘要展示已降级',String(page?.preview_warning||'CHAT_SUPPORT_INBOX_PREVIEW_UNAVAILABLE'))
      if(!unreadComplete)console.warn('[消息] 更多反馈未读数暂未完整同步',String(page?.unread_warning||'CHAT_SUPPORT_INBOX_UNREAD_UNAVAILABLE'))
      // Cursor pages intentionally omit global counters. Preserve the trusted
      // first-page snapshot instead of resetting badges while loading more.
      const supportEntry={...this.data.supportEntry,summaryReady:true}
      this.setData({supportEntry,supportInboxHasMore:!!this.supportNext,adminInboxError:''},()=>this.showChatRows())
    }catch(error){
      if(!current())return
      if(error?.code==='CHAT_RESYNC_REQUIRED'){
        this.supportNext=''
        this.clearSupportRetry()
        this.supportRefreshQueued=true
      }else if(['CHAT_PERMISSION_DENIED','CHAT_UNAUTHORIZED','CHAT_FORBIDDEN'].includes(error?.code)){
        this.supportMap={};this.supportNext='';this.clearSupportRetry()
        this.setData({supportEntry:{...this.data.supportEntry,staff:false},supportInboxHasMore:false,adminInboxError:''},()=>this.showChatRows())
      }else{
        this.deferSupportRetry()
        console.warn('[消息] 更多反馈会话暂未加载',String(error?.code||'CHAT_UNAVAILABLE'),Number(error?.statusCode||0),String(error?.requestId||''))
        this.setData({adminInboxError:'更多反馈会话暂未加载，点击“加载更多”重试'})
      }
    }finally{
      if(this._visible&&serial===this._showSerial)this.setData({supportInboxLoading:false})
      if(current()){
        if(this.supportRefreshQueued){
          this.supportRefreshQueued=false
          const pending=this.supportLoadJob
          Promise.resolve(pending).then(()=>{if(current())this.loadSupportEntry(serial)},()=>{if(current())this.loadSupportEntry(serial)})
        }
      }
    }
  },
  openFeedbackInbox(){if(this.data.supportEntry?.staff&&this.chatManager.enabled)wx.navigateTo({url:'/subpackages/chat/feedback/index'})},
  async openSupport(event) {
    let entry=this.data.supportEntry
    if(!entry || !this.chatManager.enabled)return
    const inbox=event.currentTarget.dataset.mode==='inbox'
    if(inbox){
      if(!entry.staff)return
      wx.navigateTo({url:'/subpackages/chat/feedback/index'})
      return
    }
    if(this._openingSupport)return
    this._openingSupport=true
    try{
      if(!entry.conversation_id){
        const prepared=await require('../../utils/OrderApi.js').request('chat.support.prepare','POST',{})
        entry={...entry,...(prepared.data||{})}
        if(entry.conversation_id)this.setData({supportEntry:entry})
      }
      const id=String(entry.conversation_id||'')
      if(!/^[1-9][0-9]{0,19}$/.test(id)){wx.showToast({title:'反馈会话正在准备，请稍后再试',icon:'none'});return}
      wx.navigateTo({url:'/subpackages/chat/conversation/index?conversation_id='+encodeURIComponent(id)+'&scene=support&title='+encodeURIComponent(String(entry.name||'平台反馈助手').slice(0,24))})
    }catch(error){wx.showToast({title:error?.message||'暂时无法打开反馈会话',icon:'none'})}
    finally{this._openingSupport=false}
  },
  async loadDirectInbox(serial) {
    const manager=this.chatManager,request=this.directRequest=(this.directRequest||0)+1
    const identityEpoch=manager.identityEpoch,transportEpoch=manager.epoch
    const current=()=>this._visible&&serial===this._showSerial&&request===this.directRequest&&identityEpoch===manager.identityEpoch&&transportEpoch===manager.epoch&&manager.enabled
    if(!manager.enabled||!this.data.isLoggedIn||manager.ordersOnly||manager.simulation||manager.testSandbox){
      if(this._visible&&serial===this._showSerial){
        this.directMap={}
        this.setData({directEnabled:false,directSummaryStale:false,directHasMore:false},()=>this.showChatRows())
      }
      return
    }
    let enabled=false
    try{
      const result=await OrderApi.request('chat.direct.availability','POST',{})
      enabled=result.data?.enabled===true
    }catch(error){
      if(!current())return
      if(['CHAT_DIRECT_DISABLED','CHAT_UNAUTHORIZED','CHAT_FORBIDDEN'].includes(error?.code)){
        this.directMap={}
        this.setData({directEnabled:false,directSummaryStale:false,directHasMore:false},()=>this.showChatRows())
      }else this.setData({directSummaryStale:Object.keys(this.directMap||{}).length>0})
      return
    }
    if(!current())return
    if(!enabled){
      this.directMap={}
      this.setData({directEnabled:false,directSummaryStale:false,directHasMore:false},()=>this.showChatRows())
      return
    }
    const entries=[]
    let after='0',hasMore=false
    try{
      for(let pageNo=0;pageNo<5;pageNo++){
        const page=(await OrderApi.request('chat.direct.inbox','POST',{after,filter:'active'})).data||{}
        if(!current())return
        entries.push(...(Array.isArray(page.items)?page.items:[]))
        const next=String(page.next||'')
        if(!next){hasMore=false;break}
        hasMore=true;after=next
      }
    }catch(error){
      if(!current())return
      if(['CHAT_DIRECT_DISABLED','CHAT_UNAUTHORIZED','CHAT_FORBIDDEN'].includes(error?.code)){
        this.directMap={}
        this.setData({directEnabled:false,directSummaryStale:false,directHasMore:false},()=>this.showChatRows())
      }else this.setData({directEnabled:true,directSummaryStale:true})
      return
    }
    const eligible=entries.filter(entry=>InboxModel.validID(entry?.relationship)&&entry?.ready===true&&entry?.blocked!==true)
    const prior=this.directMap||{},next={}
    if(!eligible.length){
      this.directMap={}
      this.setData({directEnabled:true,directSummaryStale:false,directHasMore:hasMore},()=>this.showChatRows())
      return
    }
    let token
    try{
      token=await manager.credential(transportEpoch)
      if(!current()||manager.simulation||manager.testSandbox)throw Object.assign(new Error('context changed'),{code:'CHAT_CONTEXT_CHANGED'})
    }catch(error){
      if(!current())return
      if(['CHAT_DIRECT_DISABLED','CHAT_UNAUTHORIZED','CHAT_FORBIDDEN','CHAT_PERMISSION_DENIED'].includes(error?.code)){
        this.directMap={}
        this.setData({directEnabled:false,directSummaryStale:false,directHasMore:false},()=>this.showChatRows())
        return
      }
      const allowed=new Set(eligible.map(entry=>String(entry.relationship)))
      for(const id of Object.keys(prior))if(allowed.has(id))next[id]=prior[id]
      this.directMap=next
      this.setData({directEnabled:true,directSummaryStale:true,directHasMore:hasMore},()=>this.showChatRows())
      return
    }
    let stale=false
    for(let start=0;start<eligible.length;start+=20){
      const batch=eligible.slice(start,start+20),ids=batch.map(entry=>String(entry.relationship))
      try{
        const result=await manager.io.http('direct/summaries',{conversation_ids:ids},token,'direct')
        if(!current())return
        const summaries=new Map((result?.items||[]).map(item=>[String(item?.conversation_id||''),item?.unread]))
        for(const entry of batch){
          const id=String(entry.relationship),row=InboxModel.directRow(entry,summaries.get(id))
          if(row)next[id]=row
        }
      }catch(error){
        if(!current())return
        if(['CHAT_DIRECT_DISABLED','CHAT_UNAUTHORIZED','CHAT_FORBIDDEN','CHAT_PERMISSION_DENIED'].includes(error?.code)){
          this.directMap={}
          this.setData({directEnabled:false,directSummaryStale:false,directHasMore:false},()=>this.showChatRows())
          return
        }
        stale=true
        for(const entry of batch){
          const id=String(entry.relationship)
          if(prior[id])next[id]=prior[id]
        }
      }
    }
    if(!current())return
    this.directMap=next
    this.setData({directEnabled:true,directSummaryStale:stale,directHasMore:hasMore},()=>this.showChatRows())
  },
  openDirect() {if(this.data.directEnabled&&this.chatManager.enabled&&!this.data.ordersOnly)wx.navigateTo({url:'/subpackages/chat/direct/index'})},
  clearOnlineRows() {
    clearTimeout(this.onlinePoll)
    clearTimeout(this.onlineExpiry)
    this.onlineRequest=(this.onlineRequest||0)+1
    this.setData({onlineRows:{}})
  },
  scheduleOnlineRows(serial,delay=0) {
    clearTimeout(this.onlinePoll)
    if(!this._visible||serial!==this._showSerial||!this.chatManager.enabled)return
    const wait=Math.max(0,Math.min(5000,Number(delay)||0))
    this.onlinePoll=setTimeout(async()=>{
      if(!this._visible||serial!==this._showSerial||!this.chatManager.enabled)return
      const manager=this.chatManager
      const ids=this.data.visibleChatRows.filter(row=>row.kind==='chat').slice(0,12).map(row=>row.conversationId)
      const epoch=manager.epoch,request=this.onlineRequest=(this.onlineRequest||0)+1
      if(manager.socketReady&&ids.length) {
        try {
          const page=await manager.command('presence.batch',{conversation_ids:ids})
          if(this._visible&&serial===this._showSerial&&epoch===manager.epoch&&this.onlineRequest===request&&manager.socketReady){
            const online={}
            for(const id of page.online_conversations||[])if(ids.includes(id))online[id]=true
            this.setData({onlineRows:online})
            clearTimeout(this.onlineExpiry)
            this.onlineExpiry=setTimeout(()=>this.setData({onlineRows:{}}),Math.min(6,Number(page.ttl_seconds)||6)*1000)
          }
        } catch(error) {
          if(this._visible&&serial===this._showSerial&&this.onlineRequest===request&&error?.code!=='CHAT_RESOURCE_BUSY')this.setData({onlineRows:{}})
        }
      }
      this.scheduleOnlineRows(serial,5000)
    },wait)
  },
  openGovernance(){if(this.chatManager.enabled)wx.navigateTo({url:'/subpackages/chat/governance/index'})},

  stopSummaryPolling() {
    if(this._summaryTimer)clearTimeout(this._summaryTimer)
    this._summaryTimer=null
  },

  scheduleSummaryRefresh(serial) {
    this.stopSummaryPolling()
    if(!this._visible||!this.data.isLoggedIn||serial!==this._showSerial)return
    this._summaryTimer=setTimeout(()=>{
      this._summaryTimer=null
      if(!this._visible||serial!==this._showSerial)return
      this.loadSupportEntry(serial)
      this.loadDirectInbox(serial)
      this.refreshSummary(serial,false).finally(()=>this.scheduleSummaryRefresh(serial))
    },45000)
  },

  applySummary(summary) {
    const top = summary && summary.topTodo
    const priority = top ? Number(top.priority) || 0 : 0
    this.setData({
      noticeUnreadCount: Math.max(0, Number(summary && summary.unreadCount) || 0),
      todoCount: Math.max(0, Number(summary && summary.todoCount) || 0),
      importantCount: summary && summary.businessSource && summary.businessSource.ready ? summary.businessSource.b : Math.max(0, Number(summary && summary.todoCount) || 0),
      topTodoText: top ? String(top.action || '') : '',
      topTodoRole: top ? String(top.role || '') : '',
      latestNotification: summary && summary.latestNotification || null,
      businessTone: top ? (priority >= 90 ? 'urgent' : priority >= 75 ? 'attention' : 'normal') : 'normal'
    })
    this.showChatRows()
  },

  refreshSummary(serial,force=true) {
    const key = this.activeIdentity
    return NotificationState.refresh({ force, maxAge:1500 }).then(summary => {      if (!this._visible || serial !== this._showSerial || key !== this.activeIdentity) return
      this.setData({ summaryReady: true, summaryError: false })
      this.applySummary(summary)
    }).catch(error => {
      if (!this._visible || serial !== this._showSerial || key !== this.activeIdentity) return
      // Never turn an unavailable PHP source into a successful empty snapshot.
      this.setData({ summaryError: true })
      console.warn('[消息] 通知待办摘要暂不可用', error)
    })
  },

  onChatEvent(type, payload) {
    if (!this._visible) return
    if(type==='availability'){
      const enabled=payload?.enabled===true,ordersOnly=payload?.ordersOnly===true,wasEnabled=this.data.chatEnabled===true
      if(!enabled)this.clearOnlineRows()
      if(!enabled||ordersOnly){this.directMap={};this.supportMap={};this.supportNext=''}
      this.setData({chatEnabled:enabled,chatAvailabilityReason:String(payload?.reason||''),auditAllowed:enabled&&payload?.auditAllowed===true,canSwitchChatScope:payload?.canSwitchChatScope===true,ordersOnly,chatLoading:false,
        supportEntry:enabled&&!ordersOnly?supportBase(payload?.supportService,this.data.supportEntry):null,...(!enabled||ordersOnly?{directEnabled:false,directSummaryStale:false,directHasMore:false,supportInboxHasMore:false,supportInboxLoading:false,adminInboxError:''}:{})})
      if(enabled&&this.data.isLoggedIn){
        if(!ordersOnly&&(!this.data.supportEntry||!this.data.supportEntry.detailReady))this.loadSupportEntry(this._showSerial)
        if(!ordersOnly&&(!wasEnabled||!this.data.directEnabled))this.loadDirectInbox(this._showSerial)
        this.scheduleOnlineRows(this._showSerial,0)
      }
      if(!enabled||ordersOnly)this.showChatRows()
      return
    }
    if(type==='transport'){this.setData({chatSimulation:payload.simulation===true});return}
    if(type==='account.summary'){
      if(!this.data.ordersOnly&&this.data.supportEntry)this.loadSupportEntry(this._showSerial)
      if(!this.data.ordersOnly&&this.data.directEnabled)this.loadDirectInbox(this._showSerial)
      return
    }
    if (type === 'reset') {
      this.clearOnlineRows()
      this.directMap={};this.supportMap={};this.supportNext='';this.adminExpandedUsers={}
      this.setData({supportEntry:null,directEnabled:false,directSummaryStale:false,directHasMore:false,supportInboxHasMore:false,supportInboxLoading:false,adminInboxError:'',adminUserGroups:[],adminInboxMode:false})
      this.managerEpoch = this.chatManager.epoch
      this.identitySerial++;this.identityPending=false
      if(this.auditClient)this.auditClient.clear()
      this.setData({
        chatSimulation:false,
        chatStatus:this.chatManager.enabled ? this.chatManager.state : 'disabled',
        chatLoading:this.data.chatRows.length ? false : this.chatManager.enabled
      })
      // reset 可能由登录身份切换同步触发；延后一微任务再读取 app.globalData，
      // 确保新身份已经落地。相同身份只后台重连，不让整页退回“确认登录状态”。
      if(!this._runtimeResumeScheduled){
        this._runtimeResumeScheduled=true
        Promise.resolve().then(()=>{
          this._runtimeResumeScheduled=false
          if(this._visible)this.onShow()
        })
      }
      return
    }
    if (!this.data.isLoggedIn || !this.chatManager.enabled) return
    if (type === 'conversation.clear') {
      const id = payload && String(payload.conversation_id || '')
      if (id && (this.digestMap[id] || this.pendingDigests[id] || this.directMap[id] || this.supportMap[id])) {
        delete this.memberIdentities[id]
        delete this.digestMap[id]
        delete this.pendingDigests[id]
        delete this.directMap[id]
        delete this.supportMap[id]
        this.showChatRows()
      }
      return
    }
    if (type === 'connection') {
      const state=payload && payload.state || 'unavailable'
      this.setData({ chatStatus: state, chatLoading: false })
      if(state==='socket')this.scheduleOnlineRows(this._showSerial,0)
      else this.clearOnlineRows()
      return
    }
    if(type==='preference'){
      const id=String(payload.conversation_id),next=payload.preferences
      for(const map of [this.digestMap,this.pendingDigests,this.directMap])if(map[id]&&map[id].preferences.member_version===next.member_version)map[id].preferences=InboxModel.mergePreferences(map[id].preferences,next)
      this.showChatRows();return
    }
    if (type !== 'digests') return
    if(payload.replace){this.candidateCount=0;this.hasPlatformCandidate=false}
    const options={filter:this.data.chatFilter,role:this.data.chatRole,before:this.listBoundary}
    ;(payload.items||[]).forEach(item=>{
      const row=InboxModel.normalize(item)
      if(row?.memberRole==='platform_staff')this.hasPlatformCandidate=true
      if(row&&InboxModel.matches(row,options))this.candidateCount++
    })

    this.pendingDigests = InboxModel.applyPage(this.pendingDigests, payload, options)
    const more = !!(payload && payload.has_more)
    if (!more) {
      this.digestMap = this.pendingDigests
      this.setData({listMore:this.candidateCount>InboxModel.MAX_ROWS,hasPlatformChats:this.hasPlatformCandidate===true})
      this.showChatRows()
    }
    this.setData({ chatHasMore: more, chatLoading: more && !this.data.chatRows.length })
  },

  showChatRows() {
    if(!this.memberIdentities)this.memberIdentities={}
    if(!this.supportMap)this.supportMap={}
    if(!this.adminExpandedUsers)this.adminExpandedUsers={}
    const decorate=row=>({...((row.conversationType==='direct'||row.conversationType==='support'||row.kind!=='chat')?row:InboxModel.identities.row(row,this.memberIdentities[row.conversationId])),activityLabel:Presentation.timeLabel(row.activityAt)})
    const rows=InboxModel.toRows(this.digestMap).map(decorate)
    const summary = this.data.summaryReady && this.data.chatRole==='all' && !this.listBoundary ? {
      unreadCount: this.data.noticeUnreadCount,
      latestNotification: this.data.latestNotification
    } : null
    const visible=InboxModel.inboxRows(this.digestMap,summary,this.data.chatFilter)
    if(this.data.chatRole==='all'&&!this.data.ordersOnly&&!this.listBoundary&&['all','later'].includes(this.data.chatFilter)){
      for(const row of Object.values(this.directMap||{}))if(this.data.chatFilter!=='later'||row.preferences?.later)visible.push(row)
    }
    visible.sort(InboxModel.rowCompare)
    const decorated=visible.map(decorate)
    const adminInboxMode=!!(this.data.supportEntry?.staff||this.data.hasPlatformChats)&&['all','platform_staff'].includes(this.data.chatRole)
    if(adminInboxMode&&!this.data.ordersOnly){
      const children=decorated.filter(row=>row.kind==='chat')
      if(this.data.chatRole==='all'&&this.data.chatFilter==='all'&&!this.listBoundary){
        for(const row of Object.values(this.supportMap||{}))children.push(decorate(row))
      }
      const groups=InboxModel.adminUserGroups(children,this.adminExpandedUsers).map(group=>({...group,activityLabel:Presentation.timeLabel(group.activityAt)}))
      this.setData({chatRows:rows,visibleChatRows:decorated.filter(row=>row.kind!=='chat'),adminInboxMode:true,adminUserGroups:groups,adminConversationCount:children.length})
    }else this.setData({chatRows:rows,visibleChatRows:decorated,adminInboxMode:false,adminUserGroups:[],adminConversationCount:0})
    this.loadInboxParticipants()
  },

  async loadInboxParticipants(){
    if(!this._visible||!this.data.isLoggedIn||!this.chatManager.enabled||!this.chatManager.io?.participants||this.identityPending||this.identityRetryAt>Date.now())return
    const requests=InboxModel.toRows(this.digestMap).filter(row=>{
      const old=this.memberIdentities[row.conversationId]
      return InboxModel.validID(row.policyVersion)&&(!old||old.version!==row.policyVersion||old.at+60000<Date.now())
    }).slice(0,20).map(row=>({conversation_id:row.conversationId,policy_version:row.policyVersion}))
    if(!requests.length)return
    const serial=++this.identitySerial,life=this._showSerial,epoch=this.chatManager.epoch
    this.identityPending=true
    try{
      const result=await this.chatManager.io.participants(requests)
      if(!this._visible||life!==this._showSerial||epoch!==this.chatManager.epoch||serial!==this.identitySerial)return
      const entries=InboxModel.identities.response(requests,result)
      for(const request of requests){
        const id=request.conversation_id,entry=entries[id]
        if(this.digestMap[id]?.policyVersion!==request.policy_version)continue
        if(entry)this.memberIdentities[id]=entry
        else this.memberIdentities[id]={version:request.policy_version,at:Date.now(),members:{},list:[],viewerId:''}
      }
      this.identityPending=false;this.showChatRows()
    }catch(_){if(this._visible&&serial===this.identitySerial){this.identityPending=false;this.identityRetryAt=Date.now()+30000;this.memberIdentities={};this.showChatRows()}}
  },
  restartList(reset=true){
    if(reset)this.listBoundary=null
    this.pendingDigests={};this.digestMap={};this.candidateCount=0
    this.setData({chatRows:[],visibleChatRows:[],chatLoading:this.data.chatEnabled,listBefore:!!this.listBoundary,listMore:false})
    this.showChatRows();if(this.chatManager.enabled)this.chatManager.refreshAccount()
  },
  selectChatFilter(event) {
    const filter=String(event.currentTarget.dataset.filter||'all')
    if(!['all','later','ended'].includes(filter))return
    this.setData({chatFilter:filter});this.restartList()
  },
  selectChatRole(event){
    const role=String(event.currentTarget.dataset.role||'all')
    if(!['all','customer','worker'].includes(role) && !(role==='platform_staff'&&this.data.hasPlatformChats))return
    this.setData({chatRole:role});this.restartList()
  },
  nextList(){
    if(!this.data.listMore||this.data.chatHasMore||!this.data.chatRows.length)return
    this.listBoundary=InboxModel.bookmark(this.data.chatRows[this.data.chatRows.length-1]);this.restartList(false)
  },
  firstList(){this.restartList()},
  conversationActions(event){
    const id=String(event.currentTarget.dataset.id||''),row=this.digestMap[id]||this.directMap[id],serial=this._showSerial,epoch=this.chatManager.epoch
    if(!this._visible||!this.data.isLoggedIn||!this.chatManager.enabled||!row||this.data.preferenceBusy||!/^[0-9a-f]{64}$/.test(row.preferences.member_version))return
    const snapshot={...row.preferences},direct=row.conversationType==='direct'
    wx.showActionSheet({itemList:[snapshot.pinned?'取消置顶':'置顶',snapshot.later?'取消稍后处理':'稍后处理'],success:async result=>{
      if(!this._visible||serial!==this._showSerial||epoch!==this.chatManager.epoch||!(this.digestMap[id]||this.directMap[id]))return
      if(result.tapIndex!==0&&result.tapIndex!==1)return
      const field=result.tapIndex===0?'pinned':'later'
      this.setData({preferenceBusy:true,preferenceError:''})
      try{
        if(direct){
          const token=await this.chatManager.credential(epoch)
          if(!this._visible||serial!==this._showSerial||epoch!==this.chatManager.epoch)return
          const next=await this.chatManager.io.http('conversations/preference',{conversation_id:id,member_version:snapshot.member_version,
            field,value:!snapshot[field],expected_version:snapshot[field+'_version']},token,'direct')
          if(this._visible&&serial===this._showSerial&&epoch===this.chatManager.epoch&&this.directMap[id]){
            this.directMap[id]={...this.directMap[id],preferences:InboxModel.preferences(next)}
            this.showChatRows()
            this.chatManager.refreshAccount()
          }
        }else{
          await this.chatManager.updatePreference(id,snapshot,field,!snapshot[field])
          if(this._visible&&serial===this._showSerial&&epoch===this.chatManager.epoch)this.restartList()
        }
      }catch(error){
        if(this._visible&&serial===this._showSerial&&epoch===this.chatManager.epoch){
          const labels={CHAT_PIN_LIMIT:'最多可置顶10个会话',CHAT_PREFERENCE_CONFLICT:'另一端已修改设置，已重新核对，请再次选择',CHAT_RESYNC_REQUIRED:'会话权限已更新，请刷新后重试',CHAT_PREFERENCE_UNKNOWN:'设置结果待确认，请刷新核对'}
          this.setData({preferenceError:labels[error.code]||'设置暂未确认，请刷新核对'});this.chatManager.refreshAccount()
        }
      }finally{if(this._visible&&serial===this._showSerial&&epoch===this.chatManager.epoch)this.setData({preferenceBusy:false})}
    }})
  },

  toggleAdminUserGroup(event){
    const key=String(event.currentTarget.dataset.key||'')
    if(!key||!this.data.adminInboxMode)return
    this.adminExpandedUsers[key]=this.adminExpandedUsers[key]!==true
    this.showChatRows()
  },
  openAdminConversation(event){
    const id=String(event.currentTarget.dataset.id||''),type=String(event.currentTarget.dataset.type||'')
    if(!InboxModel.validID(id)||!this.data.isLoggedIn||!this.data.chatEnabled)return
    if(type==='support'){
      const row=this.supportMap&&this.supportMap[id]
      if(!row)return
      const title=String(row.peerDisplayName||row.displayTitle||'用户反馈').trim().slice(0,24)
      wx.navigateTo({url:'/subpackages/chat/conversation/index?conversation_id='+encodeURIComponent(id)+'&scene=support&title='+encodeURIComponent(title)})
      return
    }
    this.openConversation({currentTarget:{dataset:{id}}})
  },

  openConversation(event) {
    const id = String(event.currentTarget.dataset.id || '')
    if (id === 'notifications') {
      if (this.data.isLoggedIn && !this.data.checkingLogin && this.data.summaryReady) this.openOrdinaryNotifications()
      return
    }
    if (!this.data.isLoggedIn || !this.data.chatEnabled || !InboxModel.validID(id)) return
    if(this.directMap[id]){
      const row=this.directMap[id],title=String(row.peerDisplayName||row.displayTitle||row.title||'普通私聊').slice(0,24)
      wx.navigateTo({url:'/subpackages/chat/conversation/index?scene=direct&conversation_id='+encodeURIComponent(id)+'&title='+encodeURIComponent(title)})
      return
    }
    if(!this.digestMap[id])return
    const view=(this.data.chatRows||[]).find(row=>row.conversationId===id)
    const title=String(view?.displayTitle||view?.title||this.digestMap[id]?.title||'订单沟通').trim().slice(0,24)
    wx.navigateTo({ url: '/subpackages/chat/conversation/index?conversation_id=' + encodeURIComponent(id) + '&title=' + encodeURIComponent(title) })
  },

  inboxMenu(){
    if(!this._visible)return
    this.setData({showInboxMenu:!this.data.showInboxMenu})
  },
  closeInboxMenu(){if(this.data.showInboxMenu)this.setData({showInboxMenu:false})},
  stopMenuTouch(){},
  runInboxMenuAction(event){
    const action=String(event.currentTarget.dataset.action||'')
    if(!action||!this._visible)return
    this.setData({showInboxMenu:false})
    if(!this._visible)return
    if(action==='notifications'){this.openNotificationSettings();return}
    if(action==='scope'){if(this.data.canSwitchChatScope)ChatRuntime.toggleChatScope();return}
    if(action==='governance'){this.openGovernance();return}
    if(action==='audit'){this.openAudit();return}
    if(action==='direct'){this.openDirect();return}
    if(action==='feedback'){this.openFeedbackInbox();return}
    if(!this.data.chatEnabled||!this.data.isLoggedIn)return
    if(action.startsWith('filter_')){
      const filter=action.slice(7)
      if(!['all','later','ended'].includes(filter))return
      this.setData({chatFilter:filter},()=>this.restartList())
      return
    }
    if(action.startsWith('role_')){
      const role=action.slice(5)
      if(!['all','customer','worker'].includes(role) && !(role==='platform_staff'&&this.data.hasPlatformChats))return
      this.setData({chatRole:role},()=>this.restartList())
    }
  },
  openNotifications() { wx.navigateTo({ url: '/pages/notificationCenter/index' + (this.data.todoCount > 0 ? '' : '?view=all') }) },
  openOrdinaryNotifications() { wx.navigateTo({ url: '/pages/notificationCenter/index?view=all' }) },
  openNotificationSettings() { wx.navigateTo({ url: '/subpackages/account/settings/index?section=notifications' }) },

  onPullDownRefresh() {
    this.clearSupportRetry()
    ChatRuntime.refreshAvailability(true).then(()=>{
      if(!this._visible||!this.chatManager.enabled)return
      this.chatManager.refreshAccount()
      const key=this.activeIdentity
      if(key)ChatRuntime.discoverCollaborations(key,true).then(changed=>{
        if(changed&&this._visible&&key===this.activeIdentity&&this.chatManager.enabled)this.chatManager.refreshAccount()
      })
    }).catch(()=>{})
    const serial = this._showSerial
    if (this.data.isLoggedIn) {
      const jobs=[this.refreshSummary(serial)]
      if(this.chatManager.enabled&&!this.chatManager.ordersOnly){
        jobs.push(this.loadSupportEntry(serial))
        jobs.push(this.loadDirectInbox(serial))
      }
      Promise.allSettled(jobs).finally(() => wx.stopPullDownRefresh())
    } else {
      wx.stopPullDownRefresh()
    }
  }
})