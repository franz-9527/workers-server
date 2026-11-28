'use strict'
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm')
const root=path.resolve(__dirname,'..')
const read=p=>fs.readFileSync(path.join(root,p),'utf8')
const tick=()=>new Promise(resolve=>setImmediate(resolve))
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return {promise,resolve,reject}}

async function readReceipt(){
 const module={exports:{}}
 vm.runInNewContext(read('miniprogram/subpackages/chat/references.js'),{module,require(){return {parse(){return null}}},wx:{}})
 const methods=module.exports.sceneReferences
 let calls=0,attempt=deferred(),success=0
 const p={serial:1,sceneAtBottom:true,data:{conversation:'9',messages:[{}],readCursor:'one'},active:s=>s===1,
  http(){calls++;return calls===1?attempt.promise:new Promise(()=>{})},
  manager:{refreshAccount(){success++}}}
 p.acknowledgeSceneRead=methods.acknowledgeSceneRead.bind(p)
 const pending=p.acknowledgeSceneRead()
 attempt.reject(Object.assign(new Error('offline'),{code:'CHAT_NETWORK_ERROR'}))
 await pending;await tick()
 assert.equal(calls,1,'failure must not cause an immediate retry loop')
 assert.equal(success,0)
 assert.equal(p.sceneReadJob,null)
 // A later normal refresh may retry, and a newer cursor is sent after a success.
 const requests=[]
 p.http=(_serial,_route,body)=>{const d=deferred();requests.push({body,d});return d.promise}
 const retried=p.acknowledgeSceneRead()
 assert.equal(requests.length,1)
 p.data.readCursor='two'
 await p.acknowledgeSceneRead()
 requests[0].d.resolve({});await retried;await tick()
 assert.equal(requests.length,2)
 assert.equal(requests[1].body.cursor,'two')
 requests[1].d.resolve({});await tick()
 assert.equal(p.sceneReadCursor,'two')
}

async function feedbackRefresh(){
 let definition
 const requests=[]
 const manager={enabled:true,simulation:false,testSandbox:false,identityEpoch:1,epoch:1,
  credential(){return Promise.resolve('token')},live(){return true},
  io:{http(){const d=deferred();requests.push(d);return d.promise}}}
 const api={request(){return Promise.resolve({data:{enabled:true,staff:true}})}}
 const inbox={previewText:m=>m.text},presentation={timeLabel:()=>''}
 const resolve=name=>name.includes('ChatRuntime')?{get:()=>manager}:name.includes('OrderApi')?api:name.includes('ChatInboxModel')?inbox:name.endsWith('ChatIdentity.js')?require('../miniprogram/utils/ChatIdentity.js'):presentation
 vm.runInNewContext(read('miniprogram/subpackages/chat/feedback/index.js'),{Page(v){definition=v},require:resolve,wx:{},console,Promise})
 const p={data:{...definition.data},setData(v){Object.assign(this.data,v)}}
 for(const [name,fn] of Object.entries(definition))if(typeof fn==='function')p[name]=fn.bind(p)
 p.onLoad();p.visible=true
 const first=p.start();await tick()
 assert.equal(requests.length,1)
 const second=p.start();await tick()
 assert.equal(requests.length,2,'new refresh must not inherit stale loading lock')
 requests[0].resolve({items:[{conversation_id:'1',owner_name:'旧数据'}]});await first;await tick()
 assert.equal(p.data.loading,true,'old request cannot release newer generation loading lock')
 requests[1].resolve({items:[{conversation_id:'2',owner_name:'新数据'}],next_cursor:''});await second
 assert.equal(p.data.items.length,1)
 assert.equal(p.data.items[0].conversation_id,'2')
 assert.equal(p.data.items[0].avatarPath,'/images/chat-user.svg')
 assert.equal(p.data.loading,false)
}
async function supportReadLifecycle(){
 let experience={},definition
 const timers=[]
 const noop=()=>{}
 const modules={
  '../../../utils/ChatRuntime.js':{refreshAvailability:noop},
  '../../../utils/ChatInboxModel.js':{validID:id=>/^[1-9][0-9]*$/.test(id)},
  '../../../utils/ChatManager.js':{compare:(a,b)=>Number(a)-Number(b)}
 }
 const expModule={exports:{}}
 vm.runInNewContext(read('miniprogram/subpackages/chat/conversation/experience.js'),{
  module:expModule,require(){return {}},wx:{},setTimeout(fn){timers.push(fn);return timers.length},clearTimeout:noop,Promise
 })
 experience=expModule.exports
 vm.runInNewContext(read('miniprogram/subpackages/chat/conversation/index.js'),{
  Page(v){definition=v},require(name){return name==='./experience.js'?experience:(modules[name]||{})},
  wx:{},console,Date,Promise,setTimeout,clearTimeout
 })
 const makePage=(initialized,mode,target)=>{
  const receipts=[],http=[]
  const manager={enabled:true,epoch:1,identityEpoch:1,state:'http',active:'9',focus:noop,
   readTarget(){return target.value},acknowledge(_id,t){receipts.push(t);return Promise.resolve()},
   http(...args){http.push(args);return Promise.resolve({})},io:{now:()=>Date.now()}}
  const p={...definition,manager,id:'9',scene:'support',supportScene:true,managerIdentityEpoch:1,
   life:0,visible:false,initialized,atBottom:mode==='latest',mode,highSeq:'0',
   data:{...definition.data,supportScene:true,messages:[],receiptLabels:{}},
   setData(v,cb){Object.assign(this.data,v);if(cb)cb()},
   current(){return this.visible&&this.manager.enabled},
   syncNavigationTheme:noop,refreshComposer:noop,refreshRestriction:noop,startAV:noop,consumeVideo:noop,
   renderPage:noop,startImages:noop,consumeImageSelection:noop,refreshUnread:noop,jumpBottom:noop}
  return {p,receipts,http}
 }
 const target={value:{seq:'5',cursor:'signed'}}
 const first=makePage(false,'latest',target)
 first.p.onShow()
 assert.equal(timers.length,0,'wait for synchronized initial window')
 first.p.handleEvent('conversation.page',{conversation_id:'9',page:{messages:[],changes:[],has_more:false}})
 assert.equal(timers.length,1,'feedback initial window starts read tracking')
 await timers.shift()();assert.equal(first.receipts.length,1,'visible latest feedback is acknowledged')
 assert.equal(first.http.length,0,'feedback does not fetch sender read receipts')
 first.p.visible=false;timers.length=0
 const past=makePage(true,'history',target)
 past.p.onShow();assert.equal(timers.length,1,'returning to feedback starts tracking')
 await timers.shift()();assert.equal(past.receipts.length,0,'history must not advance read cursor')
 past.p.visible=false;timers.length=0
 const incomplete=makePage(true,'latest',{value:null})
 incomplete.p.onShow();await timers.shift()()
 assert.equal(incomplete.receipts.length,0,'incomplete synchronization has no read cursor')
 incomplete.p.visible=false;timers.length=0
}

