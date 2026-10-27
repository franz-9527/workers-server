(() => {
 'use strict'
 const root=document.getElementById('chat-governance');if(!root)return
 const el=id=>document.getElementById('cg-'+id)
 let token='',until=0,life=0,busy=false,cursor='',cid='',history='',returnPage=null,caseAfter='0',currentCase=null,restriction=null,photoTimer,reminderAfter='0'
 const kindLabel={review:'内容复核',report:'消息举报',appeal:'本人申诉'},stateLabel={pending:'等待处理',confirm:'问题已确认',correct:'已纠正',dismiss:'已处理／未支持',block:'已屏蔽',expired:'保留期结束',open:'持续未读',closed:'此轮已结束',exhausted:'成功提醒已达预算',provider_unknown:'渠道结果待核对',channel_limited:'渠道受限'},diagnosticLabel={channel_budget:'聊天渠道预算不足',php_receipt_unconfirmed:'内部回执待确认',template_unavailable:'订阅模板不可用',subscription_unavailable:'订阅额度或权限不可用',openid_missing:'微信身份不可用',recipient_id_unsupported:'用户编号超出当前渠道范围',template_data_invalid:'模板字段不可用',template_data_missing:'模板字段缺失',provider_unknown:'供应商结果待核对',sent:'已发送',read_or_permission_changed:'已读或资格已变化',subscription_changed:'订阅资格已变化'}
 let mediaBlob='',mediaGeneration=0,mediaAbort=null,mediaTimer=null
 const status=text=>{el('status').textContent=text}
 const button=(label,fn)=>{const b=document.createElement('button');b.type='button';b.className='admin-btn';b.textContent=label;b.onclick=()=>run(fn);return b}
 function clearPhoto(){mediaGeneration++;clearTimeout(mediaTimer);mediaAbort?.abort();mediaAbort=null;const player=el('media');if(player){player.pause?.();player.removeAttribute('src');player.load?.();player.hidden=true};if(mediaBlob)URL.revokeObjectURL(mediaBlob);mediaBlob='';clearTimeout(photoTimer);el('photo').hidden=true;el('photo').removeAttribute('src')}
 function clearSensitive(){clearPhoto();el('messages').replaceChildren();el('detail').hidden=true;if(el('case'))el('case').hidden=true;if(el('evidence-text'))el('evidence-text').textContent='';if(el('evidence-items'))el('evidence-items').replaceChildren();currentCase=null;returnPage=null;restriction=null}
 async function credential(){
  const generation=life
  if(token&&Date.now()<until)return token
  const response=await fetch(root.dataset.credential,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:'{}'})
  const result=await response.json();if(generation!==life)throw new Error('页面已切换');if(!response.ok||!result.data?.access_token)throw new Error('凭证或权限不可用，请刷新')
  token=result.data.access_token;until=Date.parse(result.data.expires_at)-15000;return token
 }
 async function api(route,data={}){
  const generation=life,access=await credential()
  if(generation!==life)throw new Error('页面已切换')
  const response=await fetch('/chat/api/v1/'+route,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json',Authorization:'Bearer '+access},body:JSON.stringify(data)})
  const result=await response.json();if(generation!==life)throw new Error('页面已切换')
  if(!response.ok||result.data===undefined){if([401,403].includes(response.status)){token='';clearSensitive();el('list').replaceChildren()}throw new Error(response.status===409?'版本、权限或快照已变化，请刷新后重试':'操作未完成，请核对权限和开放状态')}
  return result.data
 }
 async function run(fn){if(busy)return;busy=true;try{await fn()}catch(error){status(error.message)}finally{busy=false}}
 async function conversations(reset){
  if(reset){cursor='';clearSensitive()}
  const page=await api('audit/list',cursor?{cursor,limit:20}:{limit:20});cursor=page.next_cursor||''
  el('more').disabled=!page.has_more;el('list').replaceChildren(...page.items.map(row=>button('订单 #'+row.order_id+' · 会话 '+row.conversation_id+' · '+new Date(row.activity_at).toLocaleString(),()=>open(row.conversation_id))))
  status(page.items.length?'固定活动快照；只读抽检不改变任何用户的已读状态。':'暂无订单会话')
 }
 function renderMessages(page){
  el('detail').hidden=false;el('title').textContent='会话 '+cid+' · 只读抽检'
  history=page.history_cursor||'';el('earlier').disabled=!history
  el('messages').replaceChildren(...page.messages.map(message=>{
   const box=document.createElement('div');box.className='admin-card__body'
   const text=document.createElement('p');text.textContent=message.sender_id+' · '+new Date(message.created_at).toLocaleString()+' · '+(message.state==='published'?(message.text||(message.av?(message.message_type==='audio'?'[语音]':'[视频]'):'[图片 '+(message.images||[]).length+' 张]')):message.state==='recalled'?'[已撤回]':'[不可查看]');box.append(text)
   for(const ref of [message.reply,message.edited_from])if(ref){box.append(button(ref.text||'[引用不可查看]',async()=>{returnPage=page;el('return').disabled=false;const located=await api('audit/history',{conversation_id:cid,message_id:ref.message_id,limit:30});clearPhoto();renderMessages(located)}))}
   if(message.state==='published'&&message.av)box.append(button('按需播放'+(message.message_type==='audio'?'语音':'视频'),async()=>{const data=await api('audit/av',{conversation_id:cid,message_id:message.message_id});await media(data.url,message.message_type,data)}))
   for(const item of message.images||[]){box.append(button('打开第'+(item.ordinal+1)+'张',async()=>{const data=await api('audit/url',{conversation_id:cid,message_id:message.message_id,item_id:item.item_id});photo(data.url)}))}
   return box
  }))
 }
 async function open(id){cid=id;returnPage=null;el('return').disabled=true;clearPhoto();renderMessages(await api('audit/history',{conversation_id:cid,limit:30}))}
 function photo(url){clearPhoto();const parsed=new URL(url);if(parsed.protocol!=='https:')throw new Error('图片取件地址不可用');el('photo').src=url;el('photo').hidden=false;photoTimer=setTimeout(clearPhoto,50000)}
 async function media(url,kind,access={}){
  clearPhoto();const serial=mediaGeneration,generation=life,parsed=new URL(url);if(parsed.protocol!=='https:')throw new Error('媒体取件地址不可用')
  mediaAbort=new AbortController();const response=await fetch(url,{cache:'no-store',credentials:'omit',signal:mediaAbort.signal});if(!response.ok||!response.body)throw new Error('媒体加载失败')
  const reader=response.body.getReader(),chunks=[];let size=0
  for(;;){const item=await reader.read();if(item.done)break;size+=item.value.byteLength;if(size>(kind==='audio'?1048576:20971520)){await reader.cancel();throw new Error('媒体超过大小限制')};chunks.push(item.value)}
  if(serial!==mediaGeneration||generation!==life)return
  const player=el('media');if(!player)throw new Error('媒体查看器不可用')
  mediaBlob=URL.createObjectURL(new Blob(chunks,{type:kind==='audio'?'audio/mpeg':'video/mp4'}));player.src=mediaBlob;player.hidden=false
  let ttl=300000;
  mediaTimer=setTimeout(clearPhoto,ttl)
 }
 function reason(){const text=el('reason').value.trim();if([...text].length<2)throw new Error('请先填写至少2字的理由');return text}
 async function cases(reset){
  clearSensitive();if(reset)caseAfter='0'
  const page=await api('governance/queue',{after:caseAfter});caseAfter=page.next_after;el('case-more').disabled=!page.has_more
  el('list').replaceChildren(...page.items.map(row=>button('#'+row.case_id+' · '+(kindLabel[row.kind]||'治理事项')+' · '+(stateLabel[row.state]||'状态待核对')+' · 用户 '+row.subject_id,()=>{currentCase=row;el('case').hidden=false;el('case-title').textContent='案件 #'+row.case_id;el('case-summary').textContent=row.reason+' · '+row.target_type+' #'+row.target_id+' · '+(row.resolution||'待处理');el('evidence-text').textContent='';el('evidence-items').replaceChildren()})))
  status('案件每页20项；查看原始证据需要独立权限和理由。')
 }
 async function evidence(item){
  if(!currentCase)return
  const row=currentCase,data=await api('governance/evidence',{case_id:row.case_id,expected_version:row.version,reason:reason(),...(item?{item_id:item}:{})})
  if(currentCase!==row)return
  el('evidence-text').textContent=data.unavailable?'证据已过保留期或不可用':(data.text||'')+(data.context?'\n上下文：'+data.context:'')
  if(!item)el('evidence-items').replaceChildren(...data.items.map(v=>button('按理由查看证据第'+(v.ordinal+1)+'张',()=>evidence(v.item_id))),...(data.av?[button('按理由打开完整音视频证据',()=>evidence(data.av.item_id))]:[]))
  if(data.url){if(data.av)await media(data.url,data.av.message_type);else photo(data.url)}
 }
 async function decide(action){
  if(!currentCase)return;const row=currentCase
  await api('governance/decide',{case_id:row.case_id,expected_version:row.version,action,reason:reason()});clearSensitive();status('处理已记录；没有补发消息或财务处罚。请刷新案件状态。')
 }
 async function inspectRestriction(){restriction=null;const actor=el('actor').value.trim();if(!/^[1-9][0-9]{0,19}$/.test(actor))throw new Error('请填写用户编号');restriction=await api('governance/status',{actor_id:actor});el('restriction-status').textContent=(restriction.blocked?(restriction.permanent?'永久禁聊':'禁聊至 '+restriction.blocked_until):'可正常发送')+' · 版本 '+restriction.version+' · 已确认风险 '+restriction.confirmed_risks}
 async function restrict(action){if(!restriction||restriction.actor_id!==el('actor').value.trim())throw new Error('请先刷新此用户禁聊状态');await api('governance/restrict',{actor_id:restriction.actor_id,expected_version:restriction.version,action,reason:reason(),...(action==='timed'?{seconds:Number(el('seconds').value)}:{})});await inspectRestriction();status('禁聊状态已更新，合法历史、撤回及订单业务继续可用。')}
 async function reminderSummary(reset){
  clearSensitive();if(reset)reminderAfter='0'
  const rows=await api('governance/reminders',{after:reminderAfter})
  if(rows.length)reminderAfter=rows[rows.length-1].cluster_id
  el('reminder-more').disabled=rows.length<20
  el('list').replaceChildren(...rows.map(row=>{const line=document.createElement('p');line.textContent='会话 '+row.conversation_id+' · 用户 '+row.recipient_id+' · 首次到期 '+new Date(row.first_due_at).toLocaleString()+' · 成功 '+row.successful_rounds+'轮 · '+(stateLabel[row.state]||'状态待核对')+' · '+(diagnosticLabel[row.diagnostic]||'待评估');return line}))
  status('聚合提醒记录，每页20项；当前可读资格和真实未读在外发前重新核验。')
 }
 el('reminders').onclick=()=>run(()=>reminderSummary(true));el('reminder-more').onclick=()=>run(()=>reminderSummary(false))
 el('refresh').onclick=()=>run(()=>conversations(true));el('more').onclick=()=>run(()=>conversations(false))
 el('earlier').onclick=()=>run(async()=>{if(history){clearPhoto();renderMessages(await api('audit/history',{conversation_id:cid,cursor:history,limit:30}))}})
 el('return').onclick=()=>run(async()=>{if(returnPage){const target=returnPage.messages[0];returnPage=null;el('return').disabled=true;clearPhoto();if(target)renderMessages(await api('audit/history',{conversation_id:cid,message_id:target.message_id,limit:30}))}})
 if(root.dataset.moderate==='1'){
  el('cases').onclick=()=>run(()=>cases(true));el('case-more').onclick=()=>run(()=>cases(false))
  el('restriction').onclick=()=>run(inspectRestriction)
  for(const action of ['timed','permanent','unban'])el(action).onclick=()=>run(()=>restrict(action))
  for(const action of ['confirm','correct','dismiss','block'])el(action).onclick=()=>run(()=>decide(action))
  if(el('evidence'))el('evidence').onclick=()=>run(()=>evidence(''))
 }
 addEventListener('pagehide',()=>{life++;token='';until=0;clearSensitive();el('list').replaceChildren()})
 run(()=>conversations(true))
})()
