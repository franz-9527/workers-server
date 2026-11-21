const test=require('node:test'),assert=require('node:assert/strict'),fs=require('fs'),vm=require('vm')
const {ChatManager}=require('../miniprogram/utils/ChatManager.js')
const flush=async()=>{for(let i=0;i<12;i++)await new Promise(setImmediate)}
const read=p=>fs.readFileSync(p,'utf8')
test('503 credential failure is shared by connect and poll, cached for 60 seconds, then can recover',async()=>{
 let now=100000,calls=0,healthy=false;const timers=new Map();let n=0
 const m=new ChatManager({now:()=>now,random:()=>0.5,setTimeout(fn,delay){timers.set(++n,{fn,delay});return n},clearTimeout(id){timers.delete(id)},
  credential:async()=>{calls++;if(!healthy)throw Object.assign(Error('down'),{code:'CHAT_DEPENDENCY_UNAVAILABLE',statusCode:503});return {access_token:'fixture',expires_at:new Date(now+300000).toISOString()}},
  http:async()=>({items:[],has_more:false}),socket(){throw Error('not used')}})
 m.enabled=true;m.foreground=true
 const first=m.connect(),poll=m.reconcile();await Promise.allSettled([first,poll]);assert.equal(calls,1)
 for(let i=0;i<8;i++){await m.connect();await m.reconcile().catch(()=>{});now+=5000}assert.equal(calls,1)
 assert.ok([...timers.values()].some(t=>t.delay>=60000))
 now=160001;healthy=true;await m.credential(m.epoch);assert.equal(calls,2);assert.equal(m.credentialFailure,null)
 m.setForeground(false)
})
test('manual retry and identity reset discard old credential failure',async()=>{
 let now=100000,calls=0;const m=new ChatManager({now:()=>now,setTimeout:()=>1,clearTimeout(){},random:()=>0.5,credential:async()=>{calls++;throw Object.assign(Error('not ready'),{statusCode:503})}})
 m.enabled=true;m.foreground=true;await m.credential(m.epoch).catch(()=>{});assert.equal(calls,1)
 m.foreground=false;m.retryConnection();assert.equal(m.credentialFailure,null)
 m.reset();assert.equal(m.credentialFailure,null)
})
function notice(){
 let event,summary,revision=1,current,currentCall,vibrations=0,participantRequests=0
 const timers=new Map(),destinations=[],callActions=[],navigationCompletions=[]
 let timerID=0
 const ChatIdentity=require('../miniprogram/utils/ChatIdentity.js')
 const manager={
  epoch:1,foreground:true,
  calls:{
   actorID:'1',current:null,
   async action(action){
    callActions.push(action)
    if(!this.current)throw Error('no call')
    this.current={...this.current,state:action==='accept'?'preparing':'rejected'}
    event('calls.changed',this.current)
    return this.current
   }
  },
  io:{participants:async requests=>{participantRequests++;return {items:requests.map(r=>({conversation_id:r.conversation_id,policy_version:r.policy_version,viewer_actor_id:'1',members:[
   {actor_id:'1',role:'user',avatar:'user',display_name:'用户A1'},
   {actor_id:'2',role:'worker',avatar:'worker',display_name:'师傅B2'}
  ]}))}}},
  subscribe(fn){event=fn}
 }
 const deps={
  NotificationState:{subscribe(fn){summary=fn},refresh:()=>Promise.resolve()},
  ChatRuntime:{get:()=>manager},
  ChatIdentity,
  CallSound:{sync(){},clear(){}},
  OrderApi:{contextRevision:()=>revision}
 }
 const module={exports:{}}
 vm.runInNewContext(read('miniprogram/utils/InAppNotice.js'),{
  module,require:p=>deps[p.replace('./','').replace('.js','')],Date,Map,console,
  setTimeout(fn,delay){timers.set(++timerID,{fn,delay});return timerID},clearTimeout(id){timers.delete(id)},
  wx:{navigateTo:options=>{destinations.push(options.url);navigationCompletions.push(()=>{if(options.success)options.success();if(options.complete)options.complete()})},vibrateShort(){vibrations++},showToast(){}}
 })
 const service=module.exports,owner={}
 service.subscribe(value=>current=value)
 service.subscribeCall(value=>currentCall=value)
 service.setForeground(true)
 service.show(owner,'pages/index/index')
 const emit=(type,payload)=>{if(['calls.incoming','calls.changed'].includes(type))manager.calls.current=payload;event(type,payload)}
 return {service,current:()=>current,call:()=>currentCall,summary:value=>summary({businessSource:{ready:true,stale:false},...value}),
  event:emit,destinations,timers,callActions,vibrations:()=>vibrations,participantRequests:()=>participantRequests,finishNavigation(){const done=navigationCompletions.shift();if(done)done()},route:(route,options)=>service.show(owner,route,options),switchIdentity:()=>{revision++}}
}
test('home waits for the first snapshot and then shows only new business notices and todos',()=>{
 const f=notice(),old={id:'1',title:'旧通知'},todo={orderId:'A',action:'支付预约费',role:'我的下单',detailPage:'user'}
 f.summary({latestNotification:old,topTodo:todo});assert.equal(f.current(),null)
 f.summary({latestNotification:{id:'2',title:'新通知'},topTodo:todo});assert.equal(f.current().title,'新通知')
 f.service.activate();assert.deepEqual(f.destinations,['/pages/notificationCenter/index?view=all'])
 f.summary({latestNotification:{id:'2'},topTodo:{...todo,orderId:'B'}});assert.equal(f.current().kind,'todo')
 f.service.activate();assert.equal(f.destinations[1],'/pages/userOrder/detail?id=B')
})
test('home shows new chat but does not repeat it inside the open conversation',()=>{
 const f=notice(),digest=(count,id)=>({items:[{conversation_id:'2',unread:{unread_count:count,last_message:{message_id:id,sender_id:'3'}}}],has_more:false})
 f.event('digests',digest(1,'11'));assert.equal(f.current(),null)
 f.event('digests',digest(2,'12'));assert.equal(f.current().kind,'chat')
 f.service.activate();assert.equal(f.destinations[0],'/subpackages/chat/conversation/index?conversation_id=2')
 f.route('subpackages/chat/conversation/index',{conversation_id:'2'})
 f.event('digests',digest(3,'13'));assert.equal(f.current(),null)
})
test('identity change and background do not show historical notices on home',()=>{
 const f=notice();f.summary({latestNotification:{id:'1'}})
 f.service.setForeground(false);f.summary({latestNotification:{id:'2'}})
 f.service.setForeground(true);f.summary({latestNotification:{id:'2'}});assert.equal(f.current(),null)
 f.switchIdentity();f.summary({topTodo:{orderId:'C',action:'选择报价'}});assert.equal(f.current(),null)
})
test('incoming call uses the floating call surface and accept enters the live call page',async()=>{
 const f=notice(),call={call_id:'21',conversation_id:'31',caller_id:'2',callee_id:'1',media:'audio',state:'ringing',policy_version:'1'}
 f.event('calls.changed',call);f.event('calls.incoming',call);await flush()
 assert.equal(f.call().callId,'21');assert.equal(f.call().peerName,'师傅B2');assert.equal(f.call().media,'audio')
 assert.equal(f.current(),null);assert.equal(f.vibrations(),1);assert.equal(f.participantRequests(),1)
 f.event('calls.changed',call);f.event('calls.incoming',call);await flush()
 assert.equal(f.vibrations(),1);assert.equal(f.participantRequests(),1)
 assert.equal(await f.service.answerCall('accept'),true)
 assert.deepEqual(f.callActions,['accept']);assert.equal(f.call(),null)
 assert.equal(f.destinations.at(-1),'/subpackages/chat/call/index?call_id=21')
})
test('invalidating the ringing conversation drops its pending name and actions',async()=>{
 const fixture=notice(),call={call_id:'21',conversation_id:'31',caller_id:'2',callee_id:'1',media:'audio',state:'ringing',policy_version:'1'}
 fixture.event('calls.changed',call);fixture.event('conversation.clear',{conversation_id:'32'});assert.equal(fixture.call().callId,'21')
 fixture.event('conversation.clear',{conversation_id:'31'});await flush();assert.equal(fixture.call(),null)
 assert.deepEqual(fixture.callActions,[])
})
test('ringing call restored by state polling clears itself on terminal state',()=>{
 const f=notice(),call={call_id:'22',conversation_id:'32',caller_id:'2',callee_id:'1',media:'video',state:'ringing',policy_version:'1'}
 f.event('calls.changed',call);assert.equal(f.call().media,'video');assert.equal(f.vibrations(),0)
 f.service.openCall();assert.equal(f.destinations.at(-1),'/subpackages/chat/call/index?call_id=22')
 f.event('calls.changed',{...call,state:'missed'});assert.equal(f.call(),null)
})
test('rapid call-card taps create one navigation until the first transition completes',()=>{
 const f=notice(),call={call_id:'24',conversation_id:'34',caller_id:'2',callee_id:'1',media:'audio',state:'ringing',policy_version:'1'}
 f.event('calls.changed',call);f.service.openCall();f.service.openCall();assert.equal(f.destinations.length,1)
 f.finishNavigation();f.service.openCall();assert.equal(f.destinations.length,2)
})
test('the matching conversation expands an incoming call to the immersive call page',()=>{
 const f=notice(),call={call_id:'26',conversation_id:'36',caller_id:'2',callee_id:'1',media:'audio',state:'ringing',policy_version:'1'}
 f.route('subpackages/chat/conversation/index',{conversation_id:'36'})
 f.event('calls.changed',call);f.event('calls.incoming',call)
 assert.equal(f.call(),null);assert.equal(f.destinations.at(-1),'/subpackages/chat/call/index?call_id=26')
})
test('web-view routes immediately expand the incoming call because the native surface can cover normal overlays',()=>{
 const f=notice(),call={call_id:'25',conversation_id:'35',caller_id:'2',callee_id:'1',media:'audio',state:'ringing',policy_version:'1'}
 f.route('subpackages/web/h5/index');f.event('calls.changed',call);f.event('calls.incoming',call)
 assert.equal(f.destinations.at(-1),'/subpackages/chat/call/index?call_id=25');assert.equal(f.vibrations(),1)
})
test('background clears the visible incoming call until a fresh ringing snapshot arrives',()=>{
 const f=notice(),call={call_id:'23',conversation_id:'33',caller_id:'2',callee_id:'1',media:'audio',state:'ringing',policy_version:'1'}
 f.event('calls.changed',call);assert.equal(f.call().callId,'23')
 f.service.setForeground(false);assert.equal(f.call(),null)
 f.service.setForeground(true);assert.equal(f.call(),null)
 f.event('calls.changed',call);assert.equal(f.call().callId,'23')
})
test('incoming call rendering no longer uses the native confirmation modal',()=>{
 const runtime=read('miniprogram/utils/ChatRuntime.js'),wxml=read('miniprogram/components/inapp-notice/index.wxml'),wxss=read('miniprogram/components/inapp-notice/index.wxss')
 assert.equal(runtime.includes("wx.showModal({title:view.media==='video'"),false)
 assert(wxml.includes('incoming-call-card')&&wxml.includes('data-action="reject"')&&wxml.includes('data-action="accept"'))
 assert(wxss.includes('.incoming-call-action.reject')&&wxss.includes('.incoming-call-action.accept')&&wxss.includes('min-width:104rpx'))
})
test('registered redirect and web surfaces host the incoming-call bridge',()=>{
 const hosts=["miniprogram/pages/plus/plus.wxml","miniprogram/pages/mine/settings.wxml","miniprogram/pages/recharge/recharge.wxml","miniprogram/pages/location/location.wxml","miniprogram/pages/mine/examine.wxml","miniprogram/pages/web-view/web.wxml","miniprogram/pages/search/search.wxml","miniprogram/pages/pay/pay.wxml","miniprogram/pages/h5/h5.wxml","miniprogram/pages/help/help.wxml","miniprogram/pages/agreementCenter/index.wxml","miniprogram/pages/workerFinance/index.wxml","miniprogram/pages/realName/index.wxml","miniprogram/pages/merchantAuth/index.wxml","miniprogram/subpackages/web/view/index.wxml","miniprogram/subpackages/web/h5/index.wxml"]
 for(const path of hosts)assert(read(path).includes('<inapp-notice'),path+' missing inapp-notice')
})
test('floating component ready and page-show do not clear and redraw the same notice',()=>{
 let def,shown=0,listener,callListener;vm.runInNewContext(read('miniprogram/components/inapp-notice/index.js'),{Component:x=>def=x,require:()=>({subscribe(fn){listener=fn;return ()=>{}},subscribeCall(fn){callListener=fn;return ()=>{}},show(){shown++;listener({title:'提醒'});callListener(null)},hide(){}}),getCurrentPages:()=>[{route:'pages/orderHall/orderHall',options:{}}],wx:{getWindowInfo:()=>({statusBarHeight:20}),getMenuButtonBoundingClientRect:()=>({top:26,height:32})}})
 const patches=[],c={properties:{customNav:true},data:{notice:null,call:null,callBusy:false,top:8},setData(p){patches.push(p);Object.assign(this.data,p)},...def.methods}
 def.lifetimes.attached.call(c);def.pageLifetimes.show.call(c);def.lifetimes.ready.call(c)
 assert.equal(shown,1);assert.equal(c.data.notice.title,'提醒');assert.ok(!patches.some(p=>p.notice===null))
})
test('announcement loads coalesce, isolate sites and use persisted same-site cache after a cold-start network failure', async () => {
  let revision = 0, selected = 'production', offline = false
  const requests = []
  const storage = new Map()
  const environment = {
    capture: () => ({ revision, key: selected }),
    assertCurrent(context) {
      if (context.revision !== revision || context.key !== selected) throw new Error('site changed')
    },
    storageKey(key, context) { return context.key === 'testing' ? 'api_testing:' + key : key }
  }
  const api = { publicRequest(route, query) {
    if (offline) return Promise.reject(Object.assign(new Error('offline'), { code: 'NETWORK_ERROR' }))
    return new Promise(resolve => requests.push({ route, scene: query.scene, resolve }))
  } }
  const wx = {
    getStorageSync(key) { return storage.get(key) },
    setStorageSync(key, value) { storage.set(key, value) },
    removeStorageSync(key) { storage.delete(key) }
  }
  const createLoader = () => {
    const module = { exports: {} }
    vm.runInNewContext(read('miniprogram/utils/AnnouncementLoader.js'), {
      module, require: p => p.includes('OrderApi') ? api : environment, wx, Date, console
    })
    return module.exports
  }
  const loader = createLoader()
  const first = loader.load('platform_agreement')
  assert.equal(first, loader.load('platform_agreement'))
  assert.equal(requests.length, 1)
  const discarded = assert.rejects(first, /site changed/)
  selected = 'testing'
  revision++
  const next = loader.load('platform_agreement')
  const store = loader.load('store')
  assert.deepEqual(requests.map(r => [r.route, r.scene]), [
    ['announcement.current', 'platform_agreement'],
    ['announcement.current', 'platform_agreement'],
    ['announcement.current', 'store']
  ])
  requests[0].resolve({ data: { environment: 'production', enabled: true, text: '正式协议' } })
  requests[1].resolve({ data: { environment: 'testing', enabled: true, text: '测试协议' } })
  requests[2].resolve({ data: { environment: 'testing', enabled: false } })
  await discarded
  assert.equal((await next).text, '测试协议')
  assert.equal((await store).enabled, false)
  const refresh = loader.load('platform_agreement')
  requests[3].resolve({ data: { environment: 'testing', enabled: true, text: '修改后的协议' } })
  assert.equal((await refresh).text, '修改后的协议')

  // 模拟小程序冷启动：普通页面/平台协议继续使用单条 Loader 的同站缓存。
  offline = true
  const coldLoader = createLoader()
  const fallback = await coldLoader.load('platform_agreement')
  assert.equal(fallback.text, '修改后的协议')
  assert.equal(fallback.stale, true)
  assert.equal(fallback.environment, 'testing')
  assert.equal(storage.has('api_testing:worker_public_announcement_v1_platform_agreement'), true)
  const disabledStore = await coldLoader.load('store')
  assert.equal(disabledStore.enabled, false)
  assert.equal(coldLoader.peek('store').enabled, false)
  selected = 'production'
  revision++
  await assert.rejects(coldLoader.load('platform_agreement'), /offline/)
  selected = 'testing'
  revision++
  assert.equal((await coldLoader.load('platform_agreement')).text, '修改后的协议')
})
test('home announcement loader keeps a multi-item list separate from single-page announcement loader', async () => {
  let revision = 0, selected = 'testing', offline = false
  const requests = [], storage = new Map()
  const environment = {
    capture: () => ({ revision, key: selected }),
    assertCurrent(context) { if (context.revision !== revision || context.key !== selected) throw new Error('site changed') },
    storageKey(key, context) { return context.key === 'testing' ? 'api_testing:' + key : key },
    onChange() { return () => {} }
  }
  const api = { publicRequest(route) {
    if (offline) return Promise.reject(Object.assign(new Error('offline'), { code: 'NETWORK_ERROR' }))
    return new Promise(resolve => requests.push({ route, resolve }))
  } }
  const wx = {
    getStorageSync(key) { return storage.get(key) },
    setStorageSync(key, value) { storage.set(key, value) },
    removeStorageSync(key) { storage.delete(key) }
  }
  const createLoader = () => {
    const module = { exports: {} }
    vm.runInNewContext(read('miniprogram/utils/HomeAnnouncementLoader.js'), {
      module, require: p => p.includes('OrderApi') ? api : environment, wx, Date, console
    })
    return module.exports
  }
  const loader = createLoader()
  const first = loader.load()
  assert.equal(first, loader.load())
  assert.deepEqual(requests.map(r => r.route), ['announcement.home'])
  requests[0].resolve({ data: { environment: 'testing', items: [
    { id: 'home-1', text: '公告一', link: '/pages/index/index' },
    { id: 'home-2', text: '公告二', link: '' }
  ] } })
  const result = await first
  assert.deepEqual(result.items.map(item => item.text), ['公告一','公告二'])
  assert.deepEqual(loader.peek().items.map(item => item.text), ['公告一','公告二'])

  offline = true
  const cold = createLoader()
  const cached = await cold.load()
  assert.equal(cached.stale, true)
  assert.deepEqual(cached.items.map(item => item.text), ['公告一','公告二'])
  selected = 'production'; revision++
  await assert.rejects(cold.load(), /offline/)
})

