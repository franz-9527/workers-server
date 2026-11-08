const NotificationState = require('../../utils/NotificationState.js')
const ChatRuntime = require('../../utils/ChatRuntime.js')
const InboxModel = require('../../utils/ChatInboxModel.js')
const Presentation = require('../../utils/ChatPresentation.js')
const ApiEnvironment = require('../../utils/ApiEnvironment.js')

function identity(user) {
  const origin = ApiEnvironment.current() || {}
  return String(origin.baseURL || '') + '|' + String(user && user.openid || '') + '|' + String(user && user.mobile || '')
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
    chatSimulation: false,
    canSwitchChatScope:false,
    ordersOnly:false,
    chatStatus: 'disabled',
    chatAvailabilityReason:'',
    showInboxMenu:false,hasPlatformChats:false,chatFilter: 'all', chatRole:'all',listMore:false,listBefore:false,preferenceError:'',preferenceBusy:false,
    chatRows: [],
    visibleChatRows: [],
    chatHasMore: false,
    chatLoading: false
  },

  onLoad() {
    this.memberIdentities={};this.identitySerial=0;this.identityPending=false
    this.activeIdentity = ''
    this.digestMap = {}
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
    this.setData({ chatSimulation:this.chatManager.simulation===true,auditAllowed:chatEnabled&&this.chatManager.auditAllowed===true,pageVisible: true, chatEnabled, chatStatus: chatEnabled ? this.chatManager.state : 'disabled', chatAvailabilityReason:this.chatManager.availabilityReason||'', checkingLogin: !this.data.isLoggedIn })
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
    this._visible=false
    this.identitySerial++;this.identityPending=false
    this.stopSummaryPolling()
    if(this.auditClient)this.auditClient.clear()
    this._visible = false
    ++this._showSerial
    this.setData({ pageVisible: false,preferenceBusy:false,showInboxMenu:false })
  },

  onUnload() {
    this.identitySerial++;this.identityPending=false;this.memberIdentities={}
    this.setData({chatRows:[],visibleChatRows:[]})
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
    this.identitySerial=(this.identitySerial||0)+1;this.identityPending=false;this.memberIdentities={};this.identityRetryAt=0
    if(this.auditClient)this.auditClient.clear()
    this.stopSummaryPolling()
    this.digestMap = {}
    this.pendingDigests = {};this.listBoundary=null;this.candidateCount=0
    this.hasPlatformCandidate=false
    this.setData({
      auditAllowed:false,canSwitchChatScope:false,ordersOnly:false,isLoggedIn: false, summaryReady: false, noticeUnreadCount: 0,
      importantCount: 0, todoCount: 0, topTodoText: '', topTodoRole: '', summaryError: false,
      businessTone: 'normal',
      latestNotification: null,
      chatRows: [], visibleChatRows: [], chatLoading: false,
      chatHasMore: false, hasPlatformChats:false,showInboxMenu:false,chatFilter: 'all',chatRole:'all',listMore:false,listBefore:false,preferenceError:'',preferenceBusy:false
    })
  },

  openAudit(){if(this.data.auditAllowed&&this.chatManager.enabled)wx.navigateTo({url:'/subpackages/chat/audit/index'})},
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
    return NotificationState.refresh({ force, maxAge:1500 }).then(summary => {
      if (!this._visible || serial !== this._showSerial || key !== this.activeIdentity) return
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
      this.setData({chatEnabled:payload?.enabled===true,chatAvailabilityReason:String(payload?.reason||''),auditAllowed:payload?.enabled===true&&payload?.auditAllowed===true,canSwitchChatScope:payload?.canSwitchChatScope===true,ordersOnly:payload?.ordersOnly===true,chatLoading:false})
      return
    }
    if(type==='transport'){this.setData({chatSimulation:payload.simulation===true});return}
    if (type === 'reset') {
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
      if (id && (this.digestMap[id] || this.pendingDigests[id])) {
        delete this.memberIdentities[id]
        delete this.digestMap[id]
        delete this.pendingDigests[id]
        this.showChatRows()
      }
      return
    }
    if (type === 'connection') {
      this.setData({ chatStatus: payload && payload.state || 'unavailable', chatLoading: false })
      return
    }
    if(type==='preference'){
      const id=String(payload.conversation_id),next=payload.preferences
      for(const map of [this.digestMap,this.pendingDigests])if(map[id]&&map[id].preferences.member_version===next.member_version)map[id].preferences=InboxModel.mergePreferences(map[id].preferences,next)
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
    const rows = InboxModel.toRows(this.digestMap).map(row=>({...InboxModel.identities.row(row,this.memberIdentities[row.conversationId]),activityLabel:Presentation.timeLabel(row.activityAt)}))
    const summary = this.data.summaryReady && this.data.chatRole==='all' && !this.listBoundary ? {
      unreadCount: this.data.noticeUnreadCount,
      latestNotification: this.data.latestNotification
    } : null
    this.setData({ chatRows: rows, visibleChatRows: InboxModel.inboxRows(this.digestMap, summary, this.data.chatFilter).map(row=>({...InboxModel.identities.row(row,this.memberIdentities[row.conversationId]),activityLabel:Presentation.timeLabel(row.activityAt)})) })
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
    const id=String(event.currentTarget.dataset.id||''),row=this.digestMap[id],serial=this._showSerial,epoch=this.chatManager.epoch
    if(!this._visible||!this.data.isLoggedIn||!this.chatManager.enabled||!row||this.data.preferenceBusy||!/^[0-9a-f]{64}$/.test(row.preferences.member_version))return
    const snapshot={...row.preferences}
    wx.showActionSheet({itemList:[snapshot.pinned?'取消置顶':'置顶',snapshot.later?'取消稍后处理':'稍后处理'],success:async result=>{
      if(!this._visible||serial!==this._showSerial||epoch!==this.chatManager.epoch||!this.digestMap[id])return
      if(result.tapIndex!==0&&result.tapIndex!==1)return
      const field=result.tapIndex===0?'pinned':'later'
      this.setData({preferenceBusy:true,preferenceError:''})
      try{await this.chatManager.updatePreference(id,snapshot,field,!snapshot[field]);if(this._visible&&serial===this._showSerial&&epoch===this.chatManager.epoch)this.restartList()}
      catch(error){
        if(this._visible&&serial===this._showSerial&&epoch===this.chatManager.epoch){
          const labels={CHAT_PIN_LIMIT:'最多可置顶10个会话',CHAT_PREFERENCE_CONFLICT:'另一端已修改设置，已重新核对，请再次选择',CHAT_RESYNC_REQUIRED:'会话权限已更新，请刷新后重试',CHAT_PREFERENCE_UNKNOWN:'设置结果待确认，请刷新核对'}
          this.setData({preferenceError:labels[error.code]||'设置暂未确认，请刷新核对'});this.chatManager.refreshAccount()
        }
      }finally{if(this._visible&&serial===this._showSerial&&epoch===this.chatManager.epoch)this.setData({preferenceBusy:false})}
    }})
  },

  openConversation(event) {
    const id = String(event.currentTarget.dataset.id || '')
    if (id === 'notifications') {
      if (this.data.isLoggedIn && !this.data.checkingLogin && this.data.summaryReady) this.openOrdinaryNotifications()
      return
    }
    if (!this.data.isLoggedIn || !this.data.chatEnabled || !InboxModel.validID(id) || !this.digestMap[id]) return
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
      this.refreshSummary(serial).finally(() => wx.stopPullDownRefresh())
    } else {
      wx.stopPullDownRefresh()
    }
  }
})
