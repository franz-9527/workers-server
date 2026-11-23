const Api=require('../../../utils/OrderApi.js')
const Runtime=require('../../../utils/ChatRuntime.js')
Page({
 data:{loading:true,saving:false,error:'',revision:'',name:'',autoReplyEnabled:false,autoReplyText:''},
 onLoad(options){this.conversationId=String(options?.conversation_id||'')},
 onShow(){this.load()},
 async load(){
  if(this.data.saving)return
  this.setData({loading:true,error:''})
  try{
   const result=await Api.request('chat.support.settings','POST',{}),value=result.data||{}
   this.setData({revision:String(value.revision||''),name:String(value.name||''),autoReplyEnabled:value.auto_reply_enabled===true,autoReplyText:String(value.auto_reply_text||''),loading:false})
  }catch(error){this.setData({loading:false,error:error?.code==='CHAT_FORBIDDEN'?'当前账号没有反馈助手设置权限。':'暂时无法读取反馈助手设置。'})}
 },
 nameInput(event){this.setData({name:event.detail.value})},
 autoReplyChange(event){this.setData({autoReplyEnabled:event.detail.value===true})},
 autoReplyInput(event){this.setData({autoReplyText:event.detail.value})},
 async save(){
  if(this.data.loading||this.data.saving)return
  const name=String(this.data.name||'').trim(),autoReplyText=String(this.data.autoReplyText||'').trim()
  if(!name){wx.showToast({title:'请填写反馈助手名称',icon:'none'});return}
  if(this.data.autoReplyEnabled&&!autoReplyText){wx.showToast({title:'开启自动回复后请填写回复内容',icon:'none'});return}
  this.setData({saving:true,error:''})
  try{
   const result=await Api.request('chat.support.configure','POST',{revision:String(this.data.revision||''),name,auto_reply_enabled:this.data.autoReplyEnabled===true,auto_reply_text:autoReplyText}),value=result.data||{}
   this.setData({revision:String(value.revision||''),name:String(value.name||name),autoReplyEnabled:value.auto_reply_enabled===true,autoReplyText:String(value.auto_reply_text||autoReplyText)})
   const manager=Runtime.get();manager.supportService={...(manager.supportService||{}),enabled:true,name:String(value.name||name)};manager.refreshAccount()
   wx.showToast({title:'已保存',icon:'success'})
  }catch(error){
   if(error?.code==='CHAT_VERSION_CONFLICT'){this.setData({error:'设置已被其他管理员更新，已重新加载。'});await this.load()}
   else this.setData({error:error?.code==='CHAT_FORBIDDEN'?'当前账号没有反馈助手设置权限。':'设置保存失败，请稍后重试。'})
  }finally{this.setData({saving:false})}
 }
})
