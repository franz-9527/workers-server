// Familiar chat controls stay separate from the message/permission state machine.
module.exports={
 toggleVoiceMode(){
  if(!this.visible||!this.data.canSend||!this.data.audioEnabled||this.recordWanted||this.data.recording)return
  if(typeof wx.hideKeyboard==='function')wx.hideKeyboard()
  this.setData({voiceMode:!this.data.voiceMode,showTools:false,keyboardHeight:0})
 },
 toggleTools(){
  if(!this.visible||!this.data.canSend||this.recordWanted||this.data.recording)return
  if(typeof wx.hideKeyboard==='function')wx.hideKeyboard()
  const life=this.life,epoch=this.manager.epoch
  this.captureAnchor().then(anchor=>{if(this.current(life,epoch))this.setData({showTools:!this.data.showTools,keyboardHeight:0},()=>this.atBottom?this.jumpBottom():this.restoreAnchor(anchor,life,epoch))})
 },
 focusComposer(){if(this.data.showTools)this.setData({showTools:false})},
 dismissTools(){if(this.data.showTools)this.setData({showTools:false})},
 takePhoto(){this.chooseImages({currentTarget:{dataset:{source:'camera'}}})},
 chatInfo(){
  if(!this.visible||!this.manager.enabled||this.data.status==='denied')return
  const life=this.life,epoch=this.manager.epoch,actions=[]
  if(this.manager.simulation){
   if(this.data.canOpenOrder)actions.push(['查看测试订单',()=>this.openOrder()])
   actions.push(['模拟会话说明',()=>wx.showModal({title:'测试模拟会话',content:'本会话仅在当前测试实例的客户与师傅身份间同步文字，支持未读、已读和撤回。不会发送真实通知，图片、音视频、举报及引用回复不在此次模拟范围内。',showCancel:false})])
   wx.showActionSheet({itemList:actions.map(a=>a[0]),success:r=>{if(this.current(life,epoch))actions[r.tapIndex]?.[1]()}});return
  }
  if(this.data.isGroup)actions.push(['群成员与邀请',()=>this.openGroup()])
  if(this.data.canOpenOrder)actions.push(['查看订单',()=>this.openOrder()])
  actions.push(['通话记录',()=>this.openCallHistory()],['聊天缓存',()=>wx.navigateTo({url:'/subpackages/chat/storage/index'})],['举报与申诉',()=>this.openGovernance()],['沟通说明',()=>wx.showModal({title:'沟通说明',content:'请在平台内沟通。站外交流记录可能无法核验，影响售后处理和责任认定；多次违规可能被限制聊天。草稿只在本次进程内短时保留，退出登录或超时后清除。',showCancel:false})])
  wx.showActionSheet({itemList:actions.map(a=>a[0]),success:r=>{if(this.current(life,epoch))actions[r.tapIndex]?.[1]()}})
 }
}