function quoteProjection(){
 const module={exports:{}}
 vm.runInNewContext(read('miniprogram/utils/ChatManager.js'),{
  module,require(name){return name==='./ChatScene.js'?{supportsConversation:()=>true}:{}},console,Date,Promise
 })
 const {ChatManager,projectReference}=module.exports
 const mgr=Object.create(ChatManager.prototype);mgr.disk=null;mgr.images={urls:new Map()}
 const parent={message_id:'2',seq:'2',state:'published',state_version:'1',
  reply:{message_id:'1',sender_id:'7',message_type:'audio',state:'published',state_version:'1'}}
 const model={conversationId:'9',scene:'support',metadata:{},viewerID:'8',messages:new Map([['2',parent]])}
 const voice={message_id:'1',seq:'1',sender_id:'7',message_type:'audio',state:'published',state_version:'2',av:{transcript:'识别结果'}}
 mgr.merge(model,{messages:[voice]})
 const updated=model.messages.get('2').reply
 assert.equal(updated.message_type,'audio','ASR update preserves quoted voice type')
 assert.equal(updated.sender_id,'7','ASR update preserves quote author')
 const image=projectReference({message_id:'3',sender_id:'7',message_type:'image_album',item_id:'8',ordinal:2},
  {state:'published',state_version:'2',sender_id:'7',message_type:'image_album'},'reply')
 assert.equal(image.item_id,'8');assert.equal(image.ordinal,2)
 mgr.merge(model,{changes:[{message:{...voice,state:'recalled',state_version:'3'}}]})
 const recalled=model.messages.get('2').reply
 assert.equal(recalled.state,'recalled')
 assert.equal(recalled.text,undefined);assert.equal(recalled.message_type,undefined);assert.equal(recalled.item_id,undefined)
 mgr.merge(model,{messages:[parent]})
 assert.equal(model.messages.get('2').reply.state,'recalled','late quote cannot restore recalled content')
}

async function mediaAuthorizationRetry(){
 const module={exports:{}}
 vm.runInNewContext(read('miniprogram/utils/ChatManager.js'),{module,require(){return {}},console,Date,Promise})
 const manager=Object.create(module.exports.ChatManager.prototype)
 manager.epoch=1;manager.enabled=true;manager.foreground=true;manager.network=true
 const events=[],tokens=[]
 manager.credential=async(_epoch,renew)=>{tokens.push(!!renew);return renew?'new':'old'}
 manager.forgetConversation=id=>events.push(['clear',id])
 manager.emit=(type,data)=>events.push([type,data.conversation_id])
 let calls=0
 manager.io={http:async(_path,_payload,token,scene)=>{
  calls++
  assert.equal(scene,'support')
  if(token==='old')throw Object.assign(new Error('expired'),{code:'CHAT_UNAUTHORIZED',statusCode:401})
  throw Object.assign(new Error('revoked'),{code:'CHAT_PERMISSION_DENIED',statusCode:403})
 }}
 await assert.rejects(manager.http('media.url',{conversation_id:'9'},1,'support'),e=>e.code==='CHAT_PERMISSION_DENIED')
 assert.equal(calls,2);assert.deepEqual(tokens,[false,true])
 assert.equal(events.length,2,'renewed request denial clears the cached conversation')
 assert.equal(events[0][1],'9');assert.equal(events[1][0],'conversation.clear')
 // Unknown transport results are never replayed and must not be treated as revocation.
 calls=0;events.length=0
 manager.io.http=async()=>{calls++;throw Object.assign(new Error('offline'),{code:'CHAT_NETWORK_ERROR'})}
 await assert.rejects(manager.http('av.upload',{conversation_id:'9'},1,'support'),e=>e.code==='CHAT_NETWORK_ERROR')
 assert.equal(calls,1);assert.equal(events.length,0)
}

;(async()=>{await readReceipt();await feedbackRefresh();await supportReadLifecycle();quoteProjection();await mediaAuthorizationRetry();console.log('Chat lifecycle regression: OK')})().catch(error=>{console.error(error);process.exitCode=1})
