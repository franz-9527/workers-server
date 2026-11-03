'use strict'
const assert=require('assert/strict'),fs=require('fs'),vm=require('vm'),path=require('path')
if(path.basename(path.resolve(__dirname,'..'))!=='workers-server')throw Error('Independent repository required')
const {ChatAV}=require('../miniprogram/utils/ChatAV')
let checks=0;function ck(v,n){assert.ok(v,n);console.log('PASS '+n);checks++}
const ctx={module:{exports:{}},setTimeout,clearTimeout,console,require(name){if(name.includes('ChatManager'))return{ChatManager:class{constructor(io){this.io=io}subscribe(){}}};if(name.includes('ApiEnvironment'))return{capture:()=>({}),assertCurrent(){},onChange(){}};if(name.includes('ChatCache'))return{createWxCache:()=>null};if(name.includes('ChatAVFiles'))return{createWxAV:()=>null};throw Error(name)}}
ctx.wx={uploadFile(q){queueMicrotask(()=>q.fail({errMsg:'uploadFile:fail url not in domain list'}));return{}}}
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../miniprogram/utils/ChatRuntime.js'),'utf8'),ctx)
async function main(){
 const io=ctx.module.exports.get().io
 try{await io.upload({host:'https://up-z0.qiniup.com',token:'fixture',key:'fixture'},'/voice',()=>{},new Set());throw Error('unexpected upload')}catch(e){ck(e.code==='CHAT_UPLOAD_DOMAIN_NOT_ALLOWED','native WeChat domain rejection has an actionable typed error')}
 const routes=[],sent=[],model={canSend:true,capabilities:{audio:true}},status={client_message_id:'',state:'checking',phase:'uploading',part_count:1,uploaded_parts:[]}
 let failUpload=true
 const manager={epoch:1,serial:0,focusEpoch:1,cache:new Map([['12',model]]),disk:{scope:{environment:'test',actor:'11'}},live:()=>true,checkFocus(){},io:{now:()=>Date.now(),random:()=>.3,av:{info:async()=>({size:2000,digest:'a'.repeat(32)}),save:async()=>{},warn(){},network:async()=>'wifi',remove:async()=>{}},upload:async()=>{if(failUpload)throw Object.assign(new Error(),{code:'CHAT_UPLOAD_DOMAIN_NOT_ALLOWED'})}},emit(){},async http(route,q){routes.push(route);if(route==='av.upload')return{attachment_id:'4',object_version:'b'.repeat(64),part_count:1};if(route==='av.status')return{...status,client_message_id:q.client_message_id};if(route==='av.part')return{state:'missing',host:'fixture'};return{}},async action(id,type,q){sent.push(q);status.client_message_id=q.client_message_id;return{...status}},schedulePoll(){},refreshAccount(){}}
 const av=new ChatAV(manager);manager.av=av;const client=av.stage('12',{source:'voice',path:'/voice',size:2000,duration:2})
 try{await av.send('12',client)}catch(e){ck(e.code==='CHAT_UPLOAD_DOMAIN_NOT_ALLOWED','blocked upload preserves failure code on original transfer')}
 ck(routes.indexOf('av.status')>routes.indexOf('av.upload')&&sent.length===1,'new voice creates intent before status query, without expected 409 preflight')
 ck(av.list('12')[0].client_message_id===client&&av.list('12')[0].code==='CHAT_UPLOAD_DOMAIN_NOT_ALLOWED','failed voice remains recoverable with original client ID')
 failUpload=false;await av.retry('12',client)
 ck(sent.length===1&&routes.at(-1)==='av.complete','retry resumes same upload and never duplicates message send')
 console.log('PASS media transfer checks '+checks)
}
main().catch(e=>{console.error(e);process.exitCode=1})
