const test=require('node:test')
const assert=require('node:assert/strict')
const fs=require('fs'),path=require('path')
const {ChatAV}=require('../miniprogram/utils/ChatAV.js')
const defer=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no});return {promise,resolve,reject}}
const fail=code=>Object.assign(new Error(code),{code})
function fixture(size=5*1024*1024){
 let status=null,uploaded=new Set(),active=0,maximum=0,sendCalls=0,downloads=0
 const uploads=[],routes=[],pieces=[],events=[]
 const model={canSend:true,capabilities:{audio:true,video:true,cache:true},messages:new Map()}
 const m={epoch:1,serial:0,enabled:true,foreground:true,cache:new Map([['9',model]]),disk:{scope:{actor:'1',environment:'test'}},
  io:{now:()=>1800000000000,random:()=>0.1,av:{info:async()=>({size,digest:'a'.repeat(32)}),save:async()=>{},load:async()=>[],remove:async()=>{},warn(){},network:async()=> 'wifi',piece:async(p,start,bytes)=>{pieces.push({start,bytes});return 'piece-'+start},unlink:async()=>{}},
   upload:async(grant,p)=>{active++;maximum=Math.max(active,maximum);uploads.push({ordinal:grant.ordinal,path:p});await Promise.resolve();active--}},
  live(epoch){return this.epoch===epoch&&this.enabled&&this.foreground},checkFocus(){},emit(type,data){events.push({type,data})},schedulePoll(){},refreshAccount(){},
  async http(type,q){
   routes.push({type,q:{...q}})
   if(type==='av.upload')return {attachment_id:'8',object_version:'b'.repeat(64),part_count:size>4194304?Math.ceil(size/2097152):1}
   if(type==='av.status'){if(!status||status.client_message_id!==q.client_message_id)throw fail('CHAT_SEND_RESULT_UNKNOWN');return {...status,uploaded_parts:[...uploaded]}}
   if(type==='av.part'){if(q.confirm){uploaded.add(q.ordinal);return {state:'present'}}return {state:'issued',ordinal:q.ordinal,host:'https://upload.invalid',token:'secret',key:'part-'+q.ordinal}}
   if(type==='av.complete'){status.phase='prepare';return {submitted:true}}
   if(type==='av.pending')return status?[status]:[]
   if(type==='av.cancel'){status.state='canceled';return {...status}}
   if(type==='av.listened')return {heard:true}
   if(type==='av.url')return {url:'https://private.invalid',object_hash:'c'.repeat(64),state_version:'1',retention_mode:'permanent'}
   throw fail('UNKNOWN_ROUTE')
  },
  async action(id,type,q){sendCalls++;status={client_message_id:q.client_message_id,state:'checking',phase:'uploading',part_count:size>4194304?Math.ceil(size/2097152):1,attachment_id:'8',object_version:'b'.repeat(64),expires_at:'2027-01-16T09:00:00Z'};return {...status}}
 }
 const av=new ChatAV(m);m.av=av
 return {m,av,model,routes,uploads,pieces,events,stats:()=>({maximum,sendCalls,downloads}),setStatus:s=>status=s,setDone:parts=>uploaded=new Set(parts)}
}
test('AV selection performs no upload; sends only on explicit confirmation with bounded pieces',async()=>{
 const f=fixture(),id=f.av.stage('9',{path:'original',size:5*1048576,duration:120,source:'album'})
 assert.equal(f.routes.length,0)
 await f.av.send('9',id)
 assert.equal(f.stats().sendCalls,1);assert.equal(f.uploads.length,3);assert(f.stats().maximum<=2)
 assert(f.pieces.every(p=>p.bytes<=2097152));assert.deepEqual(f.pieces.map(p=>p.start),[0,2097152,4194304])
 assert.equal(f.av.rows.get(id).phase,'prepare')
})
test('AV resumed transfer keeps the original action and uploads only missing pieces',async()=>{
 const f=fixture(),id=f.av.stage('9',{path:'original',size:5*1048576,duration:120,source:'album'})
 let failed=true;const original=f.m.io.upload
 f.m.io.upload=async(g,p)=>{if(failed&&g.ordinal===2)throw fail('CHAT_UPLOAD_FAILED');return original(g,p)}
 await assert.rejects(f.av.send('9',id));const request=JSON.stringify(f.av.rows.get(id).request)
 failed=false;await f.av.retry('9',id)
 assert.equal(f.stats().sendCalls,1);assert.equal(JSON.stringify(f.av.rows.get(id).request),request)
 assert.equal(f.uploads.filter(p=>p.ordinal===1).length,1)
})
test('AV limits reject album beyond 120 seconds or 20MB and camera beyond 30 seconds',()=>{
 const f=fixture()
 for(const v of [{source:'album',duration:120.1,size:20*1048576},{source:'album',duration:120,size:20*1048576+1},{source:'camera',duration:31,size:100},{source:'voice',duration:0.9,size:100}])assert.throws(()=>f.av.stage('9',{path:'x',...v}))
 assert.equal(f.routes.length,0)
})
test('AV hides during a late upload and cannot submit completion or show stale state',async()=>{
 const f=fixture(100),pending=defer();f.m.io.upload=()=>pending.promise
 const id=f.av.stage('9',{path:'x',size:100,duration:2,source:'voice'}),sending=f.av.send('9',id)
 for(let i=0;i<12&&!f.routes.some(r=>r.type==='av.part');i++)await Promise.resolve()
 f.m.foreground=false;f.av.pause();pending.resolve()
 await assert.rejects(sending);assert(!f.routes.some(r=>r.type==='av.complete'))
})
test('AV fresh authorization is used on every cached playback and no admin read is fabricated',async()=>{
 const f=fixture();f.model.messages.set('4',{message_id:'4',message_type:'audio',state:'published',state_version:'1'})
 let auth=0;f.m.disk.file=async(q,authorize,guard)=>{auth++;await authorize();assert(guard());return 'cached.mp3'}
 await f.av.url('9','4');await f.av.url('9','4');assert.equal(auth,2)
 assert(!f.routes.some(r=>r.type==='av.listened'))
 f.model.messages.get('4').state='recalled';await assert.rejects(f.av.url('9','4'))
})
function native(wx){
 const module={exports:{}},source=fs.readFileSync(path.join(__dirname,'../miniprogram/subpackages/chat/conversation/av.js'),'utf8')
 new Function('module','require','wx',source)(module,()=>require('../miniprogram/utils/ChatAV.js'),wx)
 const p={...module.exports,visible:true,life:1,id:'9',playSerial:0,views:{},data:{canSend:true,audioEnabled:true,recording:false},setData(v){Object.assign(this.data,v)},current(l,e){return this.visible&&this.life===l&&this.manager.epoch===e}}
 return p
}
test('voice playback receipt is emitted only by actual ended callback, never start or hidden callback',async()=>{
 let ended,heard=0;const audio={onEnded(fn){ended=fn},onError(){},play(){},stop(){},destroy(){}}
 const p=native({createInnerAudioContext:()=>audio});p.manager={epoch:1,av:{heard:async()=>heard++}}
 const row={messageId:'4',stateVersion:'1'};p.views['4']=row;p.playVoiceSource('local.mp3',row)
 assert.equal(heard,0);await ended();assert.equal(heard,1)
 p.playVoiceSource('local.mp3',row);p.visible=false;await ended();assert.equal(heard,1)
})
test('releasing before microphone permission returns cannot start a hidden recorder',async()=>{
 const permission=defer();let starts=0
 const p=native({authorize(o){permission.promise.then(()=>o.success({}))},getRecorderManager(){return {start(){starts++}}}})
 p.manager={epoch:1};const started=p.beginVoice({touches:[{clientY:100}]});p.endVoice();permission.resolve();await started
 assert.equal(starts,0);assert.equal(p.data.recording,false)
})

test('AV can send more than four completed messages while keeping active work bounded',async()=>{
 const f=fixture(100)
 for(let i=0;i<8;i++){
  const id=f.av.stage('9',{path:'x',size:100,duration:2,source:'voice'})
  await f.av.send('9',id);const row=f.av.rows.get(id),mid=String(40+i)
  f.av.settle(row,{client_message_id:id,state:'accepted',message_id:mid})
  f.model.messages.set(mid,{message_id:mid,state:'published'});f.av.list('9')
 }
 assert.equal(f.stats().sendCalls,8);assert.equal(f.av.rows.size,0)
})