test('home falls back only for an absent route and respects disabled or empty announcements', async () => {
  let response, legacyEnabled = true
  const routes = []
  const api = { async publicRequest(route, params) {
    routes.push(route)
    if (route === 'announcement.home') {
      if (response instanceof Error) throw response
      return response
    }
    assert.equal(params.scene, 'home')
    return { data: { enabled: legacyEnabled, text: '旧站首页公告' } }
  } }
  const environment = { capture: () => ({ key: 'production', revision: 1 }), assertCurrent() {} }
  const module = { exports: {} }
  vm.runInNewContext(read('miniprogram/utils/HomeAnnouncementLoader.js'), {
    module, require: path => path.includes('OrderApi') ? api : environment, console
  })
  response = Object.assign(new Error('missing'), { statusCode: 404, code: 'NOT_FOUND' })
  assert.equal((await module.exports.load()).items[0].text, '旧站首页公告')
  legacyEnabled = false
  assert.equal((await module.exports.load()).items.length, 0)
  response = { data: { items: [] } }
  assert.equal((await module.exports.load()).items.length, 0)
  response = Object.assign(new Error('down'), { statusCode: 503 })
  await assert.rejects(module.exports.load(), /down/)
  assert.equal(routes.filter(route => route === 'announcement.current').length, 2)
})

