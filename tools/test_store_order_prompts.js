'use strict'
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict')
const root=path.resolve(__dirname,'..')
if(process.env.STORE_ORDER_TEST!=='1'||path.basename(root)!=='workers-server')throw Error('Independent test repository required')
const read=p=>fs.readFileSync(path.join(root,p),'utf8')
const support=require('../miniprogram/utils/OrderSupportPresentation')
const storefront=require('../miniprogram/subpackages/store/utils/Storefront')
let checks=0
function ck(ok,why){assert.ok(ok,why);checks++}
const base=()=>({
 order:{id:'O-DEMO',category_name:'墙纸',lifecycle_status:'active',fulfillment_status:'in_progress',payment_status:'paid',assignment_status:'accepted',final_amount_cents:80000,booking_fee_cents:10000,refund_status:'none'},
 actions:{can_work:true,can_worker_cancel:true,can_cancel:true},
 assignment:{status:'accepted',amount_cents:80000,commission_cents:8000,net_income_cents:72000},
 media:[],answers:[],changes:[],after_sales:[],negotiations:[],events:[],quotes:[],commission:{rate_percent:10,minimum_cents:0}
})
const later=()=>new Promise(resolve=>setImmediate(resolve))
function instance(def,properties={}){
 const obj={...def,data:structuredClone(def.data||{}),properties:{...Object.fromEntries(Object.entries(def.properties||{}).map(([k,p])=>[k,p.value])),...properties}}
 Object.assign(obj,def.methods||{})
 obj.setData=(d,cb)=>{Object.assign(obj.data,d);if(cb)cb()}
 return obj
}
function capture(file,mocks={}){
 let def
 vm.runInNewContext(read(file),{Page:d=>def=d,Component:d=>def=d,require:mocks.require||(()=>({})),wx:mocks.wx||{},getApp:()=>mocks.app||{globalData:{}},requirePlugin:()=>mocks.plugin||{},console:{log(){},warn(){},error(){}},setTimeout,clearTimeout,...mocks.extra})
 return def
}
async function main(){
 // Full package asset paths, not merely image placeholders.
 const config=JSON.parse(read('project.config.json'))
 ck(config.packOptions.include.some(x=>x.type==='folder'&&x.value==='subpackages/store/images'),'dynamic store images explicitly included in package')
 for(const icon of storefront.icons([])){
  ck(icon.img.startsWith('/subpackages/store/images/'),'store image stays outside main package')
  const bytes=fs.readFileSync(path.join(root,'miniprogram',icon.img))
  ck(bytes.subarray(0,8).equals(Buffer.from([137,80,78,71,13,10,26,10])),'real restored PNG exists')
 }
 ck(storefront.icons([{name:'旧图标',img:'/images/2406shop.png',pagesUrl:'/pages/h5/h5?title=bbs-store'}])[0].pagesUrl==='/subpackages/web/h5/index?title=bbs-store','legacy content route keeps query')
 ck(storefront.icons([{img:'/images/2406shop.png'}])[0].img==='/subpackages/store/images/2406shop.png','legacy cloud image path rewritten')
 let calls=[]
 global.wx={cloud:{callFunction:o=>{calls.push(o.name);o.success(o.name==='store'?{result:undefined}:{result:{code:1,data:[]}})}}}
 await storefront.read('getStoreIconList',{});ck(calls.join(',')==='store,base_store','old deployment read fallback')
 calls=[];global.wx.cloud.callFunction=o=>{calls.push(o.name);o.success({result:{code:1,data:[]}})}
 await storefront.read('getLastStore',{});ck(calls.join(',')==='store','valid empty response is not retried against legacy site')
 calls=[];global.wx.cloud.callFunction=o=>{calls.push(o.name);o.fail({errMsg:'cloud.callFunction:fail network unavailable'})}
 await assert.rejects(storefront.read('getNear',{}));ck(calls.length===1,'network error does not silently downgrade')
 calls=[];global.wx.cloud.callFunction=o=>{calls.push(o.name);if(o.name==='store')o.fail({errMsg:'FUNCTION_NOT_FOUND'});else o.success({result:{code:1,data:[]}})}
 await storefront.read('getNear',{});ck(calls.length===2,'missing function legacy compatibility')
 calls=[];await assert.rejects(storefront.read('saveRecord',{}));ck(calls.length===0,'write actions never fall back')
 // Actual store page: default navigation is ready without cloud/login/location.
 let storeDef,pluginReads=0,navigations=[],cloudCalls=[]
 const app={globalData:{version:'fixture',location:{latitude:30,longitude:120,city:'测试城市'}}}
 const locationFailure={getLocation:o=>o.fail({errMsg:'auth deny'}),createMapContext:()=>({}),getSystemInfo:o=>o.success({}),navigateTo:o=>navigations.push(o.url),openEmbeddedMiniProgram:o=>navigations.push(o.appId)}
 global.wx={...locationFailure,cloud:{callFunction:o=>{cloudCalls.push(o.name+':'+o.data.action);o.success({result:undefined})}}}
 const storeSource=read('miniprogram/subpackages/store/index/index.js').replace(/^import .*$/gm,'')
 vm.runInNewContext(storeSource,{Page:d=>storeDef=d,require:name=>name.includes('Storefront')?storefront:name.includes('SubpackageRedirect')?require('../miniprogram/utils/SubpackageRedirect'):{load:()=>Promise.resolve({enabled:false}),onChange:()=>()=>{}},getApp:()=>app,requirePlugin:()=>({getLocation(){pluginReads++;throw Error('plugin not ready')}}),wx:global.wx,MAPCONFIG:{DEFLOCATION:app.globalData.location},MAPKEY:'fixture',formatTime:()=>'',calDistance:()=>'',setTimeout,clearTimeout,console:{log(){},warn(){},error(){}}})
 const store=instance(storeDef);store.onLoad();store.onShow();await later()
 ck(store.data.StoreIconList.length===4,'store local navigation survives unavailable cloud response')
 ck(pluginReads===0&&navigations.length===0,'first show neither calls location plugin nor jumps to settings')
 store._awaitingChooseLocation=true;store.onShow();ck(pluginReads===1,'throwing location plugin is contained')
 store.handleItemClick({currentTarget:{dataset:{index:3}}});ck(navigations.at(-1)==='/subpackages/store/plus/plus','store registration uses subpackage route')
 ck(!JSON.stringify(store.data).includes('onTap'),'no function callbacks enter serialized page data')
 store.onUnload()
 // Payment semantics: no blanket unpaid assertion, and no new calculation for platform balances.
 const d=base(),untouched=JSON.stringify(d)
 ck(support.offlineCollection(d,true).amountText==='¥700.00','ordinary tail uses confirmed total minus booking')
 ck(support.offlineCollection(d,false).description.includes('无需重复支付'),'customer reminder avoids duplicate payment')
 ck(JSON.stringify(d)===untouched,'view helper does not mutate API facts')
 for(const patch of [
  {order:{workflow_profile:'measurement'}},{order:{lifecycle_status:'cancelled'}},{order:{fulfillment_status:'accepted'}},{order:{refund_status:'processing'}},{order:{assignment_status:'none'},assignment:null},
  {viewer_mode:'public_preview'},{actions:{is_admin:true}},{order:{final_amount_cents:0}},{order:{booking_fee_cents:-1}},{order:{final_amount_cents:Infinity}},
  {order:{is_platform_order:true},platform_order:{worker_settlement_mode:'platform_balance',customer_tail_cents:10000}}
 ]){
  const c={...base(),...patch,order:{...base().order,...patch.order}}
  ck(support.offlineCollection(c,true)===null,'hide in non-collection state '+JSON.stringify(patch))
 }
 const adjusted=base();adjusted.collection_adjustment={status:'approved',requested_tail_cents:60000};ck(support.offlineCollection(adjusted,true).amountText==='¥600.00','approved actual tail overrides estimate')
 adjusted.collection_adjustment.status='rejected';ck(support.offlineCollection(adjusted,true).amountText==='¥700.00','rejected tail does not change amount')
 const platform=base();platform.order.is_platform_order=true;platform.platform_order={worker_settlement_mode:'worker_collect',customer_tail_cents:45000}
 ck(support.offlineCollection(platform,true).amountText==='¥450.00','platform collection uses server-provided customer tail, not labor quote')
 d.media=[{purpose:'collection_receipt'}];ck(support.offlineCollection(d,true).description.includes('勿重复收取'),'receipt does not get treated as unpaid debt')
 ck(support.workerCards(base()).map(x=>x.key).join(',')==='cannot_contact,cannot_work,cancel_service,service','separate contact/work/cancel/service cards')
 const measurement=base();measurement.order.workflow_profile='measurement';ck(support.workerCards(measurement)[1].title==='无法测量','measurement-specific label')
 const done=base();done.actions={can_work:true};done.order.fulfillment_status='accepted';ck(support.workerCards(done).length===0,'completed order cannot reopen problem submission')
 const preview=base();preview.viewer_mode='public_preview';ck(support.workerCards(preview).length===0,'public preview has no service actions')
 let componentEvents=[]
 const cards=instance(capture('miniprogram/components/order-support-cards/index.js'),{items:support.workerCards(base())})
 cards.triggerEvent=(name,detail)=>componentEvents.push([name,detail.key]);cards.select({currentTarget:{dataset:{key:'cannot_work'}}});cards.select({currentTarget:{dataset:{key:'fake'}}})
 ck(componentEvents.length===1&&componentEvents[0][1]==='cannot_work','card emits only displayed keys')
 // Load the actual detail pages and exercise dispatch rather than a duplicate UI implementation.
 const pageModels={}
 for(const role of ['user','worker']){
  let current=base();const api={request:()=>Promise.resolve({data:structuredClone(current)})}
  const wxMock={setNavigationBarTitle(){},stopPullDownRefresh(){},showModal:o=>{throw Error(o.content)},showToast(){}}
  const def=capture('miniprogram/pages/'+role+'Order/detail.js',{wx:wxMock,require:name=>{
   if(name.includes('OrderSupportPresentation'))return support
   if(name.includes('OrderStagePresentation'))return require('../miniprogram/utils/OrderStagePresentation')
   if(name.includes('WorkerOrderPresentation'))return require('../miniprogram/utils/WorkerOrderPresentation')
   if(name.includes('OrderApi'))return api
   return {}
  }})
  const p=instance(def);p.data.id=p.data.orderId='O-DEMO';p.showError=e=>{throw e}
  if(role==='worker'){
   await p.loadDetail({});p.supportAction({detail:{key:'cannot_work'}});ck(p.data.arrivalType==='cannot_work'&&p.data.modalName==='arrival','work card preselects correct report type')
   p.supportAction({detail:{key:'cannot_contact'}});ck(p.data.arrivalType==='cannot_contact','contact card preselects correct report type')
   let cancel=0;p.cancelOwnService=()=>cancel++;p.supportAction({detail:{key:'cancel_service'}});ck(cancel===1,'cancel card reuses existing confirmation flow')
   current.actions={};await p.loadDetail({});p.hideModal();p.supportAction({detail:{key:'cannot_work'}});ck(p.data.modalName==='','stale hidden action cannot open report')
   current=base();await p.loadDetail({})
  }else{
   await p.load();let opened=0;p.openProblemMenu=()=>opened++;p.supportAction({detail:{key:'problem'}});ck(opened===1,'customer problem card keeps existing menu and progress routing')
  }
  ck(p.data.offlineCollection.amountText==='¥700.00','actual '+role+' page receives collection model')
  pageModels[role]=p.data
 }
 // Subscription authorization must remain synchronous with tap and never run on refresh.
 let health={action:'top_up',code:'insufficient',subscription_type:'one_time'},requests=0,settings=0,revision=1,resolveRequest
 const subscription={getSubscriptionHealth:()=>Promise.resolve(health),requestImportantTopUp:()=>{requests++;return new Promise(r=>resolveRequest=r)}}
 const banner=instance(capture('miniprogram/components/subscription-health-banner/index.js',{wx:{navigateTo:()=>settings++,showToast(){}},require:name=>name.includes('Subscribe')?subscription:{contextRevision:()=>revision}}))
 banner.lifetimes.attached.call(banner);banner._visible=true;await banner.refresh()
 ck(requests===0&&banner.data.actionText==='续收提醒','refresh prepares text without requesting authorization')
 const request=banner.open();ck(requests===1&&banner.data.pending,'tap invokes subscription before any asynchronous step')
 banner.open();banner.openSettings();ck(requests===1&&settings===0,'pending request prevents double authorization and settings navigation')
 resolveRequest({acceptedCount:1,status:'ok'});await request;banner.openSettings();ck(settings===1&&!banner.data.pending,'settings separate from top-up save state')
 health=null;await banner.refresh(true);ck(banner.data.health===null,'healthy subscription hides the lightweight reminder')
 health={action:'wechat_setting',code:'rejected'};await banner.refresh(true);ck(banner.data.actionText==='去设置','rejected permission points to settings')
 await banner.open();ck(settings===2&&requests===1,'settings path never invokes subscription')
 banner.lifetimes.detached.call(banner)
 for(const role of ['user','worker']){
  const src=read('miniprogram/pages/'+role+'Order/detail.wxml')
  ck(src.indexOf('<subscription-health-banner')<src.indexOf('class="hero'),'banner is physically above hero')
  ck(/subscription-health-banner[^>]+order:-10/.test(src),'banner sorts ahead of hero')
  ck(src.includes('bindaction="supportAction"'),'detail wires shared support cards')
 }
 fs.mkdirSync(path.join(root,'test-artifacts'),{recursive:true})
 fs.writeFileSync(path.join(root,'test-artifacts/r35-models.json'),JSON.stringify(pageModels))
 console.log('PASS TOTAL '+checks+' store, subscription, order-model and event checks')
}
main().catch(e=>{console.error(e);process.exit(1)})
