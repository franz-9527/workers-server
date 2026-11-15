// Familiar chat controls stay separate from the message/permission state machine.
module.exports={
 toggleVoiceMode(){
  if(!this.visible||!this.data.canSend||!this.data.audioEnabled||this.recordWanted||this.data.recording)return
  if(typeof wx.hideKeyboard==='function')wx.hideKeyboard()
  const voiceMode=!this.data.voiceMode
  this.setData({voiceMode,showTools:false,keyboardHeight:0},()=>{if(typeof this.persistComposerMode==='function')this.persistComposerMode(voiceMode)})
 },
 toggleTools(){
  if(!this.visible||!this.data.canSend||this.recordWanted||this.data.recording)return
  if(typeof wx.hideKeyboard==='function')wx.hideKeyboard()
  const life=this.life,epoch=this.manager.epoch
  this.captureAnchor().then(anchor=>{if(this.current(life,epoch))this.setData({showTools:!this.data.showTools,showChatMenu:false,keyboardHeight:0},()=>this.atBottom?this.jumpBottom():this.restoreAnchor(anchor,life,epoch))})
 },
 focusComposer(){this.closeMessageMenu();this.closeChatMenu();if(this.data.showTools)this.setData({showTools:false})},
 dismissTools(){this.closeMessageMenu();this.closeChatMenu();if(this.data.showTools)this.setData({showTools:false})},
 takePhoto(){this.chooseImages({currentTarget:{dataset:{source:'camera'}}})},
 chatInfo(){
  if(!this.visible||!this.manager.enabled||this.data.status==='denied')return
  const opening=!this.data.showChatMenu
  if(opening&&typeof wx.hideKeyboard==='function')wx.hideKeyboard()
  this.setData({showChatMenu:opening,showTools:opening?false:this.data.showTools,testSandbox:this.manager.testSandbox===true})
 },
 closeChatMenu(){if(this.data.showChatMenu)this.setData({showChatMenu:false})},
 stopMenuTouch(){},
 runChatMenuAction(event){
  const action=String(event.currentTarget.dataset.action||'')
  if(!action||!this.visible||!this.manager.enabled||this.data.status==='denied'){this.closeChatMenu();return}
  const life=this.life,epoch=this.manager.epoch
  this.setData({showChatMenu:false})
  if(!this.visible||this.life!==life||this.manager.epoch!==epoch||!this.manager.enabled||this.data.status==='denied')return
  if(action==='group'){this.openGroup();return}
  if(action==='order'){this.openOrder();return}
  if(action==='calls'){this.openCallHistory();return}
  if(action==='storage'){wx.navigateTo({url:'/subpackages/chat/storage/index'});return}
  if(action==='governance'){this.openGovernance();return}
  if(action==='simulation')wx.showModal({title:'测试会话说明',content:'仅在当前测试实例同步文字、未读、已读和撤回，不发送真实通知。',showCancel:false})
 }
}
