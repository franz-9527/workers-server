'use strict'
const fs=require('fs'),path=require('path'),http=require('http'),assert=require('assert/strict')
const {chromium}=require('playwright')
const root=path.resolve(__dirname,'..')
if(path.basename(root)!=='workers-server')throw Error('Independent test repository required')
const members=[{actor_key:'admin:1',wire_id:'7000000000000000001',display_name:'管理员测试',display_role:'admin'},{actor_key:'user:11',wire_id:'11',display_name:'演示客户测试',display_role:'customer'}]
let records=[],operations=new Map(),uploads=new Map(),pendingImages=new Map(),uploadPosts=0,requests=[],call=null,revision=0,id=0,checks=0
const actor=key=>members.find(x=>x.actor_key===key)?.wire_id
const view=()=>call?{...call,revision:String(revision),attempt_epoch:'1',prepared_count:2,authorized_until:Math.floor(Date.now()/1000)+45,server_time:Math.floor(Date.now()/1000),policy_version:'1'}:null
const page=()=>({conversation_mode:'direct',order_context:{simulation:true,sandbox_room_id:'21',public_no:'',title:'【测试】完整会话',workflow:'construction'},capabilities:{cache:false,images:true,audio:true,video:true},member_role:'customer',viewer_actor_id:'11',can_send:true,policy_version:'1',messages:records,changes:[],snapshot_max_seq:String(records.length),snapshot_max_change_seq:String(records.length),sync_cursor:'fixture-sync',change_cursor:'fixture-change',history_cursor:'',has_more:false})
function reply(res,data,status=200){res.writeHead(status,{'Content-Type':'application/json'});res.end(JSON.stringify(data))}
const server=http.createServer(async(req,res)=>{
 const url=new URL(req.url,'http://fixture.invalid')
 if(url.pathname==='/admin/'&&req.method==='POST'){
  let raw='';for await(const b of req)raw+=b
  assert.match(raw,/name="_token"/);assert.match(raw,/name="_action"/)
  const key=raw.match(/name="sender"\r\n\r\n([^\r]+)/)?.[1]
  return reply(res,{ok:true,data:{access_token:'fixture:'+key,actor_id:actor(key),environment:'test',test_sandbox:true,moderation_ready:true,calls_enabled:true,room_id:'21',members,expires_at:new Date(Date.now()+300000).toISOString()}})
 }
 if(url.pathname.startsWith('/chat/api/v1/')){
  let raw='';for await(const b of req)raw+=b
  const q=raw?JSON.parse(raw):{},token=(req.headers.authorization||'').replace('Bearer fixture:',''),who=actor(token),route=url.pathname.slice('/chat/api/v1/'.length)
  requests.push({route,who,q})
  if(route==='auth/socket-ticket')return reply(res,{code:'CHAT_DEPENDENCY_UNAVAILABLE'},503)
  if(route==='conversations/digests')return reply(res,{data:{items:[{conversation_id:'321',unread:{...page(),viewer_actor_id:who,unread_count:0,last_message:records.at(-1)||null}}],has_more:false,badge_count:0,preference_revision:'1'}})
  if(route==='governance/status')return reply(res,{data:{can_send:true,restricted:false,restrictions:[]}})
  if(['conversations/bootstrap','messages/sync','messages/changes','messages/history'].includes(route))return reply(res,{data:{...page(),viewer_actor_id:who,...(route==='messages/changes'?{messages:[],changes:records.map(r=>({change_seq:r.seq,change_type:'message.changed',message:r}))}:{})}})
  if(route==='conversations/unread')return reply(res,{data:{...page(),viewer_actor_id:who,unread_count:0}})
  if(route==='conversations/readers')return reply(res,{data:{items:[]}})
  if(route==='conversations/preferences')return reply(res,{data:{member_version:'1',pinned:false,later:false,pinned_version:'0',later_version:'0',account_revision:'1'}})
  if(['media/pending','av/pending','calls/history'].includes(route))return reply(res,{data:[]})
  if(route==='calls/availability')return reply(res,{data:{enabled:true,audio:true,video:true}})
  if(route==='media/upload'){const key=String(700+uploads.size);uploads.set(key,q);return reply(res,{data:{attachment_id:key,object_version:'fixture-version',key:'fixture/'+key,host:'https://upload.fixture.invalid',token:'fixture-upload',state:'created'}})}
  if(route==='media/uploaded')return reply(res,{data:{state:'processed'}})
  if(route==='media/status'||route==='media/cancel'){
   const result=pendingImages.get(q.client_message_id)||operations.get(q.client_message_id)
   return reply(res,{data:result||{client_message_id:q.client_message_id,state:'checking'}})
  }
  if(route==='media/url')return reply(res,{data:{url:'https://private.fixture.invalid/image.jpg',expires_at:Math.floor(Date.now()/1000)+50}})
  if(route==='messages/send'&&['image','image_album'].includes(q.message_type)){
   const result={client_message_id:q.client_message_id,state:'review_pending',items:q.items};pendingImages.set(q.client_message_id,{...result,request:q,who});return reply(res,{data:result})
  }
  if(route==='messages/send'){
   if(!operations.has(q.client_message_id)){
    const row={message_id:String(++id),seq:String(id),sender_id:who,policy_version:'1',state:'published',state_version:'1',message_type:q.message_type,text:q.text||'',created_at:new Date().toISOString()}
    records.push(row);operations.set(q.client_message_id,row)
   }
   return reply(res,{data:{client_message_id:q.client_message_id,state:'accepted',message_id:operations.get(q.client_message_id).message_id}})
  }
  if(route==='messages/recall'){const row=records.find(x=>x.message_id===q.message_id);row.state='recalled';row.text='';row.state_version='2';return reply(res,{data:row})}
  if(route==='calls/create'){call={call_id:'7',conversation_id:'321',caller_id:who,callee_id:q.peer_id,provider:'shengwang',media:q.media,state:'ringing',created_at:Math.floor(Date.now()/1000)};revision=1;return reply(res,{data:view()})}
  if(route==='calls/inbox')return reply(res,{data:call&&!['ended','rejected'].includes(call.state)?[view()]:[]})
  if(route==='calls/status')return reply(res,{data:view()})
  if(route==='calls/accept'){call.state='preparing';revision++;return reply(res,{data:view()})}
  if(route==='calls/prepare')return reply(res,{data:{call:view(),credentials:{provider:'shengwang',app_id:'fixture-app',token:'fixture-rtc',channel:'sandbox-fixture',uid:who===call.caller_id?1:2,peer_uid:who===call.caller_id?2:1,expires_at:Math.floor(Date.now()/1000)+40}}})
  if(route==='calls/prepared'||route==='calls/ready'||route==='calls/pulse'){if(route==='calls/ready')call.state='active';revision++;return reply(res,{data:view()})}
  if(route==='calls/end'||route==='calls/reject'){call.state=route.endsWith('reject')?'rejected':'ended';revision++;return reply(res,{data:view()})}
  return reply(res,{code:'CHAT_CONTRACT_INVALID',message:'未定义fixture路由 '+route},400)
 }
 let file=url.pathname==='/admin/'?path.join(root,'test-artifacts/live-chat.html'):path.join(root,'server',url.pathname)
 if(!file.startsWith(root+path.sep)||!fs.existsSync(file)){res.writeHead(404);return res.end('missing')}
 res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':'text/html;charset=utf-8')
 res.end(fs.readFileSync(file))
})
async function main(){
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const origin='http://127.0.0.1:'+server.address().port
 const browser=await chromium.launch({headless:true})
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1050}})
  const errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('requestfailed',r=>console.error('REQUEST FAILED',r.url(),r.failure()?.errorText))
  page.on('response',async r=>{if(new URL(r.url()).pathname==='/admin/'&&r.request().method()==='POST'){try{const d=await r.json();console.log('CREDENTIAL FIXTURE',r.status(),Object.keys(d.data||{}),d.data?.environment,d.data?.expires_at)}catch(e){console.error('CREDENTIAL JSON',e.message)}}})
  await page.route('https://download.agora.io/**',route=>route.fulfill({contentType:'application/javascript',body:`window.fixtureRTC={joined:0,published:0,closed:0,left:0};window.AgoraRTC={createClient:()=>({on(){},removeAllListeners(){},setClientRole:async()=>{},join:async()=>{window.fixtureRTC.joined++},publish:async()=>{window.fixtureRTC.published++},subscribe:async()=>{},renewToken:async()=>{},leave:async()=>{window.fixtureRTC.left++}}),createMicrophoneAudioTrack:async()=>({play(){},stop(){},close(){window.fixtureRTC.closed++}}),createCameraVideoTrack:async()=>({play(){},stop(){},close(){window.fixtureRTC.closed++}})};`}))
  await page.route('https://upload.fixture.invalid',async route=>{if(route.request().method()==='POST')uploadPosts++;await route.fulfill({status:200,headers:{'Access-Control-Allow-Origin':'*'},body:'{}'})})
  await page.route('https://private.fixture.invalid/**',route=>route.fulfill({status:200,headers:{'Access-Control-Allow-Origin':'*'},contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0S8AAAAASUVORK5CYII=','base64')}))
  await page.goto(origin+'/admin/')
  const peers=page.locator('[data-live-peer]'),a=peers.nth(0),b=peers.nth(1)
  assert.equal(await peers.count(),2);assert.equal(await page.locator('[data-live-actions]:disabled').count(),2);checks++
  assert.equal(requests.length,0,'no real API/provider operation on page entry');checks++
  await a.locator('[data-live-connect]').click();await a.locator('[data-live-actions]').evaluate(el=>new Promise((resolve,reject)=>{let n=0;const t=setInterval(()=>{if(!el.disabled){clearInterval(t);resolve()}else if(++n>100){clearInterval(t);reject(Error('connect timeout'))}},50)}))
  await b.locator('[data-live-connect]').click();await page.waitForFunction(()=>document.querySelectorAll('[data-live-actions]:disabled').length===0)
  assert.equal(await a.locator('[data-call-peer]').inputValue(),'11');assert.equal(await b.locator('[data-call-peer]').inputValue(),'7000000000000000001');checks++
  await a.locator('textarea[name=text]').fill('跨端文字 <script>不可执行</script>')
  await a.locator('button[type=submit]').click()
  await b.getByText('跨端文字 <script>不可执行</script>',{exact:true}).waitFor()
  assert.equal(await page.locator('[data-live-log] script').count(),0);assert.equal(records.length,1);checks++
  await page.waitForTimeout(200)
  assert.equal(operations.size,1,'no duplicate send through socket fallback');checks++
  await a.locator('[data-live-images]').setInputFiles({name:'fixture.png',mimeType:'image/png',buffer:Buffer.from('fixture-image')})
  await a.getByRole('button',{name:'发送图片',exact:true}).waitFor()
  assert.equal(uploadPosts,1,'shared uploader posted actual browser multipart data');checks++
  await a.getByRole('button',{name:'发送图片',exact:true}).click()
  await a.getByText(/图片 · 待审核/).waitFor()
  assert.equal(records.length,1,'prereview is never rendered as a published message');checks++
  const [client,pending]=[...pendingImages][0]
  const row={message_id:String(++id),seq:String(id),sender_id:pending.who,policy_version:'1',state:'published',state_version:'1',message_type:'image',images:pending.request.items.map(x=>({item_id:x.attachment_id})),created_at:new Date().toISOString()}
  records.push(row);pendingImages.set(client,{client_message_id:client,state:'accepted',message_id:row.message_id})
  await b.locator('[data-live-log] img').waitFor({timeout:15000})
  await page.waitForFunction(()=>Array.from(document.querySelectorAll('[data-live-log] img')).every(x=>x.complete&&x.naturalWidth>0))
  assert.equal(await b.locator('[data-live-log] img').count(),1);checks++
  await a.locator('[data-call-video]').click()
  await b.locator('[data-call-accept]').waitFor({state:'visible',timeout:12000})
  assert.equal(await page.evaluate(()=>window.fixtureRTC.joined),0,'incoming ring never captures mic/camera before accept');checks++
  await b.locator('[data-call-accept]').click()
  await page.waitForFunction(()=>window.fixtureRTC.published===2,{timeout:18000})
  await page.waitForFunction(()=>Array.from(document.querySelectorAll('[data-call-status]')).every(el=>el.textContent==='通话中'),{timeout:18000})
  assert.equal(call.provider,'shengwang');assert.equal(call.media,'video');assert.ok(requests.some(x=>x.route==='calls/prepared'));assert.ok(requests.some(x=>x.route==='calls/ready'));checks++
  await a.locator('[data-call-end]').click()
  await page.waitForFunction(()=>window.fixtureRTC.closed>=4&&window.fixtureRTC.left>=2,{timeout:12000})
  assert.equal(call.state,'ended');checks++
  await a.locator('[data-live-audio]').setInputFiles({name:'wrong.wav',mimeType:'audio/wav',buffer:Buffer.from('not-mp3')})
  await a.getByText('语音文件请使用MP3，与小程序录音编码保持一致',{exact:true}).waitFor();checks++
  assert.equal(errors.length,0,errors.join('\n'));checks++
  await page.screenshot({path:'test-artifacts/live-chat-desktop.png',fullPage:true})
  await page.setViewportSize({width:390,height:844});await page.screenshot({path:'test-artifacts/live-chat-mobile.png',fullPage:true})
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'mobile layout overflows');checks++
  await page.close()
  console.log('PASS TOTAL '+checks+' browser real-state-machine scenarios')
 }catch(error){
  for(const p of browser.contexts().flatMap(c=>c.pages())){console.error('BROWSER DIAGNOSTICS',await p.locator('[data-live-notice]').allTextContents(),await p.locator('[data-live-peer]').evaluateAll(xs=>xs.map(x=>x.dataset.liveFailure)),await p.locator('[data-call-status]').allTextContents());await p.screenshot({path:'test-artifacts/live-chat-failure.png',fullPage:true})}
  console.error('RECENT API',requests.slice(-18));throw error
 }finally{await browser.close();server.close()}
}
main().catch(e=>{console.error(e);server.close();process.exit(1)})
