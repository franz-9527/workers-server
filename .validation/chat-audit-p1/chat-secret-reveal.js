(function(document,window){
'use strict'
var form=document.querySelector('[data-chat-secret-reveal-form]')
if(!form)return
var modal=form.closest('[data-modal="chat-secret-reveal"]'),password=form.querySelector('[data-chat-secret-password]'),nameInput=form.querySelector('[data-chat-secret-name]'),labelNode=form.querySelector('[data-chat-secret-label]'),errorNode=form.querySelector('[data-chat-secret-error]'),submit=form.querySelector('[data-chat-secret-submit]')
var pending=null
function controls(){return document.querySelectorAll('[data-chat-secret-control]')}
function hide(control,status){
 if(!control)return
 var timer=control._chatSecretTimer;if(timer)window.clearTimeout(timer);control._chatSecretTimer=null
 var value=control.querySelector('[data-chat-secret-value]'),copy=control.querySelector('[data-chat-secret-copy]'),hideButton=control.querySelector('[data-chat-secret-hide]'),reveal=control.querySelector('[data-chat-secret-reveal]'),note=control.querySelector('[data-chat-secret-status]')
 if(value){value.value='';value.hidden=true}
 if(copy)copy.hidden=true;if(hideButton)hideButton.hidden=true;if(reveal)reveal.hidden=false
 if(note){note.textContent=status||'';if(status)window.setTimeout(function(){if(note.textContent===status)note.textContent=''},2500)}
}
function hideAll(){Array.prototype.forEach.call(controls(),function(control){hide(control,'')})}
function show(control,secret,seconds){
 hideAll()
 var value=control.querySelector('[data-chat-secret-value]'),copy=control.querySelector('[data-chat-secret-copy]'),hideButton=control.querySelector('[data-chat-secret-hide]'),reveal=control.querySelector('[data-chat-secret-reveal]'),note=control.querySelector('[data-chat-secret-status]')
 value.value=secret;value.hidden=false;copy.hidden=false;hideButton.hidden=false;reveal.hidden=true
 if(note)note.textContent='已临时显示'
 control._chatSecretTimer=window.setTimeout(function(){hide(control,'已自动隐藏')},Math.max(5,Number(seconds)||20)*1000)
}
function closeModal(){var closer=modal&&modal.querySelector('[data-modal-close]');if(closer)closer.click()}
function resetModal(){if(password)password.value='';if(errorNode){errorNode.hidden=true;errorNode.textContent=''};if(submit)submit.disabled=false;pending=null}
document.addEventListener('click',function(event){
 var reveal=event.target.closest&&event.target.closest('[data-chat-secret-reveal]')
 if(reveal){
  pending=reveal.closest('[data-chat-secret-control]')
  nameInput.value=String(reveal.getAttribute('data-secret-name')||'')
  labelNode.textContent=String(reveal.getAttribute('data-secret-label')||'敏感配置')
  if(password)password.value=''
  if(errorNode){errorNode.hidden=true;errorNode.textContent=''}
  return
 }
 var copy=event.target.closest&&event.target.closest('[data-chat-secret-copy]')
 if(copy){
  var control=copy.closest('[data-chat-secret-control]'),value=control&&control.querySelector('[data-chat-secret-value]'),plain=value?value.value:''
  if(!plain)return
  var done=function(){hide(control,'已复制并隐藏')}
  if(navigator.clipboard&&navigator.clipboard.writeText)navigator.clipboard.writeText(plain).then(done).catch(function(){
   value.hidden=false;value.select();document.execCommand('copy');done()
  })
  else{value.hidden=false;value.select();document.execCommand('copy');done()}
  return
 }
 var hideButton=event.target.closest&&event.target.closest('[data-chat-secret-hide]')
 if(hideButton)hide(hideButton.closest('[data-chat-secret-control]'),'已隐藏')
})
form.addEventListener('submit',function(event){
 event.preventDefault()
 if(!pending||!nameInput.value)return
 if(submit)submit.disabled=true
 if(errorNode){errorNode.hidden=true;errorNode.textContent=''}
 fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},cache:'no-store'})
  .then(function(response){return response.json().catch(function(){return {success:false,message:'服务器返回不可解析'}}).then(function(data){if(!response.ok||!data.success)throw new Error(data.message||'验证失败');return data})})
  .then(function(data){
   var control=pending,secret=data&&data.data&&data.data.secret
   if(typeof secret!=='string'||!secret)throw new Error('该密钥当前不可读取')
   closeModal();show(control,secret,data.data.expires_in)
  })
  .catch(function(error){if(errorNode){errorNode.textContent=error&&error.message?error.message:'验证失败';errorNode.hidden=false}})
  .finally(function(){if(password)password.value='';if(submit)submit.disabled=false})
})
document.addEventListener('admin:modal-close',function(event){if(event.detail&&event.detail.modal===modal)resetModal()})
document.addEventListener('visibilitychange',function(){if(document.hidden)hideAll()})
window.addEventListener('pagehide',hideAll)
})(document,window)
