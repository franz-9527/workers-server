const OrderApi = require('../../utils/OrderApi.js')
const NotificationState = require('../../utils/NotificationState.js')
const SubscribeMessage = require('../../utils/SubscribeMessageUtils.js')

Page({
  data: {
    viewMode: 'todo',
    items: [], loading: true, loadingMore: false, page: 1, hasMore: false,
    unreadCount: 0, errorText: '', readAllLoading: false,
    todos: [], todoCount: 0, todoLoading: true, todoErrorText: '',
    subscriptionAlert: null,resumeHint:false, todoHasMore:false, todoLoadingMore:false, readThroughId:''
  },

  // V2.2.3：消息中心升级为“待我处理 + 订单动态线程”，提醒等级跟随订单当前真实状态变化。
  onLoad(options) {
    // The page is no longer a tab. Keep the original two working lists and
    // accept only the two known entry points from the dedicated chat inbox.
    const initial = options && options.view === 'all' ? 'all' : 'todo'
    this.setData({ viewMode: initial })
    this._visible = true
    this._scope = this.scopeKey()
    this.todoScopes = {}
    this.rawNotificationItems = []
    this.expandedThreads = {}
    this.currentTodoMap = {}
    this.tabStale={};this.readRevision=0;this.threadCache={};this.pendingReads=new Map();this.scrollPositions={todo:0,all:0};this.notificationCursor='';this.readRangeCursor=''
    this.load(true)
    this.loadTodos()
    this.loadSubscriptionHealth()
  },
  onShow() {
    this._visible=true
    if(this._scope!==this.scopeKey()) {
      this._scope=this.scopeKey();this.rawNotificationItems=[];this.expandedThreads={};this.currentTodoMap={};this.todoScopes={}
      this.threadCache={};this.pendingReads=new Map();this.scrollPositions={todo:0,all:0};this.notificationCursor='';this.readRangeCursor=''
      this.loadedOnce=false;this.todoLoadedOnce=false
      this.setData({items:[],todos:[],unreadCount:0,todoCount:0,subscriptionAlert:null,readThroughId:'',todoHasMore:false,hasMore:false,errorText:'',todoErrorText:''})
    } else if(!this.shownOnce){this.shownOnce=true;return}
    this.tabStale={todo:true,all:true};this.setData({resumeHint:true})
    // Returning from an order/settings keeps each tab's loaded range and scroll.
    if(!this.loadedOnce)this.load(true,true)
    if(!this.todoLoadedOnce)this.loadTodos(true)
    const scope=this.scopeKey()
    NotificationState.refresh({force:true}).then(value=>{
      if(this.isCurrent(scope)&&Number.isSafeInteger(value.unreadCount))this.setData({unreadCount:value.unreadCount})
    }).catch(()=>{})
    this.restoreScroll();this.loadSubscriptionHealth(true)
  },
  onHide(){this._visible=false;++this.loadSerial;++this.todoLoadSerial;this.setData({loading:false,loadingMore:false,todoLoading:false,todoLoadingMore:false})},
  onUnload(){this.onHide();this.rawNotificationItems=[];this.currentTodoMap={};this.threadCache={};this.expandedThreads={};this.pendingReads.clear();this.notificationCursor='';this.readRangeCursor='';this.scrollPositions={todo:0,all:0};this.setData({items:[],todos:[]})},
  scopeKey(){return typeof OrderApi.contextRevision==='function'?OrderApi.contextRevision():0},
  isCurrent(scope=this._scope){return this._visible && scope===this.scopeKey() && this._scope===scope},


  openSubscribeSettings() { wx.navigateTo({ url: '/pages/mine/settings?section=notifications' }) },

  loadSubscriptionHealth(silent) {
    const scope=this.scopeKey()
    return SubscribeMessage.getSubscriptionHealth(true).then(alert => {
      if(!this.isCurrent(scope))return
      this.setData({ subscriptionAlert: alert || null })
    }).catch(error => {
      if (!silent) console.warn('[消息中心] 订阅权限状态读取失败', error)
    })
  },

  handleSubscriptionAlert() {
    const alert = this.data.subscriptionAlert
    if (!alert || alert.action === 'none') return
    if (alert.action === 'wechat_setting') {
      return wx.openSetting({
        withSubscriptions: true,
        success: () => this.loadSubscriptionHealth(true),
        fail: () => wx.showToast({ title: '无法打开微信授权设置', icon: 'none' })
      })
    }
    if (alert.action === 'top_up') {
      return SubscribeMessage.requestImportantTopUp({ silent: true }).then(result => {
        if (result.acceptedCount > 0 && result.filteredCount > 0) wx.showToast({ title: '已 +' + result.acceptedCount + ' 类，' + result.filteredCount + ' 类被微信过滤', icon: 'none' })
        else if (result.acceptedCount > 0) wx.showToast({ title: '已为 ' + result.acceptedCount + ' 类提醒 +1', icon: 'success' })
        else if (result.filteredCount > 0) wx.showToast({ title: '模板被微信过滤，请检查后台模板标题', icon: 'none' })
        else if (result.status === 'not_prepared') return this.openSubscribeSettings()
        else wx.showToast({ title: '本次未增加提醒次数', icon: 'none' })
        return this.loadSubscriptionHealth(true)
      }).catch(() => this.openSubscribeSettings())
    }
    this.openSubscribeSettings()
  },

  selectMode(e) {
    const mode = e.currentTarget.dataset.mode
    if (mode !== 'todo' && mode !== 'all') return
    this.setData({ viewMode: mode,resumeHint:!!this.tabStale[mode] },()=>this.restoreScroll())
  },

  markFresh(mode){this.tabStale[mode]=false;if(this.data.viewMode===mode)this.setData({resumeHint:false})},
  refreshCurrent(){this.onPullDownRefresh()},
  onPageScroll(event){this.scrollPositions[this.data.viewMode]=Math.max(0,Number(event.scrollTop)||0)},
  restoreScroll(){if(typeof wx.pageScrollTo==='function')wx.pageScrollTo({scrollTop:this.scrollPositions[this.data.viewMode]||0,duration:0})},

  loadTodos(silent) {
    const serial=(this.todoLoadSerial||0)+1;this.todoLoadSerial=serial
    this.setData({todoLoading:!silent,todoLoadingMore:false,todoErrorText:''})
    const summary=NotificationState.refresh({force:true}).catch(()=>null)
    return Promise.all([summary,this.loadTodoScope('mine',1),this.loadTodoScope('worker',1)]).then(([counts,...groups])=>{
      if(serial!==this.todoLoadSerial||!this.isCurrent())return
      this.todoScopes={}
      this.mergeTodoGroups(groups,true,counts);this.todoLoadedOnce=true;this.markFresh('todo')
    }).catch(error=>{
      if(serial!==this.todoLoadSerial||!this.isCurrent())return
      this.setData({todoErrorText:error.message||'待办暂时无法加载，请稍后重试'})
    }).finally(()=>{if(serial===this.todoLoadSerial&&this.isCurrent())this.setData({todoLoading:false});wx.stopPullDownRefresh()})
  },
  loadTodoScope(kind,page) {
    return OrderApi.request('orders.center','GET',null,{kind,status:'todo',page}).then(res=>({kind,page,items:res.data.items||[],hasMore:!!res.data.has_more,total:Math.max(0,Number(res.data.todo_count)||0)}))
  },
  mergeTodoGroups(groups,reset,summary) {
    const map=reset?{}:Object.assign({},this.currentTodoMap)
    const keys=reset?[]:this.data.todos.map(t=>t.detail_page+':'+t.order_id)
    groups.forEach(group=>{
      this.todoScopes[group.kind]={page:group.page,hasMore:group.hasMore,total:group.total}
      group.items.forEach(item=>{
        const todo=this.decorateTodo(item,group.kind),key=todo.detail_page+':'+todo.order_id
        if(!map[key])keys.push(key)
        map[key]=todo
      })
    })
    let todos=keys.map(key=>map[key])
    if(reset)todos.sort((a,b)=>b.priority-a.priority)
    this.currentTodoMap=map
    const total=Object.values(this.todoScopes).reduce((n,scope)=>n+scope.total,0)
    this.setData({todos,todoCount:Math.max(todos.length,total,Number(summary&&summary.todoCount)||0),todoHasMore:Object.values(this.todoScopes).some(scope=>scope.hasMore),todoErrorText:''})
    this.rebuildMessages()
  },
  loadMoreTodos() {
    if(this.data.todoLoading||this.data.todoLoadingMore||!this.data.todoHasMore||!this.isCurrent())return Promise.resolve()
    const serial=this.todoLoadSerial
    const calls=Object.keys(this.todoScopes).filter(k=>this.todoScopes[k].hasMore).map(k=>this.loadTodoScope(k,this.todoScopes[k].page+1))
    this.setData({todoLoadingMore:true})
    return Promise.all(calls).then(groups=>{if(serial===this.todoLoadSerial&&this.isCurrent())this.mergeTodoGroups(groups,false)})
      .catch(error=>{if(serial===this.todoLoadSerial&&this.isCurrent())this.setData({todoErrorText:error.message||'更多待办加载失败，请重试'})})
      .finally(()=>{if(serial===this.todoLoadSerial&&this.isCurrent())this.setData({todoLoadingMore:false})})
  },

  decorateTodo(item, kind) {
    const worker = kind !== 'mine'
    const action = String(item.todo_action || (worker ? '处理订单' : '查看待办'))
    const meta = this.todoMeta(action)
    return {
      order_id: item.id,todo_key:(worker?'worker':'user')+':'+String(item.id),
      detail_page: worker ? 'worker' : 'user',
      role_text: worker ? '我的接单' : '我的下单',
      role_class: worker ? 'worker' : 'user',
      title: item.title || ((item.category_name || '') + '订单') || '订单待办',
      category_name: item.category_name || '订单',
      address_text: item.display_address || item.address || '',
      visit_text: item.expected_visit_at || '',
      action_text: action,
      hint_text: meta.hint,
      tone_class: meta.tone,
      icon_class: meta.icon,
      priority: meta.priority,
      urgency_class: meta.urgency,
      urgency_text: meta.urgency === 'must' ? '必须处理' : (meta.urgency === 'attention' ? '需要关注' : '普通待办')
    }
  },

  todoMeta(action) {
    if (/维修|售后/.test(action)) return { hint: '用户已发起售后，请尽快响应处理', tone: 'after-sale', icon: 'cuIcon-repair', priority: 100, urgency: 'must' }
    if (/支付/.test(action)) return { hint: '完成支付后订单才能继续推进', tone: 'money', icon: 'cuIcon-pay', priority: 95, urgency: 'must' }
    if (/接单/.test(action)) return { hint: '请确认是否承接，避免用户长时间等待', tone: 'worker', icon: 'cuIcon-people', priority: 90, urgency: 'must' }
    if (/补充/.test(action)) return { hint: '订单资料需要补充后才能继续审核', tone: 'order', icon: 'cuIcon-edit', priority: 85, urgency: 'attention' }
    if (/费用|跑空/.test(action)) return { hint: '有费用事项等待你确认', tone: 'money', icon: 'cuIcon-moneybag', priority: 80, urgency: 'attention' }
    if (/完工|验收/.test(action)) return { hint: '施工已提交，请核对后确认', tone: 'order', icon: 'cuIcon-roundcheck', priority: 75, urgency: 'attention' }
    if (/报价/.test(action)) return { hint: '已有师傅报价，选择后可继续安排施工', tone: 'worker', icon: 'cuIcon-tag', priority: 70, urgency: 'normal' }
    return { hint: '此订单有事项等待你处理', tone: 'order', icon: 'cuIcon-form', priority: 60, urgency: 'normal' }
  },

  openTodo(e) {
    const item = this.data.todos[Number(e.currentTarget.dataset.index)]
    if (!item) return
    const page = item.detail_page === 'worker' ? '/pages/workerOrder/detail' : '/pages/userOrder/detail'
    wx.navigateTo({ url: page + '?id=' + encodeURIComponent(item.order_id) })
  },

  load(reset, silent) {
    if(!this.isCurrent()||(!reset&&(this.data.loading||this.data.loadingMore||!this.notificationCursor)))return Promise.resolve()
    const serial=(this.loadSerial||0)+1;this.loadSerial=serial
    const scope=this.scopeKey(),cursor=reset?'':this.notificationCursor,readRevision=this.readRevision
    this.setData({loading:silent?false:reset,loadingMore:!reset,errorText:''})
    return OrderApi.request('notifications.threads','GET',null,{cursor}).then(res=>{
      if(serial!==this.loadSerial||!this.isCurrent(scope))return
      if(readRevision!==this.readRevision)throw Object.assign(new Error('通知状态已更新，请重试'),{code:'NOTIFICATION_SNAPSHOT_CHANGED'})
      const data=res.data||{}
      if(!reset&&data.range_cursor!==this.readRangeCursor)throw Object.assign(new Error('通知浏览范围已变化，请下拉刷新'),{code:'NOTIFICATION_RESYNC_REQUIRED'})
      if(data.schema!==2||!Array.isArray(data.items)||!data.range_cursor||(data.has_more&&!data.next_cursor))throw new Error('通知分页响应无效，请重试')
      const map=new Map((reset?[]:this.rawNotificationItems||[]).map(row=>[row.thread_key,row]))
      data.items.forEach(row=>{if(row&&row.thread_key&&!map.has(row.thread_key))map.set(row.thread_key,row)})
      if(reset){this.threadCache={};this.expandedThreads={}}
      this.rawNotificationItems=Array.from(map.values())
      this.notificationCursor=String(data.next_cursor||'');this.readRangeCursor=String(data.range_cursor)
      this.setData({page:reset?2:this.data.page+1,readThroughId:String(data.read_through_id||''),hasMore:!!data.has_more,unreadCount:Math.max(0,Number(data.unread_count)||0)})
      NotificationState.setLocal({unreadCount:this.data.unreadCount})
      this.rebuildMessages();this.loadedOnce=true;this.markFresh('all')
    }).catch(error=>{
      if(serial!==this.loadSerial||!this.isCurrent(scope))return
      this.setData({errorText:error&&error.message||'通知暂时无法加载，请重试'})
      // Expiry/deletion is a refresh request; keep the visible snapshot and both tab positions.
      if(error&&error.code==='NOTIFICATION_RESYNC_REQUIRED')this.notificationCursor=''
    }).finally(()=>{
      if(serial===this.loadSerial&&this.isCurrent(scope))this.setData({loading:false,loadingMore:false})
      wx.stopPullDownRefresh()
    })
  },

  rebuildMessages() {
    const decorated = this.decorateItems(this.rawNotificationItems || [])
    const items = this.aggregateItems(decorated)
    let previousGroup = ''
    items.forEach(item => {
      const group = this.dateGroup(item.created_at)
      item.group_text = group
      item.show_group = group !== previousGroup
      previousGroup = group
    })
    this.setData({ items })
  },

  decorateItems(items) {
    return (items || []).map(item => {
      const meta = this.notificationMeta(item.template_key)
      return Object.assign({}, item, meta, {
        role_text: item.recipient_type === 'worker' ? '我的接单' : '我的下单',
        role_class: item.recipient_type === 'worker' ? 'worker' : 'user',
        time_text: this.shortTime(item.created_at),
        action_text: item.order_no ? (item.recipient_type === 'worker' ? '查看订单' : '查看订单') : ''
      })
    })
  },

  aggregateItems(items) {
    return (items||[]).map(item=>{
      const cache=this.threadCache[item.thread_key]||{items:[],cursor:item.thread_cursor,hasMore:true,loaded:false}
      const todo=(this.currentTodoMap||{})[item.detail_page+':'+item.order_no]||null
      const urgency=this.messageUrgency([Object.assign({},item,{read:!item.unread_count})],todo)
      return Object.assign({},item,{
        id:item.is_thread?'thread:'+item.thread_key:item.id,read:!item.unread_count,
        messages:this.decorateItems(cache.items),expanded:!!this.expandedThreads[item.thread_key],
        threadLoading:!!cache.loading,threadError:cache.error||'',threadHasMore:cache.loaded&&cache.hasMore,
        action_text:todo?todo.action_text:(item.order_no?'查看订单':''),current_todo:!!todo,
        urgency_class:urgency.level,urgency_text:urgency.text
      })
    })
  },

  messageUrgency(messages, todo) {
    const latest = (messages || [])[0]
    // V2.2.5：自动评价只是状态收尾通知，不得因历史线程曾经重要而继续占用高优先级。
    const latestKey = String(latest && latest.template_key || '').toLowerCase()
    if (/(review_auto_created|auto_reviewed_5d|review_submitted)/.test(latestKey) && !todo) return { level: 'normal', text: '普通通知' }
    if (todo) return { level: 'must', text: '必须处理' }
    if (!latest || latest.read) return { level: 'normal', text: '普通通知' }
    const text = (String(latest.template_key || '') + ' ' + String(latest.title || '')).toLowerCase()
    if (/(dispute|platform_intervention|failed|refund|cancel|supplement_required)/.test(text)) {
      return { level: 'attention', text: '需要关注' }
    }
    return { level: 'normal', text: '普通通知' }
  },

  notificationMeta(key) {
    key = String(key || '').toLowerCase()
    if (/(refund|cancel|payment|paid|run_empty|withdraw|commission)/.test(key)) {
      return { category_text: '资金与支付', icon_class: 'cuIcon-pay', tone_class: 'money' }
    }
    if (/(repair|warranty|dispute|after_sale)/.test(key)) {
      return { category_text: '售后处理', icon_class: 'cuIcon-repair', tone_class: 'after-sale' }
    }
    if (/(quote|worker_assigned|assignment|invite)/.test(key)) {
      return { category_text: '报价与接单', icon_class: 'cuIcon-people', tone_class: 'worker' }
    }
    if (/(appointment|completion|change|supplement|order_)/.test(key)) {
      return { category_text: '订单进度', icon_class: 'cuIcon-form', tone_class: 'order' }
    }
    if (/(realname|collection|profile|credential|merchant)/.test(key)) {
      return { category_text: '账户服务', icon_class: 'cuIcon-profile', tone_class: 'account' }
    }
    return { category_text: '系统通知', icon_class: 'cuIcon-notice', tone_class: 'system' }
  },

  toggleThread(e) {
    const index = Number(e.currentTarget.dataset.index)
    const item = this.data.items[index]
    if (!item || !item.is_thread) return
    const expanded = !item.expanded
    this.expandedThreads[item.thread_key] = expanded
    this.setData({ ['items[' + index + '].expanded']: expanded })
    if(expanded)this.loadThread(item.thread_key,false)
  },

  openMessage(e) {
    const scope=this.scopeKey()
    const index = Number(e.currentTarget.dataset.index)
    const item = this.data.items[index]
    if (!item || item.is_thread) return
    this.ensureStandaloneRead(item).then(() => {
      if(!this.isCurrent(scope))return
      if (item.order_no) return this.goOrder(item)
      wx.showToast({ title: '已读', icon: 'none' })
    })
  },

  openThreadOrder(e) {
    const scope=this.scopeKey()
    const index = Number(e.currentTarget.dataset.index)
    const item = this.data.items[index]
    if (!item || !item.order_no) return
    const done = item.is_thread ? this.ensureThreadRead(index, item) : this.ensureStandaloneRead(item)
    done.then(() => {if(this.isCurrent(scope))this.goOrder(item)})
  },

  loadMoreThread(event){
    const item=this.data.items[Number(event.currentTarget.dataset.index)]
    if(item&&item.is_thread&&item.expanded){if((this.threadCache[item.thread_key]||{}).resync)return this.load(true,true);return this.loadThread(item.thread_key,true)}
  },
  loadThread(key,more) {
    const row=(this.rawNotificationItems||[]).find(item=>item.thread_key===key)
    if(!row||!this.isCurrent())return Promise.resolve()
    let cache=this.threadCache[key]
    if(!cache)cache=this.threadCache[key]={items:[],cursor:row.thread_cursor,hasMore:true,loaded:false,loading:false,error:''}
    if(cache.loading)return Promise.resolve()
    if(cache.loaded&&!more)return this.ensureThreadRead(0,{thread_key:key,messages:cache.items})
    if(!cache.cursor||!cache.hasMore)return Promise.resolve()
    const scope=this.scopeKey(),range=this.readRangeCursor,requestCursor=cache.cursor
    cache.loading=true;cache.error='';this.rebuildMessages()
    return OrderApi.request('notifications.thread_messages','GET',null,{cursor:requestCursor}).then(res=>{
      if(!this.isCurrent(scope)||range!==this.readRangeCursor||this.threadCache[key]!==cache)return
      const data=res.data||{}
      if(data.schema!==2||data.thread_key!==key||!Array.isArray(data.items)||(data.has_more&&!data.next_cursor))throw new Error('通知记录响应无效，请重试')
      const ids=new Set(cache.items.map(item=>item.id))
      cache.items=cache.items.concat(data.items.filter(item=>!ids.has(item.id)&&ids.add(item.id)))
      cache.cursor=String(data.next_cursor||'');cache.hasMore=!!data.has_more;cache.loaded=true
      this.rebuildMessages()
      if(this.expandedThreads[key])return this.ensureThreadRead(0,{thread_key:key,messages:cache.items})
    }).catch(error=>{
      if(!this.isCurrent(scope)||range!==this.readRangeCursor||this.threadCache[key]!==cache)return
      cache.resync=error&&error.code==='NOTIFICATION_RESYNC_REQUIRED'
      cache.error=error&&error.message||'记录暂时无法加载，请重试'
    }).finally(()=>{
      if(this.isCurrent(scope)&&this.threadCache[key]===cache){cache.loading=false;this.rebuildMessages()}
    })
  },
  refreshUnread(scope) {
    NotificationState.invalidate()
    return NotificationState.refresh({force:true}).then(summary=>{
      if(this.isCurrent(scope)&&Number.isSafeInteger(summary.unreadCount))this.setData({unreadCount:summary.unreadCount})
    }).catch(()=>{})
  },
  markVisibleRead(messages) {
    const scope=this.scopeKey(),range=this.readRangeCursor
    const unread=messages.filter(item=>!item.read&&!this.pendingReads.has(item.id))
    if(!unread.length||!this.isCurrent(scope))return Promise.resolve()
    const token={};unread.forEach(item=>this.pendingReads.set(item.id,token))
    let chain=Promise.resolve()
    for(let offset=0;offset<unread.length;offset+=50){
      const batch=unread.slice(offset,offset+50),ids=batch.map(item=>item.id)
      chain=chain.then(()=>{
        if(!this.isCurrent(scope)||range!==this.readRangeCursor)return
        return OrderApi.request('notifications.read_batch','POST',{ids}).then(res=>{
          if(!this.isCurrent(scope)||range!==this.readRangeCursor)return
          this.readRevision++
          const readIds=new Set(ids)
          this.rawNotificationItems=(this.rawNotificationItems||[]).map(row=>{
            const cache=this.threadCache[row.thread_key]
            const known=cache?cache.items:[row]
            const changed=known.filter(item=>!item.read&&readIds.has(item.id)).length
            return Object.assign({},row,{read:row.read||readIds.has(row.id),unread_count:Math.max(0,row.unread_count-changed)})
          })
          Object.values(this.threadCache).forEach(cache=>{cache.items=cache.items.map(item=>readIds.has(item.id)?Object.assign({},item,{read:true}):item)})
          const changed=Math.max(0,Math.min(ids.length,Number(res.data&&res.data.updated_count)||0))
          this.setData({unreadCount:Math.max(0,this.data.unreadCount-changed)})
          NotificationState.setLocal({unreadCount:this.data.unreadCount});this.rebuildMessages()
        })
      })
    }
    return chain.catch(error=>{
      if(this.isCurrent(scope))wx.showToast({title:error.message||'消息状态同步失败',icon:'none'})
    }).finally(()=>{
      unread.forEach(item=>{if(this.pendingReads.get(item.id)===token)this.pendingReads.delete(item.id)})
      if(this.isCurrent(scope))this.refreshUnread(scope)
    })
  },
  ensureThreadRead(index,thread) {return this.markVisibleRead((this.threadCache[thread.thread_key]||{}).items||[])},
  ensureStandaloneRead(item) {
    const row=(this.rawNotificationItems||[]).find(value=>value.thread_key===item.thread_key)
    return this.markVisibleRead(row?[Object.assign({},row,{read:!row.unread_count})]:[])
  },

  dateGroup(value) {
    const date = this.parseServerTime(value)
    if (!date) return '更早'
    const now = new Date()
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()
    const day = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime()
    const diff = Math.round((today - day) / 86400000)
    if (diff === 0) return '今天'
    if (diff === 1) return '昨天'
    if (diff > 1 && diff < 7) return '近 7 天'
    return '更早'
  },

  shortTime(value) {
    const date = this.parseServerTime(value)
    if (!date) return String(value || '')
    const now = new Date()
    const sameDay = now.getFullYear() === date.getFullYear() && now.getMonth() === date.getMonth() && now.getDate() === date.getDate()
    const pad = n => (Number(n) < 10 ? '0' : '') + Number(n)
    if (sameDay) return pad(date.getHours()) + ':' + pad(date.getMinutes())
    return (date.getMonth() + 1) + '月' + date.getDate() + '日'
  },

  parseServerTime(value) {
    const text = String(value || '').trim()
    if (!text) return null
    const normalized = text.replace(/-/g, '/').replace('T', ' ')
    const date = new Date(normalized)
    return Number.isNaN(date.getTime()) ? null : date
  },

  markAllRead() {
    if(!this.data.unreadCount||this.data.readAllLoading||!this.readRangeCursor||!this.isCurrent())return
    const scope=this.scopeKey(),range_cursor=this.readRangeCursor
    this.setData({readAllLoading:true})
    return OrderApi.request('notifications.read_all','POST',{range_cursor}).then(()=>{
      if(!this.isCurrent(scope))return
      this.readRevision++;NotificationState.invalidate()
      return this.load(true,true)
    }).catch(error=>{if(this.isCurrent(scope))wx.showToast({title:error.message||'操作失败，请重试',icon:'none'})})
      .finally(()=>{if(this.isCurrent(scope))this.setData({readAllLoading:false})})
  },

  goOrder(item) {
    const page = item.detail_page === 'worker' ? '/pages/workerOrder/detail' : '/pages/userOrder/detail'
    wx.navigateTo({ url: page + '?id=' + encodeURIComponent(item.order_no) })
  },

  retry() {
    if (this.data.viewMode === 'todo') return this.loadTodos()
    return this.load(true)
  },
  onPullDownRefresh() {
    this.scrollPositions[this.data.viewMode]=0
    if(this.data.viewMode==='todo')this.loadTodos(true)
    else this.load(true,true)
  },
  onReachBottom() {
    if (this.data.viewMode === 'todo') this.loadMoreTodos()
    else if (this.data.hasMore) this.load(false)
  }
})