test('home banner restores the two retired assets without replacing custom campaigns', () => {
  let page, data
  const wx = { cloud: { callFunction({ success }) { success({ result: { code: 1, data } }) } } }
  vm.runInNewContext(read('miniprogram/pages/index/index.js').replace(/^import .+$/mg, ''), {
    Page: value => { page = value }, require: () => ({}), getApp: () => ({ globalData: {} }),
    requirePlugin: () => ({}), MAPCONFIG: {}, CLOUDCONFIG: {}, console, wx
  })
  page.setData = patch => Object.assign(page.data, patch)
  const [worker, help, calculator, measure] = page.data.banner
  assert.match(worker.url, /banner_worker\.png$/)
  assert.match(help.url, /banner_help\.png$/)
  const campaign = { url: 'https://example.org/custom.png', page: '/campaign' }
  data = [{ url: worker.url.replace('banner_worker', 'Banner3') }, { url: help.url.replace('banner_help', 'banner1') }, campaign]
  page.getBanner()
  assert.equal(page.data.banner[0], worker)
  assert.equal(page.data.banner[1], help)
  assert.equal(page.data.banner[2], campaign)
  assert.equal(page.data.banner.length, 3)
  assert.equal(measure.url, 'cloud://worker-real.776f-worker-real-1301382302/public/banner/banner_measure.jpg')
  assert.equal(calculator.page, '/subpackages/calculator/index/index')
  assert.equal(measure.page, '/subpackages/measure/imageAnnotator/index')
  data = [worker, help, calculator, measure]; page.getBanner()
  assert.equal(page.data.banner.length, 4)
  data = []; page.getBanner(); assert.equal(page.data.banner.length, 0)
})

