'use strict'
const assert=require('node:assert/strict')
const fs=require('node:fs')
const path=require('node:path')
const root=path.join(__dirname,'..','source')
const Experience=require(path.join(root,'miniprogram/subpackages/chat/conversation/experience.js'))
const state={
 data:{
  isGroup:false,assignmentGroup:false,
  receiptLabels:{},
  messages:[
   {messageId:'9',seq:'99',isMine:true,state:'published'},
   {messageId:'10',seq:'100',isMine:true,state:'published'},
   {messageId:'11',seq:'101',isMine:true,state:'published'},
   {messageId:'12',seq:'102',isMine:false,state:'published'}
  ]
 },
 setData(patch){this.data={...this.data,...patch}}
}
for(const [k,v] of Object.entries(Experience))if(typeof v==='function')state[k]=v
assert.equal(state.applyPeerReadSeq('100'),true)
assert.deepEqual(state.data.receiptLabels,{'9':'已读','10':'已读','11':'未读'})
assert.equal(state.applyPeerReadSeq('99'),false,'peer watermark must never move backwards')
assert.deepEqual(state.data.receiptLabels,{'9':'已读','10':'已读','11':'未读'})
assert.equal(state.applyPeerReadSeq('101'),true)
assert.equal(state.data.receiptLabels['11'],'已读')
const wxml=fs.readFileSync(path.join(root,'miniprogram/subpackages/chat/conversation/index.wxml'),'utf8')
assert.match(wxml,/directCalls \? '未读' : '查看阅读状态'/)
const group=fs.readFileSync(path.join(root,'miniprogram/subpackages/chat/conversation/group.js'),'utf8')
assert.match(group,/if\(!this\.data\.isGroup&&!this\.data\.assignmentGroup\)/)
assert.match(group,/conversation\.readers/)
const exp=fs.readFileSync(path.join(root,'miniprogram/subpackages/chat/conversation/experience.js'),'utf8')
assert.match(exp,/if\(!this\.data\.isGroup&&!this\.data\.assignmentGroup\)/)
assert.match(exp,/await this\.refreshUnread\(\)/)
assert.match(exp,/conversation\.readers/)
console.log('direct receipt frontend behavior: OK')
