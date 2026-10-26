const Runtime=require('../../../utils/ChatRuntime.js')
const Identity=require('../../../utils/ChatIdentity.js')
const roles=Object.entries(Identity.labels).map(([value,label])=>({value,label}))
const failText=e=>e?.message&&!String(e.message).startsWith('CHAT_')?String(e.message):'操作未完成，请刷新后重试'
Page({
 data:{loading:false,busy:false,invalid:false,disabled:false,loginNeeded:false,error:'',group:null,preview:null,roles,roleIndex:0,share:null,poster:'',events:[]},
 onLoad(options){
  this.manager=Runtime.get();this.invite=String(options.invite||options.scene||'');this.id=String(options.conversation_id||'')
  this.visible=false;this.life=0;this.epoch=this.manager.epoch;this.files=[]
  this.setData({invalid:!(/^[a-f0-9]{32}$/.test(this.invite)||/^[1-9][0-9]{0,19}$/.test(this.id))})
  this.unsubscribe=this.manager.subscribe(type=>{if(type==='reset'){this.life++;this.epoch=this.manager.epoch;this.erase();if(this.visible&&this.manager.enabled)Promise.resolve().then(()=>this.refresh())}})
 },
 onShow(){this.visible=true;this.life++;this.setData({disabled:!this.manager.enabled});if(!this.data.invalid&&this.manager.enabled)this.refresh()},
 onHide(){this.visible=false;this.life++;this.erase()},
 onUnload(){this.onHide();this.unsubscribe?.()},
 current(life,epoch){return this.visible&&this.life===life&&this.manager.epoch===epoch&&this.manager.enabled},
 erase(){if(this.projectionTimer)clearTimeout(this.projectionTimer);this.projectionTimer=null;for(const path of this.files||[])try{wx.getFileSystemManager().unlink({filePath:path})}catch(_){};this.files=[];this.setData({group:null,preview:null,share:null,poster:'',events:[],busy:false,loading:false,error:''})},
 async request(action,data){
  const life=this.life,epoch=this.manager.epoch
  try{const out=await this.manager.io.collaboration(action,data);if(!this.current(life,epoch))throw new Error('CHAT_CONTEXT_CHANGED');return out}
  catch(error){if(this.current(life,epoch)&&['CHAT_UNAUTHORIZED','UNAUTHORIZED'].includes(error.code))this.setData({loginNeeded:true});throw error}
 },
 async refresh(){
  if(this.projectionTimer)clearTimeout(this.projectionTimer);this.projectionTimer=null
  const life=this.life,epoch=this.manager.epoch
  this.setData({loading:true,error:'',loginNeeded:false})
  try{
   const out=await this.request(this.id?'info':'preview',this.id?{conversation_id:this.id}:{token:this.invite})
   if(!this.current(life,epoch))return
   if(this.id){const members=(out.members||[]).map(m=>({...m,label:Identity.labels[m.display_role]||({worker:'正式施工师傅',platform:'平台工作人员'}[m.display_role])||'成员',avatar:Identity.avatars[m.display_role==='team_worker'||m.role_source==='assigned_worker'?'worker':['merchant','merchant_service'].includes(m.display_role)||m.role_source==='merchant_owner'?'merchant':m.role_source==='platform_staff'?'platform':'user']}));this.setData({group:{...out,members},events:(out.events||[]).map(v=>({...v,label:({'member.joined':'成员加入','member.left':'成员退出','member.removed':'成员被移除','member.role_changed':'成员更正展示身份','member.authority_closed':'系统成员权限更新','invite.generated':'分享邀请已更新','invite.revoked':'分享邀请已撤销'}[v.event_type])||'群信息更新'}))})}
   else this.setData({preview:out})
   if(this.id&&out.projection_ready===false&&(this.projectionChecks||0)<10){
    this.projectionChecks=(this.projectionChecks||0)+1
    this.projectionTimer=setTimeout(()=>{if(this.current(life,epoch))this.refresh()},500)
   }else if(this.id&&out.projection_ready===true){this.projectionChecks=0;this.manager.refreshAccount()}
   if(this.data.share&&(out.can_invite!==true||String(out.invite_generation)!==String(this.data.share.generation)))this.setData({share:null,poster:''})

  }catch(error){if(this.current(life,epoch))this.setData({error:failText(error),group:null,preview:null,share:null,poster:''})}
  finally{if(this.current(life,epoch))this.setData({loading:false})}
 },
 async login(){try{await require('../../../utils/OrderApi.js').login(false);if(this.visible)await this.refresh()}catch(error){if(this.visible)this.setData({error:failText(error)})}},
 chooseRole(event){this.setData({roleIndex:Number(event.detail.value)||0})},
 async run(action,extra={}){
  if(this.data.busy)return null
  const life=this.life,epoch=this.manager.epoch;this.setData({busy:true,error:''})
  try{const out=await this.request(action,{conversation_id:this.id,...extra});return this.current(life,epoch)?out:null}
  catch(error){if(this.current(life,epoch)){const message=failText(error);this.setData({error:message,share:null,poster:''});await this.refresh();if(this.current(life,epoch))this.setData({error:message})}return null}
  finally{if(this.current(life,epoch))this.setData({busy:false})}
 },
 async join(){
  if(this.data.busy)return
  const life=this.life,epoch=this.manager.epoch;this.setData({busy:true,error:''})
  try{const out=await this.request('join',{token:this.invite,display_role:roles[this.data.roleIndex].value});if(!this.current(life,epoch))return
   if(out.conversation_id){this.id=out.conversation_id;this.invite='';this.setData({group:out,preview:null});await this.refresh();this.manager.refreshAccount()}
   else this.setData({error:'已加入，群消息正在同步，请稍后刷新此分享'})
  }catch(error){if(this.current(life,epoch))this.setData({error:failText(error)})}
  finally{if(this.current(life,epoch))this.setData({busy:false})}
 },
 async generate(){
  const life=this.life,epoch=this.manager.epoch,viewer=this.data.group?.viewer_actor_id
  const value=await this.run('invite',{expected_generation:this.data.group.invite_generation})
  if(!value||!this.current(life,epoch))return
  this.setData({share:value,poster:''});await this.refresh()
  if(!this.current(life,epoch))return
  if(this.data.group?.viewer_actor_id===viewer&&this.data.group?.can_invite===true&&String(this.data.group.invite_generation)===String(value.generation))this.setData({share:value})
  else this.setData({share:null,poster:''})
 },
 async revoke(){if(await this.run('revoke',{expected_generation:this.data.group.invite_generation})){this.setData({share:null,poster:''});await this.refresh()}},
 async remove(event){
  const target=this.data.group.members.find(m=>m.actor_id===String(event.currentTarget.dataset.id))
  if(!target?.can_remove)return
  const life=this.life,epoch=this.manager.epoch
  wx.showModal({title:'移除群成员',content:'该成员将失去群消息访问权限；重新加入需要新的有效邀请。',success:async result=>{
   if(!result.confirm||!this.current(life,epoch))return
   if(await this.run('remove',{target_actor_id:target.actor_id,expected_member_version:target.member_version}))await this.refresh()
  }})
 },
 async leave(){
  const me=this.data.group.members.find(m=>m.actor_id===this.data.group.viewer_actor_id);if(!me)return
  const life=this.life,epoch=this.manager.epoch
  wx.showModal({title:'退出协作群',content:'退出后无法继续查看群消息，重新加入从新的授权区间开始。',success:async result=>{
   if(!result.confirm||!this.current(life,epoch))return
   if(await this.run('leave',{expected_member_version:me.member_version})){this.manager.forgetConversation(this.id);this.erase();wx.switchTab({url:'/pages/chatMessages/index'})}
  }})
 },
 async editRole(){
  const me=this.data.group.members.find(m=>m.actor_id===this.data.group.viewer_actor_id);if(!me)return
  if(await this.run('role',{display_role:roles[this.data.roleIndex].value,expected_display_version:me.display_version}))await this.refresh()
 },
 async openChat(){
  if(!this.id||this.data.busy)return
  if(this.data.group?.projection_ready===false){await this.refresh();if(this.data.group?.projection_ready!==true){if(this.visible)this.setData({error:'群成员权限正在同步，请稍后重试'});return}}
  if(this.visible)wx.navigateTo({url:'/subpackages/chat/conversation/index?conversation_id='+encodeURIComponent(this.id)})
 },
 onShareAppMessage(){return this.data.share?{title:this.data.share.title,path:this.data.share.path}:{title:'壁选师傅',path:'/pages/index/index'}},
 async makePoster(){
  if(!this.data.share)return
  const life=this.life,epoch=this.manager.epoch
  const result=await this.run('poster',{expected_generation:this.data.share.generation})
  if(!result||!this.current(life,epoch))return
  try{
   if(!/^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(result.code_image)||result.code_image.length>1500000)throw new Error('分享图片无效')
   const path=wx.env.USER_DATA_PATH+'/chat-invite-'+Date.now()+'.png'
   await new Promise((resolve,reject)=>wx.getFileSystemManager().writeFile({filePath:path,data:result.code_image.split(',')[1],encoding:'base64',success:resolve,fail:reject}))
   if(!this.current(life,epoch)){wx.getFileSystemManager().unlink({filePath:path});return}this.files.push(path)
   const ctx=wx.createCanvasContext('groupPoster',this);ctx.setFillStyle('#ffffff');ctx.fillRect(0,0,600,860);ctx.setFillStyle('#007f8b');ctx.setFontSize(36);ctx.fillText('壁选师傅',56,80)
   ctx.setFillStyle('#202124');ctx.setFontSize(30);ctx.fillText(String(result.title||'订单协作群').slice(0,26),56,150);ctx.setFontSize(22);ctx.fillText('受邀加入，一起沟通订单事项',56,198);ctx.drawImage(path,85,240,430,430)
   ctx.setFontSize(20);ctx.fillText('长按小程序码，登录后选择展示身份',56,715);ctx.fillText('邀请有效期：'+String(result.expires_at).slice(0,10),56,755);ctx.fillText('身份标签不代表业务权限或认证',56,800)
   await new Promise(resolve=>ctx.draw(false,resolve))
   const image=await new Promise((resolve,reject)=>wx.canvasToTempFilePath({canvasId:'groupPoster',width:600,height:860,destWidth:600,destHeight:860,fileType:'png',success:resolve,fail:reject},this))
   if(this.current(life,epoch))this.setData({poster:image.tempFilePath})
  }catch(error){if(this.current(life,epoch))this.setData({error:failText(error)})}
 },
 savePoster(){if(this.data.poster)wx.saveImageToPhotosAlbum({filePath:this.data.poster,fail:()=>wx.showToast({title:'保存未完成，请检查相册授权',icon:'none'})})}
})