test('home keeps multi-item rolling announcements separate from platform agreement and other page styles', async () => {
  let def, revision = 0, change
  const homeRequests = [], singleRequests = []
  const homeAnnouncements = {
    context: () => ({ revision }),
    peek: () => null,
    onChange(fn) { change = fn; return () => {} },
    load() { return new Promise((resolve, reject) => homeRequests.push({ resolve, reject })) }
  }
  const singleAnnouncements = {
    context: () => ({ revision }),
    peek: () => null,
    load(scene) { return new Promise((resolve, reject) => singleRequests.push({ scene, resolve, reject })) }
  }
  vm.runInNewContext(read('miniprogram/pages/index/index.js').replace(/^import .+$/mg, ''), {
    Page: x => def = x,
    require: p => p.includes('HomeAnnouncementLoader') ? homeAnnouncements : (p.includes('AnnouncementLoader') ? singleAnnouncements : { resolve: x => x }),
    getApp: () => ({ globalData: {} }), requirePlugin: () => ({}), MAPCONFIG: {}, CLOUDCONFIG: {}, console, wx: {}
  })
  const page = { ...def, data: { ...def.data, PlatformAgreement: { ...def.data.PlatformAgreement } }, setData(patch) { Object.assign(this.data, patch) } }
  const homeMarkup = read('miniprogram/pages/index/index.wxml')
  const orderAnnouncementMarkup = read('miniprogram/components/order-announcement/index.wxml')
  assert(homeMarkup.includes('class="tips-swiperitem"'), '首页必须保留最初滚动容器')
  assert(homeMarkup.includes('radius-lg bg-orange light'), '首页必须保留原橙色滚动公告样式')
  assert(!homeMarkup.includes('<order-announcement'), '首页不得复用其他页面单条公告组件')
  assert(!orderAnnouncementMarkup.includes('tips-swiperitem'), '其他页面公告组件不得反向复用首页滚动样式')
  assert(homeMarkup.includes('Headtips.length > 1'), '两条首页公告即使没有平台协议也必须自动滚动')

  const homeRequest = page.getTips()
  assert.equal(homeRequests.length, 1)
  homeRequests[0].resolve({ items: [
    { id: 'home-1', text: '首页公告一', link: '/pages/index/index' },
    { id: 'home-2', text: '首页公告二', link: '' }
  ] })
  await homeRequest
  assert.deepEqual(page.data.Headtips.map(item => item.title), ['首页公告一','首页公告二'])
  assert.equal(singleRequests.length, 0, '首页公告不得走其他页面单条公告 Loader')

  const agreementRequest = page.getPlatformAgreement()
  assert.equal(singleRequests.at(-1).scene, 'platform_agreement')
  singleRequests.at(-1).resolve({ enabled: true, text: '平台协议', link: '/subpackages/support/agreements/index' })
  await agreementRequest
  assert.equal(page.data.PlatformAgreement.text, '平台协议')
  assert.deepEqual(page.data.Headtips.map(item => item.title), ['首页公告一','首页公告二'])

  const oldHome = page.getTips()
  const oldHomeRequest = homeRequests.at(-1)
  revision++
  change()
  assert.equal(homeRequests.length >= 3, true)
  assert.equal(singleRequests.at(-1).scene, 'platform_agreement')
  const freshHome = homeRequests.at(-1)
  freshHome.resolve({ items: [{ id: 'home-1', text: '测试站公告', link: '' }] })
  singleRequests.at(-1).resolve({ enabled: true, text: '测试站协议', link: '/subpackages/support/agreements/index' })
  await flush()
  oldHomeRequest.resolve({ items: [{ id: 'home-1', text: '迟到正式站公告', link: '' }] })
  await oldHome
  assert.equal(page.data.Headtips[0].title, '测试站公告')
  assert.equal(page.data.PlatformAgreement.text, '测试站协议')
})
test('page announcement preserves same-site content during refresh and clears it when disabled', async () => {
  let def, revision = 0, change
  const requests = []
  const announcements = {
    context: () => ({ revision }),
    onChange(fn) { change = fn; return () => {} },
    load: () => new Promise((resolve, reject) => requests.push({ resolve, reject })),
  }
  vm.runInNewContext(read('miniprogram/components/order-announcement/index.js'), { Component: x => def = x, require: p => p.includes('AnnouncementLoader') ? announcements : { resolve: x => x } })
  const c = { ...def.methods, data: { ...def.data }, properties: { scene: 'order_hall' }, setData(patch) { Object.assign(this.data, patch) } }
  def.lifetimes.attached.call(c)
  requests.at(-1).resolve({ enabled: true, text: '接单公告', link: '' })
  await flush()
  def.pageLifetimes.show.call(c)
  assert.equal(c.data.text, '接单公告')
  requests.at(-1).reject(new Error('offline'))
  await flush()
  assert.equal(c.data.visible, true)
  def.pageLifetimes.show.call(c)
  requests.at(-1).resolve({ enabled: false, text: '', link: '' })
  await flush()
  assert.equal(c.data.visible, false)
  def.pageLifetimes.show.call(c)
  requests.at(-1).resolve({ enabled: true, text: '正式公告', link: '' })
  await flush()
  const oldSite = c.refreshAnnouncement()
  const oldRequest = requests.at(-1)
  revision++
  change()
  assert.equal(c.data.visible, false)
  requests.at(-1).resolve({ enabled: true, text: '测试公告', link: '' })
  await flush()
  oldRequest.resolve({ enabled: true, text: '迟到的正式公告', link: '' })
  await oldSite
  assert.equal(c.data.text, '测试公告')
  def.lifetimes.detached.call(c)
})

