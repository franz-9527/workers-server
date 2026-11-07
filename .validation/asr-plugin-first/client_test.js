const assert=require('node:assert/strict')
const {ChatAV}=require('./candidate/ChatAV.js')
const originalSetTimeout=global.setTimeout
global.setTimeout=(fn)=>{fn();return 1}

let complete=false,statusCalls=0,actionCalls=0
const id='9'
const manager={
 epoch:1,focusEpoch:1,serial:0,
 io:{
  now:()=>1700000000000,random:()=>0.25,
  av:{
   info:async()=>({size:100,digest:'a'.repeat(32)}),
   save:async()=>{},remove:async()=>{},warn:()=>{},network:async()=>'wifi',unlink:async()=>{}
  },
  upload:async()=>{}
 },
 cache:new Map([[id,{canSend:true,capabilities:{audio:true,video:false},messages:new Map()}]]),
 live:()=>true,checkFocus:()=>{},emit:()=>{},
 action:async(_id,_name,req)=>{actionCalls++;return {client_message_id:req.client_message_id,state:'checking',phase:'uploading'}},
 http:async(name,args)=>{
  if(name==='av.upload')return {attachment_id:'11',object_version:'b'.repeat(64),part_count:1}
  if(name==='av.part'){
   if(args.confirm)return {state:'present'}
   return {state:'missing',url:'https://example.invalid',headers:{}}
  }
  if(name==='av.complete'){complete=true;return {ok:true}}
  if(name==='av.status'){
   statusCalls++
   if(!complete)return {client_message_id:args.client_message_id,state:'checking',phase:'uploading',part_count:1,uploaded_parts:[]}
   if(statusCalls<3)return {client_message_id:args.client_message_id,state:'checking',phase:'publish',part_count:1,uploaded_parts:[1]}
   return {client_message_id:args.client_message_id,state:'accepted',phase:'published',message_id:'55',part_count:1,uploaded_parts:[1]}
  }
  throw new Error('unexpected '+name)
 },
 schedulePoll:()=>{},refreshAccount:()=>{}
}

;(async()=>{
 const av=new ChatAV(manager)
 const client=av.stage(id,{path:'voice.mp3',size:100,duration:2,source:'voice',clientTranscript:'插件即时文字'})
 const result=await av.send(id,client,{})
 assert.equal(result.state,'accepted')
 assert.equal(actionCalls,1)
 assert.ok(statusCalls>=3)
 const row=av.rows.get(client)
 assert.equal(row.clientTranscript,'插件即时文字')
 assert.equal(row.state,'accepted')
 assert.equal(row.phase,'published')
 console.log('PLUGIN_FIRST_FAST_ACCEPT_OK')
})().finally(()=>{global.setTimeout=originalSetTimeout}).catch(e=>{console.error(e);process.exit(1)})
