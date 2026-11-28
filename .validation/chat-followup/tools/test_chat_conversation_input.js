'use strict'
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm')
const source=fs.readFileSync(path.join(__dirname,'../miniprogram/subpackages/chat/conversation/index.js'),'utf8')
let definition
vm.runInNewContext(source,{
 Page(value){definition=value},
 require(name){
  if(name.includes('ChatManager'))return{compare:()=>0,validText:()=>true,mergeMessage:v=>v}
  return{}
 },
 console,Date,Promise,setTimeout,clearTimeout
})
if(!definition)throw new Error('conversation page not registered')
const settle=()=>new Promise(resolve=>setImmediate(resolve))
function page(atBottom=true){
 let jumps=0,restores=0
 const p={data:{keyboardHeight:240,keyboardOpen:true},visible:true,life:1,atBottom,
  manager:{epoch:1,typing:()=>Promise.resolve()},setData(patch,done){Object.assign(this.data,patch);done?.()},
  closeMessageMenu(){},captureAnchor:async()=>({id:'anchor'}),current:()=>true,jumpBottom(){jumps++},restoreAnchor(){restores++}}
 p.keyboardChange=definition.keyboardChange.bind(p);p.inputBlur=definition.inputBlur.bind(p)
 return{p,jumps:()=>jumps,restores:()=>restores}
}
;(async()=>{
 const bottom=page(true);bottom.p.inputBlur();await settle()
 assert.equal(bottom.p.data.keyboardHeight,0);assert.equal(bottom.p.data.keyboardOpen,false);assert.equal(bottom.jumps(),1)
 const stale=page(false);stale.p.data.keyboardHeight=0;stale.p.data.keyboardOpen=true
 stale.p.keyboardChange({detail:{height:0}});await settle()
 assert.equal(stale.p.data.keyboardOpen,false);assert.equal(stale.restores(),1)
 const module={exports:{}}
 const actions=[]
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../miniprogram/subpackages/chat/references.js'),'utf8'),{
  module,require:()=>require('../miniprogram/utils/ChatOrderCard.js'),
  wx:{pageScrollTo:value=>actions.push(value),showModal:value=>actions.push(value)}
 })
 const {sceneReferences}=module.exports
 const rows=[{message_id:'20',reply:{message_id:'10',state:'published'}}]
 const q={serial:3,data:{conversation:'6',messages:rows,readCursor:'unchanged'},active:n=>n===3,
  async http(_serial,route,body){
   assert.equal(route,'messages/locate');assert.equal(body.message_id,'10')
   return {messages:[{message_id:'10',state:'published',message_type:'image_album'}]}
  }}
 const event={currentTarget:{dataset:{message:'20'}}}
 await sceneReferences.openQuote.call(q,event)
 assert.equal(actions.at(-1).content,'图片合集')
 rows.push({message_id:'10',state:'published'})
 await sceneReferences.openQuote.call(q,event)
 assert.equal(actions.at(-1).selector,'#chat-message-10')
 assert.equal(q.data.readCursor,'unchanged')
 console.log('Chat conversation keyboard and quote checks passed')
})().catch(error=>{console.error(error);process.exitCode=1})
