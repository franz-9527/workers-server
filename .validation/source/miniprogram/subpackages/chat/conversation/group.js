module.exports={
 openGroup(){if(!this.data.isGroup&&!this.manager.testSandbox)return;wx.navigateTo({url:'/subpackages/chat/group/index?conversation_id='+encodeURIComponent(this.id)})},
 async showReaders(event){
  const id=String(event.currentTarget.dataset.id||''),life=this.life,epoch=this.manager.epoch
  if(!this.data.isGroup&&!this.data.assignmentGroup){
   const label=this.data.receiptLabels[id]||'未读'
   wx.showModal({title:'消息阅读情况',content:label,showCancel:false});return
  }
  try{const value=await this.manager.http('conversation.readers',{conversation_id:this.id,message_id:id},epoch)
   if(!this.current(life,epoch)||this.views[id]?.state!=='published')return
   const label=this.receiptLabel(value,id)
   if(!label)throw new Error('Invalid receipt')
   this.updateReceiptLabel(id,label)
   wx.showModal({title:'消息阅读情况',content:label,showCancel:false})
  }catch(_){if(this.current(life,epoch))wx.showToast({title:'暂时无法查询阅读情况',icon:'none'})}
 }
}
