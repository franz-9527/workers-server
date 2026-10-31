document.addEventListener('DOMContentLoaded',()=>{
 const section=new URL(location.href).searchParams.get('section')||'overview'
 if(!['overview','workflow','measurement','aftersale','messages','records','tools'].includes(section))return
 for(const form of document.querySelectorAll('form[method="post"]'))if(!form.elements.namedItem('section')){
  const input=document.createElement('input');input.type='hidden';input.name='section';input.value=section;form.append(input)
 }
 const panel=new URL(location.href).searchParams.get('panel')||''
 if(panel)for(const form of document.querySelectorAll('form[method="post"]'))if(!form.elements.namedItem('panel')){const input=document.createElement('input');input.type='hidden';input.name='panel';input.value=panel;form.append(input)}
 const log=document.querySelector('.test-chat');if(log&&!new URL(location.href).searchParams.has('chat_before'))log.scrollTop=log.scrollHeight
})
