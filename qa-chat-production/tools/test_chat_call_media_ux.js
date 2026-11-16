const assert=require('assert')
const fs=require('fs')
const path=require('path')
const Presentation=require('../miniprogram/utils/ChatPresentation.js')
const {ChatImages}=require('../miniprogram/utils/ChatImages.js')

const now=Date.parse('2026-11-16T18:10:00Z') // Asia/Shanghai: 2026-11-17 02:10
assert.strictEqual(Presentation.timeLabel('2026-11-16T18:00:00Z',now),'02:00')
assert.strictEqual(Presentation.fullTimeLabel('2026-11-16T18:00:00Z'),'2026-11-17 02:00')
assert.strictEqual(Presentation.fullTimeLabel('2026-11-17 02:00:00'),'2026-11-17 02:00')

const view={state:'published',state_version:'3',images:[{item_id:'9'}]}
const model={messages:new Map([['8',view]]),capabilities:{cache:true}}
const manager={io:{now:()=>1000},cache:new Map([['7',model]])}
const images=new ChatImages(manager)
images.urls.set('7:8:9:thumb:3',{url:'cached-local',until:Number.MAX_SAFE_INTEGER,local:true})
assert.strictEqual(images.cachedURL('7','8','9',true),'cached-local')
view.state_version='4'
assert.strictEqual(images.cachedURL('7','8','9',true),'','state-version changes must invalidate media cache')

const root=path.join(__dirname,'..')
const conversation=fs.readFileSync(path.join(root,'miniprogram/subpackages/chat/conversation/index.js'),'utf8')
assert(!conversation.includes("this.imageURLs={};for(const key of this.manager.images?.urls?.keys()"),'page hide must not erase published image cache')
assert(conversation.includes('cachedURL(this.id,id,v.item_id,true)'),'image render must reuse warm media cache immediately')

const notice=fs.readFileSync(path.join(root,'miniprogram/utils/InAppNotice.js'),'utf8')
assert(notice.includes("route==='subpackages/chat/conversation/index'"),'matching conversation must use immersive incoming-call route')
assert(notice.includes('CallSound.sync(view,actor)'),'incoming call must own foreground ringtone state')

const floatWxml=fs.readFileSync(path.join(root,'miniprogram/components/inapp-notice/index.wxml'),'utf8')
assert(floatWxml.includes('{{call.peerName}}')&&floatWxml.includes('语音通话来电'),'floating call card must identify caller')

const callWxml=fs.readFileSync(path.join(root,'miniprogram/subpackages/chat/call/index.wxml'),'utf8')
assert(callWxml.includes('call-finished-actions')&&callWxml.includes('返回会话'),'finished call must expose an adapted return action')
assert(callWxml.includes('incoming-controls')&&callWxml.includes('拒绝')&&callWxml.includes('接听'),'incoming full-screen controls must expose large accept/reject actions')
assert(callWxml.includes('bindtap="reject"'),'incoming decline must use reject, not generic end/hangup')

const calls=fs.readFileSync(path.join(root,'miniprogram/utils/ChatCalls.js'),'utf8')
assert(calls.includes("schedulePoll(0)")&&calls.includes('becameTerminal'),'terminal call state must trigger immediate conversation reconciliation')

for(const file of [
 'miniprogram/subpackages/chat/call/index.js',
 'miniprogram/subpackages/chat/governance/index.js'
]){
 const body=fs.readFileSync(path.join(root,file),'utf8')
 assert(!body.includes('.toLocaleString()'),file+' must not render user time in device timezone')
}
console.log('chat call/media UX regression passed')
