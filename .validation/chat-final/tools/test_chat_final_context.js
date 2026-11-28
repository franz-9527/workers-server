const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path')
const {ChatCache}=require('../miniprogram/utils/ChatCache.js')
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8')
const defer=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return {promise,resolve,reject}}
const error=code=>Object.assign(Error(code),{code})
function pageDefinition(file,modules={}){
 let page
 vm.runInNewContext(read(file),{Page(v){page=v},require:name=>modules[name]||{},wx:{},console,Date,Promise,setTimeout,clearTimeout})
 return page
}
function inbox(){
 const entry=defer(),calls=[],m={enabled:true,ordersOnly:false,identityEpoch:1,epoch:1,supportService:{enabled:true},live:()=>true,credential:async()=> 'fixture'}
 let directHandler=async()=>({data:{enabled:true}})
 const definition=pageDefinition('miniprogram/pages/chatMessages/index.js',{'../../utils/OrderApi.js':{request:(route,method,body)=>{calls.push(route);return route==='chat.support.entry'?entry.promise:directHandler(route,body)}},'../../utils/ChatInboxModel.js':{supportSummary:()=>null}})
 const p={...definition,data:{...definition.data,isLoggedIn:true,chatEnabled:true,directEnabled:true,supportEntry:{enabled:true,staff:false}},chatManager:m,_visible:true,_showSerial:1,supportMap:{},directMap:{},digestMap:{},pendingDigests:{},setData(v,cb){Object.assign(this.data,v);cb?.()},showChatRows(){},scheduleOnlineRows(){}}
 return {p,m,entry,calls,handle:fn=>directHandler=fn}
}
test('support availability revokes in-flight entry result without clearing direct rows',async()=>{
 const f=inbox();f.p.directMap={200:{conversationId:'200'}};f.p.supportMap={300:{conversationId:'300'}}
 const pending=f.p.loadSupportEntry(1)
 f.m.supportService={enabled:false}
 f.p.onChatEvent('availability',{enabled:true,supportService:{enabled:false}})
 f.entry.resolve({data:{enabled:true,staff:false,conversation_id:'300'}})
 await pending
 assert.equal(f.p.data.supportEntry,null)
 assert.equal(Object.keys(f.p.supportMap).length,0)
 assert.equal(Object.keys(f.p.directMap).length,1)
})
test('explicit direct permission denial clears prior inbox projection',async()=>{
 const f=inbox();f.p.directMap={200:{conversationId:'200'}}
 f.handle(async()=>{throw error('CHAT_PERMISSION_DENIED')})
 await f.p.loadDirectInbox(1)
 assert.equal(f.p.data.directEnabled,false);assert.equal(Object.keys(f.p.directMap).length,0)
})
test('repeated direct cursor stops further calls and marks snapshot stale',async()=>{
 const f=inbox();let pages=0
 f.handle(async route=>route==='chat.direct.availability'?{data:{enabled:true}}:(pages++,{data:{items:[],next:'10'}}))
 await f.p.loadDirectInbox(1)
 assert.equal(pages,2);assert.equal(f.p.data.directSummaryStale,true)
})
test('own feedback summary denial clears previous private preview',async()=>{
 const f=inbox();f.p.data.supportEntry={enabled:true,conversation_id:'300',ownLastText:'prior',ownUnread:5}
 f.m.io={http:async()=>{throw error('CHAT_PERMISSION_DENIED')}}
 const pending=f.p.loadSupportEntry(1)
 f.entry.resolve({data:{enabled:true,staff:false,conversation_id:'300'}})
 await pending
 assert.equal(f.p.data.supportEntry.ownLastText,'');assert.equal(f.p.data.supportEntry.ownUnread,0);assert.equal(f.p.data.supportEntry.conversation_id,'')
})
test('queued scroll callback cannot move a replacement conversation window',()=>{
 const d=pageDefinition('miniprogram/subpackages/chat/conversation/index.js'),pending=[],p={life:1,manager:{epoch:1},data:{},current(l,e){return l===this.life&&e===this.manager.epoch},setData(v,cb){Object.assign(this.data,v);if(cb)pending.push(cb)}}
 d.jumpTo.call(p,'message-1');p.life++;pending[0]();assert.equal(p.data.scrollInto,'')
 d.jumpTo.call(p,'message-2');pending[1]();assert.equal(p.data.scrollInto,'message-2')
})
function cache(){
 let now=1000000,warnings=0
 const io={root:'/fixture',now:()=>now,full(){warnings++},async mkdir(){throw Error('ENOSPC')}}
 const c=new ChatCache(io);c.bind('test','1')
 const q={conversation_id:'5',message_id:'9',state_version:'1',kind:'audio',variant:'main'}
 const access=extra=>({url:'https://fixture.invalid/media',object_hash:'a'.repeat(64),state_version:'1',server_time:new Date(now).toISOString(),expires_at:(now+60000)/1000,cloud_available:true,...extra})
 return {c,io,q,access,advance:n=>now+=n,warnings:()=>warnings}
}
test('disk mkdir failure returns only a still-valid HTTPS cloud grant',async()=>{
 const f=cache()
 assert.equal(await f.c.file(f.q,async()=>f.access({})),'https://fixture.invalid/media')
 await assert.rejects(f.c.file(f.q,async()=>f.access({cloud_available:false,url:''})),{code:'CHAT_MEDIA_CLOUD_EXPIRED'})
 await assert.rejects(f.c.file(f.q,async()=>f.access({url:'http://fixture.invalid/media'})),{code:'CHAT_INVALID_RESPONSE'})
 await assert.rejects(f.c.file(f.q,async()=>f.access({expires_at:'bad'})),{code:'CHAT_MEDIA_UNAVAILABLE'})
 f.io.mkdir=async()=>{f.advance(61000);throw Error('ENOSPC')}
 await assert.rejects(f.c.file(f.q,async()=>f.access({})),{code:'CHAT_MEDIA_UNAVAILABLE'})
 assert.equal(f.warnings(),4)
})