test('ordinary unread stays a dot while chat and important notices produce a number',async()=>{
 const api={request:async()=>({data:{attention_schema:1,order_attention_count:2,standalone_unread_count:4,unread_count:6}})}
 const module={exports:{}}
 vm.runInNewContext(read('miniprogram/utils/NotificationState.js'),{module,require:()=>api,Date,console})
 const state=module.exports
 await state.refresh({force:true})
 state.applyChat({badge_count:3})
 const summary=state.getSnapshot()
 assert.equal(summary.messageNumberCount,5)
 assert.equal(summary.ordinaryUnread,true)
 let definition
 vm.runInNewContext(read('miniprogram/custom-tab-bar/index.js'),{Component:value=>definition=value,require:()=>state,console})
 const tab={data:{},setData(patch){Object.assign(this.data,patch)}}
 const update=value=>definition.methods.applyNotificationBadge.call(tab,value)
 update(summary)
 assert.equal(tab.data.notificationBadgeText,'5')
 assert.equal(tab.data.notificationBadgeDot,false)
 update({ordinaryUnread:true})
 assert.equal(tab.data.notificationBadgeText,'')
 assert.equal(tab.data.notificationBadgeDot,true)
 update({messageNumberCount:105,ordinaryUnread:true})
 assert.equal(tab.data.notificationBadgeText,'99+')
 assert.equal(tab.data.notificationBadgeDot,false)
 update({})
 assert.equal(tab.data.notificationBadgeCount,0)
 assert.equal(tab.data.notificationBadgeDot,false)
})

