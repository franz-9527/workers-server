'use strict'
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const path = require('node:path')
const root = path.resolve(__dirname, '../snapshot/miniprogram')
const file = p => fs.readFileSync(path.join(root, p), 'utf8')
const inbox = require(path.join(root, 'utils/ChatInboxModel.js'))
function flush() { return new Promise(resolve => setImmediate(resolve)) }
function mount(rel, mocks, extra) {
  let config = null
  const context = {
    require(name) { if (!mocks[name]) throw Error('unexpected import: ' + name); return mocks[name] },
    Page(options) { config = options },
    getApp: extra.getApp,
    wx: extra.wx,
    console, Promise, Number, String, Object, Array, Math, Date, encodeURIComponent
  }
  vm.runInNewContext(file(rel), context, { filename: rel })
  assert.ok(config, 'Page registered: ' + rel)
  const instance = Object.assign({}, config)
  instance.data = JSON.parse(JSON.stringify(config.data))
  instance.setData = function(patch) { Object.assign(this.data, patch) }
  instance.getTabBar = () => ({ setData() {} })
  return instance
}

async function main() {
  const appConfig = JSON.parse(file('app.json'))
  assert.equal(appConfig.tabBar.list[2].pagePath, 'pages/chatMessages/index')
  assert.ok(appConfig.pages.includes('pages/chatMessages/index'))
  assert.ok(appConfig.pages.includes('pages/notificationCenter/index'), 'original PHP notification page preserved')
  assert.equal(appConfig.tabBar.list.some(x => x.pagePath === 'pages/notificationCenter/index'), false)
  assert.ok(appConfig.subpackages.some(x => x.root === 'subpackages/chat' && x.pages.includes('conversation/index')))
  assert.ok(appConfig['mini-ios'].pages.includes('pages/chatMessages/index'))
  assert.ok(appConfig['mini-android'].pages.includes('pages/chatMessages/index'))
  const bar = file('custom-tab-bar/index.js')
  assert.match(bar, /pagePath:\s*"\/pages\/chatMessages\/index"/)
  assert.match(bar, /chat\.svg/)
  assert.match(file('custom-tab-bar/index.wxml'), /index === 3 && notificationBadgeCount/)
  assert.equal(JSON.parse(file('pages/notificationCenter/index.json')).navigationBarTitleText, '通知与待办')
  const html = file('pages/chatMessages/index.wxml')
  assert.match(html, /bindtap="openTodo"/)
  assert.match(html, /bindtap="openNotices"/)
  assert.match(html, /bindtap="openConversation"/)
  assert.match(html, /订单聊天即将开放/)
  assert.doesNotMatch(file('pages/chatMessages/index.js'), /receipt\.read|markAllRead|notifications\.read_all/)
  const oldPHP = file('pages/notificationCenter/index.js')
  assert.match(oldPHP, /loadTodos\(/)
  assert.match(oldPHP, /markAllRead\(/)
  assert.match(oldPHP, /notifications\.read_all/)
  assert.match(oldPHP, /options\.view === 'all'/)
  assert.match(file('pages/mine/mine.wxml'), /通知与待办/)
  const home = file('pages/index/index.js')
  assert.match(home, /canonical === '\/pages\/store\/store'/)
  assert.match(home, /'\/pages\/chatMessages\/index'/)
  assert.doesNotMatch(home, /'\/pages\/notificationCenter\/index', '\/pages\/mine\/mine'/)
  const store = file('pages/store/store.js')
  assert.doesNotMatch(store, /selected:\s*3/)
  const {ChatManager}=require(path.join(root,'utils/ChatManager.js'))
  const sample = new ChatManager({setTimeout(){return 1},clearTimeout(){}})
  sample.enabled=true;sample.foreground=true;sample.network=true;sample.digestCursor='later-page'
  sample.refreshAccount()
  assert.equal(sample.digestCursor,'')
  sample.reconciling=Promise.resolve();sample.digestCursor='in-flight-page'
  sample.refreshAccount()
  assert.equal(sample.restartDigestAfterCurrent,true)
  console.log('PASS retained PHP actions, ordinary store navigation and fresh digest lifecycle')
  console.log('PASS navigation, legacy notification retention, gated inbox template')

  assert.equal(inbox.normalize({ conversation_id: 'invalid', unread: { unread_count: 5 }}), null)
  assert.equal(inbox.normalize({ conversation_id: '15', unread: { unread_count: 107 }}).unreadLabel, '99+')
  assert.equal(inbox.normalize({ conversation_id: '15', unread: { unread_count: -7 }}).unreadCount, 0)
  let model = inbox.applyPage({}, {replace:true,items:[{conversation_id:'15',unread:{unread_count:3}},{conversation_id:'22',unread:{unread_count:0}}]})
  assert.deepEqual(inbox.toRows(model,'unread').map(x=>x.conversationId), ['15'])
  model = inbox.applyPage(model,{replace:false,items:[{conversation_id:'15',unread:{unread_count:0}}]})
  assert.equal(inbox.toRows(model,'unread').length,0)
  assert.equal(inbox.toRows(model).length,2)
  const large = Array.from({length:122},(_,i)=>({conversation_id:String(i+1),unread:{unread_count:0}}))
  assert.equal(Object.keys(inbox.applyPage({}, {replace:true,items:large})).length,100)
  console.log('PASS authorized summary normalization, deduplication, bounded memory')

  let user = {openid:'actor-A',mobile:'13800138000'}
  const app = {globalData:{get userInfo(){ return user }},ensureUserReady:()=>Promise.resolve(user)}
  const nav = []
  const wx = {
    navigateTo(data) {nav.push(['navigate',data.url])},
    switchTab(data) {nav.push(['tab',data.url])},
    stopPullDownRefresh() {},
    showToast() {}
  }
  const notifications = {
    subscribe() {return () => {}},
    refresh() {return Promise.resolve({unreadCount:2,todoCount:1,topTodo:{action:'支付预约费',role:'我的下单'}})}
  }
  let pollCount=0, listeners=[]
  const manager={
    enabled:false,epoch:1,state:'disabled',
    subscribe(cb){listeners.push(cb);return () => {listeners=listeners.filter(x=>x!==cb)}},
    schedulePoll(){pollCount++},
    refreshAccount(){pollCount++;this.digestCursor=''},
    focus(id){this.active=id},
    active:''
  }
  const runtime={get:()=>manager}
  const mocks={
    '../../utils/NotificationState.js':notifications,
    '../../utils/ChatRuntime.js':runtime,
    '../../utils/ChatInboxModel.js':inbox,
    '../../utils/ApiEnvironment.js':{current:()=>({baseURL:'https://testing.invalid/api/'})}
  }
  const page=mount('pages/chatMessages/index.js',mocks,{getApp:()=>app,wx})
  page.onLoad();page.onShow();await flush()
  assert.equal(page.data.chatEnabled,false)
  assert.equal(page.data.isLoggedIn,true)
  assert.equal(page.data.todoCount,1)
  assert.equal(page.data.noticeUnreadCount,2)
  assert.equal(pollCount,0,'closed chat must not schedule Go API polls')
  page.openTodo();page.openNotices()
  assert.deepEqual(nav.slice(-2),[
    ['navigate','/pages/notificationCenter/index?view=todo'],
    ['navigate','/pages/notificationCenter/index?view=all']
  ])
  assert.equal(nav.some(x=>x[0]==='tab'&&x[1].includes('notificationCenter')),false)
  console.log('PASS disabled chat, live PHP summary, retained notice/todo navigation')

  manager.enabled=true;manager.state='offline'
  page.onShow();await flush()
  assert.ok(pollCount>0)
  listeners.forEach(fn=>fn('digests',{replace:true,has_more:false,items:[{conversation_id:'12',unread:{unread_count:4}},{conversation_id:'13',unread:{unread_count:0}}]}))
  assert.equal(page.data.chatRows.length,2)
  assert.equal(page.data.chatRows[0].conversationId,'12')
  page.selectChatFilter({currentTarget:{dataset:{filter:'unread'}}})
  assert.equal(page.data.visibleChatRows.length,1)
  page.openConversation({currentTarget:{dataset:{id:'12'}}})
  assert.equal(nav.at(-1)[1],'/subpackages/chat/conversation/index?conversation_id=12')
  listeners.forEach(fn=>fn('conversation.clear',{conversation_id:'12'}))
  assert.equal(page.data.visibleChatRows.length,0,'revoked conversation removed immediately')
  listeners.forEach(fn=>fn('digests',{replace:true,items:[{conversation_id:'14',unread:{unread_count:1}}]}))
  manager.epoch++
  page.onHide();page.onShow()
  assert.equal(page.data.chatRows.length,0,'session epoch reset clears old rows')
  await flush()
  user={openid:'actor-B',mobile:'13900139000'}
  page.onHide();page.onShow();await flush()
  assert.equal(page.data.chatRows.length,0,'account switch cannot re-display prior account')
  page.onUnload()
  console.log('PASS gated live digest, unread filter, chat navigation, revocation and account switching')

  const detail=mount('subpackages/chat/conversation/index.js',{
    '../../../utils/ChatRuntime.js':runtime,
    '../../../utils/ChatInboxModel.js':inbox
  },{getApp:()=>app,wx})
  detail.onLoad({conversation_id:'23'});detail.onShow()
  assert.equal(manager.active,'23')
  listeners.forEach(fn=>fn('conversation.reset',{conversation_id:'23',page:{messages:[
    {message_id:'101',seq:'1',state_version:'1',state:'published',message_type:'text',text:'施工安排'},
    {message_id:'102',seq:'2',state_version:'2',state:'recalled',message_type:'text',text:'must not leak'}
  ],changes:[]}}))
  assert.equal(detail.data.messages.length,2)
  assert.equal(detail.data.messages[0].body,'施工安排')
  assert.equal(detail.data.messages[1].body,'消息已撤回')
  assert.equal(JSON.stringify(detail.data.messages).includes('must not leak'),false)
  manager.epoch++;detail.onHide();detail.onShow()
  assert.equal(detail.data.messages.length,0)
  detail.onUnload()
  assert.equal(manager.active,'')
  console.log('PASS native read-only detail, recalled text redaction, actor session reset')
}
main().catch(e=>{console.error(e);process.exitCode=1})
