'use strict'
const fs=require('fs'),path=require('path'),assert=require('assert/strict'),{chromium}=require('playwright')
const {render}=require('./test_order_presentation')
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8')
if(path.basename(root)!=='workers-server')throw Error('Independent test repository required')
function css(file,seen=new Set()){if(seen.has(file))return'';seen.add(file);return read(file).replace(/@import\s+["']([^"']+)["'];/g,(_,p)=>css(path.posix.join(path.posix.dirname(file),p),seen)).replace(/(-?[\d.]+)rpx/g,(_,n)=>(Number(n)/7.5)+'vw')}
const base={enabled:true,status:'http',loading:false,canSend:true,audioEnabled:true,videoEnabled:true,isGroup:true,orderLabel:'【测试】客户与师傅完整会话',orderContext:{simulation:true,title:'完整会话'},messages:[{messageId:'1',state:'published',displayName:'演示客户甲',avatarPath:'',body:'您好，请确认上门施工时间。',isText:true},{messageId:'2',state:'published',displayName:'演示师傅甲',avatarPath:'',body:'明天下午两点可以上门。',isMine:true,isText:true}],receiptLabels:{'2':'1人已读 · 1人未读'},draft:'确认施工时间',keyboardHeight:240,keyboardOpen:true,attempts:[],albums:[],avAttempts:[],smsDialog:null,notice:'',inputError:'',showTools:false,simulation:false,voiceMode:false}
async function main(){let checks=0;const browser=await chromium.launch()
 try{for(const width of[320,375])for(const colorScheme of['light','dark'])for(const draft of['','确认施工时间']){
 const state={...base,draft},body=render(read('miniprogram/subpackages/chat/conversation/index.wxml'),state)
 const styles=css('miniprogram/app.wxss')+css('miniprogram/subpackages/chat/conversation/index.wxss')
 const context=await browser.newContext({viewport:{width,height:740},colorScheme}),page=await context.newPage()
 await page.route('**/*',r=>r.abort())
 await page.setContent('<!doctype html><meta charset="utf-8"><style>html,body,page{height:100%;margin:0}page,view,scroll-view{display:block}button{box-sizing:border-box;width:184px;min-width:92px}textarea{resize:none}'+styles+'</style><page>'+body+'</page>')
 assert.equal(await page.locator('.composer-send').count(),draft?1:0)
 assert.equal(await page.locator('.composer-more').count(),draft?0:1)
 const box=await page.evaluate(()=>{const r=s=>{const x=document.querySelector(s).getBoundingClientRect();return{top:x.top,bottom:x.bottom,left:x.left,width:x.width,height:x.height}};return{composer:r('.composer'),input:r('.composer-input'),action:r('.composer-send,.composer-more'),header:r('.chat-details'),title:r('.conversation-context-copy'),overflow:document.documentElement.scrollWidth>innerWidth+1}})
 assert.equal(box.overflow,false,'no horizontal overflow')
 assert.ok(box.composer.bottom<=740-239,'composer sits above keyboard, without native-navigation double height')
 assert.ok(box.action.height<=box.input.height+12,'send action stays compact and aligned')
 assert.ok(box.action.width<width*.22,'native button defaults cannot stretch the send control')
 assert.ok(box.input.width>width*.5,'typing area remains the largest part of the composer')
 assert.equal(await page.locator('.message-sender').count(),2,'both sides of group messages show personnel names')
 assert.ok(box.header.left<box.title.left,'details icon occupies leftmost toolbar position')
 assert.equal(await page.locator('.message-receipt').count(),1,'confirmed receipt appears below own bubble')
 assert.equal(await page.locator('.message-bubble .message-receipt').count(),0,'receipt does not occupy message content')
 await page.screenshot({path:'test-artifacts/chat-mini-'+width+'-'+colorScheme+'-'+(draft?'send':'plus')+'.png',fullPage:true})
 checks++;await context.close()
 }
 for(const width of[320,375])for(const colorScheme of['light','dark'])for(const mode of['tools','record','cancel','pending']){
  const state={...base,draft:'',keyboardHeight:0,keyboardOpen:false,showTools:mode==='tools',voiceMode:mode!=='tools',recording:['record','cancel'].includes(mode),recordCanceled:mode==='cancel',voiceCountdown:mode==='record'?9:60,avAttempts:mode==='pending'?[{client:'123',kind:'audio',submitted:true,state:'unknown',duration:2,displayName:'演示师傅甲',failed:true,code:'微信后台尚未允许七牛上传域名',label:'发送未完成',bubbleWidth:188}]:[]}
  const context=await browser.newContext({viewport:{width,height:740},colorScheme}),page=await context.newPage()
  await page.route('**/*',r=>r.abort())
  await page.setContent('<style>html,body,page{height:100%;margin:0}page,view,scroll-view{display:block}button{box-sizing:border-box;width:184px;min-width:92px}'+css('miniprogram/app.wxss')+css('miniprogram/subpackages/chat/conversation/index.wxss')+'</style><page>'+render(read('miniprogram/subpackages/chat/conversation/index.wxml'),state)+'</page>')
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'all tool/voice states fit the native viewport')
  if(mode==='tools'){
   const tools=await page.locator('.chat-tool').evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect();return{left:r.left,right:r.right,top:r.top,width:r.width}}))
   assert.equal(tools.length,6);assert.ok(Math.abs(tools[0].top-tools[3].top)<2);assert.ok(tools[4].top>tools[0].top)
   assert.ok(tools.every(r=>r.left>=0&&r.right<=width+1));assert.ok(await page.locator('.chat-icon-order').evaluate(n=>getComputedStyle(n).backgroundImage!=='none'))
  }
  if(mode==='record'||mode==='cancel'){assert.equal(await page.locator('.voice-record-panel').count(),1);assert.ok((await page.locator('.voice-record-instruction').textContent()).includes(mode==='cancel'?'松开手指':'手指上滑'))}
  if(mode==='pending'){assert.equal(await page.locator('.media-retry').count(),1);assert.equal(await page.locator('.av-attempt').count(),0)}
  await page.screenshot({path:'test-artifacts/chat-native-'+width+'-'+colorScheme+'-'+mode+'.png',fullPage:true});checks++;await context.close()
 }
 console.log('PASS mini-program chat layout scenarios '+checks)
 }finally{await browser.close()}
}
main().catch(e=>{console.error(e);process.exitCode=1})
