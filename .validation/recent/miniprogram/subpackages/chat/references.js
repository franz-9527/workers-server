const OrderCard = require('../../utils/ChatOrderCard.js')

function quoteText(message) {
  if (!message || message.state === 'unavailable') return '引用内容已不可查看'
  if (message.state === 'recalled') return '原消息已撤回'
  const card = OrderCard.parse(message.text)
  if (card) return '订单 ' + card.publicNo
  if (message.message_type === 'image_album' || message.images?.length > 1) return '图片合集'
  if (message.message_type === 'image' || message.images?.length) return '图片'
  return Array.from(message.text || '消息').slice(0, 120).join('')
}

const sceneReferences = {
  selectReply(event) {
    if (this.data.canSend === false || this.data.busy || this.data.pending) return
    this.closeMessageMenu?.()
    if(this.data.showTools)this.setData({showTools:false})
    const id = event.currentTarget.dataset.message
    const row = this.data.messages.find(item => item.message_id === id && item.state === 'published')
    if (row) this.setData({replyTo: id, replyPreview: row.author + '：' + quoteText(row)})
  },
  clearReply() {
    if (!this.data.pending) this.setData({replyTo: '', replyPreview: ''})
  },
  toggleSceneTools() {
    if(this.data.busy||this.data.pending||this.data.canSend===false)return
    this.closeMessageMenu?.()
    if(this.data.showSettings)this.setData({showSettings:false})
    this.setData({showTools:!this.data.showTools})
  },
  closeSceneTools() {
    if(this.data.showTools)this.setData({showTools:false})
  },
  toggleSceneSettings() {
    if(this.data.inbox||this.data.busy)return
    this.closeMessageMenu?.()
    if(this.data.showTools)this.setData({showTools:false})
    this.setData({showSettings:!this.data.showSettings})
  },
  closeSceneSettings() {
    if(this.data.showSettings)this.setData({showSettings:false})
  },
  toggleSceneInboxMenu() {
    if(!this.data.inbox||this.data.busy)return
    this.closeMessageMenu?.()
    this.setData({showInboxMenu:!this.data.showInboxMenu,showSettings:false,showTools:false})
  },
  closeSceneInboxMenu() {
    if(this.data.showInboxMenu)this.setData({showInboxMenu:false})
  },
  stopSceneOperation() {},
  async openQuote(event) {
    if (this.quoteJob) return
    const serial = this.serial
    const source = this.data.messages.find(item => item.message_id === event.currentTarget.dataset.message)
    const target = source?.reply?.message_id
    if (!target || source.reply.state !== 'published') return
    const showUnavailable = () => wx.showToast({ title: '引用内容已不可查看', icon: 'none' })
    const job = this.http(serial, 'messages/locate', {conversation_id: this.data.conversation, message_id: target, limit: 1})
    this.quoteJob = job
    try {
      const page = await job
      if (!this.active(serial)) return
      const original = page.messages.find(item => item.message_id === target && item.state === 'published')
      if (!original) {
        showUnavailable()
        return
      }
      if (this.data.messages.some(item => item.message_id === target && item.state === 'published')) {
        wx.pageScrollTo({selector: '#chat-message-' + target, duration: 200})
      } else {
        wx.showModal({title: '引用原消息', content: quoteText(original), showCancel: false})
      }
    } catch (error) {
      if (this.active(serial)) {
        if (error.code === 'CHAT_PERMISSION_DENIED') showUnavailable()
        else wx.showToast({title: '暂时无法打开引用', icon: 'none'})
      }
    } finally {
      if (this.quoteJob === job) this.quoteJob = null
    }
  },
  closeMessageMenu() {
    this.messageMenuActions = null
    if (this.data.messageMenu) this.setData({messageMenu:null})
  },
  messageActions(event) {
    if(this.data.showTools||this.data.showSettings)this.setData({showTools:false,showSettings:false})
    const id=String(event.currentTarget.dataset.message||event.currentTarget.dataset.id||'')
    const row=this.data.messages.find(item=>item.message_id===id)
    if(!row||row.state!=='published'||this.data.busy)return
    const actions=[],item=String(event.currentTarget.dataset.item||'')
    const add=(key,label,run,tone='')=>actions.push({key,label,run,tone})
    if(this.data.canSend!==false&&!this.data.pending)add('reply','引用',()=>this.selectReply({currentTarget:{dataset:{message:id}}}))
    if(!row.order&&(!row.images||!row.images.length)&&typeof row.text==='string'&&row.text)add('copy','复制',()=>wx.setClipboardData({data:row.text}))
    if(row.images?.length){
      const target=item||String(row.images[0].item_id||'')
      if(target)add('open','查看',()=>this.openImage({currentTarget:{dataset:{message:id,item:target}}}))
    }
    if(row.order&&typeof this.openOrder==='function')add('order','查看订单',()=>this.openOrder({currentTarget:{dataset:{message:id}}}))
    if(row.canRecall)add('recall','撤回',()=>this.recallSceneMessage(id),'danger')
    if(!row.own&&typeof this.report==='function')add('report','举报',()=>this.report({currentTarget:{dataset:{message:id}}}),'danger')
    this.presentMessageMenu(event,id,String(row.version||''),actions)
  },
  presentMessageMenu(event,id,version,actions) {
    if(!actions.length)return
    const window=typeof wx.getWindowInfo==='function'?wx.getWindowInfo():wx.getSystemInfoSync()
    const maxColumns=Math.max(1,Math.min(5,Math.floor((window.windowWidth-32)/58)))
    const columns=Math.min(maxColumns,Math.max(1,actions.length)),rows=Math.ceil(actions.length/columns)
    const width=Math.min(window.windowWidth-24,columns*58+12),height=rows*62+12
    const point=event.touches?.[0]||event.changedTouches?.[0]||{},x=Number(point.clientX),y=Number(point.clientY)
    const anchorY=Number.isFinite(y)?y:100,above=anchorY>=height+12||anchorY+height+10>window.windowHeight-8
    const left=Number.isFinite(x)?x-width/2:12,top=above?anchorY-height-10:anchorY+10
    const icons={reply:'quote',copy:'copy',open:'view',order:'order',recall:'recall',report:'report'}
    this.messageMenuActions={id,version,actions,serial:this.serial}
    this.setData({messageMenu:{
      left:Math.max(12,Math.min(left,window.windowWidth-width-12)),
      top:Math.max(8,Math.min(top,window.windowHeight-height-8)),
      width,columns,placement:above?'above':'below',actions:actions.map(({key,label,icon,tone})=>({key,label,icon:icon||icons[key]||'more',tone}))
    }})
  },
  runMessageAction(event) {
    const menu=this.messageMenuActions,key=event.currentTarget.dataset.key
    this.closeMessageMenu()
    if(!menu||menu.serial!==this.serial)return
    const row=this.data.messages.find(item=>item.message_id===menu.id)
    if(!row||row.state!=='published'||String(row.version||'')!==menu.version)return
    menu.actions.find(action=>action.key===key)?.run()
  },
  async recallSceneMessage(id) {
    const row=this.data.messages.find(item=>item.message_id===id)
    if(!row?.canRecall||this.data.busy)return
    const serial=this.serial
    this.setData({busy:true})
    try{
      const result=await this.http(serial,'messages/recall',{conversation_id:this.data.conversation,message_id:id})
      if(!this.active(serial))return
      this.setData({messages:this.data.messages.map(item=>item.message_id===id?{...item,state:'recalled',version:String(result.state_version||item.version||''),canRecall:false,text:'消息已撤回',images:[],order:null}:item),notice:'消息已撤回'})
      this.manager.refreshAccount()
    }catch(error){
      if(!this.active(serial))return
      if(['CHAT_UNAUTHORIZED','CHAT_FORBIDDEN','CHAT_PERMISSION_DENIED','CHAT_DIRECT_DISABLED'].includes(error.code))this.failure(serial,error)
      else this.setData({notice:error.code==='CHAT_RECALL_WINDOW_EXPIRED'?'已超过两分钟撤回时间':error.code==='CHAT_RECALL_NOT_ALLOWED'?'该消息当前不能撤回':'撤回未完成，请稍后重试'})
    }finally{if(this.active(serial))this.setData({busy:false})}
  },
  onPageScroll(event) {
    const top=Number(event.scrollTop)||0
    if(this.sceneScrollTop!==undefined&&top<this.sceneScrollTop-4)this.sceneAtBottom=false
    this.sceneScrollTop=top
    if(this.data.messageMenu)this.closeMessageMenu()
    if(this.data.showSettings||this.data.showInboxMenu)this.setData({showSettings:false,showInboxMenu:false})
  },
  onReachBottom() {
    if(this.data.inbox)return
    this.sceneAtBottom=true
    if(this.deep===true||this.deepPage===true){
      this.deep=false
      this.deepPage=false
      Promise.resolve(this.refresh()).then(()=>{if(this.sceneAtBottom)this.acknowledgeSceneRead()})
      return
    }
    this.acknowledgeSceneRead()
  },
  afterSceneRefresh(messages,append=false) {
    if(append){this.sceneAtBottom=false;return}
    const rows=Array.isArray(messages)?messages:[]
    if(!rows.length)return
    const first=!this.sceneLatestMounted
    this.sceneLatestMounted=true
    if(!first&&!this.sceneAtBottom)return
    this.sceneAtBottom=true
    const serial=this.serial
    const finish=()=>{if(this.active(serial)&&this.sceneAtBottom)this.acknowledgeSceneRead()}
    const jump=()=>{
      if(!this.active(serial)||this.data.inbox)return
      if(typeof wx.pageScrollTo==='function')wx.pageScrollTo({scrollTop:999999,duration:0,complete:finish})
      else finish()
    }
    if(typeof wx.nextTick==='function')wx.nextTick(jump)
    else setTimeout(jump,0)
  },
  async acknowledgeSceneRead() {
    if(this.data.inbox||!this.data.conversation||!this.data.messages?.length)return
    const cursor=String(this.data.readCursor||this.data.cursor||'')
    if(!cursor||cursor===this.sceneReadCursor)return
    if(this.sceneReadJob){this.sceneReadAgain=true;return}
    const serial=this.serial
    const job=this.http(serial,'conversations/read',{conversation_id:this.data.conversation,cursor})
    this.sceneReadJob=job
    try{
      await job
      if(!this.active(serial))return
      this.sceneReadCursor=cursor
      this.manager.refreshAccount()
    }catch(error){
      if(this.active(serial)&&['CHAT_UNAUTHORIZED','CHAT_FORBIDDEN','CHAT_PERMISSION_DENIED','CHAT_DIRECT_DISABLED'].includes(error.code))this.failure(serial,error)
    }finally{
      if(this.sceneReadJob===job)this.sceneReadJob=null
      if(this.active(serial)&&this.sceneAtBottom&&String(this.data.readCursor||this.data.cursor||'')!==this.sceneReadCursor)this.acknowledgeSceneRead()
    }
  }
}

module.exports = {quoteText, sceneReferences}