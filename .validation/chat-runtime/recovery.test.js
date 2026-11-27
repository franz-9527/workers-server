const test=require('node:test'),assert=require('node:assert/strict')
const {ChatManager}=require('./miniprogram/utils/ChatManager.js')
const fail=code=>Object.assign(Error(code),{code})
function fixture(handler,scene=''){
 let now=1000000,serial=0;const calls=[],timers=new Map()
 const io={now:()=>now,randomBytes:async n=>'ab'.repeat(n),setTimeout(fn,delay){timers.set(++serial,{fn,delay});return serial},clearTimeout(id){timers.delete(id)},credential:async()=>({access_token:'fixture',expires_at:new Date(now+300000).toISOString(),actor_id:'1',environment:'test'}),http:async(path,data,token,requestScene)=>{calls.push({path,data,requestScene});return handler(path,data)},jitter:()=>0.5}
 const m=new ChatManager(io);m.enabled=true;m.foreground=true;m.active='9';m.activeScene=scene;m.focusEpoch=1;m.cache.set('9',{scene,messages:new Map(),canSend:true,policy:'1'})
 m.schedulePoll=()=>{};m.refreshAccount=()=>{}
 const tick=async n=>{now+=n;await m.probeTextAttempts()}
 const send=async()=>{m.saveDraft('9','原消息🙂');await m.sendText('9','原消息🙂',m.draft('9').revision);return m.attempts('9')[0]}
 return {m,send,tick,calls,writes:()=>calls.filter(x=>x.path==='messages/send'),reads:()=>calls.filter(x=>x.path==='messages/result')}
}
for(const scene of ['', 'direct', 'support'])test('two retries, immutable payload, '+(scene||'order'),async()=>{
 let count=0;const f=fixture((p,d)=>{if(p==='messages/result')throw fail('CHAT_SEND_RESULT_UNKNOWN');if(p==='messages/send'){count++;throw fail(count===1?'CHAT_DEPENDENCY_UNAVAILABLE':'CHAT_CHECK_UNAVAILABLE')}throw Error(p)},scene)
 let row=await f.send();assert.equal(row.state,'retryable');assert.equal(f.writes().length,1)
 await f.tick(1499);assert.equal(count,1);await f.tick(1);assert.equal(count,2)
 await f.tick(2999);assert.equal(count,2);await f.tick(1);assert.equal(count,3)
 await f.tick(60000);assert.equal(count,3);assert.equal(f.m.canAutoRetryText(f.m.attempts('9')[0]),false)
 for(const w of f.writes())assert.deepEqual(w.data,f.writes()[0].data)
 assert.equal(f.reads().length,2);for(const w of f.writes())assert.equal(w.requestScene,scene)
})
test('temporary failure then accepted, no third send',async()=>{
 let count=0;const f=fixture((p,d)=>{if(p==='messages/result')return {client_message_id:d.client_message_id,state:'retryable',code:'CHAT_CHECK_UNAVAILABLE'};if(p==='messages/send'){if(++count===1)throw fail('CHAT_CHECK_UNAVAILABLE');return {client_message_id:d.client_message_id,state:'accepted',message_id:'42'}}throw Error(p)})
 await f.send();await f.tick(1500);await f.tick(60000);assert.equal(count,2);assert.equal(f.m.attempts('9')[0].state,'accepted')
})
for(const state of ['accepted','rejected','checking','review_pending'])test('status '+state+' does not auto resend',async()=>{
 const f=fixture((p,d)=>{if(p==='messages/result')return {client_message_id:d.client_message_id,state,message_id:state==='accepted'?'42':''};throw fail('CHAT_CHECK_UNAVAILABLE')})
 await f.send();await f.tick(1500);assert.equal(f.writes().length,1);assert.equal(f.m.attempts('9')[0].state,state)
})
test('unknown result remains status-only',async()=>{
 const f=fixture((p,d)=>{if(p==='messages/result')throw fail('CHAT_SEND_RESULT_UNKNOWN');throw fail('CHAT_NETWORK_ERROR')})
 await f.send();await f.tick(5000);assert.equal(f.writes().length,1);assert.equal(f.m.attempts('9')[0].state,'unknown')
})
test('query down consumes bounded attempts, no blind resend',async()=>{
 const f=fixture((p,d)=>{throw fail('CHAT_DEPENDENCY_UNAVAILABLE')})
 await f.send();await f.tick(1500);await f.tick(6000);await f.tick(60000);assert.equal(f.writes().length,1);assert.equal(f.m.canAutoRetryText(f.m.attempts('9')[0]),false)
})
test('permission change during status clears pending',async()=>{
 const f=fixture((p,d)=>{if(p==='messages/result')throw fail('CHAT_PERMISSION_DENIED');throw fail('CHAT_CHECK_UNAVAILABLE')})
 await f.send();await f.tick(1500);assert.equal(f.writes().length,1);assert.equal(f.m.outbox.size,0)
})
test('leaving visible conversation stops recovery',async()=>{
 const f=fixture((p,d)=>{throw fail('CHAT_CHECK_UNAVAILABLE')});await f.send();f.m.active='10';f.m.focusEpoch++;await f.tick(1500);assert.equal(f.writes().length,1)
})
test('recovered ledger row cannot replay',async()=>{
 const f=fixture((p,d)=>{throw fail('CHAT_CHECK_UNAVAILABLE')});await f.send();const row=[...f.m.outbox.values()][0];row.recovered=true;row.text='';await f.tick(1500);assert.equal(f.writes().length,1)
})
function decodeTitle(options){
  let rawTitle=String(options && options.title || '')
  // WeChat navigation query values can retain URI encoding. Decode before clipping.
  if(/%[0-9a-f]{2}/i.test(rawTitle)){try{rawTitle=decodeURIComponent(rawTitle)}catch(_){rawTitle=''}}
  const initialTitle=Array.from(rawTitle.replace(/[\u0000-\u001f\u007f]/g,'').trim()).slice(0,24).join('')
 return initialTitle
}
test('URI Chinese titles, literal Chinese, emoji length, malformed bytes',()=>{
 assert.equal(decodeTitle({title:encodeURIComponent('用户反馈 · 中文名称')}),'用户反馈 · 中文名称')
 assert.equal(decodeTitle({title:'中文'}),'中文')
 assert.equal(decodeTitle({title:encodeURIComponent('😀'.repeat(30))}),'😀'.repeat(24))
 assert.equal(decodeTitle({title:'%E7%94'}),'')
})
