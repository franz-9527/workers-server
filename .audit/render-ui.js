const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('assert/strict')
const {parseDocument}=require('htmlparser2'),{chromium}=require('playwright-core')
const root=path.join(__dirname,'src'),out=path.join(__dirname,'previews');fs.mkdirSync(out,{recursive:true})
const read=p=>fs.readFileSync(path.join(root,p),'utf8')
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))
function expr(s,data){try{return vm.runInNewContext(s,data,{timeout:30})}catch{return ''}}
function interpolate(s,data){return String(s||'').replace(/\{\{([\s\S]*?)\}\}/g,(_,x)=>String(expr(x,data)??''))}
function initial(file){let def;vm.runInNewContext(read(file),{Page:p=>def=p,require:()=>({}),console,setTimeout,clearTimeout});return def.data}
function children(nodes,data){
 let result='',chain=false,matched=false
 for(const n of nodes||[]){
  if(n.type==='text'){result+=esc(interpolate(n.data,data));continue}
  if(n.type!=='tag')continue
  const a=n.attribs||{}
  if('wx:if'in a){chain=true;matched=!!expr(a['wx:if'].slice(2,-2),data);if(!matched)continue}
  else if('wx:elif'in a){if(!chain||matched)continue;matched=!!expr(a['wx:elif'].slice(2,-2),data);if(!matched)continue}
  else if('wx:else'in a){if(!chain||matched)continue;matched=true}
  else{chain=false;matched=false}
  result+=node(n,data)
 }
 return result
}
function node(n,data){
 const a=n.attribs||{}
 if(a['wx:for']){
  const rows=expr(a['wx:for'].slice(2,-2),data)||[]
  const copy={...n,attribs:{...a}};delete copy.attribs['wx:for']
  return rows.map((item,index)=>node(copy,{...data,[a['wx:for-item']||'item']:item,[a['wx:for-index']||'index']:index})).join('')
 }
 if(n.name==='block')return children(n.children,data)
 if(n.name==='subscription-health-banner')return ''
 const tag={view:'div',text:'span','scroll-view':'div',image:'img','live-player':'div','live-pusher':'div'}[n.name]||n.name
 let attrs=''
 for(const [key,value]of Object.entries(a)){
  if(key.startsWith('wx:')||key.startsWith('bind')||key.startsWith('catch')||['mode','scroll-y','auto-height','adjust-position'].includes(key))continue
  if(key==='value'&&tag==='textarea')continue
  let v=interpolate(value,data)
  if(['disabled','autoplay'].includes(key)){if(v==='false'||v==='')continue;attrs+=' '+key;continue}
  if(key==='src'&&v.startsWith('/images/'))v='file://'+path.join(root,'miniprogram',v)
  attrs+=' '+key+'="'+esc(v)+'"'
 }
 if(tag==='img')return '<img'+attrs+'>'
 return '<'+tag+attrs+'>'+(tag==='textarea'?esc(interpolate(a.value||'',data)):children(n.children,data))+'</'+tag+'>'
}
function css(file,width,seen=new Set()){
 if(seen.has(file))return '';seen.add(file)
 return read(file).replace(/@import\s+["']([^"']+)["'];?/g,(_,p)=>css(path.posix.normalize(path.posix.join(path.posix.dirname(file),p)),width,seen)).replace(/([\d.]+)rpx/g,(_,n)=>(Number(n)*width/750)+'px').replace(/(^|})\s*page\s*\{/g,'$1 body{')
}
const stamp='2026-10-27T06:30:00Z'
function fixture(name,tools=false){
 const p='miniprogram/'+(name==='inbox'?'pages/chatMessages':name==='conversation'?'subpackages/chat/conversation':'subpackages/chat/call')+'/index'
 const data={...initial(p+'.js')}
 if(name==='inbox')Object.assign(data,{checkingLogin:false,isLoggedIn:true,summaryReady:true,chatEnabled:true,chatStatus:'http',visibleChatRows:[
  {conversationId:'1',kind:'chat',displayTitle:'李师傅',subtitle:'施工订单 BX-1027',lastText:'明天上午九点到，材料我会提前核对。',avatarPath:'/images/chat-worker.svg',unreadCount:2,unreadLabel:'2',activityLabel:'14:30',preferences:{pinned:true}},
  {conversationId:'2',kind:'chat',displayTitle:'订单协作群',subtitle:'客厅墙布施工',lastText:'业主：墙面已经清理好了。',groupAvatars:['/images/chat-user.svg','/images/chat-worker.svg','/images/chat-merchant.svg'],unreadCount:5,unreadLabel:'5',activityLabel:'14:18',preferences:{}},
  {conversationId:'notifications',kind:'notification',title:'普通通知',lastText:'您的订单预约已确认',unreadCount:1,unreadLabel:'1',activityLabel:'12:08',preferences:{}},
  {conversationId:'3',kind:'chat',displayTitle:'商家138****8000',subtitle:'测量订单 BX-1026',lastText:'尺寸收到，稍后发您确认。',avatarPath:'/images/chat-merchant.svg',activityLabel:'昨天',preferences:{later:true}}
 ]})
 if(name==='conversation')Object.assign(data,{enabled:true,status:'http',canSend:true,audioEnabled:true,videoEnabled:true,callAudio:true,callVideo:true,showTools:tools,orderLabel:'客厅墙布施工',orderContext:{publicNo:'BX-1027'},messages:[
  {messageId:'1',displayName:'李师傅',avatarPath:'/images/chat-worker.svg',isText:true,body:'您好，明天上午九点可以上门施工。',createdAt:stamp,timeDivider:'14:30'},
  {messageId:'2',displayName:'用户ABC123',avatarPath:'/images/chat-user.svg',isMine:true,isText:true,body:'好的，墙面已经清理好了。',createdAt:stamp},
  {messageId:'3',displayName:'李师傅',avatarPath:'/images/chat-worker.svg',isAV:true,isAudio:true,duration:12,heard:false,transcript:'我会提前联系您，进场后先核对材料和施工范围。',createdAt:stamp},
  {messageId:'4',displayName:'用户ABC123',avatarPath:'/images/chat-user.svg',isMine:true,isText:true,body:'收到，辛苦了。',createdAt:stamp}
 ]})
 if(name==='call')Object.assign(data,{peerName:'李师傅',peerAvatar:'/images/chat-worker.svg',call:{state:'active'},status:'通话中',canHangup:true,isNative:false})
 return {p,data}
}
async function render(browser,name,theme,width=375,large=false,tools=false){
 const {p,data}=fixture(name,tools),page=await browser.newPage({viewport:{width,height:812},deviceScaleFactor:1,colorScheme:theme})
 await page.route('**/*',r=>/^(file:|data:|about:)/.test(r.request().url())?r.continue():r.abort())
 const source=read(p+'.wxml'),html=children(parseDocument(source,{xmlMode:true,decodeEntities:true}).children,data)
 await page.setContent('<html><head><meta charset="utf-8"><style>html,body{margin:0;width:100%;font-family:"Noto Sans CJK SC",sans-serif}*{box-sizing:border-box}button,textarea{font-family:inherit}button{border:0;cursor:pointer}img{object-fit:cover}textarea{resize:none}'+css(p+'.wxss',width)+'</style></head><body>'+html+'</body></html>')
 if(large)await page.evaluate(()=>{const nodes=[...document.querySelectorAll('*')];const sizes=nodes.map(n=>parseFloat(getComputedStyle(n).fontSize));nodes.forEach((n,i)=>n.style.fontSize=(sizes[i]*1.35)+'px')})
 await page.evaluate(()=>document.fonts.ready)
 const measure=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,buttons:[...document.querySelectorAll('button')].filter(b=>b.getBoundingClientRect().width>0).map(b=>({label:b.getAttribute('aria-label')||b.textContent.trim(),width:b.getBoundingClientRect().width,height:b.getBoundingClientRect().height})),composer:document.querySelector('.composer')?.getBoundingClientRect().bottom}))
 assert(measure.scroll<=width+1,'horizontal overflow: '+name+' '+theme+' '+width+' '+large+' '+JSON.stringify(measure))
 if(name==='conversation')assert(measure.composer<=813,'composer clipped below viewport')
 if(!large&&width===375&&!tools)await page.screenshot({path:path.join(out,name+'-'+theme+'.png')})
 console.log('VISUAL_LAYOUT',name,theme,width,large?'large':'normal',tools?'tools-open':'default',JSON.stringify(measure))
 await page.close()
}
;(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_BIN,args:['--no-sandbox','--disable-dev-shm-usage']})
 for(const theme of ['light','dark'])for(const name of ['inbox','conversation','call']){await render(browser,name,theme);await render(browser,name,theme,320,true);if(name==='conversation')await render(browser,name,theme,375,false,true)}
 await browser.close()
 for(const name of ['inbox','conversation','call'])for(const theme of ['light','dark'])console.log('UI_IMAGE',name,theme,fs.readFileSync(path.join(out,name+'-'+theme+'.png')).toString('base64'))
})().catch(e=>{console.error(e);process.exitCode=1})
