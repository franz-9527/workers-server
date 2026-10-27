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
  if(this.data.isGroup)actions.push(['群成员与邀请',()=>this.openGroup()])
  if(this.data.canOpenOrder)actions.push(['查看订单',()=>this.openOrder()])
  actions.push(['通话记录',()=>this.openCallHistory()],['聊天缓存',()=>wx.navigateTo({url:'/subpackages/chat/storage/index'})],['举报与申诉',()=>this.openGovernance()],['沟通说明',()=>wx.showModal({title:'沟通说明',content:'请在平台内沟通。站外交流记录可能无法核验，影响售后处理和责任认定；多次违规可能被限制聊天。草稿只在本次进程内短时保留，退出登录或超时后清除。',showCancel:false})])
  wx.showActionSheet({itemList:actions.map(a=>a[0]),success:r=>{if(this.current(life,epoch))actions[r.tapIndex]?.[1]()}})
 }
}
