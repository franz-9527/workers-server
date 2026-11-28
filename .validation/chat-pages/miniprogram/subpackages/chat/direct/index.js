// Direct owns relation resolution and filtered inbox only. All messages use conversation/index.
const Api=require('../../../utils/OrderApi.js')
const Runtime=require('../../../utils/ChatRuntime.js')
const Inbox=require('../../../utils/ChatInboxModel.js')
const Presentation=require('../../../utils/ChatPresentation.js')
const api=async(route,body)=>(await Api.request('chat.direct.'+route,'POST',body)).data
const directInboxSummary=value=>{const s=Inbox.directSummary(value);return s?{...s,activityLabel:Presentation.timeLabel(s.activityAt)}:null}
const url=(id,title)=>'/subpackages/chat/conversation/index?scene=direct&conversation_id='+encodeURIComponent(id)+'&title='+encodeURIComponent(title||'普通私聊')
Page({
 data:{items:[],next:'',filter:'active',ready:false,inbox:true,error:'',showInboxMenu:false,inboxSummaryStale:false},
 onLoad(options={}){this.manager=Runtime.get();this.serial=0;this.target=options.id?{relationship:String(options.id)}:options.target?{target:String(options.target),source:options.source==='worker'?'worker':'public_user'}:null;this.setData({inbox:!this.target});this.leave=this.manager.subscribe((type,payload)=>{if(type==='reset'||type==='availability'&&!payload.enabled){this.serial++;clearTimeout(this.timer);this.loading=false;this.setData({items:[],ready:false,error:'当前私聊暂不可用，请重新进入'})}})},
 onShow(){this.visible=true;this.identity=this.manager.identityEpoch;++this.serial;this.refresh().finally(()=>this.schedule(this.serial))},
 onHide(){this.visible=false;++this.serial;clearTimeout(this.timer);this.loading=false},
 onUnload(){this.onHide();this.leave?.()},
 active(serial){return this.visible&&serial===this.serial&&this.identity===this.manager.identityEpoch},
 schedule(serial){clearTimeout(this.timer);if(this.active(serial))this.timer=setTimeout(async()=>{if(this.active(serial))await this.refresh();this.schedule(serial)},9000)},
 async http(serial,path,body){const epoch=this.manager.epoch,token=await this.manager.credential(epoch);if(!this.active(serial)||!this.manager.live(epoch)||this.manager.simulation||this.manager.testSandbox)throw Error('context changed');const result=await this.manager.io.http(path,body,token,'direct');if(!this.active(serial)||!this.manager.live(epoch))throw Error('context changed');return result},
 async refresh(append=false){
  if(!this.visible||this.loading||this.redirecting)return
  const serial=this.serial;this.loading=true
  try{
   if(!this.target){
        const page=await api('inbox',{after:append?this.data.next:'0',filter:this.data.filter})
        if(!this.active(serial))return
        const prior=new Map(this.data.items.map(row=>[String(row.relationship||''),row]))
        const ids=(page.items||[]).map(row=>String(row.relationship||'')).filter(id=>/^[1-9][0-9]{0,19}$/.test(id))
        const summaries=new Map()
        let summaryStale=false,summaryFetched=ids.length===0
        if(ids.length){
          try{
            const result=await this.http(serial,'direct/summaries',{conversation_ids:ids})
            summaryFetched=true
            for(const item of (result?.items||[])){
              const id=String(item?.conversation_id||''),summary=directInboxSummary(item?.unread)
              if(summary&&ids.includes(id))summaries.set(id,summary)
            }
          }catch(err){
            if(!this.active(serial))return
            if(['CHAT_UNAUTHORIZED','CHAT_FORBIDDEN','CHAT_PERMISSION_DENIED','CHAT_DIRECT_DISABLED'].includes(err.code))throw err
            summaryStale=true
          }
        }
        const rows=new Map((append?this.data.items:[]).map(row=>[row.relationship,row]))
        for(const row of (page.items||[])){
          if(!row.relationship)continue
          const id=String(row.relationship)
          const summary=summaryFetched?(summaries.get(id)||null):(prior.get(id)?.summary||null)
          rows.set(row.relationship,{...row,summary,avatarPath:require('../../../utils/ChatIdentity.js').avatarPath(row.peer?.avatar_ref,(row.peer?.roles||[]).includes('admin')?'platform':(row.peer?.roles||[]).includes('worker')?'worker':'user')})
        }
        this.setData({items:[...rows.values()],next:page.next,ready:true,error:'',inboxSummaryStale:summaryStale})

   }else{
    const entry=await api('entry',this.target)
    if(!this.active(serial))return
    if(!entry.ready||!Inbox.validID(String(entry.relationship||''))){this.setData({ready:false,error:'正在建立私聊会话，请稍后。'});return}
    this.redirecting=true
    wx.redirectTo({url:url(String(entry.relationship),entry.peer?.display_name),fail:()=>{this.redirecting=false;if(this.active(serial))this.setData({error:'会话打开失败，请下拉重试'})}})
   }
  }catch(error){if(this.active(serial)){const denied=['CHAT_UNAUTHORIZED','CHAT_FORBIDDEN','CHAT_PERMISSION_DENIED','CHAT_DIRECT_DISABLED'].includes(error.code);this.setData({...denied?{items:[],ready:false,next:''}:{},error:'当前私聊暂不可用，请刷新重试。'})}}
  finally{if(this.active(serial))this.loading=false}
 },
 onPullDownRefresh(){this.refresh().finally(()=>wx.stopPullDownRefresh())},
 more(){this.refresh(true)},
 filter(event){const filter=String(event.currentTarget.dataset.filter||'');if(this.loading||!['active','archived','hidden'].includes(filter))return;this.setData({items:[],next:'',filter,showInboxMenu:false});this.refresh()},
 toggleSceneInboxMenu(){this.setData({showInboxMenu:!this.data.showInboxMenu})},
 closeSceneInboxMenu(){this.setData({showInboxMenu:false})},stopSceneOperation(){},
 open(event){const id=String(event.currentTarget.dataset.id||'');if(!Inbox.validID(id))return;this.closeSceneInboxMenu();wx.navigateTo({url:url(id,this.data.items.find(row=>String(row.relationship)===id)?.peer?.display_name)})}
})
