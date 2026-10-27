module.exports={
 openGroup(){if(!this.data.isGroup)return;wx.navigateTo({url:'/subpackages/chat/group/index?conversation_id='+encodeURIComponent(this.id)})},
 async showReaders(event){
  if(!this.data.isGroup)return
  const id=String(event.currentTarget.dataset.id||''),life=this.life,epoch=this.manager.epoch
  try{const value=await this.manager.http('conversation.readers',{conversation_id:this.id,message_id:id},epoch)
   if(this.current(life,epoch))wx.showModal({title:'群消息阅读情况',content:value.read_count+'人已读，'+value.unread_count+'人未读（仅统计可查看这条消息的当前成员）',showCancel:false})
  }catch(_){if(this.current(life,epoch))wx.showToast({title:'暂时无法查询阅读情况',icon:'none'})}
 }
}
