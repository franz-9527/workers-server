'use strict'
const assert=require('assert/strict'),fs=require('fs'),path=require('path'),vm=require('vm')
const root=path.resolve(__dirname,'..')
if(path.basename(root)!=='workers-server')throw Error('Independent test repository required')
const {ChatManager}=require('../miniprogram/utils/ChatManager.js')
const Identity=require('../miniprogram/utils/ChatIdentity.js'),Cards=require('../miniprogram/utils/ChatOrderCard.js')
let checks=0
function ck(value,name){assert.ok(value,name);console.log('PASS '+name);checks++}
const tasks=new Map();let next=0,hideCalls=0,definition,now=Date.now(),confirm=true,recorderStop,recorderStart,recorderError,sendMedia=[]
const wx={showToast(q){wx.toast=q.title},vibrateShort(){},hideKeyboard(){hideCalls++},getWindowInfo(){return{windowHeight:700,windowWidth:375}},authorize(q){q.success({})},getRecorderManager(){return{onStart(fn){recorderStart=fn},offStart(){},onStop(fn){recorderStop=fn},offStop(){},onError(fn){recorderError=fn},offError(){},start(){recorderStart?.()},stop(){}}},showModal(q){q.success?.({confirm})},showActionSheet(q){q.success?.({tapIndex:0})},navigateTo(q){wx.lastURL=q.url},setClipboardData(q){wx.clipboard=q.data}}
global.wx=wx
const orderApiPath=path.join(root,'miniprogram/utils/OrderApi.js')
require.cache[orderApiPath]={id:orderApiPath,filename:orderApiPath,loaded:true,exports:{request:async()=>({data:{items:[{public_no:'DEMO_ORDER_1',title:'测试订单'}],has_more:false}})}}
const runtime={refreshAvailability(){return Promise.resolve()},get(){return manager}}
const timer=(fn,delay)=>{const id=++next;tasks.set(id,{fn,delay});return id},clear=id=>tasks.delete(id)
function loadPage(file){
 const context={console,wx,Page(v){definition=v},getApp(){return app},setTimeout:timer,clearTimeout:clear,Date,
 require(name){if(name.includes('ChatRuntime'))return runtime;if(name.includes('NotificationState'))return{subscribe(){return()=>{}}};if(name.includes('ApiEnvironment'))return{current(){return{baseURL:'https://fixture.invalid'}}};if(name.includes('OrderApi'))return{request:async()=>({data:{items:[{public_no:'DEMO_ORDER_1',title:'测试订单'}]}})};if(name==='./sms.js')return{};return require(path.resolve(path.dirname(file),name))}}
 vm.runInNewContext(fs.readFileSync(file,'utf8'),context,{filename:file})
 const out={data:structuredClone(definition.data),setData(data,cb){Object.assign(this.data,data);cb?.()}}
 for(const[k,v]of Object.entries(definition))if(typeof v==='function')out[k]=v.bind(out)
 return out
}
const manager={epoch:1,enabled:true,foreground:true,active:'12',state:'http',focusEpoch:1,testSandbox:true,simulation:false,io:{now:()=>now,av:{info:async()=>({size:2000})}},cache:new Map(),subscribe(){return()=>{}},focus(id){this.active=id;this.focusEpoch++},draft(){return draft},saveDraft(id,text){draft={...draft,text,revision:draft.revision+1};return true},typing:async()=>{},attempts:()=>[],readTarget:()=>null,sendText:async(id,text,revision)=>{sent.push({id,text,revision});return{}},av:{list(){return[]},stage(id,file){staged.push(file);return'client'},send:async(...args)=>sendMedia.push(args)},http:async()=>({audio:false,video:false,peers:[]}),refreshAccount(){},forgetConversation(){}}
let draft={text:'你好',revision:2,source:{}},sent=[],staged=[],readyResolve
const app={globalData:{userInfo:{openid:'real',mobile:'13800000000'}},ensureUserReady(){return new Promise(r=>readyResolve=r)}}
async function main(){
 const page=loadPage(path.join(root,'miniprogram/subpackages/chat/conversation/index.js'))
 manager.cache.set('12',{canSend:true,capabilities:{audio:true,video:true}});page.id='12';page.manager=manager;page.managerEpoch=1;page.life=3;page.visible=true;page.jumpBottom=()=>{};page.views={};page.identities={};page.data.enabled=true;page.data.canSend=true;page.data.audioEnabled=true;page.data.status='http';page.data.draft=draft.text;page.draftRevision=draft.revision
 page.handleEvent('availability',{enabled:true});ck(page.life===3,'availability refresh does not remount conversation or invalidate pending sends')
 await page.sendText({type:'confirm',detail:{value:'键盘发送'}})
 ck(sent.length===1&&sent[0].text==='键盘发送'&&hideCalls===0,'keyboard confirm flushes final composition and sends without hiding keyboard')
 await page.beginVoice({touches:[{clientY:500}]})
 ck(page.data.recording===true,'hold-to-talk enters recording state')
 page.moveVoice({touches:[{clientY:400}]});ck(page.recordCanceled===true,'sliding up marks recording for cancel')
 page.moveVoice({touches:[{clientY:490}]});ck(page.recordCanceled===false,'sliding back restores send gesture')
 page.endVoice();await recorderStop({duration:2000,tempFilePath:'/fixture/voice.mp3'})
 ck(staged.length===1&&sendMedia.length===1,'releasing hold stages and sends voice through real shared AV manager')
 await page.beginVoice({touches:[{clientY:500}]});page.cancelVoice();await recorderStop({duration:2000,tempFilePath:'/fixture/canceled.mp3'})
 ck(staged.length===1,'touch cancel never sends the recording')
 await page.beginVoice({touches:[{clientY:500}]});page.endVoice();await recorderStop({duration:300,tempFilePath:'/fixture/short.mp3'})
 ck(staged.length===1&&wx.toast.includes('太短'),'short recording explains failure without staging an invalid media attempt')
 const authorize=wx.authorize;let authorizeDone
 wx.authorize=q=>{authorizeDone=q.success};const before=staged.length;const waiting=page.beginVoice({touches:[{clientY:500}]});page.endVoice();authorizeDone({});await waiting
 ck(!page.voiceSession&&staged.length===before,'releasing during initial permission request never starts a detached recording');wx.authorize=authorize
 const person=Identity.normalize({actor_id:'11',display_name:'演示客户甲',role:'user',avatar:'user',simulation:true})
 ck(Identity.attribution({role_source:'invite',display_role:'user'},person).displayName==='演示客户甲','sandbox sender stamps preserve authorized display names')
 ck(Identity.attribution({role_source:'platform_staff'},Identity.normalize({actor_id:'99',display_name:'平台工作人员',role:'platform',avatar:'platform'})).displayName==='平台工作人员','normal platform sender retains authorized name without a user suffix')
 page.views={'1':{messageId:'1',seq:'1',state:'published',stateVersion:'1',body:'hello',canCopy:true,isMine:false,createdAt:new Date(now).toISOString()}}
 page.current=(life,epoch)=>page.visible&&page.life===life&&manager.epoch===epoch;page.replyTo=()=>{};page.openReport=()=>{}
 page.messageActions({currentTarget:{dataset:{id:'1'}},touches:[{clientX:350,clientY:300}]})
 ck(page.data.messageMenu.top<300&&page.data.messageMenu.left>=12,'long-press menu appears above bubble and remains within viewport')
 page.runMessageAction({currentTarget:{dataset:{key:'copy'}}});ck(wx.clipboard==='hello'&&!page.data.messageMenu,'anchored menu dispatches the selected message action')
 page.messageActions({currentTarget:{dataset:{id:'1'}},touches:[{clientX:20,clientY:300}]});page.views['1'].stateVersion='2';wx.clipboard=''
 page.runMessageAction({currentTarget:{dataset:{key:'copy'}}});ck(!wx.clipboard,'menu cannot act on a message changed after long-press')
 page.data.orderContext={role:'customer',simulation:true};page.refreshComposer=()=>{}
 await page.shareOrder();ck(Cards.parse(sent.at(-1).text)?.publicNo==='DEMO_ORDER_1','sandbox can share an existing authorized demo order with confirmation')
 ck(draft.text==='键盘发送','order sharing preserves typed draft')
 ck(!Cards.parse('【平台订单】\n{"publicNo":"../escape","title":"bad"}'),'order card rejects arbitrary paths')
 page.views['2']={orderCard:{publicNo:'DEMO_ORDER_1',title:'测试订单'}};page.openSharedOrder({currentTarget:{dataset:{id:'2'}}})
 ck(wx.lastURL==='/pages/userOrder/detail?id=DEMO_ORDER_1','order card opens existing permission-checked detail page')
 const oldHttp=manager.http;manager.calls={enabled:true};manager.http=async()=>{throw Object.assign(new Error(),{code:'CALL_UNAVAILABLE'})}
 await page.openCall({currentTarget:{dataset:{media:'audio'}}});ck(page.data.notice.includes('通话服务未就绪'),'missing call configuration is explained before any call is submitted')
 let called=0;manager.http=async()=>({audio:true,video:true,peers:['22']});manager.calls={enabled:true,create:async()=>{called++}}
 await page.openCall({currentTarget:{dataset:{media:'video'}}});ck(called===1&&wx.lastURL==='/subpackages/chat/call/index','ready calls use the real shared call manager and RTC call page');manager.http=oldHttp
 const m=new ChatManager({now:()=>now,random:()=>.2,setTimeout:timer,clearTimeout:clear,credential:async()=>({access_token:'renewed',expires_at:new Date(now+60000).toISOString()}),http:async(route,payload,token)=>{requests.push({route,payload,token});if(token==='old')throw Object.assign(new Error(),{statusCode:401,code:'CHAT_UNAUTHORIZED'});return{ok:true}},av:{}})
 m.enabled=true;m.foreground=true;m.token={value:'old',expires:now+60000};const model={canSend:true};m.cache.set('12',model);m.active='12'
 m.focus('');m.focus('12');ck(m.cache.get('12')===model,'ordinary page return reuses account-scoped conversation cache')
 const requests=[],payload={conversation_id:'12',client_message_id:'original'}
 await m.http('message.send',payload);ck(requests.length===2&&requests[1].payload===payload&&requests[1].token==='renewed','expired credentials renew once with immutable original request')
 m.reset();ck(m.cache.size===0,'account or site reset still erases message cache')
 const inbox=loadPage(path.join(root,'miniprogram/pages/chatMessages/index.js'))
 inbox.chatManager=manager;inbox.managerEpoch=1;inbox.activeIdentity='https://fixture.invalid|real|13800000000';inbox._showSerial=0;inbox.data.isLoggedIn=true;inbox.data.chatRows=[{conversationId:'12'}];inbox.stopSummaryPolling=()=>{};inbox.getTabBar=()=>null;inbox.clearSensitive=()=>{throw Error('ordinary return cleared valid identity')};let refreshed=0;inbox.refreshSummary=()=>{refreshed++};inbox.scheduleSummaryRefresh=()=>{};inbox.probeAudit=()=>{};inbox.onShow();await Promise.resolve();await Promise.resolve()
 ck(inbox.data.checkingLogin===false&&inbox.data.chatRows.length===1,'returning to inbox shows valid session and loaded rows while login refresh is pending')
 ck(refreshed===1,'valid inbox return refreshes API data without waiting for a slow cloud profile check')
 console.log('PASS TOTAL '+checks)
}
main().catch(error=>{console.error(error);process.exitCode=1})
