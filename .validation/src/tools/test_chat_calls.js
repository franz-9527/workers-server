'use strict'
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path')
const {ChatCalls}=require('../miniprogram/utils/ChatCalls.js')
const base={call_id:'1',conversation_id:'2',caller_id:'11',callee_id:'12',provider:'shengwang',media:'audio',state:'ringing',attempt_epoch:'1',revision:'1',ring_expires_at:130,authorized_until:160,server_time:100,prepared_count:0,created_at:100}
function fixture(handler=async()=>({...base})){
 let serial=0;const timers=new Map(),events=[],requests=[],incoming=[]
 const manager={epoch:1,enabled:true,foreground:true,live(e){return this.enabled&&this.foreground&&this.epoch===e},emit(t,v){events.push([t,v])},
  io:{randomBytes:async n=>(++serial).toString(16).padStart(n*2,'0'),setTimeout(fn){const id=++serial;timers.set(id,fn);return id},clearTimeout(id){timers.delete(id)},incoming(v,e){incoming.push([v,e])}},
  command:async(type,q)=>{requests.push([type,structuredClone(q)]);return handler(type,q)}}
 const calls=new ChatCalls(manager);manager.calls=calls
 return{manager,calls,timers,events,requests,incoming}
}
test('disabled capability does not start account polling',async()=>{const f=fixture();await f.calls.poll();assert.equal(f.requests.length,0);f.calls.foreground(true);assert.equal(f.timers.size,0)})
test('duplicate create clicks share a single immutable operation',async()=>{
 const f=fixture();const [a,b]=await Promise.all([f.calls.create('2','12','audio'),f.calls.create('2','12','audio')])
 assert.equal(a.call_id,b.call_id);assert.equal(f.requests.length,1);assert.match(f.requests[0][1].operation_id,/^[a-f0-9]{64}$/)
})
test('unknown create retries original operation and rejects changed input',async()=>{
 let count=0;const f=fixture(async()=>{if(++count===1)throw Object.assign(new Error('network'),{code:'CHAT_NETWORK_ERROR',retryable:true});return {...base}})
 await assert.rejects(f.calls.create('2','12','audio'));const original=structuredClone(f.calls.pendingCreate)
 await assert.rejects(f.calls.create('2','12','video'),e=>e.code==='CALL_OPERATION_PENDING')
 await f.calls.create('2','12','audio');assert.deepEqual(f.requests[1][1],original);assert.equal(f.calls.pendingCreate,null)
})
test('late create acknowledgment after account reset cannot restore state',async()=>{
 let done;const f=fixture(()=>new Promise(resolve=>done=resolve))
 const work=f.calls.create('2','12','audio');await new Promise(setImmediate)
 f.calls.reset();f.manager.epoch++;done({...base});await work
 assert.equal(f.calls.current,null);assert.equal(f.calls.pendingCreate,null)
})
test('account reset during nonce generation never sends the old invitation',async()=>{
 let done;const f=fixture();let first=true
 f.manager.io.randomBytes=n=>first?(first=false,new Promise(resolve=>done=()=>resolve('a'.repeat(n*2)))):Promise.resolve('b'.repeat(n*2))
 const work=f.calls.create('2','12','audio');f.calls.reset();f.manager.epoch++;done()
 await assert.rejects(work,e=>e.code==='CHAT_CONTEXT_CHANGED');assert.equal(f.requests.length,0);assert.equal(f.calls.pendingCreate,null)
})
test('out-of-order views do not restore old epoch/revision or shorten a renewed permit',()=>{
 const f=fixture();f.calls.apply({...base,state:'active',revision:'4',authorized_until:300,prepared_count:2})
 f.calls.apply({...base,state:'ringing',revision:'1'});assert.equal(f.calls.current.state,'active')
 f.calls.apply({...base,state:'active',revision:'4',authorized_until:160,prepared_count:0});assert.equal(f.calls.current.authorized_until,300);assert.equal(f.calls.current.prepared_count,2)
 f.calls.apply({...base,attempt_epoch:'2',revision:'5'});f.calls.apply({...base,state:'active',revision:'99'});assert.equal(f.calls.current.attempt_epoch,'2')
})
test('concurrent call actions never overwrite an in-flight operation',async()=>{
 let done;const f=fixture(()=>new Promise(resolve=>done=resolve));f.calls.apply({...base,state:'connecting',revision:'3'})
 const pending=f.calls.action('ready');await new Promise(setImmediate)
 await assert.rejects(f.calls.action('pulse'),e=>e.code==='CALL_OPERATION_PENDING')
 done({...base,state:'connecting',revision:'3'});await pending;assert.equal(f.calls.pending,null)
})
test('WSS permanent conflict clears pending operation; transport uncertainty retains it',async()=>{
 let fail=true;const f=fixture(async()=>{if(fail)throw Object.assign(new Error('conflict'),{code:'CALL_STATE_CONFLICT',retryable:false});return {...base,state:'cancelled',revision:'2'}})
 f.calls.apply({...base});await assert.rejects(f.calls.action('end'));assert.equal(f.calls.pending,null)
 fail=false;await f.calls.action('end');assert.equal(f.calls.current.state,'cancelled')
})
test('incoming invite is presented once and only to the callee',async()=>{
 const f=fixture(async()=>[{...base}]);f.calls.enabled=true;f.calls.actorID='12'
 await f.calls.poll();await f.calls.poll();assert.equal(f.incoming.length,1)
 f.calls.reset();f.manager.epoch++;f.calls.enabled=true;f.calls.actorID='11';await f.calls.poll();assert.equal(f.incoming.length,1)
})
function mediaClass(SDK){
 const module={exports:{}}
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../miniprogram/subpackages/chat/call/media.js'),'utf8'),{module,require:()=>SDK})
 return module.exports.CallMedia
}
test('voice SDK joins audio-only and receives complete native status events',async()=>{
 const seen={},paints=[];class Client{
  on(n,fn){seen[n]=fn}async init(){}async setRole(role){seen.role=role}async join(...args){seen.join=args}async publish(){return'rtmp://synthetic/local'}
  async subscribe(uid,options){seen.subscribe=[uid,options];return{url:'rtmp://synthetic/remote'}}updatePusherStateChange(e){seen.pusher=e}updatePlayerStateChange(uid,e){seen.player=e}
  updatePusherNetStatus(e){seen.pusherNet=e}updatePlayerNetStatus(uid,e){seen.playerNet=e}async unpublish(){}async leave(){}async destroy(){}async renewToken(){}
 }
 const Media=mediaClass({Client});let ready=0
 const media=new Media({},p=>paints.push(p),{prepared(){},ready(){ready++},end(){},error(){}})
 await media.start({provider:'shengwang',app_id:'a'.repeat(32),token:'synthetic',channel:'synthetic',uid:1,peer_uid:2},{media:'audio'})
 assert.equal(seen.join[3],true);assert.equal(seen.role,'broadcaster')
 const event={detail:{code:1002,info:{}}};media.pusher(event);media.player(event);media.net(event,'pusher');media.net(event,'player')
 assert.equal(seen.pusher,event);assert.equal(seen.player,event);assert.equal(seen.pusherNet,event);assert.equal(seen.playerNet,event);assert.equal(ready,1)
 await seen['stream-added']({uid:99});assert.equal(seen.subscribe,undefined)
 await seen['stream-added']({uid:2});assert.equal(seen.subscribe[0],2);assert.equal(seen.subscribe[1].video,false)
 await media.stop();await seen['stream-added']({uid:2});assert.equal(media.closed,true)
})
test('late SDK initialization after stop never joins or restores media URLs',async()=>{
 let finish,joins=0;class Client{on(){}init(){return new Promise(resolve=>finish=resolve)}async setRole(){}async join(){joins++}async publish(){return'rtmp://synthetic'}async unpublish(){}async leave(){}async destroy(){}}
 const Media=mediaClass({Client});const paints=[]
 const media=new Media({},p=>paints.push(p),{prepared(){},ready(){},end(){},error(){}})
 const start=media.start({provider:'shengwang'},{media:'video'});await media.stop();finish();await start
 assert.equal(joins,0);assert.ok(paints.every(p=>!p.localURL&&!p.remoteURL))
})
test('native caller waits for both prepared endpoints; listener never creates a second call',async()=>{
 let joins=0,member,ready=0
 const api={setEnable1v1Chat(o){o.success?.()},join1v1Chat(o){joins++;o.success()},exitVoIPChat(){},onVoIPChatMembersChanged(fn){member=fn},offVoIPChatMembersChanged(){},onVoIPChatInterrupted(){},offVoIPChatInterrupted(){}}
 const Media=mediaClass({}),grant={provider:'wechat',initiator:true,caller:{openid:'synthetic-a',nickname:'用户A'},listener:{openid:'synthetic-b',nickname:'用户B'}}
 const media=new Media(api,()=>{},{prepared(){},ready(){ready++},end(){},error(){}})
 await media.start(grant,{media:'audio'});await media.initiate({media:'audio',prepared_count:1});assert.equal(joins,0)
 await media.initiate({media:'audio',prepared_count:2});await media.initiate({media:'audio',prepared_count:2});assert.equal(joins,1)
 member({openIdList:['synthetic-a','synthetic-b']});assert.equal(ready,1);await media.stop();member({openIdList:['synthetic-a','synthetic-b']});assert.equal(ready,1)
})
test('an end intent prevents media re-creation while confirmation is pending',async()=>{
 let definition,prepares=0,ends=0
 const calls={actorID:'11',current:{...base,state:'active',revision:'4'},async status(){return this.current},async action(name){if(name==='end')ends++;return this.current},async prepare(){prepares++;return{}}}
 const manager={epoch:1,enabled:true,calls,live:()=>true,subscribe:()=>()=>{}}
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../miniprogram/subpackages/chat/call/index.js'),'utf8'),{
  require(name){if(name.includes('ChatRuntime'))return{get:()=>manager};if(name==='./media.js')return{CallMedia:class{}};return{terminal:new Set(['ended','failed','cancelled','rejected','missed'])}},
  Page(o){definition=o},wx:{},setTimeout:()=>1,clearTimeout(){},Date,console
 })
 const page={...definition,data:structuredClone(definition.data),setData(p){Object.assign(this.data,p)}}
 page.onLoad({});page.visible=true;page.ending=true;await page.tick()
 assert.equal(prepares,0);assert.equal(ends,1)
})
