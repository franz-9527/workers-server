'use strict'
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path')
const source=fs.readFileSync(path.join(__dirname,'../miniprogram/subpackages/chat/conversation/av.js'),'utf8')
let ended
const audio={onEnded(fn){ended=fn},onError(){},play(){},stop(){},destroy(){},autoplay:false,src:''}
const moduleBox={exports:{}}
new Function('module','require','wx',source)(moduleBox,()=>({limits:{}}),{createInnerAudioContext:()=>audio})
const p={...moduleBox.exports,visible:true,life:1,id:'9',playSerial:0,views:{},data:{messages:[],playingAudio:''},
 setData(v){Object.assign(this.data,v)},current(l,e){return this.visible&&this.life===l&&this.manager.epoch===e}}
let receipts=0
p.manager={epoch:1,av:{heard:async()=>{receipts++;throw Object.assign(new Error('network'),{code:'CHAT_NETWORK_ERROR'})}}}
const row={messageId:'4',stateVersion:'1',heard:false};p.views['4']=row;p.data.messages=[row]
p.playVoiceSource('local.mp3',row)
assert.equal(row.heard,false);assert.equal(receipts,0)
;(async()=>{
 await ended()
 assert.equal(row.heard,true);assert.equal(p.data.messages[0].heard,true);assert.equal(p.data.playingAudio,'');assert.equal(receipts,1)
 console.log('Chat voice heard UI: 6 assertions passed')
})().catch(error=>{console.error(error);process.exitCode=1})
