const Runtime=require('../../../utils/ChatRuntime.js')
const {validID}=require('../../../utils/ChatInboxModel.js')
Page({
 data:{enabled:false,busy:false,error:'',status:null,cases:[],hasMore:false,reason:'',target:null,report:null,diagnostic:''},
 onLoad(options){this.options=options||{};this.life=0;this.manager=Runtime.get();this.stop=this.manager.subscribe(type=>{if(type==='reset'){this.life++;this.erase();this.setData({error:'身份已变化，请重新进入'})}})},
 onShow(){this.visible=true;this.life++;this.erase();this.setData({enabled:this.manager.enabled});if(this.manager.enabled)this.load(false)},
 onHide(){this.visible=false;this.life++;this.erase()},
 onUnload(){this.onHide();if(this.stop)this.stop()},
 erase(){this.after='0';this.setData({busy:false,status:null,cases:[],target:null,report:null,reason:'',hasMore:false,error:'',diagnostic:''})},
 current(life){return this.visible&&this.life===life&&this.epoch===this.manager.epoch},
 async load(more){
  if(this.data.busy||!this.manager.enabled)return
  const life=this.life;this.epoch=this.manager.epoch;this.setData({busy:true,error:''})
  try{
   const status=await this.manager.http('governance.status',{},this.epoch)
   const page=await this.manager.http('governance.cases',{after:more?this.after:'0'},this.epoch)
   if(!this.current(life))return
   this.after=page.next_after
   const kinds={review:'内容复核',report:'消息举报',appeal:'本人申诉'},states={pending:'等待处理',confirm:'问题已确认',correct:'已纠正／申诉通过',dismiss:'已处理／未支持',block:'消息已屏蔽',expired:'保留期结束'}
   const items=page.items.map(row=>({...row,kindLabel:kinds[row.kind]||'治理事项',stateLabel:states[row.state]||'状态待核对'}))
   this.setData({status:{...status,untilLabel:status.blocked_until?new Date(status.blocked_until).toLocaleString():''},cases:(more?[...this.data.cases,...items]:items).slice(-100),hasMore:page.has_more})
   const o=this.options
   if(!more&&o.message_id&&validID(o.message_id)&&validID(o.conversation_id))this.setData({report:{conversation_id:o.conversation_id,message_id:o.message_id,...(validID(o.item_id)?{item_id:o.item_id}:{})}})
   if(!more&&o.client_message_id&&validID(o.conversation_id)){
    const result=await this.manager.http(o.media==='av'?'av.status':'media.status',{conversation_id:o.conversation_id,client_message_id:o.client_message_id},this.epoch)
    if(!this.current(life))return
    if(result.state==='rejected'&&validID(result.intent_id)&&validID(result.review_version))this.setData({target:{target_type:o.media==='av'?'media_intent':'image_intent',target_id:result.intent_id,expected_version:result.review_version}})
    else this.setData({diagnostic:'此图片发送尚无可申诉的拒绝结果，可返回会话核验状态。'})
   }
  }catch(error){if(this.current(life))this.setData({status:null,cases:[],report:null,target:null,error:'状态暂不可用，请刷新后重试'})}
  finally{if(this.current(life))this.setData({busy:false})}
 },
 refresh(){this.load(false)},more(){if(this.data.hasMore)this.load(true)},
 input(event){this.setData({reason:String(event.detail.value||'').slice(0,200)})},
 appealBan(){const s=this.data.status;if(s?.blocked)this.setData({report:null,target:{target_type:'restriction',target_id:s.actor_id,expected_version:s.version},reason:'',diagnostic:''})},
 appealCase(event){
  const c=this.data.cases.find(v=>v.case_id===String(event.currentTarget.dataset.id||''))
  if(c&&c.kind==='review'&&c.target_type==='risk')this.setData({report:null,target:{target_type:'risk',target_id:c.target_id,expected_version:c.target_version},reason:'',diagnostic:''})
 },
 async submit(){
  if(this.data.busy)return
  const reason=this.data.reason.trim();if([...reason].length<2){this.setData({error:'请填写至少2字的具体原因'});return}
  const life=this.life;this.epoch=this.manager.epoch;this.setData({busy:true,error:''})
  try{
   if(this.data.report)await this.manager.http('governance.report',{...this.data.report,reason},this.epoch)
   else if(this.data.target)await this.manager.http('governance.appeal',{...this.data.target,reason},this.epoch)
   else return
   if(!this.current(life))return
   this.options={};this.setData({target:null,report:null,reason:'',diagnostic:'已提交，处理结果可在下方查看。申诉通过不会自动补发旧消息。'})
  }catch(error){if(this.current(life))this.setData({error:'提交未完成，请刷新案件状态后重试，同一事项会合并'})}
  finally{if(this.current(life))this.setData({busy:false})}
 },
 cancel(){this.options={};this.setData({target:null,report:null,reason:''})}
})
