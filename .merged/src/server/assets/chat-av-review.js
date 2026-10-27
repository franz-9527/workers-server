(() => {
 'use strict'
 const root=document.getElementById('chat-av-review');if(!root)return
 const get=id=>document.getElementById('av-review-'+id)
 let token='',until=0,after='0',rows=[],current=null,busy=false,blob='',generation=0,controller=null,expiry=null,played=false
 const status=text=>{get('status').textContent=text}
 function clear(){generation++;clearTimeout(expiry);controller?.abort();controller=null;get('media').pause();get('media').removeAttribute('src');get('media').load();get('media').hidden=true;if(blob)URL.revokeObjectURL(blob);blob='';current=null;played=false;get('detail').hidden=true}
 async function credential(){
  if(token&&Date.now()<until)return token
  const res=await fetch(root.dataset.credential,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:'{}'})
  const body=await res.json();if(!res.ok||!body.data?.access_token)throw new Error('预审身份暂不可用，请核对权限和开放状态')
  token=body.data.access_token;until=Date.parse(body.data.expires_at)-15000;return token
 }
 async function api(action,payload){
  const access=await credential(),res=await fetch('/chat/api/v1/av/'+action,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json',Authorization:'Bearer '+access},body:JSON.stringify(payload)})
  const body=await res.json();if(!res.ok||body.data===undefined){if(res.status===401||res.status===403){token='';clear()}throw new Error('任务状态或权限已变化，请刷新核对')};return body.data
 }
 function button(text,fn){const b=document.createElement('button');b.type='button';b.className='admin-btn';b.textContent=text;b.onclick=fn;return b}
 async function load(reset){
  if(busy)return;busy=true;clear();if(reset)after='0'
  try{rows=await api('review/list',{after});get('list').replaceChildren(...rows.map(row=>{
   const line=document.createElement('div'),text=document.createElement('p');text.textContent='#'+row.intent_id+' · '+(row.message_type==='audio'?'语音':'视频')+' · '+Math.ceil(row.duration_ms/1000)+'秒'
   line.append(text,button('领取并完整检查',()=>claim(row)));return line
  }));get('next').disabled=rows.length<20;status(rows.length?'请选择任务，领取后加载完整媒体。':'当前页暂无待审任务。')}
  catch(error){status(error.message)}finally{busy=false}
 }
 async function claim(row){
  if(busy)return;busy=true;clear()
  const serial=generation
  try{const claimed=await api('review/decide',{intent_id:row.intent_id,expected_version:row.expected_version,action:'claim',reason:'领取完整音视频预审'})
   if(serial!==generation||document.hidden)return
   current=claimed
   current.kind=row.message_type;get('detail').hidden=false;get('title').textContent='检查音视频 #'+row.intent_id;get('transcript').textContent=row.transcript?'语音转写：'+row.transcript:'无可识别文字；仍需完整检查画面及声音'
   get('general').checked=false;get('group').checked=false;get('reason').value='';status('已领取，请加载并完整播放本次媒体。')
   expiry=setTimeout(()=>{clear();status('领取已过期，请刷新后重新领取。')},290000)
  }catch(error){clear();status(error.message)}finally{busy=false}
 }
 async function open(){
  if(!current||busy)return;busy=true;const target=current,serial=generation;controller=new AbortController()
  try{
   const result=await api('review/url',{intent_id:target.intent_id,expected_version:target.expected_version});if(target!==current||serial!==generation)return
   const url=new URL(result.url);if(url.protocol!=='https:'||result.expires_at*1000<=Date.now())throw new Error('临时取件凭证已过期')
   const response=await fetch(url,{cache:'no-store',credentials:'omit',signal:controller.signal})
   if(!response.ok||!response.body)throw new Error('完整媒体加载失败')
   const reader=response.body.getReader(),chunks=[];let size=0
   for(;;){const part=await reader.read();if(part.done)break;size+=part.value.byteLength;if(size>20971520){await reader.cancel();throw new Error('媒体超出20MB限制')};chunks.push(part.value)}
   if(target!==current||serial!==generation)return
   if(blob)URL.revokeObjectURL(blob);blob=URL.createObjectURL(new Blob(chunks,{type:target.kind==='audio'?'audio/mpeg':'video/mp4'}))
   get('media').src=blob;get('media').hidden=false;played=false;status('完整媒体已加载。请播放至结束并完成两项检查。')
  }catch(error){if(serial===generation)status(error.message||'媒体加载失败')}finally{busy=false}
 }
 async function decide(action){
  if(!current||busy)return;const reason=get('reason').value.trim(),general=get('general').checked,group=get('group').checked
  if(reason.length<2){status('请填写至少2字理由');return}
  if(action==='approve'&&(!played||!general||!group)){status('请完整播放媒体，并确认两项检查');return}
  busy=true;try{await api('review/decide',{intent_id:current.intent_id,expected_version:current.expected_version,action,reason,general_safety_confirmed:general,group_confirmed:group});clear();get('list').replaceChildren();status(action==='approve'?'已批准，等待服务端复验用户当前权限后处理。':'已拒绝。')}catch(error){clear();status(error.message)}finally{busy=false}
 }
 get('media').onended=()=>{played=true}
 get('refresh').onclick=()=>load(true);get('next').onclick=()=>{if(rows.length){after=rows[rows.length-1].intent_id;load(false)}}
 get('open').onclick=open;get('approve').onclick=()=>decide('approve');get('reject').onclick=()=>decide('reject')
 addEventListener('pagehide',()=>{clear();token='';until=0});document.addEventListener('visibilitychange',()=>{if(document.hidden){clear();token='';until=0}});load(true)
})()
