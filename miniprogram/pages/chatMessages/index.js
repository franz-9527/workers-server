const NotificationState = require('../../utils/NotificationState.js')
const ChatRuntime = require('../../utils/ChatRuntime.js')
const InboxModel = require('../../utils/ChatInboxModel.js')
const Presentation = require('../../utils/ChatPresentation.js')
const SubscribeMessage = require('../../utils/SubscribeMessageUtils.js')
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
    subscriptionAlert: null,
    topTodoText: '',
    topTodoRole: '',
    businessTone: 'normal',
    latestNotification: null,
    summaryReady: false,
    summaryError: false,
    auditAllowed:false,
    chatEnabled: false,
    chatSimulation: false,
    chatStatus: 'disabled',
    showRoleFilters:false,chatFilter: 'all', chatRole:'all',listMore:false,listBefore:false,preferenceError:'',preferenceBusy:false,
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
      this.activeIdentity = ''
      this.clearSensitive()
    }
    if (this.getTabBar && this.getTabBar()) this.getTabBar().setData({ selected: 3 })
    const chatEnabled = this.chatManager.enabled === true
    this.setData({ chatSimulation:this.chatManager.simulation===true,auditAllowed:false,pageVisible: true, chatEnabled, chatStatus: chatEnabled ? this.chatManager.state : 'disabled', checkingLogin: true })
    const app = getApp()
    const ready = typeof app.ensureUserReady === 'function' ? app.ensureUserReady(false) : Promise.resolve(app.globalData.userInfo || {})
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
      this.setData({ isLoggedIn: true, checkingLogin: false })
      this.refreshSubscriptionHealth(serial)
      this.refreshSummary(serial)
      this.scheduleSummaryRefresh(serial)
      // The shared S11 transport performs its own bounded, authenticated
      // recovery. Opening an inbox NEVER acknowledges any message as read.
      if (chatEnabled) {
        this.setData({ chatLoading: !this.data.chatRows.length })
        this.chatManager.refreshAccount()
        this.probeAudit(serial)
      }
    }).catch(() => {
      if (!this._visible || serial !== this._showSerial) return
      this.clearSensitive()
      this.setData({ checkingLogin: false, summaryError: true })
    })
  },

  onHide() {
    this._visible=false
    this.identitySerial++;this.identityPending=false;this.memberIdentities={};this.showChatRows()
    this.stopSummaryPolling()
    if(this.auditClient)this.auditClient.clear()
    this._visible = false
    ++this._showSerial
    this.setData({ pageVisible: false,preferenceBusy:false })
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
    this.setData({
      auditAllowed:false,isLoggedIn: false, summaryReady: false, noticeUnreadCount: 0,
      subscriptionAlert: null, importantCount: 0, todoCount: 0, topTodoText: '', topTodoRole: '', summaryError: false,
      businessTone: 'normal',
      latestNotification: null,
      chatRows: [], visibleChatRows: [], chatLoading: false,
      chatHasMore: false, chatFilter: 'all',chatRole:'all',listMore:false,listBefore:false,preferenceError:'',preferenceBusy:false
    })
  },

  async probeAudit(serial){
    if(this.auditClient)this.auditClient.clear()
    this.auditClient=require('../../utils/ChatAudit.js').create()
    try{await this.auditClient.credential();if(this._visible&&serial===this._showSerial)this.setData({auditAllowed:true})}
    catch(_){if(this._visible&&serial===this._showSerial)this.setData({auditAllowed:false})}
  },
  openAudit(){if(this.data.auditAllowed&&this.chatManager.enabled)wx.navigateTo({url:'/subpackages/chat/audit/index'})},
  openGovernance(){if(this.chatManager.enabled)wx.navigateTo({url:'/subpackages/chat/governance/index'})},

  refreshSubscriptionHealth(serial) {
    const key = this.activeIdentity
    SubscribeMessage.getSubscriptionHealth(true).then(alert => {
      if (this._visible && serial === this._showSerial && key === this.activeIdentity) this.setData({ subscriptionAlert: alert || null })
    }).catch(() => {})
  },

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
    if(type==='transport'){this.setData({chatSimulation:payload.simulation===true});return}
    if (type === 'reset') {
        this.setData({chatSimulation:false})
      this.managerEpoch = this.chatManager.epoch
      this.digestMap = {}
      this.activeIdentity = ''
      this.clearSensitive()
      this.setData({ checkingLogin: true })
      this.onShow()
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
    if(payload.replace)this.candidateCount=0
    const options={filter:this.data.chatFilter,role:this.data.chatRole,before:this.listBoundary}
    ;(payload.items||[]).forEach(item=>{const row=InboxModel.normalize(item);if(row&&InboxModel.matches(row,options))this.candidateCount++})

    this.pendingDigests = InboxModel.applyPage(this.pendingDigests, payload, options)
    const more = !!(payload && payload.has_more)
    if (!more) {
      this.digestMap = this.pendingDigests
      this.setData({listMore:this.candidateCount>InboxModel.MAX_ROWS})
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
    if(!['all','unread','later','ended'].includes(filter))return
    this.setData({chatFilter:filter});this.restartList()
  },
  selectChatRole(event){
    const role=String(event.currentTarget.dataset.role||'all')
    if(!['all','customer','worker'].includes(role))return
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
    wx.navigateTo({ url: '/subpackages/chat/conversation/index?conversation_id=' + encodeURIComponent(id) })
  },

  inboxMenu(){
    const serial=this._showSerial,epoch=this.chatManager.epoch
    const actions=[['通知设置',()=>this.openNotificationSettings()]]
    if(this.data.chatEnabled&&this.data.isLoggedIn)actions.push(['筛选会话',()=>this.setData({showRoleFilters:!this.data.showRoleFilters})],['举报与申诉',()=>this.openGovernance()])
    if(this.data.auditAllowed&&this.data.chatEnabled)actions.push(['全部订单会话',()=>this.openAudit()])
    wx.showActionSheet({itemList:actions.map(a=>a[0]),success:r=>{if(this._visible&&serial===this._showSerial&&epoch===this.chatManager.epoch)actions[r.tapIndex]?.[1]()}})
  },
  openNotifications() { wx.navigateTo({ url: '/pages/notificationCenter/index' + (this.data.todoCount > 0 ? '' : '?view=all') }) },
  openOrdinaryNotifications() { wx.navigateTo({ url: '/pages/notificationCenter/index?view=all' }) },
  openNotificationSettings() { wx.navigateTo({ url: '/subpackages/account/settings/index?section=notifications' }) },

  onPullDownRefresh() {
    const serial = this._showSerial
    if (this.data.isLoggedIn) {
      this.refreshSubscriptionHealth(serial)
      this.refreshSummary(serial).finally(() => wx.stopPullDownRefresh())
      if (this.data.chatEnabled) this.chatManager.refreshAccount()
    } else {
      wx.stopPullDownRefresh()
    }
  }
})
