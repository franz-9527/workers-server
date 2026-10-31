(() => {
 'use strict'
 const root=document.getElementById('chat-image-review');if(!root)return
 const get=id=>document.getElementById('image-review-'+id)
 let token='',tokenUntil=0,after='0',rows=[],current=null,viewed=new Set(),busy=false,photoTimer=null,photoGeneration=0
 const status=text=>{get('status').textContent=text}
 function clearPhoto(){photoGeneration++;clearTimeout(photoTimer);get('photo').removeAttribute('src');get('photo').hidden=true;get('photo-status').textContent=''}
 function clearDetail(){clearPhoto();current=null;viewed.clear();get('detail').hidden=true}
 async function credential(){
  if(token&&Date.now()<tokenUntil)return token
  const res=await fetch(root.dataset.credential,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:'{}',cache:'no-store'})
  const body=await res.json();if(!res.ok||!body.data?.access_token)throw new Error('聊天凭证暂不可用，请核对权限和开放状态')
  token=body.data.access_token;tokenUntil=Date.parse(body.data.expires_at)-15000;return token
 }
 async function api(action,payload){
  const access=await credential()
  const res=await fetch('/chat/api/v1/media/'+action,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json',Authorization:'Bearer '+access},body:JSON.stringify(payload)})
  const body=await res.json()
  if(!res.ok||body.data===undefined){if(res.status===401||res.status===403){token='';clearDetail()}throw new Error(body.code==='CHAT_MEDIA_VERSION_CONFLICT'?'任务已变化、领取过期或已由其他人处理，请刷新':'操作未完成，请刷新后重试')}
  return body.data
 }
 function button(label,callback){const b=document.createElement('button');b.type='button';b.className='admin-btn';b.textContent=label;b.onclick=callback;return b}
 function render(){
  const list=get('list');list.replaceChildren()
  rows.forEach(row=>{
   const line=document.createElement('div');line.className='admin-card__body'
   const text=document.createElement('p');text.textContent='待审 #'+row.intent_id+' · '+row.items.length+'张 · 提交于 '+new Date(row.submitted_at).toLocaleString()
   line.append(text,button('领取并逐图检查',()=>claim(row)));list.append(line)
  })
  get('next').disabled=rows.length<20;status(rows.length?'按最早等待顺序展示，打开原图会记录访问审计。':'当前页没有待审图片。')
 }
 async function load(reset){
  if(busy)return;busy=true;clearDetail();if(reset)after='0'
  try{rows=await api('review/list',{after});render()}catch(error){status(error.message)}finally{busy=false}
 }
 async function claim(row){
  if(busy)return;busy=true;clearDetail()
  try{
   current=await api('review/decide',{intent_id:row.intent_id,expected_version:row.expected_version,action:'claim',reason:'领取逐图及整组发布前检查'})
   current.items=row.items;viewed=new Set();get('detail').hidden=false;get('title').textContent='检查图片 #'+row.intent_id
   get('general').checked=false;get('group').checked=false;get('reason').value=''
   get('items').replaceChildren(...row.items.map((item,index)=>button('打开第'+(index+1)+'/'+row.items.length+'张完整图片',()=>photo(item,index))))
   status('已领取。请逐张打开图片并完成两项检查。')
  }catch(error){clearDetail();status(error.message)}finally{busy=false}
 }
 async function photo(item,index){
  if(!current||busy)return
  const target=current;clearPhoto();const generation=photoGeneration;get('photo-status').textContent='正在读取第'+(index+1)+'张完整图片…'
  try{
   const result=await api('review/url',{intent_id:target.intent_id,expected_version:target.expected_version,item_id:item.attachment_id})
   if(current!==target||generation!==photoGeneration)return
   const url=new URL(result.url);if(url.protocol!=='https:'||Number(result.expires_at)*1000<=Date.now())throw new Error('取件凭证已过期')
   get('photo').onload=()=>{if(current!==target||generation!==photoGeneration)return;viewed.add(item.attachment_id);get('photo-status').textContent='第'+(index+1)+'张完整图片 · 已打开'+viewed.size+'/'+target.items.length+'张（点击图片切换原尺寸）'}
   get('photo').onerror=()=>{if(generation===photoGeneration)get('photo-status').textContent='图片加载失败，请重新打开'}
   get('photo').hidden=false;get('photo').src=result.url;get('photo').style.maxWidth='100%'
   photoTimer=setTimeout(()=>{if(generation===photoGeneration){clearPhoto();get('photo-status').textContent='取件凭证已过期，可重新打开。'}},Math.min(50000,Number(result.expires_at)*1000-Date.now()))
  }catch(error){get('photo-status').textContent=error.message}
 }
 async function decide(action){
  if(!current||busy)return
  const reason=get('reason').value.trim(),general=get('general').checked,group=get('group').checked
  if(reason.length<2){status('请填写至少2字的审核理由。');return}
  if(action==='approve'&&(!general||!group||viewed.size!==current.items.length)){status('请打开全部完整图片，并确认一般安全和整组检查。');return}
  busy=true
  try{await api('review/decide',{intent_id:current.intent_id,expected_version:current.expected_version,action,reason,general_safety_confirmed:general,group_confirmed:group});clearDetail();status(action==='approve'?'已批准，服务端将复验用户原发送意图和当前权限后处理。':'已拒绝；不会自动补发。');rows=[];get('list').replaceChildren()}
  catch(error){clearDetail();status(error.message)}finally{busy=false}
 }
 get('refresh').onclick=()=>load(true);get('next').onclick=()=>{if(rows.length){after=rows[rows.length-1].intent_id;load(false)}}
 get('approve').onclick=()=>decide('approve');get('reject').onclick=()=>decide('reject')
 get('photo').onclick=()=>{get('photo').style.maxWidth=get('photo').style.maxWidth==='none'?'100%':'none'}
 addEventListener('pagehide',()=>{clearDetail();token='';tokenUntil=0})
 load(true)
})()
