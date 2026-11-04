'use strict'
const assert=require('node:assert/strict')
const fs=require('node:fs')
const path=require('node:path')
const root=path.join(__dirname,'..','source')
const callsMod=require(path.join(root,'miniprogram/utils/ChatCalls.js'))
const {ChatCalls,normalizeCallCode,callMessage}=callsMod
assert.equal(normalizeCallCode('CALL_disabled'),'CALL_DISABLED')
assert.equal(normalizeCallCode('CALL_exhausted'),'CALL_EXHAUSTED')
assert.equal(normalizeCallCode('CALL_safety_insufficient'),'CALL_SAFETY_INSUFFICIENT')
assert.match(callMessage('CALL_exhausted'),/额度已用完/)
assert.match(callMessage('no_peer'),/没有可通话/)
let serial=0
function fixture(handler){
 const manager={epoch:1,enabled:true,foreground:true,live(e){return this.enabled&&this.foreground&&this.epoch===e},emit(){},io:{randomBytes:async n=>(++serial).toString(16).padStart(n*2,'0'),setTimeout(){return 1},clearTimeout(){}},command:handler}
 const calls=new ChatCalls(manager);manager.calls=calls;return {manager,calls}
}
;(async()=>{
 const refusal=fixture(async()=>{throw Object.assign(new Error('quota'),{code:'CALL_exhausted',retryable:false})})
 await assert.rejects(refusal.calls.create('2','12','audio'))
 assert.equal(refusal.calls.pendingCreate,null,'definitive lower-case refusal must clear pending create')
 const uncertain=fixture(async()=>{throw Object.assign(new Error('unknown'),{code:'CALL_UNAVAILABLE',retryable:true})})
 await assert.rejects(uncertain.calls.create('2','12','audio'))
 assert.ok(uncertain.calls.pendingCreate,'retryable unknown result must retain original operation')
 const wxml=fs.readFileSync(path.join(root,'miniprogram/subpackages/chat/conversation/index.wxml'),'utf8')
 assert.match(wxml,/callAudio \? '' : 'is-unavailable'/)
 assert.match(wxml,/callVideo \? '' : 'is-unavailable'/)
 assert.match(wxml,/call-tool-hint/)
 const conv=fs.readFileSync(path.join(root,'miniprogram/subpackages/chat/conversation/calls.js'),'utf8')
 assert.match(conv,/availabilityMessage/)
 assert.match(conv,/normalizeCallCode/)
 assert.match(conv,/呼叫结果待确认，可点击同一入口重试/)
 const page=fs.readFileSync(path.join(root,'miniprogram/subpackages/chat/call/index.js'),'utf8')
 assert.match(page,/terminal,callMessage/)
 const runtime=fs.readFileSync(path.join(root,'server/assets/chat-testing-runtime.js'),'utf8')
 assert.match(runtime,/CALL_exhausted/)
 console.log('mini-program call frontend validation: OK')
})().catch(e=>{console.error(e);process.exit(1)})
