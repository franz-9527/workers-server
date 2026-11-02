(() => {
 'use strict';
 const log = document.querySelector('[data-chat-test-log]'), status = document.querySelector('[data-chat-test-status]');
 if (!log || log.dataset.history === '1') return;
 let busy=false, stopped=false;
 async function refresh(){
  if(busy||stopped||document.hidden)return;
  busy=true;
  try{
   const response=await fetch(log.dataset.pollUrl,{credentials:'same-origin',headers:{Accept:'application/json'}});
   const body=await response.json();
   if(!response.ok||!body.ok){
    if(response.status===404){stopped=true;log.replaceChildren();document.querySelectorAll('.chat-testing-composer button').forEach(b=>b.disabled=true);status.textContent='会话已清理，请重新选择或创建';}
    else status.textContent='刷新暂未完成，可稍后点击刷新';
    return;
   }
   const names=new Map(body.data.members.map(m=>[m.actor_key,m.display_name]));
   const scrollAtEnd=log.scrollHeight-log.clientHeight-log.scrollTop<50, fragment=document.createDocumentFragment();
   for(const message of body.data.messages){
    const row=document.createElement('article'), label=document.createElement('small'), bubble=document.createElement('div');
    row.className='chat-testing-message'+(message.sender_key.startsWith('admin:')?' is-admin':'');
    label.textContent=(names.get(message.sender_key)||'已停用成员')+' · '+message.created_label;
    bubble.textContent=message.state==='recalled'?'该消息已撤回':message.text_body;
    row.append(label,bubble);fragment.append(row);
   }
   if(!body.data.messages.length){const empty=document.createElement('p');empty.className='admin-empty';empty.textContent='还没有消息，选择成员发送第一条。';fragment.append(empty);}
   log.replaceChildren(fragment);if(scrollAtEnd)log.scrollTop=log.scrollHeight;
   status.textContent='消息已同步，每5秒刷新';
  }catch(error){status.textContent='刷新暂未完成，可稍后点击刷新';}
  finally{busy=false;}
 }
 log.scrollTop=log.scrollHeight;
 const timer=setInterval(refresh,5000);
 window.addEventListener('pagehide',()=>{stopped=true;clearInterval(timer);},{once:true});
})();
