const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs')
const source=fs.readFileSync(require('node:path').join(__dirname,'../miniprogram/subpackages/chat/conversation/voice.js'),'utf8')
function fixture(duration=60000){
 let now=0,stopCount=0,staged=0,serial=0;const timers=new Map(),listeners={}
 const recorder={onStart(f){listeners.start=f},onStop(f){listeners.stop=f},onError(){},offStart(){},offStop(){},offError(){},start(){listeners.start()},stop(){stopCount++;listeners.stop({duration,tempFilePath:'fixture.mp3'})}}
 const module={exports:{}};vm.runInNewContext(source,{module,require:()=>({createVoiceRecorder:()=>recorder}),wx:{authorize(o){o.success()},vibrateShort(){},showToast(){}},Date:{now:()=>now},setTimeout(f,delay){const id=++serial;timers.set(id,{f,delay});return id},clearTimeout(id){timers.delete(id)}})
 const page={...module.exports,visible:true,life:1,id:'9',data:{canSend:true,audioEnabled:true},setData(v){Object.assign(this.data,v)},current(){return this.visible},refreshAV(){},jumpBottom(){},manager:{epoch:1,io:{av:{info:async()=>({size:100})}},av:{stage(){staged++;return 'id'},send:async()=>{}},draft:()=>({})}}
 return {page,timers,run:delay=>{const [id,t]=[...timers].find(([,t])=>t.delay===delay);timers.delete(id);t.f()},advance:n=>now=n,stats:()=>({stopCount,staged})}
}
const flush=async()=>{for(let i=0;i<8;i++)await Promise.resolve()}
test('holding voice displays elapsed seconds and stops once at sixty',async()=>{
 const f=fixture();await f.page.beginVoice({touches:[{clientY:100}]});f.advance(5000)
 f.run(250);assert.equal(f.page.data.recordSeconds,5)
 f.advance(60000);f.run(60000);await flush()
 assert.equal(f.stats().stopCount,1);assert.equal(f.stats().staged,1);assert.equal(f.timers.size,0)
})
test('over-sixty-second recorder output is never uploaded',async()=>{
 const f=fixture(60001);await f.page.beginVoice({touches:[{clientY:100}]});f.page.endVoice();await flush()
 assert.equal(f.stats().staged,0);assert.match(f.page.data.notice,/60秒/)
})
