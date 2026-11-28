const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm')
const {ChatAV}=require('../miniprogram/utils/ChatAV.js'),{ChatImages}=require('../miniprogram/utils/ChatImages.js')
const defer=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return {promise,resolve,reject}}
function browser(){
 const module={exports:{}},media=[]
 vm.runInNewContext(fs.readFileSync(require.resolve('../server/assets/chat-testing-client.js'),'utf8'),{module,require:()=>({}),document:{querySelector:()=>null,createElement:()=>{const e={duration:2};media.push(e);return e}},crypto:{randomUUID:()=> 'fixture-file'},URL:{createObjectURL:()=> 'blob:fixture',revokeObjectURL(){}},Date})
 const p=Object.create(module.exports.TestPeer.prototype),model={messages:new Map()},m={epoch:1,cache:new Map([['9',model]])}
 Object.assign(p,{generation:1,manager:m,cid:'9',files:new Map(),urls:new Map(),paint(){}})
 return {p,m,model,media}
}
test('browser file metadata from an old member cannot stage or send in a new member',async()=>{
 const f=browser();let staged=0;f.m.av={stage:async()=>{staged++;return '1'},send:async()=>{throw Error('unexpected send')}}
 const pending=f.p.select('audio',[{name:'fixture.mp3',size:100}])
 f.p.generation++;f.p.manager={...f.m};f.media[0].onloadedmetadata()
 await pending;assert.equal(staged,0)
})
test('resync invalidates captured browser media actions even after synchronization succeeds',()=>{
 const f=browser(),row={message_id:'2',state_version:'1',state:'published'}
 f.model.messages.set('2',row);const context=f.p.context()
 assert.equal(f.p.mediaCurrent(context,row),true)
 f.p.viewGeneration=1;f.p.resyncing=true;f.p.resyncing=false
 assert.equal(f.p.mediaCurrent(context,row),false)
})
test('failed browser image URL request does not poison retry cache',async()=>{
 const f=browser();let loads=0
 await assert.rejects(f.p.imageURL('x',async()=>{loads++;throw Error('offline')},()=>{}))
 await f.p.imageURL('x',async()=>{loads++;return 'fixture'},v=>assert.equal(v,'fixture'))
 assert.equal(loads,2)
})
function mediaFixture(type){
 const row={message_id:'2',state:'published',state_version:'1',message_type:type,images:[{item_id:'3'}]}
 const model={capabilities:{cache:true},messages:new Map([['2',row]])},d=defer()
 const m={epoch:1,enabled:true,live:epoch=>epoch===1,cache:new Map([['9',model]]),io:{now:()=>1000},http:()=>d.promise}
 return {m,model,row,d}
}
test('AV disk result is rejected after an in-place version change',async()=>{
 const f=mediaFixture('audio');f.m.disk={scope:{},file:()=>f.d.promise}
 const av=new ChatAV(f.m),pending=av.url('9','2')
 f.row.state_version='2';f.d.resolve('stale.mp3')
 await assert.rejects(pending,{code:'CHAT_CONTEXT_CHANGED'})
})
test('AV and image signed links with wrong versions never escape',async()=>{
 for(const kind of ['audio','image']){
  const f=mediaFixture(kind),service=kind==='audio'?new ChatAV(f.m):new ChatImages(f.m)
  const pending=kind==='audio'?service.url('9','2'):service.nativePreviewURL('9','2','3')
  f.d.resolve({url:'https://fixture.invalid/private',expires_at:60,state_version:'2',cloud_available:true})
  await assert.rejects(pending,{code:'CHAT_CONTEXT_CHANGED'})
 }
})
test('AV rejects an expired or non-HTTPS grant',async()=>{
 for(const result of [{url:'http://fixture.invalid/a',expires_at:60},{url:'https://fixture.invalid/a',expires_at:1}]){
  const f=mediaFixture('audio'),av=new ChatAV(f.m),pending=av.url('9','2')
  f.d.resolve({...result,state_version:'1'});await assert.rejects(pending,{code:'CHAT_INVALID_RESPONSE'})
 }
})
