const Audit = require('../../../utils/ChatAudit.js')
const Runtime = require('../../../utils/ChatRuntime.js')
const {validID}=require('../../../utils/ChatInboxModel.js')
Page({
 data:{enabled:false,busy:false,error:'',rows:[],messages:[],hasMore:false,hasEarlier:false,conversation:'',photo:'',av:null,hasReturn:false},
 onLoad(options){this.id=String(options?.conversation_id||'');this.life=0;this.manager=Runtime.get();this.stop=this.manager.subscribe(type=>{if(type==='reset'){this.life++;this.erase();this.setData({error:'身份已变化，请重新进入'})}})},
 onShow(){this.visible=true;this.life++;this.erase();this.client=Audit.create();this.setData({enabled:this.manager.enabled});if(this.manager.enabled)this.load(false)},
 onHide(){this.visible=false;this.life++;this.erase()},
 onUnload(){this.onHide();if(this.stop)this.stop()},
 erase(){this.stopAV();if(this.client)this.client.clear();clearTimeout(this.photoTimer);this.cursor='';this.history='';this.returnID='';this.setData({busy:false,rows:[],messages:[],photo:'',hasMore:false,hasEarlier:false,hasReturn:false,error:''})},
 current(life){return this.visible&&this.life===life&&this.client&&this.client.epoch===this.manager.epoch},
 async load(more){
  if(this.data.busy||!this.manager.enabled)return
  const life=this.life;this.stopAV();this.setData({busy:true,error:'',photo:''});clearTimeout(this.photoTimer)
  try{
   if(this.id){
    if(!validID(this.id))throw new Error('会话编号无效')
    const page=await this.client.request('audit/history',{conversation_id:this.id,limit:30,...(more&&this.history?{cursor:this.history}:{})})
    if(!this.current(life))return;this.history=page.history_cursor||''
    const messages=more?[...page.messages,...this.data.messages]:page.messages
    this.setData({conversation:this.id,messages:messages.slice(0,100),hasEarlier:!!this.history})
   }else{
    const page=await this.client.request('audit/list',{limit:20,...(more&&this.cursor?{cursor:this.cursor}:{})})
    if(!this.current(life))return;this.cursor=page.next_cursor||''
    this.setData({rows:(more?[...this.data.rows,...page.items]:page.items).slice(-100),hasMore:page.has_more})
   }
  }catch(error){if(this.current(life)){this.setData({messages:[],rows:[],photo:'',hasMore:false,hasEarlier:false,error:error.message||'抽检暂不可用'})}}
  finally{if(this.current(life))this.setData({busy:false})}
 },
 refresh(){this.cursor='';this.history='';this.load(false)},
 more(){if((this.id&&this.history)||(!this.id&&this.cursor))this.load(true)},
 open(event){const id=String(event.currentTarget.dataset.id||'');if(validID(id))wx.navigateTo({url:'/subpackages/chat/audit/index?conversation_id='+id})},
 async locate(event){
  if(this.data.busy)return
  const target=String(event.currentTarget.dataset.id||'');if(!validID(target))return
  const life=this.life;this.returnID=this.data.messages[0]?.message_id||'';this.setData({busy:true,photo:''})
  try{const page=await this.client.request('audit/history',{conversation_id:this.id,message_id:target,limit:30});if(!this.current(life))return;this.history=page.history_cursor||'';this.setData({messages:page.messages,hasEarlier:!!this.history,hasReturn:!!this.returnID})}
  catch(error){if(this.current(life))this.setData({error:error.message})}finally{if(this.current(life))this.setData({busy:false})}
 },
 backPosition(){const id=this.returnID;if(id){this.returnID='';this.locate({currentTarget:{dataset:{id}}});this.setData({hasReturn:false})}},
 async image(event){
  if(this.data.busy)return
  const mid=String(event.currentTarget.dataset.message||''),item=String(event.currentTarget.dataset.item||'')
  if(!validID(mid)||!validID(item))return
  const life=this.life;this.setData({busy:true,photo:''});clearTimeout(this.photoTimer)
  try{const data=await this.client.request('audit/url',{conversation_id:this.id,message_id:mid,item_id:item});if(!this.current(life))return;if(!/^https:\/\//.test(data.url))throw new Error('图片取件不可用');this.setData({photo:data.url});this.photoTimer=setTimeout(()=>{if(this.current(life))this.setData({photo:''})},50000)}
  catch(error){if(this.current(life))this.setData({error:error.message})}finally{if(this.current(life))this.setData({busy:false})}
 },
 stopAV(){
  if(this.avTask)try{this.avTask.abort()}catch(_){};this.avTask=null
  if(this.audio)try{this.audio.stop();this.audio.destroy()}catch(_){};this.audio=null
  if(this.avTimer)clearTimeout(this.avTimer);this.avTimer=null
  if(this.avPath&&typeof wx.getFileSystemManager==='function')wx.getFileSystemManager().unlink({filePath:this.avPath,fail(){}})
  this.avPath='';this.setData({av:null})
 },
 async media(event){
  if(this.data.busy)return
  const mid=String(event.currentTarget.dataset.message||''),message=this.data.messages.find(m=>m.message_id===mid)
  if(!validID(mid)||!message?.av||message.state!=='published')return
  const life=this.life;this.stopAV();this.setData({busy:true,error:''})
  try{
   const access=await this.client.request('audit/av',{conversation_id:this.id,message_id:mid});if(!this.current(life))return
   if(!/^https:\/\//.test(access.url))throw new Error('媒体取件不可用')
   const result=await new Promise((resolve,reject)=>{this.avTask=wx.downloadFile({url:access.url,timeout:120000,success:resolve,fail:reject})})
   this.avTask=null
   if(!this.current(life)){if(result.tempFilePath)wx.getFileSystemManager().unlink({filePath:result.tempFilePath,fail(){}});return}
   this.avPath=result.tempFilePath
   if(result.statusCode!==200||!this.avPath)throw new Error('媒体加载失败')
   const info=await new Promise((resolve,reject)=>wx.getFileInfo({filePath:this.avPath,success:resolve,fail:reject}))
   if(!this.current(life))return
   if(info.size>(message.message_type==='audio'?1048576:20971520))throw new Error('媒体超过大小限制')
   
   if(message.message_type==='audio'){
    this.audio=wx.createInnerAudioContext();this.audio.src=this.avPath;this.audio.onError(()=>{if(this.current(life))this.setData({error:'语音播放失败'})});this.audio.play()
    this.setData({av:{kind:'audio'}})
   }else this.setData({av:{kind:'video',src:this.avPath}})
  }catch(error){if(this.current(life)){this.stopAV();this.setData({error:error.message||'媒体加载失败'})}}
  finally{if(this.current(life))this.setData({busy:false})}
 },
 closeAV(){this.stopAV()},
 closeImage(){clearTimeout(this.photoTimer);this.setData({photo:''})}
})
