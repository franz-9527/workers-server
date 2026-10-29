(() => {
 'use strict'
 const root=document.getElementById('chat-governance')
 if(!root)return
 const el=id=>document.getElementById('cg-'+id)
 const make=(tag,cls,text)=>{const node=document.createElement(tag);if(cls)node.className=cls;if(text!==undefined)node.textContent=text;return node}
 const labels={text:'文字',image:'图片',image_album:'图片',audio:'语音',video:'视频',review:'内容复核',report:'消息举报',appeal:'本人申诉',pending:'待处理',claimed:'已领取',confirm:'问题已确认',correct:'已纠正',dismiss:'已处理',block:'已屏蔽',expired:'保留期结束',message:'消息',risk:'风险记录',restriction:'禁聊',open:'持续未读',closed:'已结束',exhausted:'已达提醒预算',provider_unknown:'渠道结果待核对',channel_limited:'渠道受限'}
 const roles={worker:'师傅',assigned_worker:'师傅',merchant:'商家',merchant_owner:'商家',owner:'业主',owner_mobile_match:'业主',designer:'设计师',platform_staff:'平台工作人员',merchant_service:'商家客服',team_worker:'团队师傅',project_manager:'项目负责人',coordinator:'对接人',invite:'协作者',other:'成员'}
 let token='',expires=0,life=0,busy=false,active='',cursor='',snapshot='',filters={},cid='',history='',returnTarget='',firstActor='',page=null,caseAfter='0',currentCase=null,restriction=null,reminderAfter='0'
 let reviewAfter='0',reviewRows=[],reviewMore=false,currentReview=null,reviewed=new Set(),played=false,mediaEpoch=0
 const disposers=[]
 const status=text=>{el('status').textContent=text}
 const button=(text,fn,cls='admin-btn')=>{const b=make('button',cls,text);b.type='button';b.onclick=()=>run(fn);return b}
 const when=value=>{const d=new Date(value);return Number.isNaN(d.getTime())?'时间待核对':d.toLocaleString('zh-CN',{hour12:false})}
 const id=value=>/^[1-9][0-9]{0,19}$/.test(value)
 function releaseMedia(){mediaEpoch++;while(disposers.length){try{disposers.pop()()}catch(_){}}}
 function clearReview(){releaseMedia();currentReview=null;reviewed.clear();played=false;el('review-preview').replaceChildren();el('review-detail').hidden=true}
 function clearSensitive(){
  clearReview();page=null;history='';returnTarget='';currentCase=null;restriction=null
  for(const name of ['messages','evidence-text','evidence-items','evidence-preview'])el(name)?.replaceChildren()
  el('detail').hidden=true;el('empty').hidden=false
  if(el('case'))el('case').hidden=true
 }
 function invalidate(){
  life++;token='';expires=0;snapshot='';cursor='';clearSensitive()
  for(const name of ['list','review-list','case-list','reminder-list'])el(name)?.replaceChildren()
  status('管理员登录或权限已变化，请重新登录后查询')
 }
 async function credential(){
  if(token&&Date.now()<expires-15000)return token
  const response=await fetch(root.dataset.credential,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:'{}'})
  const body=await response.json()
  if(!response.ok||!body.data?.access_token){invalidate();throw new Error('管理员身份暂不可用，请重新登录或核对聊天开放状态')}
  token=body.data.access_token;expires=Date.parse(body.data.expires_at)
  return token
 }
 async function api(route,data={}){
  const generation=life,access=await credential()
  if(generation!==life)throw new Error('页面已关闭')
  const response=await fetch('/chat/api/v1/'+route,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json',Authorization:'Bearer '+access},body:JSON.stringify(data)})
  const body=await response.json()
  if(generation!==life)throw new Error('页面已关闭')
  if(!response.ok||body.data===undefined){
   if([401,403].includes(response.status))invalidate()
   const message=response.status===400?'查询条件不正确，请检查编号和日期范围':response.status===409?'记录或领取状态已变化，请刷新后重试':response.status===429?'操作较频繁，请稍后重试':'操作未完成，请核对权限和服务状态'
   throw new Error(message)
  }
  return body.data
 }
 async function run(fn){
  if(busy)return
  busy=true;root.setAttribute('aria-busy','true');const generation=life
  try{await fn()}catch(error){if(generation===life)status(error.message||'操作未完成')}finally{busy=false;root.removeAttribute('aria-busy')}
 }
 function safeURL(value){const u=new URL(value);if(u.protocol!=='https:'||u.username||u.password)throw new Error('媒体取件地址不可用');return u.href}
 function deadline(fn,ms){const timer=setTimeout(fn,Math.max(1,Math.min(ms,expires-Date.now())));disposers.push(()=>clearTimeout(timer))}
 async function picture(container,access,loaded){
  const epoch=mediaEpoch,url=safeURL(access.url)
  if(access.expires_at&&Number(access.expires_at)*1000<=Date.now())throw new Error('图片凭据已过期，请重新打开')
  const img=make('img','chat-audit-image');img.alt='聊天完整图片';img.referrerPolicy='no-referrer'
  img.onload=()=>{if(epoch===mediaEpoch&&img.isConnected)loaded?.()}
  img.onerror=()=>{if(epoch===mediaEpoch&&img.isConnected){container.replaceChildren(make('p','admin-help','图片加载失败，请重新打开'));status('图片加载失败，请重新打开')}}
  img.onclick=()=>img.classList.toggle('is-original')
  if(epoch!==mediaEpoch)return
  container.replaceChildren(img);img.src=url
  disposers.push(()=>{img.onload=null;img.onerror=null;img.removeAttribute('src');img.remove()})
  deadline(()=>{if(img.parentElement===container){img.removeAttribute('src');container.replaceChildren(make('p','admin-help','图片已过期，可重新打开'))}},access.expires_at?Math.min(50000,access.expires_at*1000-Date.now()):50000)
 }
 async function media(container,access,kind,ended){
  const epoch=mediaEpoch,controller=new AbortController()
  disposers.push(()=>controller.abort())
  const response=await fetch(safeURL(access.url),{cache:'no-store',credentials:'omit',signal:controller.signal})
  if(!response.ok||!response.body)throw new Error('媒体加载失败')
  const reader=response.body.getReader(),chunks=[];let size=0
  for(;;){const part=await reader.read();if(part.done)break;size+=part.value.byteLength;if(size>(kind==='audio'?1048576:20971520)){await reader.cancel();throw new Error('媒体超过大小限制')};chunks.push(part.value)}
  if(epoch!==mediaEpoch)return
  const url=URL.createObjectURL(new Blob(chunks,{type:kind==='audio'?'audio/mpeg':'video/mp4'})),player=make(kind==='audio'?'audio':'video','chat-audit-player')
  player.controls=true;player.preload='metadata';player.setAttribute('playsinline','');player.src=url
  player.onended=()=>{if(epoch===mediaEpoch&&player.isConnected)ended?.()}
  container.replaceChildren(player)
  const clear=()=>{player.onended=null;player.pause();player.removeAttribute('src');player.load();URL.revokeObjectURL(url);player.remove()}
  disposers.push(clear);deadline(clear,300000)
 }
 function showPanels(section){
  active=section
  root.querySelectorAll('[data-audit-panel]').forEach(node=>{node.hidden=node.dataset.auditPanel!==section})
  root.querySelectorAll('[data-audit-tab]').forEach(node=>{const selected=node.dataset.auditTab===section;node.classList.toggle('admin-btn--primary',selected);node.setAttribute('aria-pressed',String(selected))})
  const u=new URL(location.href);u.searchParams.set('section',section);window.history.replaceState(null,'',u)
 }
 async function switchTab(section){
  clearSensitive();showPanels(section)
  if(section==='records')await conversations(true)
  if(section==='pending')await reviews(true)
  if(section==='cases')await cases(true)
 }
 function readFilters(){
  const values=Object.fromEntries(new FormData(el('search'))),result={}
  for(const key of ['conversation_id','actor_id'])if(values[key].trim()){if(!id(values[key].trim()))throw new Error('请填写有效的数字编号');result[key]=values[key].trim()}
  if(values.order_no.trim())result.order_no=values.order_no.trim()
  if(values.query.trim())result.query=values.query.trim()
  if(values.kind)result.kind=values.kind
  if(values.from)result.from=new Date(values.from+'T00:00:00').toISOString()
  if(values.to){const d=new Date(values.to+'T00:00:00');d.setDate(d.getDate()+1);result.to=d.toISOString()}
  return result
 }
 async function conversations(reset){
  if(reset){filters=readFilters();cursor='';clearSensitive();status('正在查询聊天记录…')}
  const query={...filters,limit:20,...(cursor?{cursor}:snapshot?{replace_cursor:snapshot}:{})}
  const result=await api('audit/search',query)
  snapshot=result.snapshot_cursor||'';cursor=result.next_cursor||''
  el('more').disabled=!result.has_more
  const rows=(result.items||[]).map(row=>{
   const b=button('',()=>openConversation(row.conversation_id,row.match_message_id||''),'chat-audit-row')
   b.append(make('strong','','订单 '+(row.order_no||'#'+row.order_id)),make('span','','会话 '+row.conversation_id),make('small','',when(row.activity_at)))
   b.dataset.conversation=row.conversation_id;return b
  })
  el('list').replaceChildren(...rows)
  if(!rows.length)el('list').append(make('p','chat-audit-empty','没有符合条件的会话'))
  status(rows.length?'本页 '+rows.length+' 个会话，选择后查看完整上下文。':'没有符合条件的聊天记录')
 }
 function renderMessages(result){
  page=result;history=result.history_cursor||'';el('earlier').disabled=!history;el('return').disabled=!returnTarget
  el('detail').hidden=false;el('empty').hidden=true;el('title').textContent='会话 '+cid
  if(!firstActor&&result.messages?.length)firstActor=result.messages[0].sender_id
  el('messages').replaceChildren(...(result.messages||[]).map(message=>{
   const sender=message.sender||{},role=roles[sender.display_role]||roles[sender.role_source]||'用户'
   const row=make('article','chat-audit-message'+(message.sender_id!==firstActor?' is-right':''));row.dataset.message=message.message_id
   row.append(make('div','chat-audit-avatar',role.slice(0,2)))
   const main=make('div','chat-audit-message-main'),meta=make('p','chat-audit-sender',role+' '+message.sender_id+' · '+when(message.created_at)),bubble=make('div','chat-audit-bubble')
   row.append(main);main.append(meta,bubble)
   if(message.state!=='published'){bubble.append(make('span','chat-audit-muted',message.state==='recalled'?'消息已撤回':'内容不可查看'));return row}
   if(message.text)bubble.append(make('p','chat-audit-text',message.text))
   for(const ref of [message.reply,message.edited_from])if(ref){
    bubble.append(button(ref.text||'查看引用消息',async()=>{returnTarget=page.messages[0]?.message_id||'';releaseMedia();renderMessages(await api('audit/history',{conversation_id:cid,message_id:ref.message_id,limit:30}))},'chat-audit-reference'))
   }
   for(const item of message.images||[]){
    const cell=make('div','chat-audit-image-cell')
    const view=button('查看图片 '+(Number(item.ordinal)+1),async()=>{const access=await api('audit/url',{conversation_id:cid,message_id:message.message_id,item_id:item.item_id});await picture(cell,access)})
    cell.append(view);bubble.append(cell)
   }
   if(message.av){
    const holder=make('div','chat-audit-media-cell'),kind=message.message_type,seconds=Math.ceil((message.av.duration_ms||0)/1000)
    holder.append(button((kind==='audio'?'▶ 播放语音':'▶ 播放视频')+' · '+seconds+'秒',async()=>{await media(holder,await api('audit/av',{conversation_id:cid,message_id:message.message_id}),kind)}));bubble.append(holder)
    if(message.av.transcript)bubble.append(make('p','chat-audit-transcript','转写：'+message.av.transcript))
   }
   if(!bubble.childNodes.length)bubble.append(make('span','chat-audit-muted',labels[message.message_type]||'消息'))
   return row
  }))
  if(!result.messages?.length)el('messages').append(make('p','chat-audit-empty','本会话暂无可查看的消息'))
 }
 async function openConversation(value,target=''){
  releaseMedia();cid=value;firstActor='';returnTarget=''
  renderMessages(await api('audit/history',{conversation_id:cid,...(target?{message_id:target}:{}),limit:30}))
  el('list').querySelectorAll('[data-conversation]').forEach(node=>node.classList.toggle('is-selected',node.dataset.conversation===cid))
 }
 async function reviews(reset){
  clearReview();if(reset)reviewAfter='0';status('正在查询待审内容…')
  const kind=el('review-type').value,requests=[]
  if(root.dataset.images==='1'&&kind!=='av')requests.push(['image','media/review/list'])
  if(root.dataset.media==='1'&&kind!=='image')requests.push(['av','av/review/list'])
  const generation=life
  const results=await Promise.allSettled(requests.map(async([type,route])=>({type,rows:await api(route,{after:reviewAfter})})))
  if(generation!==life)return
  const items=[];let failed=false
  for(const result of results){if(result.status==='rejected'){failed=true;continue}for(const row of result.value.rows)items.push({...row,type:result.value.type,kind:result.value.type==='image'?'image':row.message_type})}
  items.sort((a,b)=>BigInt(a.intent_id)<BigInt(b.intent_id)?-1:BigInt(a.intent_id)>BigInt(b.intent_id)?1:0)
  reviewMore=!failed&&items.length>=20;reviewRows=items.slice(0,20);el('review-next').disabled=!reviewMore
  el('review-list').replaceChildren(...reviewRows.map(row=>{
   const node=button('',()=>claim(row),'chat-audit-row');node.append(make('strong','',(labels[row.kind]||'内容')+' #'+row.intent_id),make('span','','用户 '+row.sender_id+' · 会话 '+row.conversation_id),make('small','',row.type==='image'?row.items.length+' 张图片':Math.ceil(row.duration_ms/1000)+' 秒'));return node
  }))
  if(!reviewRows.length)el('review-list').append(make('p','chat-audit-empty',failed?'部分列表加载失败，请刷新':'当前页暂无待审内容'))
  status(failed?'部分待审列表未加载，已保留成功结果；请刷新后再翻页。':'按任务编号从早到晚集中展示，每页最多20项。')
 }
 async function claim(row){
  clearReview()
  const base=row.type==='image'?'media':'av'
  const claimed=await api(base+'/review/decide',{intent_id:row.intent_id,expected_version:row.expected_version,action:'claim',reason:'领取统一内容预审'})
  currentReview={...row,...claimed,kind:row.kind,type:row.type,items:row.items,conversation_id:row.conversation_id,sender_id:row.sender_id};const target=currentReview
  el('review-detail').hidden=false;el('review-title').textContent=(labels[row.kind]||'内容')+'审核 #'+row.intent_id
  el('review-meta').textContent='用户 '+row.sender_id+' · 尚未发布'
  el('review-transcript').textContent=row.transcript?'转写：'+row.transcript:''
  el('review-general').checked=false;el('review-group').checked=false;el('review-reason').value=''
  el('review-items').replaceChildren()
  if(row.type==='image'){
   row.items.forEach((item,index)=>el('review-items').append(button('图片 '+(index+1)+'/'+row.items.length,async()=>{
    const access=await api('media/review/url',{intent_id:target.intent_id,expected_version:target.expected_version,item_id:item.attachment_id})
    if(currentReview!==target)return
    await picture(el('review-preview'),access,()=>{if(currentReview===target){reviewed.add(item.attachment_id);status('已查看 '+reviewed.size+'/'+target.items.length+' 张图片')}})
   })))
  }else{
   el('review-items').append(button('加载完整'+labels[row.kind],async()=>{
    const access=await api('av/review/url',{intent_id:target.intent_id,expected_version:target.expected_version})
    if(currentReview!==target)return
    played=false
    await media(el('review-preview'),access,row.kind,()=>{if(currentReview===target){played=true;status('已播放结束，请确认检查项并填写审核说明')}})
   }))
  }
  deadline(()=>{if(currentReview===target){clearReview();status('领取已过期，请刷新后重新领取')}},290000)
  status('已领取，请检查完整内容后再通过；也可查看聊天上下文。')
 }
 async function decideReview(action){
  if(!currentReview)return
  const target=currentReview,reason=el('review-reason').value.trim(),general=el('review-general').checked,group=el('review-group').checked
  if([...reason].length<2)throw new Error('请填写至少2字审核说明')
  if(action==='approve'&&(!general||!group||(target.type==='image'?reviewed.size!==target.items.length:!played)))throw new Error(target.type==='image'?'请逐张打开全部完整图片并确认两项检查':'请完整播放音视频并确认两项检查')
  await api((target.type==='image'?'media':'av')+'/review/decide',{intent_id:target.intent_id,expected_version:target.expected_version,action,reason,general_safety_confirmed:general,group_confirmed:group})
  await reviews(false);status(action==='approve'?'已通过，系统将复验用户当前发送状态。':'已拒绝，本次内容不会发布。')
 }
 function reason(name){const value=el(name).value.trim();if([...value].length<2)throw new Error('请填写至少2字处理或查看依据');return value}
 async function cases(reset){
  releaseMedia();if(reset)caseAfter='0';currentCase=null;el('case').hidden=true
  const result=await api('governance/queue',{after:caseAfter});caseAfter=result.next_after;el('case-more').disabled=!result.has_more
  el('case-list').replaceChildren(...result.items.map(row=>button('',async()=>{
   releaseMedia();currentCase=row;el('case').hidden=false;el('case-title').textContent=(labels[row.kind]||'治理事项')+' #'+row.case_id
   el('case-summary').textContent=row.reason+' · '+(labels[row.target_type]||'内容')+' #'+row.target_id+' · '+(labels[row.state]||'待核对')
   el('case-reason').value='';el('evidence-text').textContent='';el('evidence-items').replaceChildren();el('evidence-preview').replaceChildren()
  },'chat-audit-row')).map((node,index)=>{const row=result.items[index];node.append(make('strong','',(labels[row.kind]||'治理事项')+' #'+row.case_id),make('span','',row.reason),make('small','',labels[row.state]||'待核对'));return node}))
  if(!result.items.length)el('case-list').append(make('p','chat-audit-empty','当前页没有待处理事项'))
  status('举报、内容复核和申诉集中处理；原始证据访问与处理会记录依据。')
 }
 async function evidence(item=''){
  if(!currentCase)return
  const target=currentCase,access=await api('governance/evidence',{case_id:target.case_id,expected_version:target.version,reason:reason('case-reason'),...(item?{item_id:item}:{})})
  if(currentCase!==target)return
  el('evidence-text').textContent=access.unavailable?'证据已过保存期或不可用':(access.text||'')+(access.context?'\n上下文：'+access.context:'')
  if(!item)el('evidence-items').replaceChildren(...(access.items||[]).map(v=>button('证据图片 '+(Number(v.ordinal)+1),()=>evidence(v.item_id))),...(access.av?[button('查看音视频证据',()=>evidence(access.av.item_id))]:[]))
  if(access.url){releaseMedia();if(access.av)await media(el('evidence-preview'),access,access.av.message_type);else await picture(el('evidence-preview'),access)}
 }
 async function decideCase(action){
  if(!currentCase)return
  const row=currentCase
  await api('governance/decide',{case_id:row.case_id,expected_version:row.version,action,reason:reason('case-reason')})
  await cases(true);status('处理已记录；不会补发消息或自动扣费。')
 }
 async function inspectRestriction(){
  const actor=el('actor').value.trim();if(!id(actor))throw new Error('请填写有效用户编号')
  restriction=await api('governance/status',{actor_id:actor})
  el('restriction-status').textContent=(restriction.blocked?(restriction.permanent?'永久禁聊':'禁聊至 '+restriction.blocked_until):'可正常发送')+' · 已确认风险 '+restriction.confirmed_risks
 }
 async function restrict(action){
  if(!restriction||restriction.actor_id!==el('actor').value.trim())throw new Error('请先查询该用户当前禁聊状态')
  await api('governance/restrict',{actor_id:restriction.actor_id,expected_version:restriction.version,action,reason:reason('reason'),...(action==='timed'?{seconds:Number(el('seconds').value)}:{})})
  await inspectRestriction();status('禁聊状态已更新')
 }
 async function reminders(reset){
  if(reset)reminderAfter='0'
  const rows=await api('governance/reminders',{after:reminderAfter});if(rows.length)reminderAfter=rows[rows.length-1].cluster_id
  el('reminder-more').disabled=rows.length<20
  const diagnostics={channel_budget:'渠道预算不足',php_receipt_unconfirmed:'回执待确认',template_unavailable:'模板不可用',subscription_unavailable:'订阅权限或额度不足',openid_missing:'微信身份不可用',provider_unknown:'渠道结果待核对',sent:'已发送',read_or_permission_changed:'已读或资格变化',subscription_changed:'订阅资格变化'}
  el('reminder-list').replaceChildren(...rows.map(row=>make('p','','会话 '+row.conversation_id+' · 用户 '+row.recipient_id+' · '+when(row.first_due_at)+' · 成功 '+row.successful_rounds+'轮 · '+(labels[row.state]||'待核对')+' · '+(diagnostics[row.diagnostic]||'待评估'))))
 }
 root.querySelectorAll('[data-audit-tab]').forEach(node=>node.onclick=()=>run(()=>switchTab(node.dataset.auditTab)))
 el('search').onsubmit=event=>{event.preventDefault();run(()=>conversations(true))}
 el('reset').onclick=()=>run(async()=>{el('search').reset();await conversations(true)})
 el('more').onclick=()=>run(()=>conversations(false))
 el('earlier').onclick=()=>run(async()=>{if(history){releaseMedia();renderMessages(await api('audit/history',{conversation_id:cid,cursor:history,limit:30}))}})
 el('return').onclick=()=>run(async()=>{const target=returnTarget;returnTarget='';releaseMedia();renderMessages(await api('audit/history',{conversation_id:cid,...(target?{message_id:target}:{}),limit:30}))})
 el('review-refresh').onclick=()=>run(()=>reviews(true));el('review-type').onchange=()=>run(()=>reviews(true))
 el('review-next').onclick=()=>run(async()=>{if(reviewRows.length){reviewAfter=reviewRows[reviewRows.length-1].intent_id;await reviews(false)}})
 el('review-approve').onclick=()=>run(()=>decideReview('approve'));el('review-reject').onclick=()=>run(()=>decideReview('reject'))
 el('review-context').onclick=()=>run(async()=>{if(!currentReview)return;const value=currentReview.conversation_id;clearSensitive();showPanels('records');await openConversation(value)})
 el('reminders').onclick=()=>run(()=>reminders(true));el('reminder-more').onclick=()=>run(()=>reminders(false))
 if(root.dataset.moderate==='1'){
  el('cases').onclick=()=>run(()=>cases(true));el('case-more').onclick=()=>run(()=>cases(false))
  for(const action of ['confirm','correct','dismiss','block'])el(action).onclick=()=>run(()=>decideCase(action))
  el('restriction').onclick=()=>run(inspectRestriction)
  for(const action of ['timed','permanent','unban'])el(action).onclick=()=>run(()=>restrict(action))
  if(el('evidence'))el('evidence').onclick=()=>run(()=>evidence())
 }
 addEventListener('pagehide',()=>{life++;token='';expires=0;clearSensitive();for(const name of ['list','review-list','case-list','reminder-list'])el(name)?.replaceChildren()})
 document.addEventListener('visibilitychange',()=>{if(document.hidden){life++;clearSensitive();token='';expires=0;status('已暂停查看，返回后可重新查询或刷新待审内容')}})
 const requested=new URL(location.href).searchParams.get('type')
 if(['image','av'].includes(requested)&&el('review-type').querySelector('option[value="'+requested+'"]'))el('review-type').value=requested
 const section=root.dataset.section
 run(()=>switchTab(root.querySelector('[data-audit-tab="'+section+'"]')?section:'records'))
})()
