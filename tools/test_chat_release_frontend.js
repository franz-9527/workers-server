const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm')
const {ChatManager}=require('../miniprogram/utils/ChatManager.js')
const Inbox=require('../miniprogram/utils/ChatInboxModel.js')
const native=JSON.parse(fs.readFileSync('test-artifacts/native-simulation.json','utf8'))
async function main(){
 const timers=new Map();let counter=0,sockets=0,httpCalls=[]
 const io={now:()=>Date.now(),monotonicNow:()=>Date.now(),random:()=>0.5,setTimeout:(fn,ms)=>{timers.set(++counter,{fn,ms});return counter},clearTimeout:id=>timers.delete(id),environment:()=> 'test',credential:async()=>native.credential,socket(){sockets++;throw Error('simulation opened socket')},http:async(path)=>{
  httpCalls.push(path)
  if(path==='conversations/digests')return native.digests
  if(path==='conversations/bootstrap')return native.page
  if(path==='messages/sync')return {...native.page,messages:[],has_more:false}
  if(path==='messages/changes')return {...native.page,messages:[],changes:[],has_more:false}
  if(path==='messages/send')return {...native.sent,client_message_id:manager.attempts(cid).at(-1).client_message_id}
  if(path==='calls/availability')return {enabled:false}
  throw Error('unexpected path '+path)
 }}
 const cid=native.digests.items[0].conversation_id,manager=new ChatManager(io),events=[]
 manager.subscribe((type,payload)=>events.push({type,payload}));manager.setEnabled(true);manager.setForeground(true);manager.focus(cid)
 await manager.reconcile();await Promise.resolve();
 assert.equal(sockets,0);assert.equal(manager.simulation,true);assert(manager.cache.get(cid).messages.size>0)
 const rows=Inbox.applyPage({}, {...native.digests,replace:true});assert.equal(rows[cid].title,'【模拟】订单沟通');assert(rows[cid].unreadCount>0)
 manager.saveDraft(cid,'前端模拟文字');await manager.sendText(cid,'前端模拟文字',manager.draft(cid).revision)
 assert.equal(manager.attempts(cid).at(-1).state,'accepted');assert(httpCalls.includes('messages/send'))
 manager.setEnabled(false);assert.equal(manager.cache.size,0);assert.equal(manager.token,null);assert.equal(manager.simulation,false)
 // Exercise the actual Runtime's asynchronous switch and environment boundary.
 let enabled=false,resolveAvailability,generation=1,changeCallback,rawHTTP=0,requests=[]
 const environment={capture:()=>({generation}),assertCurrent:c=>{if(c.generation!==generation)throw Error('changed')},current:()=>({key:'testing',baseURL:'https://fixture.invalid/api/'}),onChange:fn=>changeCallback=fn}
 class StubManager{constructor(io){this.io=io;this.enabled=false;this.foreground=false;this.epoch=0;this.listeners=[]}subscribe(fn){this.listeners.push(fn)}emit(t,p){this.listeners.forEach(fn=>fn(t,p))}setEnabled(v){this.enabled=v;this.reset()}reset(){this.epoch++;this.emit('reset')}setForeground(v){this.foreground=v}setNetwork(){}}
 const api={request:async(route,method,data)=>{requests.push({route,data});if(route==='chat.availability'){if(resolveAvailability)return new Promise(r=>resolveAvailability=r);return {data:{enabled}}}return {data:{ok:true}}}}
 const runtimeCode=fs.readFileSync('miniprogram/utils/ChatRuntime.js','utf8'),module={exports:{}}
 const sandbox={module,exports:module.exports,setTimeout:()=>1,clearTimeout(){},wx:{request(){rawHTTP++}},require:p=>p.includes('ChatManager')?{ChatManager:StubManager}:p.includes('ApiEnvironment')?environment:p.includes('ChatCache')?{createWxCache:()=>null}:p.includes('ChatAVFiles')?{createWxAV:()=>null}:p.includes('NotificationState')?{resetChat(){},applyChat(){},chatUnavailable(){}}:p.includes('OrderApi')?api:{}}
 vm.runInNewContext(runtimeCode,sandbox,{filename:'ChatRuntime.js'});const runtime=module.exports,m=runtime.get()
 await runtime.refreshAvailability();assert.equal(m.enabled,false);enabled=true;await runtime.refreshAvailability();assert.equal(m.enabled,true)
 const epoch=m.epoch;await runtime.refreshAvailability();assert.equal(m.epoch,epoch,'unchanged switch must not erase messages')
 await m.io.http('messages/send',{conversation_id:'1'},'simulation');assert.equal(requests.at(-1).route,'chat.simulation');assert.equal(rawHTTP,0)
 enabled=false;await runtime.refreshAvailability();assert.equal(m.enabled,false)
 resolveAvailability=true;const stale=runtime.refreshAvailability();await Promise.resolve();const complete=resolveAvailability;generation++;changeCallback();complete({data:{enabled:true}});await stale;assert.equal(m.enabled,false,'stale environment response opened chat')
 console.log('PASS actual native payloads through ChatManager/InboxModel; simulation HTTP only; send acknowledgements; switch refresh, close and environment stale response')
}
main().catch(e=>{console.error(e);process.exitCode=1})