test('worker onboarding remains individually selectable when a daily scene shares its template',async()=>{
 const review={key:'reviewResult',audience:'worker',template_id:'same',subscription_type:'one_time',available:true}
 const order={...review,key:'order_progress',audience:'common'}
 const requests=[]
 const module={exports:{}}
 vm.runInNewContext(read('miniprogram/utils/SubscribeMessageUtils.js'),{
  module,require:name=>name.includes('ApiEnvironment')
    ? {onChange(){},storageKey:key=>key}
    : {request:async(route,method,body,query)=>{requests.push({route,query});return {data:{items:[order,review]}}}},
  wx:{getSetting:opts=>opts.success({subscriptionsSetting:{mainSwitch:true,itemSettings:{}}}),getStorageSync:()=>null,setStorageSync(){},requestSubscribeMessage(){}},
  console,Date
 })
 const settings=await module.exports.getSubscriptionSettings(true,{includeApplication:true})
 assert.equal(requests[0].query.include_application,1)
 assert.equal(settings.items.length,2)
 assert.equal(settings.items[1].key,'reviewResult')
 assert.equal(settings.items[1].audiences[0],'worker')
})

test('floating notices stay for ten seconds, including replacements',()=>{
 const f=notice(),latest=id=>({latestNotification:{id,title:'新通知'}})
 f.summary(latest('1'))
 f.summary(latest('2'))
 const [[firstID,first]]=f.timers
 assert.equal(first.delay,10000)
 f.summary(latest('3'))
 assert.equal(f.timers.size,1)
 assert.equal(f.timers.has(firstID),false)
 const [replacement]=f.timers.values()
 assert.equal(replacement.delay,10000)
 replacement.fn()
 assert.equal(f.current(),null)
})
